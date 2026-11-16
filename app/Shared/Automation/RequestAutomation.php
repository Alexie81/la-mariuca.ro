<?php
declare(strict_types=1);
namespace App\Shared\Automation;

use App\Modules\Invoicing\{InvoiceModule,InvoiceRepository,SpvConnection,SpvCurlClient,SpvHttpClient,SpvSchedule,SpvWorker};
use App\Shared\Config\Config;
use App\Shared\Database\ConnectionFactory;
use App\Shared\Mail\OutboxProcessor;
use PDO;
use Symfony\Component\Mailer\Transport\TransportInterface;

/** Small, leased batches after real site requests. No daemon or public queue endpoint. */
final class RequestAutomation
{
    public const MAIL_TYPES=['order_confirmation','internal_order_notification','invoice','automation_alert'];
    public function __construct(private readonly PDO $pdo,private readonly Config $config,private readonly string $root,
        private readonly ?SpvHttpClient $http=null,private readonly ?TransportInterface $mail=null) {}

    public static function eligible(string $method,int $status,string $contentType): bool
    {
        if($status<200 || $status>=400) { return false; }
        return ($method==='GET' && str_contains(strtolower($contentType),'text/html')) || $method==='POST';
    }

    public static function afterResponse(string $root): void
    {
        $contentType='text/html';
        foreach(headers_list() as $header) { if(stripos($header,'Content-Type:')===0) { $contentType=substr($header,13); } }
        if(!self::eligible($_SERVER['REQUEST_METHOD'] ?? '',http_response_code() ?: 200,$contentType)) { return; }
        try {
            $config=Config::load($root);
            if(!$config->bool('REQUEST_AUTOMATIONS_ENABLED',true)) { return; }
            if(session_status()===PHP_SESSION_ACTIVE) { session_write_close(); }
            ignore_user_abort(true);
            if(function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
            else { if(ob_get_level()>0) { @ob_flush(); } flush(); }
            (new self(ConnectionFactory::create($config,$root),$config,$root))->run();
        } catch(\Throwable) {
            // The response/order has already succeeded. Never expose connection or credential details.
            error_log('Request automation could not finish. Check database migrations and the automation alerts.');
        }
    }

    /** @return array{ran:bool,issued:int,spv:int,emails:int} */
    public function run(): array
    {
        if($this->pdo->inTransaction()) { throw new \LogicException('Request automation requires committed data.'); }
        $result=['ran'=>false,'issued'=>0,'spv'=>0,'emails'=>0];
        if(!$this->config->bool('REQUEST_AUTOMATIONS_ENABLED',true)) { return $result; }
        $token=bin2hex(random_bytes(16)); $now=time();
        $claim=$this->pdo->prepare('UPDATE request_automation_state SET lease_token=?,lease_until=? WHERE id=1 AND lease_until<=? AND next_run_at<=?');
        $claim->execute([$token,$now+180,$now,$now]);
        if($claim->rowCount()!==1) { return $result; }
        $result['ran']=true; $alerts=new AutomationAlerts($this->pdo);
        try {
            $this->normalizeSchedules();
            $result['issued']=InvoiceModule::process($this->pdo,$this->root,2)['issued'];
            $http=$this->http ?? new SpvCurlClient(8);
            $connection=new SpvConnection($this->pdo,$this->config,$http); $state=$connection->state();
            if($state['ready']) {
                $this->activateSchedules();
                $repo=new InvoiceRepository($this->pdo);
                $result['spv']=(new SpvWorker($repo,InvoiceModule::service($this->pdo,$this->root),$connection,$http,$this->config,$this->root))->run(1)['processed'];
                $alerts->resolve('spv:connection');
            } else {
                $due=$this->pdo->prepare("SELECT 1 FROM invoice_fiscal_jobs j JOIN invoices i ON i.id=j.invoice_id WHERE i.deleted_at IS NULL AND ((j.state IN ('queued','scheduled','awaiting_configuration','transient_error') AND j.available_at<=?) OR j.state='submitted') LIMIT 1");
                $due->execute([gmdate('Y-m-d\TH:i:s\Z')]);
                if($due->fetchColumn()) { $alerts->report('spv:connection','Trimiterea SPV așteaptă conectarea ANAF',(string)$state['problem'],'admin/spv-efactura/'); }
                else { $alerts->resolve('spv:connection'); }
            }
            $alerts->synchronize();
            $alerts->resolve('runtime');
        } catch(\Throwable) {
            $alerts->report('runtime','Automatizările necesită verificare','O etapă de facturare sau SPV nu s-a finalizat. Verifică setările și documentele în așteptare; comenzile sunt păstrate.','admin/automatizari/');
        } finally {
            try {
                $mail=(new OutboxProcessor($this->pdo,$this->config,$this->root,$this->mail))->process(3,self::MAIL_TYPES,8);
                $result['emails']=$mail['sent'];
                if($mail['failed']>0) { $alerts->report('mail','Emailurile automatizărilor nu au putut fi trimise','Verifică setările SMTP. Mesajele rămân în coadă pentru reîncercare, fără reemiterea facturilor.','admin/automatizari/'); }
                elseif($mail['sent']>0) { $alerts->resolve('mail'); }
            } catch(\Throwable) {
                $alerts->report('mail','Trimiterea emailurilor necesită verificare','Verifică setările SMTP și filtrul email. Notificările rămân disponibile în administrare.','admin/automatizari/');
            } finally {
                $this->pdo->prepare('UPDATE request_automation_state SET lease_token=NULL,lease_until=0,next_run_at=? WHERE id=1 AND lease_token=?')->execute([time()+5,$token]);
            }
        }
        return $result;
    }

    private function normalizeSchedules(): void
    {
        if((int)$this->pdo->query('SELECT schedule_version FROM request_automation_state WHERE id=1')->fetchColumn()>=1) { return; }
        (new InvoiceRepository($this->pdo))->transaction(function():void {
            $settings=$this->pdo->query('SELECT * FROM spv_settings WHERE id=1')->fetch();
            $jobs=$this->pdo->query("SELECT j.invoice_id,i.issued_at,i.kind FROM invoice_fiscal_jobs j JOIN invoices i ON i.id=j.invoice_id WHERE j.attempts=0 AND j.payload_xml IS NULL AND j.state IN ('queued','scheduled','awaiting_configuration') AND i.deleted_at IS NULL AND i.fiscal_attempted_at IS NULL AND i.fiscal_reference IS NULL")->fetchAll();
            foreach($jobs as $job) {
                $prefix=in_array($job['kind'],['sale','corrected_positive'],true)?'sale':'return';
                if($settings[$prefix.'_mode']!=='scheduled') { continue; }
                $date=SpvSchedule::afterBusinessDays($job['issued_at'],(int)$settings[$prefix.'_days'],json_decode($settings['holidays_json'],true,512,JSON_THROW_ON_ERROR),true);
                $this->pdo->prepare('UPDATE invoice_fiscal_jobs SET available_at=? WHERE invoice_id=?')->execute([$date,$job['invoice_id']]);
            }
            $this->pdo->exec('UPDATE request_automation_state SET schedule_version=1 WHERE id=1');
        });
    }

    private function activateSchedules(): void
    {
        (new InvoiceRepository($this->pdo))->transaction(function():void {
            $jobs=$this->pdo->query("SELECT j.invoice_id,j.available_at FROM invoice_fiscal_jobs j JOIN invoices i ON i.id=j.invoice_id WHERE j.state='awaiting_configuration' AND i.deleted_at IS NULL AND i.fiscal_attempted_at IS NULL AND i.fiscal_reference IS NULL")->fetchAll();
            foreach($jobs as $job) {
                $state=$job['available_at']>gmdate('Y-m-d\TH:i:s\Z')?'scheduled':'queued';
                $this->pdo->prepare('UPDATE invoice_fiscal_jobs SET state=? WHERE invoice_id=?')->execute([$state,$job['invoice_id']]);
                $this->pdo->prepare('UPDATE invoices SET fiscal_state=? WHERE id=?')->execute([$state,$job['invoice_id']]);
            }
        });
    }
}
