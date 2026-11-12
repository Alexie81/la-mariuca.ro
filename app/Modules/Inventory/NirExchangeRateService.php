<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Read-only BNR quotes. A failed lookup never blocks a manually entered documented rate. */
final class NirExchangeRateService
{
    public function __construct(private readonly string $root) {}

    /** @return array<string,mixed> */
    public function lookup(string $currency,string $date): array
    {
        $currency=strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/D',$currency) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)) { throw new \InvalidArgumentException('Alege moneda și data cursului.'); }
        $day=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$day || $day->format('Y-m-d')!==$date || (int) $day->format('Y')<2005 || $date>date('Y-m-d')) { throw new \InvalidArgumentException('Data cursului trebuie să fie validă și să nu fie în viitor.'); }
        if ($currency==='RON') { return ['success'=>true,'currency'=>'RON','rate'=>'1.00000000','date'=>$date,'source'=>'RON']; }
        $year=(int) $day->format('Y');
        foreach ([$year,$year-1] as $archiveYear) {
            $path=$this->root.'/storage/cache/bnr-'.$archiveYear.'.xml';
            $fresh=is_file($path) && filemtime($path)>time()-21600;
            $xml=$fresh?file_get_contents($path):$this->fetch('https://www.bnr.ro/files/xml/years/nbrfxrates'.$archiveYear.'.xml');
            if (is_string($xml)) {
                $quote=$this->parse($xml,$currency,$date);
                if ($quote!==null) {
                    if (!$fresh) { if (!is_dir(dirname($path))) { @mkdir(dirname($path),0770,true); } @file_put_contents($path,$xml,LOCK_EX); }
                    return ['success'=>true,'source'=>'BNR']+$quote;
                }
            }
        }
        return ['success'=>false,'manual'=>true,'message'=>'Cursul BNR nu a putut fi preluat. Poți introduce manual cursul și data documentată.'];
    }

    /** @return array{currency:string,rate:string,date:string}|null */
    public function parse(string $xml,string $currency,string $date): ?array
    {
        if (strlen($xml)>4000000 || stripos($xml,'<!DOCTYPE')!==false || stripos($xml,'<!ENTITY')!==false) { return null; }
        $previous=libxml_use_internal_errors(true);
        try {
            $doc=simplexml_load_string($xml,\SimpleXMLElement::class,LIBXML_NONET);
            if ($doc===false) { return null; }
            $chosen=null;
            foreach ($doc->xpath('//*[local-name()="Cube"]') ?: [] as $cube) {
                $quoteDate=(string) $cube['date'];
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$quoteDate) || $quoteDate>$date || ($chosen!==null && $quoteDate<=$chosen['date'])) { continue; }
                foreach ($cube->children() as $rate) {
                    if ((string) $rate['currency']!==$currency || !preg_match('/^\d+(\.\d+)?$/D',(string) $rate)) { continue; }
                    $multiplier=(string) $rate['multiplier'];
                    if ($multiplier==='') { $multiplier='1'; }
                    if (!ctype_digit($multiplier) || (int) $multiplier<1) { continue; }
                    $value=BigDecimal::of((string) $rate)->dividedBy($multiplier,8,RoundingMode::HalfUp);
                    if ($value->isPositive()) { $chosen=['currency'=>$currency,'rate'=>(string) $value,'date'=>$quoteDate]; }
                }
            }
            return $chosen;
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }

    private function fetch(string $url): ?string
    {
        $curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>7,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'LaMariuca-NIR/1.0']);
        $result=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
        return $status===200 && is_string($result) && strlen($result)<=4000000?$result:null;
    }
}
