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

    /** Every row is backed by a confirmed NIR or by an active issued invoice. */
    public static function documentMovementsSql(): string
    {
        return "SELECT CASE WHEN movement.scope_type='product' THEN movement.scope_id ELSE variant.product_id END AS product_id,
                movement.scope_type,movement.scope_id,movement.quantity_units,movement.id
            FROM nir_stock_movements movement
            LEFT JOIN product_variants variant ON movement.scope_type='variant' AND variant.id=movement.scope_id
            WHERE (movement.scope_type='product' OR variant.product_id IS NOT NULL)
              AND (
                (movement.nir_id IS NOT NULL AND EXISTS(
                    SELECT 1 FROM nir_documents source_nir
                    WHERE source_nir.id=movement.nir_id AND source_nir.status='confirmed'
                ))
                OR (movement.source_type='invoice_line' AND EXISTS(
                    SELECT 1 FROM invoice_lines source_line
                    JOIN invoices source_invoice ON source_invoice.id=source_line.invoice_id
                    WHERE source_line.id=movement.source_id AND source_invoice.deleted_at IS NULL
                ))
                OR (movement.source_type='order_item' AND EXISTS(
                    SELECT 1 FROM order_items source_item
                    JOIN invoices source_invoice ON (source_invoice.order_id=source_item.order_id OR source_invoice.primary_order_id=source_item.order_id)
                    WHERE source_item.id=movement.source_id AND source_invoice.deleted_at IS NULL
                ))
              )";
    }
}
