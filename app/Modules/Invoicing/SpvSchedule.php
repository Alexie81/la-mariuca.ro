<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class SpvSchedule
{
    public function __construct(private readonly PDO $pdo) {}
    /** @param list<string> $holidays */
    public static function afterBusinessDays(string $issued,int $days,array $holidays=[],bool $startOfDay=false): string
    {
        $date=(new DateTimeImmutable($issued))->setTimezone(new DateTimeZone('Europe/Bucharest'));
        for($count=0;$count<$days;) { $date=$date->modify('+1 day'); if((int)$date->format('N')<=5 && !in_array($date->format('Y-m-d'),$holidays,true)) { $count++; } }
        if($startOfDay) { $date=$date->setTime(0,0); }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
    /** Safe within invoice transaction. Never calls an external provider.
     * @param array<string,mixed> $invoice
     */
    public function sync(array $invoice,bool $manual=false): void
    {
        if(!empty($invoice['fiscal_attempted_at']) || !empty($invoice['fiscal_reference'])) { return; }
        $settings=$this->pdo->query('SELECT * FROM spv_settings WHERE id=1')->fetch();
        $prefix=in_array($invoice['kind'],['sale','corrected_positive'],true)?'sale':'return';
        $mode=$manual?'instant':$settings[$prefix.'_mode'];
        $repo=new InvoiceRepository($this->pdo); $job=$repo->one('invoice_fiscal_jobs',['invoice_id'=>$invoice['id']]);
        if($mode==='manual') {
            if($job) { $this->pdo->prepare('DELETE FROM invoice_fiscal_jobs WHERE invoice_id=?')->execute([$invoice['id']]); }
            $repo->update('invoices',['fiscal_state'=>'not_sent'],['id'=>$invoice['id']]); return;
        }
        $connected=(bool)$this->pdo->query('SELECT encrypted_tokens IS NOT NULL FROM spv_credentials WHERE id=1')->fetchColumn();
        $due=self::afterBusinessDays($invoice['issued_at'],$mode==='scheduled'?(int)$settings[$prefix.'_days']:0,json_decode($settings['holidays_json'],true,512,JSON_THROW_ON_ERROR),$mode==='scheduled');
        $state=$connected?($due>gmdate('Y-m-d\TH:i:s\Z')?'scheduled':'queued'):'awaiting_configuration';
        $data=['state'=>$state,'environment'=>$settings['environment'],'available_at'=>$due,'payload_hash'=>InvoiceService::documentHash($invoice),'payload_xml'=>null,'updated_at'=>$repo->now()];
        if($job) { $repo->update('invoice_fiscal_jobs',$data,['invoice_id'=>$invoice['id']]); }
        else { $repo->insert('invoice_fiscal_jobs',$data+['invoice_id'=>$invoice['id'],'attempts'=>0,'created_at'=>$repo->now()]); }
        $repo->update('invoices',['fiscal_state'=>$state],['id'=>$invoice['id']]);
    }
    public function rebuild(): void
    {
        $q=$this->pdo->query('SELECT id FROM invoices WHERE deleted_at IS NULL AND fiscal_attempted_at IS NULL AND fiscal_reference IS NULL');
        $repo=new InvoiceRepository($this->pdo);
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $invoice=$repo->one('invoices',['id'=>$id]); $invoice['snapshot']=json_decode($invoice['snapshot_json'],true); $invoice['lines']=$repo->all('invoice_lines',['invoice_id'=>$id]);
            $this->sync($invoice);
        }
    }
}
