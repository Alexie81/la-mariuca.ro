<?php
declare(strict_types=1);
namespace App\Modules\Inventory;

use PDO;

/**
 * Read model rebuilt exclusively from posted source documents.
 *
 * `nir_stock_balances` remains the transactional FIFO write model, but screens
 * and exports must never inherit an old/manual balance or the online inventory.
 */
final class AccountingStockReadModel
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param list<int> $productIds @return array<int,string> */
    public function quantities(array $productIds): array
    {
        $productIds=array_values(array_unique(array_filter($productIds,static fn(int $id):bool=>$id>0)));
        if($productIds===[]) { return []; }
        $result=array_fill_keys($productIds,'0');
        $query=$this->pdo->prepare('SELECT product_id,SUM(quantity_units) AS quantity_units FROM ('.self::documentMovementsSql().') movements WHERE product_id IN ('.implode(',',array_fill(0,count($productIds),'?')).') GROUP BY product_id');
        foreach($productIds as $index=>$productId) { $query->bindValue($index+1,$productId,PDO::PARAM_INT); }
        $query->execute();
        foreach($query->fetchAll() as $row) { $result[(int)$row['product_id']]=rtrim(rtrim(NirDecimal::quantity((int)$row['quantity_units']),'0'),'.'); }
        return $result;
    }

    public function negativeProductsCount(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM (SELECT product_id FROM ('.self::documentMovementsSql().') movements GROUP BY product_id HAVING SUM(quantity_units)<0) negative_products')->fetchColumn();
    }

    /** One quantity per source line, regardless of legacy FIFO/catalog tracking flags.
     * Credits describe the financial return; only its confirmed NIR receives goods.
     */
    public static function documentMovementsSql(): string
    {
        return "SELECT line.product_id,COALESCE(line.scope_type,'product') AS scope_type,
                COALESCE(line.scope_id,line.product_id) AS scope_id,line.stock_quantity_units AS quantity_units,
                line.id*2 AS id,document.id AS nir_id,line.id AS line_id,
                CASE WHEN document.type='customer_return' THEN 'NIR_CUSTOMER_RETURN'
                    WHEN document.type='supplier_return' THEN 'NIR_SUPPLIER_RETURN' ELSE 'NIR_IN' END AS type,
                'nir' AS source_type,document.id AS source_id,document.warehouse_id,
                document.confirmed_by AS actor_id,COALESCE((SELECT MIN(audit.created_at) FROM nir_stock_movements audit WHERE audit.nir_id=document.id AND audit.line_id=line.id),document.confirmed_at,document.created_at) AS created_at
            FROM nir_lines line JOIN nir_documents document ON document.id=line.nir_id
            WHERE document.status='confirmed' AND line.product_id IS NOT NULL AND line.stock_quantity_units<>0
            UNION ALL
            SELECT line.product_id,COALESCE(line.scope_type,'product') AS scope_type,
                COALESCE(line.scope_id,line.product_id) AS scope_id,-line.quantity_units AS quantity_units,
                line.id*2+1 AS id,NULL AS nir_id,NULL AS line_id,'INVOICE_OUT' AS type,
                'invoice_line' AS source_type,line.id AS source_id,line.warehouse_id,
                document.created_by AS actor_id,COALESCE((SELECT MIN(audit.created_at) FROM nir_stock_movements audit WHERE audit.source_type='invoice_line' AND audit.source_id=line.id AND audit.quantity_units<0),document.issued_at) AS created_at
            FROM invoice_lines line JOIN invoices document ON document.id=line.invoice_id
            WHERE document.deleted_at IS NULL AND document.kind IN ('sale','corrected_positive')
                AND line.kind='product' AND line.product_id IS NOT NULL AND line.quantity_units>0";
    }
}
