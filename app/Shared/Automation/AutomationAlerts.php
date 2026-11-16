<?php
declare(strict_types=1);
namespace App\Shared\Automation;

use App\Modules\Invoicing\InvoiceRepository;
use App\Modules\Invoicing\SpvSchedule;
use App\Modules\Orders\OrderNotificationRepository;
use PDO;

/** Persistent incidents; repeated visits do not repeat the same notification. */
final class AutomationAlerts
{
    public function __construct(private readonly PDO $pdo) {}

    public function report(string $source,string $title,string $message,string $path): void
    {
        (new InvoiceRepository($this->pdo))->transaction(function()use($source,$title,$message,$path):void {
            $q=$this->pdo->prepare('SELECT * FROM automation_alerts WHERE source_key=?'); $q->execute([$source]); $old=$q->fetch();
            if($old && $old['resolved_at']===null && $old['message']===$message && $old['title']===$title) { return; }
            $now=gmdate('c'); $revision=$old?(int)$old['revision']+1:1;
            if($old) {
                $this->pdo->prepare('UPDATE automation_alerts SET revision=?,title=?,message=?,target_path=?,created_at=?,resolved_at=NULL WHERE id=?')->execute([$revision,$title,$message,$path,$now,$old['id']]);
                $id=(int)$old['id'];
            } else {
                $this->pdo->prepare('INSERT INTO automation_alerts(source_key,title,message,target_path,created_at) VALUES(?,?,?,?,?)')->execute([$source,$title,$message,$path,$now]);
                $id=(int)$this->pdo->lastInsertId();
            }
            $insert=$this->pdo->prepare("INSERT INTO outbox_messages(message_type,recipient,payload_json,deduplication_key,status,attempts,available_at,created_at) VALUES('automation_alert',?,?,?,'pending',0,?,?)");
            foreach((new OrderNotificationRepository($this->pdo))->recipients() as $recipient) {
                $payload=json_encode(['title'=>$title,'message'=>$message,'target_path'=>$path],JSON_THROW_ON_ERROR);
                $insert->execute([$recipient,$payload,'automation:'.$id.':'.$revision.':'.hash('sha256',$recipient),$now,$now]);
            }
        });
    }

    public function resolve(string $source): void
    {
        $this->pdo->prepare('UPDATE automation_alerts SET resolved_at=? WHERE source_key=? AND resolved_at IS NULL')->execute([gmdate('c'),$source]);
    }

    public function synchronize(?\DateTimeImmutable $now=null): void
    {
        $active=[];
        foreach($this->pdo->query("SELECT a.order_id,a.last_error,o.order_number FROM invoice_automation_intents a JOIN orders o ON o.id=a.order_id WHERE a.state='failed'")->fetchAll() as $intent) {
            $source='invoice:'.$intent['order_id']; $active[$source]=true;
            $this->report($source,'Factura nu a putut fi emisă · '.$intent['order_number'],(string)$intent['last_error'],'admin/comenzi/'.$intent['order_id'].'/');
        }
        foreach($this->pdo->query("SELECT j.invoice_id,j.state,j.last_error,i.series_code,i.number FROM invoice_fiscal_jobs j JOIN invoices i ON i.id=j.invoice_id WHERE i.deleted_at IS NULL AND (j.state IN ('uncertain','rejected','transient_error') OR j.last_error IS NOT NULL)")->fetchAll() as $job) {
            $source='spv:'.$job['invoice_id']; $active[$source]=true;
            $message=$job['last_error'] ?: 'Documentul necesită verificare în pagina facturii.';
            $this->report($source,'SPV necesită atenție · '.$job['series_code'].' '.$job['number'],(string)$message,'admin/facturi/'.$job['invoice_id'].'/');
        }
        $settings=$this->pdo->query('SELECT alerts,deadline_days,holidays_json FROM spv_settings WHERE id=1')->fetch();
        if($settings['alerts']) {
            $holidays=json_decode($settings['holidays_json'],true,512,JSON_THROW_ON_ERROR);
            $zone=new \DateTimeZone('Europe/Bucharest'); $today=($now ?? new \DateTimeImmutable())->setTimezone($zone)->setTime(0,0);
            foreach($this->pdo->query("SELECT i.id,i.series_code,i.number,i.issued_at,j.state AS job_state,j.available_at,j.attempts FROM invoices i LEFT JOIN invoice_fiscal_jobs j ON j.invoice_id=i.id WHERE i.deleted_at IS NULL AND i.fiscal_reference IS NULL AND i.fiscal_state<>'accepted'")->fetchAll() as $invoice) {
                $deadline=(new \DateTimeImmutable(SpvSchedule::afterBusinessDays($invoice['issued_at'],min(5,max(1,(int)$settings['deadline_days'])),$holidays,true)))->setTimezone($zone);
                $remaining=0;
                for($day=$today;$day<$deadline && $remaining<=2;) {
                    $day=$day->modify('+1 day');
                    if((int)$day->format('N')<=5 && !in_array($day->format('Y-m-d'),$holidays,true)) { $remaining++; }
                }
                if($remaining>2) { continue; }
                $timing=$today>$deadline?'Termenul de trimitere SPV a fost depășit':match($remaining) {
                    0=>'Astăzi este ultima zi pentru trimiterea în SPV',1=>'Mai ai o zi lucrătoare pentru trimiterea în SPV',default=>'Mai sunt 2 zile lucrătoare pentru trimiterea în SPV',
                };
                $schedule=match(true) {
                    $invoice['job_state']==='uncertain'=>'Transmiterea are un rezultat neclar. Verifică în SPV dacă documentul a fost primit înainte de orice retrimitere.',
                    $invoice['job_state']==='processing'=>'Documentul este în curs de trimitere. Verifică rezultatul din fișa facturii.',
                    $invoice['job_state']==='awaiting_configuration'=>'Factura este programată, dar trimiterea este suspendată până la conectarea ANAF. Conectează certificatul și verifică factura.',
                    in_array($invoice['job_state'],['queued','scheduled','transient_error'],true) && (int)$invoice['attempts']<5=>'Factura este în coada de trimitere. Verifică programarea și eventualele erori din fișa facturii.',
                    default=>'Factura nu este programată pentru trimitere în SPV. Trimite-o acum din fișa facturii.',
                };
                $source='deadline:'.$invoice['id']; $active[$source]=true;
                $this->report($source,$timing.' · '.$invoice['series_code'].' '.$invoice['number'],$schedule.' Termen: '.$deadline->format('d.m.Y').', până la sfârșitul zilei (ora României).','admin/facturi/'.$invoice['id'].'/');
            }
        }
        foreach($this->pdo->query("SELECT source_key FROM automation_alerts WHERE resolved_at IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $source) {
            if((str_starts_with($source,'invoice:') || str_starts_with($source,'deadline:') || preg_match('/^spv:\d+$/D',$source)) && !isset($active[$source])) { $this->resolve($source); }
        }
    }
}
