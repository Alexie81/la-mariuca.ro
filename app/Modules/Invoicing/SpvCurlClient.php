<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

final class SpvCurlClient implements SpvHttpClient
{
    public function request(string $method,string $url,array $headers=[],string $body=''): array
    {
        if(parse_url($url,PHP_URL_SCHEME)!=='https' || !in_array(parse_url($url,PHP_URL_HOST),['api.anaf.ro','logincert.anaf.ro'],true)) { throw new \LogicException('Invalid ANAF endpoint'); }
        $handle=curl_init($url); $bytes='';
        curl_setopt_array($handle,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>$method==='POST'?$body:null,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$bytes):int { if(strlen($bytes)+strlen($chunk)>30_000_000) { return 0; } $bytes.=$chunk; return strlen($chunk); }]);
        $result=curl_exec($handle); $status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
        if($result===false || $status===0) { throw new SpvTransportException('ANAF nu a returnat un răspuns complet. Verifică starea înainte de o nouă transmitere.'); }
        return ['status'=>$status,'body'=>$bytes];
    }
}
