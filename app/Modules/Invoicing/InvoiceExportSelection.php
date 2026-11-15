<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use DateTimeImmutable;
use DateTimeZone;

final class InvoiceExportSelection
{
    public const FILTERS=['cautare','kind','payment_state','payment_method','email_state','fiscal_state','spv_group','customer_type','receipt'];

    /** @return array<string,array{label:string,description:string,icon:string,from:string,to:string}> */
    public static function periods(?DateTimeImmutable $now=null): array
    {
        $today=($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Europe/Bucharest'));
        $date=$today->format('Y-m-d'); $first=$today->modify('first day of this month');
        $monthsAgo=static function(int $months)use($today,$first):string {
            $month=$first->modify('-'.$months.' months');
            return $month->setDate((int)$month->format('Y'),(int)$month->format('m'),min((int)$today->format('d'),(int)$month->format('t')))->format('Y-m-d');
        };
        return [
            'all'=>['label'=>'Toată perioada','description'=>'Toate facturile, inclusiv retururile','icon'=>'document','from'=>'','to'=>''],
            'year'=>['label'=>'Tot anul','description'=>'De la 1 ianuarie până astăzi','icon'=>'document','from'=>$today->format('Y-01-01'),'to'=>$date],
            '6_months'=>['label'=>'6 luni','description'=>'Ultimele șase luni','icon'=>'clock','from'=>$monthsAgo(6),'to'=>$date],
            '3_months'=>['label'=>'3 luni','description'=>'Ultimele trei luni','icon'=>'clock','from'=>$monthsAgo(3),'to'=>$date],
            'last_month'=>['label'=>'Luna trecută','description'=>'Luna calendaristică anterioară','icon'=>'document','from'=>$first->modify('-1 month')->format('Y-m-d'),'to'=>$first->modify('-1 day')->format('Y-m-d')],
            'month'=>['label'=>'Luna curentă','description'=>'De la începutul lunii până astăzi','icon'=>'document','from'=>$first->format('Y-m-d'),'to'=>$date],
            'custom'=>['label'=>'Interval personalizat','description'=>'Alegi exact datele','icon'=>'settings','from'=>'','to'=>''],
        ];
    }

    /** @param array<string,mixed> $input @return array<string,string> */
    public static function filters(array $input): array
    {
        $result=[];
        foreach(self::FILTERS as $key) {
            if(!isset($input[$key]) || $input[$key]==='') { continue; }
            if(!is_string($input[$key]) || mb_strlen($input[$key])>300) { throw new InvoiceException('Filtrele exportului nu sunt valide.'); }
            $result[$key]=$input[$key];
        }
        return $result;
    }

    /** @param array<string,mixed> $input @return array{format:string,period:string,from:string,to:string,label:string,filters:array<string,string>} */
    public static function resolve(array $input,?DateTimeImmutable $now=null): array
    {
        $format=$input['format'] ?? 'xlsx'; $period=$input['period'] ?? 'month'; $periods=self::periods($now);
        if(!in_array($format,['xlsx','zip'],true)) { throw new InvoiceException('Alege centralizatorul Excel sau dosarul ZIP.'); }
        if(!is_string($period) || !isset($periods[$period])) { throw new InvoiceException('Alege o perioadă validă pentru export.'); }
        $from=$periods[$period]['from']; $to=$periods[$period]['to'];
        if($period==='custom') {
            $from=self::date($input['from'] ?? null); $to=self::date($input['to'] ?? null);
            if($from>$to) { throw new InvoiceException('Data de început trebuie să fie înaintea datei de sfârșit.'); }
        }
        $filters=[];
        if(($input['keep_filters'] ?? '')==='1') {
            if(!is_array($input['filters'] ?? [])) { throw new InvoiceException('Filtrele exportului nu sunt valide.'); }
            $filters=self::filters($input['filters'] ?? []);
        }
        if($from!=='') { $filters['from']=$from; $filters['to']=$to; }
        return ['format'=>$format,'period'=>$period,'from'=>$from,'to'=>$to,'label'=>$periods[$period]['label'],'filters'=>$filters];
    }

    private static function date(mixed $value): string
    {
        if(!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)) { throw new InvoiceException('Completează ambele date ale intervalului.'); }
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Europe/Bucharest'));
        if(!$date || $date->format('Y-m-d')!==$value || (int)$date->format('Y')<1) { throw new InvoiceException('Alege date calendaristice valide.'); }
        return $value;
    }
}
