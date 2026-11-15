<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use App\Shared\Config\Config;

/** Bounded cron command, compatible with PHP shared hosting. */
final class SpvWorker
{
    public function __construct(private readonly InvoiceRepository $repository,private readonly InvoiceService $invoices,private readonly SpvConnection $connection,private readonly SpvHttpClient $http,private readonly Config $config,private readonly string $root) {}
    /** @return array{processed:int,errors:int} */
    public function run(int $limit=10): array
    {
        $pdo=$this->repository->pdo(); $now=$this->repository->now();
        // Crash recovery is conservative: the external request may already have succeeded.
        $this->repository->transaction(function()use($pdo):void {
            $q=$pdo->prepare("SELECT invoice_id FROM invoice_fiscal_jobs WHERE state='processing' AND updated_at<:cutoff"); $q->execute(['cutoff'=>gmdate('Y-m-d\TH:i:s\Z',time()-600)]);
            foreach($q->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                $this->repository->update('invoice_fiscal_jobs',['state'=>'uncertain','last_error'=>'Procesarea a fost întreruptă. Verifică în SPV dacă documentul a fost primit.'],['invoice_id'=>$id]);
                $this->repository->update('invoices',['fiscal_state'=>'uncertain'],['id'=>$id]);
            }
        });
        $q=$pdo->prepare("SELECT invoice_id,environment,state FROM invoice_fiscal_jobs WHERE ((state IN ('queued','scheduled','transient_error') AND attempts<5 AND (available_at IS NULL OR available_at<=:now)) OR (state='submitted' AND (polled_at IS NULL OR polled_at<:cutoff))) ORDER BY created_at,invoice_id LIMIT ".max(1,min(50,$limit)));
        $q->execute(['now'=>$now,'cutoff'=>gmdate('Y-m-d\TH:i:s\Z',time()-120)]); $result=['processed'=>0,'errors'=>0];
        foreach($q->fetchAll() as $job) {
            try {
                $gateway=new AnafFiscalGateway($this->connection,$this->http,$this->config,$job['environment']); $service=new InvoiceFiscalService($this->repository,$this->invoices,$gateway);
                if($job['state']==='submitted') { $service->poll((int)$job['invoice_id']); } else { $service->process((int)$job['invoice_id']); }
                $current=$this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$job['invoice_id']]);
                if(!empty($current['download_reference']) && empty($current['archive_path'])) { $this->archive((int)$job['invoice_id'],$gateway); }
                $result['processed']++;
            } catch(\Throwable $error) {
                $result['errors']++; $message=$error instanceof InvoiceException?$error->getMessage():'Conexiunea ANAF nu este disponibilă. Verifică autorizarea și încearcă din nou.';
                $this->repository->update('invoice_fiscal_jobs',['last_error'=>$message,'polled_at'=>$now,'available_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+120)],['invoice_id'=>$job['invoice_id']]);
            }
        }
        return $result;
    }
    public function archive(int $id,AnafFiscalGateway $gateway): string
    {
        $job=$this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$id]) ?? throw new InvoiceException('Documentul nu a fost transmis.');
        if($job['environment']!==$gateway->environment() || empty($job['download_reference'])) { throw new InvoiceException('Arhiva ANAF nu este disponibilă.'); }
        $base=$this->root.'/storage/private/spv'; if(!is_dir($base) && !mkdir($base,0700,true) && !is_dir($base)) { throw new InvoiceException('Arhiva nu poate fi salvată.'); }
        $path=$base.'/'.$id.'-'.$job['environment'].'-'.$job['download_reference'].'.zip';
        if(!is_file($path)) {
            $bytes=$gateway->download($job['download_reference']); $temp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
            try { if(file_put_contents($temp,$bytes,LOCK_EX)!==strlen($bytes) || !rename($temp,$path)) { throw new InvoiceException('Arhiva ANAF nu a putut fi păstrată.'); } }
            finally { if(is_file($temp)) { unlink($temp); } }
        }
        $this->repository->update('invoice_fiscal_jobs',['archive_path'=>$path],['invoice_id'=>$id]); return $path;
    }
}
