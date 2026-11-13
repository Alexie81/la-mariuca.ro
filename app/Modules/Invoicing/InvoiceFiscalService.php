<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

final class InvoiceFiscalService
{
    public function __construct(private readonly InvoiceRepository $repository,private readonly InvoiceService $invoices,private readonly ?FiscalGateway $gateway=null) {}
    /** @param array<string,mixed> $actor */
    public function queue(int $id,array $actor): void
    {
        $this->invoices->allow($actor,'delete');
        if(!$this->gateway) { throw new InvoiceException('Integrarea fiscală electronică nu este configurată.'); }
        $this->repository->transaction(function()use($id,$actor):void {
            $invoice=$this->invoices->load($id); $document=$this->gateway->build($invoice); $errors=$this->gateway->validate($document);
            if($errors) { throw new InvoiceException('Verifică documentul: '.implode(' ',array_slice($errors,0,5))); }
            if($job=$this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$id])) {
                if($invoice['fiscal_attempted_at']===null) { $this->repository->update('invoice_fiscal_jobs',['state'=>'queued','payload_hash'=>InvoiceService::documentHash($invoice),'updated_at'=>$this->repository->now()],['invoice_id'=>$id]); }
                return;
            }
            $this->repository->insert('invoice_fiscal_jobs',['invoice_id'=>$id,'state'=>'queued','attempts'=>0,'payload_hash'=>InvoiceService::documentHash($invoice),'created_at'=>$this->repository->now(),'updated_at'=>$this->repository->now()]);
            $this->audit($id,'fiscal_queued',$actor['id'],[]);
        });
    }
    public function process(int $id): void
    {
        if(!$this->gateway) { throw new InvoiceException('Integrarea fiscală electronică nu este configurată.'); }
        if($this->repository->pdo()->inTransaction()) { throw new \LogicException('External transport must run after commit'); }
        $invoice=$this->repository->transaction(function()use($id):?array {
            $job=$this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$id]);
            if(!$job || !in_array($job['state'],['queued','transient_error'],true) || (int)$job['attempts']>=5) { return null; }
            $invoice=$this->invoices->load($id);
            if(!hash_equals($job['payload_hash'],InvoiceService::documentHash($invoice))) { throw new InvoiceException('Factura s-a modificat după pregătire. Revizuiește trimiterea.',409); }
            $this->repository->update('invoices',['fiscal_state'=>'processing','fiscal_attempted_at'=>$invoice['fiscal_attempted_at'] ?? $this->repository->now(),'row_version'=>(int)$invoice['row_version']+1],['id'=>$id]);
            $this->repository->update('invoice_fiscal_jobs',['state'=>'processing','attempts'=>(int)$job['attempts']+1,'updated_at'=>$this->repository->now()],['invoice_id'=>$id]);
            $this->audit($id,'fiscal_attempted',null,[]); return $invoice;
        });
        if(!$invoice) { return; }
        try {
            $result=$this->gateway->send($this->gateway->build($invoice),$invoice['document_key']);
            if($result['reference']==='' || !in_array($result['state'],['submitted','accepted','rejected'],true)) { throw new \RuntimeException('Invalid provider response'); }
            $this->record($id,$result['state'],$result['reference'],$result['response']);
        } catch(\Throwable) { $this->record($id,'transient_error',null,['message'=>'Transmiterea nu a putut fi confirmată. Reîncercarea folosește aceeași identitate a documentului.']); }
    }
    public function poll(int $id): void
    {
        if(!$this->gateway || $this->repository->pdo()->inTransaction()) { throw new InvoiceException('Verificarea fiscală nu este disponibilă.'); }
        $invoice=$this->invoices->load($id); if(!$invoice['fiscal_reference'] || $invoice['fiscal_state']!=='submitted') { return; }
        $result=$this->gateway->status($invoice['fiscal_reference']);
        if(!in_array($result['state'],['submitted','accepted','rejected'],true)) { throw new InvoiceException('Răspuns fiscal necunoscut.'); }
        $this->record($id,$result['state'],$invoice['fiscal_reference'],$result['response']);
    }
    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function response(int $id,array $actor): array
    {
        $this->invoices->allow($actor); $job=$this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$id]);
        return json_decode($job['response_json'] ?? '{}',true,512,JSON_THROW_ON_ERROR);
    }
    /** @param array<string,mixed> $response */
    private function record(int $id,string $state,?string $reference,array $response): void
    {
        $this->repository->transaction(function()use($id,$state,$reference,$response):void {
            $this->repository->update('invoice_fiscal_jobs',['state'=>$state,'external_reference'=>$reference,'response_json'=>InvoiceSnapshot::json($response),'updated_at'=>$this->repository->now()],['invoice_id'=>$id]);
            $this->repository->update('invoices',['fiscal_state'=>$state,'fiscal_reference'=>$reference],['id'=>$id]);
            $this->audit($id,'fiscal_result',null,['state'=>$state,'reference'=>$reference]);
        });
    }
    /** @param array<string,mixed> $data */
    private function audit(int $id,string $action,?int $actor,array $data): void
    {
        $this->repository->insert('invoice_audit',['invoice_id'=>$id,'actor_id'=>$actor,'action'=>$action,'reason'=>'','before_json'=>'{}','after_json'=>InvoiceSnapshot::json($data),'created_at'=>$this->repository->now()]);
    }
}
