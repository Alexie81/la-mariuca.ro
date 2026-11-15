<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

/** Queued in the receipt transaction; delivery is handled only by the mail worker. */
final class ReturnReceiptNotification
{
    public function __construct(private readonly InvoiceRepository $repository) {}

    public function queue(int $orderId,string $eventKey,?int $invoiceId=null): void
    {
        $key='return-receipt:'.$eventKey;
        if($this->repository->one('outbox_messages',['deduplication_key'=>$key])) { return; }
        $order=$this->repository->one('orders',['id'=>$orderId]);
        if(!$order) { throw new InvoiceException('Comanda returului nu există.',404); }
        $message='Am înregistrat verificarea produselor din retur și cantitățile recepționate. Restituirea banilor este urmărită separat și te anunțăm după înregistrarea ei.';
        $payload=['order_id'=>$orderId,'message'=>$message];
        if($invoiceId!==null) { $payload+=['invoice_id'=>$invoiceId,'return_notice'=>'physically_received']; }
        else { $payload+=['status'=>$order['status']]; }
        $this->repository->insert('outbox_messages',['deduplication_key'=>$key,'message_type'=>$invoiceId!==null?'invoice_return_notice':'order_status_update','recipient'=>$order['email'],
            'payload_json'=>InvoiceSnapshot::json($payload),'status'=>'pending','attempts'=>0,'available_at'=>$this->repository->now(),'created_at'=>$this->repository->now()]);
    }
}
