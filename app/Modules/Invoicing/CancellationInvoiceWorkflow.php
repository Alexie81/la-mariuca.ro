<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

/** Cancellation changes the order; its fiscal documents require a separate explicit choice. */
final class CancellationInvoiceWorkflow
{
    public function __construct(private readonly InvoiceRepository $repository,private readonly InvoiceService $invoices) {}

    /** Called before changing the status, inside the cancellation transaction.
     * @param array<string,mixed> $actor @return array<string,mixed>
     */
    public function automaticCardDocuments(int $orderId,bool $notify,array $actor): array
    {
        $documents=$this->repository->currentOrderInvoices($orderId);
        if($documents===[]) { $this->invoices->issue($orderId,['request_key'=>'card-cancellation-sale:'.$orderId,'send_email'=>$notify?'1':'0'],$actor); }
        $plan=$this->plan($orderId,$actor);
        if(!$plan['pending']) { return ['decision'=>'later']; }
        return ['decision'=>'credit','document_version'=>$plan['version'],'request_key'=>hash('sha256','card-cancellation:'.$orderId.':'.$plan['version']),'notify_customer'=>$notify?'1':'0'];
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function plan(int $orderId,array $actor): array
    {
        $order=$this->repository->one('orders',['id'=>$orderId])??throw new InvoiceException('Comanda nu există.',404);
        $documents=[];$versions=[];
        foreach($this->repository->currentOrderInvoices($orderId) as $row) {
            $versions[]=[(int)$row['id'],(int)$row['row_version']];
            if(!in_array($row['kind'],['sale','corrected_positive'],true)) { continue; }
            $invoice=$this->invoices->load((int)$row['id']);$quantities=[];
            foreach($invoice['lines'] as $line) {
                $remaining=(int)$line['quantity_units']-$this->repository->creditedUnits((int)$line['id']);
                if($remaining>0) { $quantities[$line['id']]=InvoiceCalculator::quantity($remaining); }
            }
            if($quantities===[]) { continue; }
            $documents[]=['id'=>(int)$row['id'],'number'=>$row['series_code'].' '.$row['number'],'version'=>(int)$row['row_version'],
                'quantities'=>$quantities,'delete_blocker'=>$this->invoices->deleteBlocker($invoice)];
        }
        $canDelete=count($documents)===1 && $documents[0]['delete_blocker']==='' && ($actor['role']??'')==='administrator';
        $events=$this->repository->all('audit_log',['entity_type'=>'order','entity_id'=>$orderId,'action'=>'order.cancel']);
        usort($events,static fn(array $a,array $b):int=>(int)$b['id']<=>(int)$a['id']);
        $last=json_decode($events[0]['context_json']??'{}',true);
        return ['pending'=>$documents!==[],'documents'=>$documents,'can_delete'=>$canDelete,'automatic'=>$this->repository->hasConfirmedCardPayment($orderId),
            'customer_cancelled'=>($last['source']??'')==='customer_tracking',
            'version'=>hash('sha256',InvoiceSnapshot::json([$orderId,(int)$order['invoice_cycle_start_id'],$versions,$documents,$canDelete]))];
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor */
    public function resolve(int $orderId,array $input,array $actor): void
    {
        $decision=(string)($input['decision']??'');
        if(!in_array($decision,['delete','credit'],true)) { throw new InvoiceException('Alege ștergerea documentului sau emiterea facturii de retur.'); }
        $this->invoices->allow($actor,$decision==='delete'?'delete':'return');
        $key=(string)($input['request_key']??'');
        if(!preg_match('/^[a-zA-Z0-9:_-]{8,128}$/D',$key)) { throw new InvoiceException('Redeschide confirmarea pentru documente.',409); }
        $expected=(string)($input['document_version']??'');$notify=($input['notify_customer']??'0')==='1';
        $hash=hash('sha256',InvoiceSnapshot::json([$orderId,$decision,$expected,$notify]));
        $this->repository->transaction(function()use($orderId,$decision,$key,$expected,$notify,$hash,$actor):void {
            $prior=$this->repository->one('order_document_operations',['request_key'=>$key]);
            if($prior) {
                if($prior['action']!=='cancel_documents' || (int)$prior['order_id']!==$orderId || !hash_equals($prior['payload_hash'],$hash)) { throw new InvoiceException('Confirmarea a fost folosită pentru alte date.',409); }
                return;
            }
            $order=$this->repository->one('orders',['id'=>$orderId])??throw new InvoiceException('Comanda nu există.',404);
            if($order['status']!=='cancelled') { throw new InvoiceException('Comanda nu mai este anulată. Reîncarcă pagina.',409); }
            $plan=$this->plan($orderId,$actor);
            if(!hash_equals($plan['version'],$expected)) { throw new InvoiceException('Documentele s-au modificat. Redeschide confirmarea pentru a vedea opțiunile actuale.',409); }
            if(!$plan['pending']) { throw new InvoiceException('Documentele anulării sunt deja rezolvate.',409); }
            if($decision==='delete' && !$plan['can_delete']) { throw new InvoiceException('Factura nu poate fi ștearsă. Poți emite factura de retur.',409); }
            $results=[];
            foreach($plan['documents'] as $document) {
                $operation=['request_key'=>hash('sha256',$key.':'.$decision.':'.$document['id']),'version'=>$document['version'],'reason'=>'Anularea comenzii '.$order['order_number']];
                if($decision==='delete') { $this->invoices->delete($document['id'],$operation,$actor);$results[]=$document['id']; }
                else {
                    $credit=$this->invoices->credit($document['id'],$operation+['quantities'=>$document['quantities'],'notify_customer'=>$notify?'1':'0'],$actor);
                    $results[]=$credit['id'];
                }
            }
            $this->repository->insert('order_document_operations',['request_key'=>$key,'order_id'=>$orderId,'action'=>'cancel_documents','payload_hash'=>$hash,'result_id'=>$results[0]??null,'created_at'=>$this->repository->now()]);
            $this->repository->insert('audit_log',['actor_user_id'=>$actor['id'],'action'=>'order.cancellation_documents','entity_type'=>'order','entity_id'=>$orderId,
                'context_json'=>InvoiceSnapshot::json(['decision'=>$decision,'documents'=>$results]),'created_at'=>$this->repository->now()]);
        });
    }
}
