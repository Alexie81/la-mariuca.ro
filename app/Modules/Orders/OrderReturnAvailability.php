<?php
declare(strict_types=1);
namespace App\Modules\Orders;

use PDO;

/** One quantity ledger for returns started from either the order or historic invoice controls. */
final class OrderReturnAvailability
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int,int> Returned whole pieces, keyed by order item. */
    public function returned(int $orderId): array
    {
        $query=$this->pdo->prepare('SELECT ri.order_item_id,SUM(ri.quantity) AS quantity FROM order_return_items ri JOIN order_returns r ON r.id=ri.return_id JOIN orders o ON o.id=r.order_id WHERE r.order_id=:id AND r.voided_at IS NULL AND r.id>o.return_cycle_start_id GROUP BY ri.order_item_id');
        $query->execute(['id'=>$orderId]); $result=[];
        foreach($query->fetchAll() as $row) { $result[(int)$row['order_item_id']]=(int)$row['quantity']; }
        $query=$this->pdo->prepare("SELECT l.order_item_id,SUM(-l.quantity_units) AS units FROM invoice_lines l JOIN invoices i ON i.id=l.invoice_id
            WHERE i.order_id=:id AND i.id>(SELECT invoice_cycle_start_id FROM orders WHERE id=i.order_id) AND i.deleted_at IS NULL AND i.kind='return' AND l.kind='product' AND l.quantity_units<0
            AND NOT EXISTS(SELECT 1 FROM order_return_documents d WHERE d.invoice_id=i.id) GROUP BY l.order_item_id");
        $query->execute(['id'=>$orderId]);
        foreach($query->fetchAll() as $row) {
            if($row['order_item_id']!==null) { $id=(int)$row['order_item_id']; $result[$id]=($result[$id] ?? 0)+(int)ceil((int)$row['units']/10000); }
        }
        return $result;
    }
}
