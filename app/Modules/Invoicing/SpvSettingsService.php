<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use App\Shared\Config\Config;
use PDO;

final class SpvSettingsService
{
    public function __construct(private readonly PDO $pdo,private readonly Config $config) {}
    /** @param array<string,mixed> $actor */
    public function allow(array $actor): void
    {
        $q=$this->pdo->prepare("SELECT role FROM users WHERE id=:id AND status='active'"); $q->execute(['id'=>$actor['id'] ?? 0]);
        if($q->fetchColumn()!=='administrator') { throw new InvoiceException('Setările și transmiterea SPV sunt disponibile administratorului.',403); }
    }
    /** @return array<string,mixed> */
    public function state(): array
    {
        $data=$this->pdo->query('SELECT * FROM spv_settings WHERE id=1')->fetch(); $data['holidays']=implode("\n",json_decode($data['holidays_json'],true,512,JSON_THROW_ON_ERROR));
        $data['production_allowed']=$this->config->bool('ANAF_ALLOW_PRODUCTION'); return $data;
    }
    /** @param array<string,mixed> $input @param array<string,mixed> $actor */
    public function save(array $input,array $actor): void
    {
        $this->allow($actor); $data=[];
        $data['environment']=(string)($input['environment'] ?? 'test');
        if(!in_array($data['environment'],['test','production'],true)) { throw new InvoiceException('Alege mediul de transmitere.'); }
        $current=$this->state();
        if($data['environment']==='production' && (!$this->config->bool('ANAF_ALLOW_PRODUCTION') || ($current['environment']!=='production' && ($input['confirm_production'] ?? '')!=='1'))) { throw new InvoiceException('Producția necesită activarea explicită pe server și confirmarea transmiterii oficiale.'); }
        foreach(['sale','return'] as $type) {
            $mode=(string)($input[$type.'_mode'] ?? 'manual'); $days=(string)($input[$type.'_days'] ?? '1');
            if(!in_array($mode,['manual','instant','scheduled'],true) || !preg_match('/^[1-5]$/D',$days)) { throw new InvoiceException('Alege regula și un interval între 1 și 5 zile lucrătoare.'); }
            $data[$type.'_mode']=$mode; $data[$type.'_days']=(int)$days;
        }
        $deadline=(string)($input['deadline_days'] ?? '5'); if(!preg_match('/^[1-9]$|^1[0-5]$/D',$deadline)) { throw new InvoiceException('Termenul configurat trebuie să fie între 1 și 15 zile lucrătoare.'); }
        $data['deadline_days']=(int)$deadline; $data['alerts']=($input['alerts'] ?? '')==='1'?1:0;
        $holidays=array_values(array_unique(array_filter(array_map('trim',preg_split('/[\s,;]+/',(string)($input['holidays'] ?? '')) ?: []))));
        if(count($holidays)>200) { throw new InvoiceException('Lista zilelor nelucrătoare este prea lungă.'); }
        foreach($holidays as $date) { $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date); if(!$parsed || $parsed->format('Y-m-d')!==$date) { throw new InvoiceException('Scrie zilele nelucrătoare în formatul AAAA-LL-ZZ.'); } }
        sort($holidays); $data['holidays_json']=InvoiceSnapshot::json($holidays); $data['updated_at']=gmdate('Y-m-d\TH:i:s\Z'); $data['updated_by']=(int)$actor['id'];
        $repo=new InvoiceRepository($this->pdo);
        $repo->transaction(function()use($input,$data,$actor,$repo):void {
            $before=$this->state(); if((string)($input['revision'] ?? '')!==(string)$before['revision']) { throw new InvoiceException('Setările au fost schimbate în altă fereastră. Reîncarcă pagina.',409); }
            $sets=[]; foreach(array_keys($data) as $field) { $sets[]=$field.'=:'.$field; }
            $this->pdo->prepare('UPDATE spv_settings SET '.implode(',',$sets).',revision=revision+1 WHERE id=1')->execute($data);
            (new SpvSchedule($this->pdo))->rebuild();
            $repo->insert('audit_log',['actor_user_id'=>$actor['id'],'action'=>'spv.settings','entity_type'=>'spv','entity_id'=>1,'context_json'=>InvoiceSnapshot::json(['before'=>$before,'after'=>$data]),'created_at'=>$repo->now()]);
        });
    }
    /** @return list<array<string,mixed>> */
    public function jobs(): array
    {
        return $this->pdo->query('SELECT j.*,i.series_code,i.number,i.order_id,i.issued_at FROM invoice_fiscal_jobs j JOIN invoices i ON i.id=j.invoice_id WHERE i.deleted_at IS NULL ORDER BY j.updated_at DESC LIMIT 40')->fetchAll();
    }
}
