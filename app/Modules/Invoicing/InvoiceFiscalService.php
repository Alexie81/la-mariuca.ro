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
            $data=['state'=>'queued','payload_xml'=>$document,'environment'=>$this->gateway instanceof AnafFiscalGateway?$this->gateway->environment():'test','available_at'=>$this->repository->now(),'payload_hash'=>InvoiceService::documentHash($invoice),'updated_at'=>$this->repository->now()];
            if($job=$this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$id])) {
                if($invoice['fiscal_attempted_at']!==null) { throw new InvoiceException('Documentul a fost deja transmis. Verifică starea sau folosește reluarea explicită, dacă este disponibilă.'); }
                $this->repository->update('invoice_fiscal_jobs',$data,['invoice_id'=>$id]); $this->repository->update('invoices',['fiscal_state'=>'queued'],['id'=>$id]);
                return;
            }
            $this->repository->insert('invoice_fiscal_jobs',$data+['invoice_id'=>$id,'attempts'=>0,'created_at'=>$this->repository->now()]);
            $this->repository->update('invoices',['fiscal_state'=>'queued'],['id'=>$id]);
            $this->audit($id,'fiscal_queued',$actor['id'],[]);
        });
    }
    public function process(int $id): void
    {
        if(!$this->gateway) { throw new InvoiceException('Integrarea fiscală electronică nu este configurată.'); }
        if($this->repository->pdo()->inTransaction()) { throw new \LogicException('External transport must run after commit'); }
        if($this->gateway instanceof AnafFiscalGateway) { $this->gateway->prepare(); }
        $invoice=$this->repository->transaction(function()use($id):?array {
            $job=$this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$id]);
            if(!$job || !in_array($job['state'],['queued','scheduled','transient_error'],true) || (int)$job['attempts']>=5 || (!empty($job['available_at']) && $job['available_at']>$this->repository->now())) { return null; }
            if($this->gateway instanceof AnafFiscalGateway && $job['environment']!==$this->gateway->environment()) { throw new InvoiceException('Documentul aparține altui mediu ANAF.'); }
            $invoice=$this->invoices->load($id);
            if(!hash_equals($job['payload_hash'],InvoiceService::documentHash($invoice))) { throw new InvoiceException('Factura s-a modificat după pregătire. Revizuiește trimiterea.',409); }
            $xml=$job['payload_xml'] ?: $this->gateway->build($invoice); $errors=$this->gateway->validate($xml);
            if($errors) { throw new InvoiceException('Verifică documentul: '.implode(' ',array_slice($errors,0,5))); }
            $this->repository->update('invoices',['fiscal_state'=>'processing','fiscal_attempted_at'=>$invoice['fiscal_attempted_at'] ?? $this->repository->now(),'row_version'=>(int)$invoice['row_version']+1],['id'=>$id]);
            $this->repository->update('invoice_fiscal_jobs',['state'=>'processing','payload_xml'=>$xml,'attempts'=>(int)$job['attempts']+1,'updated_at'=>$this->repository->now()],['invoice_id'=>$id]);
            $this->audit($id,'fiscal_attempted',null,[]); $invoice['fiscal_payload_xml']=$xml; return $invoice;
        });
        if(!$invoice) { return; }
        try {
            $result=$this->gateway->send($invoice['fiscal_payload_xml'],$invoice['document_key']);
            if($result['reference']==='' || !in_array($result['state'],['submitted','accepted','rejected'],true)) { throw new \RuntimeException('Invalid provider response'); }
            $this->record($id,$result['state'],$result['reference'],$result['response']);
        } catch(SpvTransportException $error) { $this->record($id,'uncertain',null,['message'=>$error->getMessage()]); }
        catch(SpvResponseException $error) { $this->record($id,$error->state,null,['message'=>$error->getMessage()]); }
        catch(\Throwable) { $this->record($id,$this->gateway instanceof AnafFiscalGateway?'uncertain':'transient_error',null,['message'=>'Transmiterea nu a putut fi confirmată. Verifică răspunsul înainte de reluare.']); }
    }
    public function poll(int $id): void
    {
        if(!$this->gateway || $this->repository->pdo()->inTransaction()) { throw new InvoiceException('Verificarea fiscală nu este disponibilă.'); }
        $invoice=$this->invoices->load($id); if(!$invoice['fiscal_reference'] || $invoice['fiscal_state']!=='submitted') { return; }
        $result=$this->gateway->status($invoice['fiscal_reference']);
        if(!in_array($result['state'],['submitted','accepted','rejected'],true)) { throw new InvoiceException('Răspuns fiscal necunoscut.'); }
        $this->record($id,$result['state'],$invoice['fiscal_reference'],$result['response'],true);
    }
    /** Only definite rejection/failure may be retried; the locked payload stays byte-identical. */
    public function retry(int $id,array $actor): void
    {
        $this->invoices->allow($actor,'delete');
        $this->repository->transaction(function()use($id,$actor):void {
            $invoice=$this->invoices->load($id); $job=$this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$id]);
            if(!$job || !in_array($job['state'],['rejected','transient_error'],true) || !$job['payload_xml']) { throw new InvoiceException('Trimiterea nu poate fi reluată în această stare. Un răspuns neclar trebuie verificat în SPV înainte de orice nouă încărcare.'); }
            if(!hash_equals($job['payload_hash'],InvoiceService::documentHash($invoice))) { throw new InvoiceException('Documentul nu mai corespunde copiei transmise. Nu poate fi retrimis.'); }
            $this->audit($id,'fiscal_retry',$actor['id'],['previous_reference'=>$job['external_reference'],'previous_response'=>$job['response_json']]);
            $this->repository->update('invoice_fiscal_jobs',['state'=>'queued','attempts'=>0,'available_at'=>$this->repository->now(),'external_reference'=>null,'download_reference'=>null,'archive_path'=>null,'updated_at'=>$this->repository->now()],['invoice_id'=>$id]);
            $this->repository->update('invoices',['fiscal_state'=>'queued','fiscal_reference'=>null],['id'=>$id]);
        });
    }
    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function response(int $id,array $actor): array
    {
        $this->invoices->allow($actor); $job=$this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$id]);
        return json_decode($job['response_json'] ?? '{}',true,512,JSON_THROW_ON_ERROR);
    }
    /** @param array<string,mixed> $response */
    private function record(int $id,string $state,?string $reference,array $response,bool $poll=false): void
    {
        $this->repository->transaction(function()use($id,$state,$reference,$response,$poll):void {
            if($poll) {
                $current=$this->invoices->load($id);
                // Another tab/worker may have completed this upload or started an explicit retry.
                if($current['fiscal_state']!=='submitted' || $current['fiscal_reference']!==$reference) { return; }
            }
            $this->repository->update('invoice_fiscal_jobs',['state'=>$state,'external_reference'=>$reference,'response_json'=>InvoiceSnapshot::json($response),'download_reference'=>$response['id_descarcare'] ?? null,'last_error'=>$response['message'] ?? null,'polled_at'=>$this->repository->now(),'available_at'=>$state==='transient_error' && $this->gateway instanceof AnafFiscalGateway?gmdate('Y-m-d\TH:i:s\Z',time()+120):null,'updated_at'=>$this->repository->now()],['invoice_id'=>$id]);
            $this->repository->update('invoices',['fiscal_state'=>$state,'fiscal_reference'=>$reference],['id'=>$id]);
            $this->audit($id,'fiscal_result',null,['state'=>$state,'reference'=>$reference,'response'=>$response]);
        });
    }
    /** @param array<string,mixed> $data */
    private function audit(int $id,string $action,?int $actor,array $data): void
    {
        $this->repository->insert('invoice_audit',['invoice_id'=>$id,'actor_id'=>$actor,'action'=>$action,'reason'=>'','before_json'=>'{}','after_json'=>InvoiceSnapshot::json($data),'created_at'=>$this->repository->now()]);
    }
}
