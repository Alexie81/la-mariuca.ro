<?php
declare(strict_types=1);
namespace App\Modules\Orders;

use App\Modules\Invoicing\InvoiceRepository;
use PDO;
use RuntimeException;

/** Reopens the same order, retaining its documents, payments and cancellation audit. */
final class OrderReopeningService
{
    public function __construct(private readonly InvoiceRepository $repository) {}

    public function blocker(int $orderId): string
    {
        foreach($this->repository->currentOrderInvoices($orderId) as $document) {
            if($document['kind']==='return') { return 'Comanda anulată are o factură de retur emisă și nu mai poate fi reluată.'; }
        }
        return '';
    }

    /** The caller holds the common order/invoice/stock transaction.
     * @param array<string,mixed> $order
     */
    public function prepare(array $order,int $actorId,string $now): void
    {
        $pdo=$this->repository->pdo();
        if(!$pdo->inTransaction()) { throw new \LogicException('Reopening requires a transaction'); }
        $actor=$this->repository->one('users',['id'=>$actorId,'status'=>'active']);
        if(!$actor || !in_array($actor['role'],['administrator','manager'],true)) { throw new RuntimeException('Nu ai permisiunea să reiei comanda.'); }
        $id=(int)$order['id'];
        $blocker=$this->blocker($id);
        if($blocker!=='') { throw new RuntimeException($blocker); }
        $query=$pdo->prepare('SELECT oi.variant_id,SUM(oi.quantity) AS quantity,COALESCE(v.stock_unlimited,0) AS unlimited FROM order_items oi LEFT JOIN product_variants v ON v.id=oi.variant_id WHERE oi.order_id=:id GROUP BY oi.variant_id,v.stock_unlimited');$query->execute(['id'=>$id]);$quantities=[];
        foreach($query->fetchAll() as $item) {
            $quantities[(int)$item['variant_id']]=(int)$item['quantity'];
            if(!$item['unlimited'] && !$this->repository->one('inventory_reservations',['order_id'=>$id,'variant_id'=>$item['variant_id']])) {
                $this->repository->insert('inventory_reservations',['order_id'=>$id,'variant_id'=>$item['variant_id'],'quantity'=>$item['quantity'],'status'=>'released','created_at'=>$now,'updated_at'=>$now]);
            }
        }
        $reservations=$this->repository->all('inventory_reservations',['order_id'=>$id]);
        foreach($reservations as $reservation) {
            if($reservation['status']==='active' || !isset($quantities[(int)$reservation['variant_id']])) { continue; }
            $quantity=$quantities[(int)$reservation['variant_id']];$physical=0;
            if($reservation['status']==='consumed') {
                // Undo only the shipment quantity not already returned physically.
                $query=$pdo->prepare("SELECT COALESCE(SUM(m.physical_delta),0) FROM inventory_movements m JOIN invoice_receipts r ON r.id=m.reference_id JOIN invoices i ON i.id=r.invoice_id WHERE m.reference_type='invoice_receipt' AND i.order_id=:id AND i.id>:boundary AND m.variant_id=:variant AND r.reversed_at IS NULL");
                $query->execute(['id'=>$id,'boundary'=>$order['invoice_cycle_start_id'],'variant'=>$reservation['variant_id']]);
                $physical=max(0,$quantity-(int)$query->fetchColumn());
            }
            $update=$pdo->prepare('UPDATE inventory SET physical_quantity=physical_quantity+:restore,reserved_quantity=reserved_quantity+:reserve,updated_at=:now WHERE variant_id=:variant AND physical_quantity+:available-reserved_quantity>=:needed');
            foreach(['restore'=>$physical,'reserve'=>$quantity,'now'=>$now,'variant'=>(int)$reservation['variant_id'],'available'=>$physical,'needed'=>$quantity] as $name=>$value) { $update->bindValue(':'.$name,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR); }
            $update->execute();
            if($update->rowCount()!==1) { throw new RuntimeException('Stoc insuficient pentru reluare. Verifică produsele disponibile; comanda a rămas anulată.'); }
            $this->repository->update('inventory_reservations',['quantity'=>$quantity,'status'=>'active','expires_at'=>null,'updated_at'=>$now],['id'=>$reservation['id']]);
            $this->repository->insert('inventory_movements',['variant_id'=>$reservation['variant_id'],'type'=>'reserve','physical_delta'=>$physical,'reserved_delta'=>$quantity,'reason'=>'Reluare comandă anulată','reference_type'=>'order','reference_id'=>$id,'actor_user_id'=>$actorId,'created_at'=>$now]);
        }
        // A fully cancelled fiscal chain stays in history; the resumed sale can receive a new invoice.
        if($this->repository->orderNet($id)===0) {
            $cash=$this->repository->orderCash($id);
            if($order['payment_method']==='cod' && !$this->repository->one('payments',['order_id'=>$id,'provider'=>'manual_cod']) && $cash['received']>0) {
                $this->repository->insert('payments',['order_id'=>$id,'provider'=>'manual_cod','provider_payment_id'=>'manual-cod:'.$id,'amount_bani'=>$cash['received'],'status'=>'paid','currency'=>$order['currency'],'refunded_bani'=>0,'created_at'=>$now,'updated_at'=>$now]);
            }
            $query=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM invoices WHERE order_id=:id');$query->execute(['id'=>$id]);$invoiceBoundary=(int)$query->fetchColumn();
            $query=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM order_returns WHERE order_id=:id');$query->execute(['id'=>$id]);$returnBoundary=(int)$query->fetchColumn();
            $pdo->prepare('UPDATE invoices SET primary_order_id=NULL WHERE primary_order_id=:id')->execute(['id'=>$id]);
            $this->repository->update('orders',['invoice_cycle_start_id'=>$invoiceBoundary,'return_cycle_start_id'=>$returnBoundary],['id'=>$id]);
        }
        $pdo->prepare("UPDATE coupon_redemptions SET status='reserved',updated_at=:now WHERE order_id=:id AND status='released'")->execute(['id'=>$id,'now'=>$now]);
    }
}
