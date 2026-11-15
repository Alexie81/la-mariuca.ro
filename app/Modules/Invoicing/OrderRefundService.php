<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

/** Records money already returned by the operator. Never initiates a transfer. */
final class OrderRefundService
{
    public function __construct(private readonly InvoiceRepository $repository) {}

    /** @param array<string,mixed> $order */
    public function eligible(array $order): bool
    {
        return in_array($order['status'],['cancelled','returned','return_confirmed','refunded'],true)
            || $this->repository->one('order_returns',['order_id'=>$order['id'],'voided_at'=>null])!==null;
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $input @param array<string,mixed> $actor */
    public function record(array $order,array $input,array $actor): void
    {
        if(!$this->repository->pdo()->inTransaction()) { throw new \LogicException('Refund recording requires a transaction.'); }
        if(!$this->eligible($order)) { throw new InvoiceException('Rambursarea este disponibilă după anulare sau confirmarea unui retur.',409); }
        $id=(int)$order['id'];$cash=$this->repository->orderCash($id);$state=(string)$input['payment_status'];
        if($cash['received']<=0) { throw new InvoiceException('Nu există o sumă încasată care să poată fi rambursată.'); }
        $total=$state==='refunded'?$cash['received']:InvoiceCalculator::bani((string)($input['refund_amount'] ?? '0'));
        if($total<=0 || $total>$cash['received']) { throw new InvoiceException('Suma restituită trebuie să fie mai mare de 0 și să nu depășească suma încasată.'); }
        if($state==='partially_refunded' && $total===$cash['received']) { throw new InvoiceException('Pentru întreaga sumă încasată, alege „Rambursată”.'); }
        if($total<$cash['refunded']) { throw new InvoiceException('Totalul nu poate fi mai mic decât restituirile deja înregistrate: '.InvoiceCalculator::money($cash['refunded']).'.'); }
        $difference=$total-$cash['refunded'];
        if($difference===0 && $order['payment_status']===$state) { return; }
        // Preserve the captured balance even if a cancelled order no longer has an invoice.
        if(!$this->repository->one('payments',['order_id'=>$id,'provider'=>'manual_cod'])) {
            $this->repository->insert('payments',['order_id'=>$id,'provider'=>'manual_cod','provider_payment_id'=>'manual-cod:'.$id,'amount_bani'=>$cash['received'],'status'=>'paid','currency'=>$order['currency'],'refunded_bani'=>0,'created_at'=>$this->repository->now(),'updated_at'=>$this->repository->now()]);
        }
        if($difference>0) {
            $this->repository->insert('order_refunds',['order_id'=>$id,'request_key'=>$input['request_key'],'amount_bani'=>$difference,'actor_id'=>$actor['id'],'created_at'=>$this->repository->now()]);
            $this->allocate($id);
        }
        $this->repository->update('orders',['payment_status'=>$state,'updated_at'=>$this->repository->now()],['id'=>$id]);
        $this->repository->insert('audit_log',['actor_user_id'=>$actor['id'],'action'=>'order.payment_updated','entity_type'=>'order','entity_id'=>$id,'context_json'=>InvoiceSnapshot::json([
            'reason'=>'Rambursare înregistrată din comandă','before'=>['status'=>$order['payment_status'],'received_bani'=>$cash['received'],'refunded_bani'=>$cash['refunded']],
            'after'=>['status'=>$state,'received_bani'=>$cash['received'],'refunded_bani'=>$total],'external_transfer_performed'=>false]),'created_at'=>$this->repository->now()]);
        if($difference>0 && ($input['notify_customer'] ?? '0')==='1') {
            $this->repository->insert('outbox_messages',['deduplication_key'=>'order-refund:'.$input['request_key'],'message_type'=>'order_status_update','recipient'=>$order['email'],
                'payload_json'=>InvoiceSnapshot::json(['order_id'=>$id,'status'=>$order['status'],'message'=>'Am înregistrat restituirea sumei de '.InvoiceCalculator::money($difference).'. Total restituit pentru această comandă: '.InvoiceCalculator::money($total).'.']),
                'status'=>'pending','attempts'=>0,'available_at'=>$this->repository->now(),'created_at'=>$this->repository->now()]);
        }
    }

    /** Link recorded refunds to available credit documents, without counting the same money twice. */
    public function allocate(int $orderId): void
    {
        if(!$this->repository->pdo()->inTransaction()) { throw new \LogicException('Refund allocation requires a transaction.'); }
        $documents=array_values(array_filter($this->repository->all('invoices',['order_id'=>$orderId,'deleted_at'=>null]),static fn(array $row):bool=>(int)$row['gross_bani']<0));
        usort($documents,static fn(array $a,array $b):int=>(int)$a['id']<=>(int)$b['id']);
        $refunds=$this->repository->all('order_refunds',['order_id'=>$orderId]);
        usort($refunds,static fn(array $a,array $b):int=>(int)$a['id']<=>(int)$b['id']);
        foreach($refunds as $refund) {
            $left=(int)$refund['amount_bani']-array_sum(array_column($this->repository->all('invoice_refunds',['order_refund_id'=>$refund['id']]),'amount_bani'));
            foreach($documents as $document) {
                if($left<=0) { break; }
                $available=abs((int)$document['gross_bani'])-array_sum(array_column($this->repository->all('invoice_refunds',['invoice_id'=>$document['id']]),'amount_bani'));
                $take=min($left,max(0,$available));if($take<=0) { continue; }
                $this->repository->insert('invoice_refunds',['invoice_id'=>$document['id'],'order_refund_id'=>$refund['id'],'amount_bani'=>$take,'reference'=>'Rambursare înregistrată din comandă','actor_id'=>$refund['actor_id'],'created_at'=>$refund['created_at']]);
                $left-=$take;
            }
        }
    }
}
