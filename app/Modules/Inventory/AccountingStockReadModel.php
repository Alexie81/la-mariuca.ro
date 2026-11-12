<?php
declare(strict_types=1);
namespace App\Modules\Inventory;

use PDO;

/** Canonical warehouse scopes replace, rather than add to, legacy global scopes. */
final class AccountingStockReadModel
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param list<int> $productIds @return array<int,string> */
    public function quantities(array $productIds): array
    {
        $productIds=array_values(array_unique(array_filter($productIds,static fn(int $id):bool=>$id>0)));
        if($productIds===[]) { return []; }
        $query=$this->pdo->prepare('SELECT product_id,SUM(quantity_units) AS quantity_units FROM ('.$this->scopesSql().') scopes WHERE product_id IN ('.implode(',',array_fill(0,count($productIds),'?')).') GROUP BY product_id');
        $query->execute($productIds); $result=[];
        foreach($query->fetchAll() as $row) { $result[(int)$row['product_id']]=rtrim(rtrim(NirDecimal::quantity((int)$row['quantity_units']),'0'),'.'); }
        return $result;
    }

    public function negativeProductsCount(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM (SELECT product_id FROM ('.$this->scopesSql().') scopes GROUP BY product_id HAVING SUM(quantity_units)<0) negative_products')->fetchColumn();
    }

    private function scopesSql(): string
    {
        return 'SELECT canonical.product_id,canonical.scope_type,canonical.scope_id,canonical.quantity_units FROM nir_stock_balances canonical UNION ALL SELECT legacy.product_id,legacy.scope_type,legacy.scope_id,legacy.quantity*10000 AS quantity_units FROM accounting_stock_balances legacy WHERE NOT EXISTS (SELECT 1 FROM nir_stock_balances canonical_scope WHERE canonical_scope.scope_type=legacy.scope_type AND canonical_scope.scope_id=legacy.scope_id)';
    }
}
