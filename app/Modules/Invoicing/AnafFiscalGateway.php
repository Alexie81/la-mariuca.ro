<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use App\Shared\Config\Config;

final class AnafFiscalGateway implements FiscalGateway
{
    public function __construct(private readonly SpvConnection $connection,private readonly SpvHttpClient $http,private readonly Config $config,private readonly string $environment='test')
    {
        if(!in_array($environment,['test','production'],true)) { throw new InvoiceException('Mediu SPV invalid.'); }
        if($environment==='production' && !$config->bool('ANAF_ALLOW_PRODUCTION')) { throw new InvoiceException('Transmiterea oficială este dezactivată pe acest server.'); }
    }
    public function prepare(): void { $this->connection->accessToken(); }
    public function environment(): string { return $this->environment; }
    public function build(array $invoice): string { return (new SpvInvoiceXml())->build($invoice); }
    public function validate(string $document): array { return (new SpvInvoiceXml())->validate($document); }
    public function send(string $document,string $documentKey): array
    {
        $doc=new \DOMDocument(); $doc->loadXML($document,LIBXML_NONET); $x=new \DOMXPath($doc);
        $x->registerNamespace('cac','urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2'); $x->registerNamespace('cbc','urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $cif=(string)$x->evaluate('string(//cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:CompanyID)');
        if(!preg_match('/^\d{2,10}$/D',$cif)) { throw new SpvResponseException('rejected','CUI-ul emitentului nu este valid.'); }
        // ANAF does not document a caller-supplied idempotency key. The local claim is authoritative.
        $result=$this->call('POST','upload',['standard'=>'UBL','cif'=>$cif],$document);
        if(in_array($result['status'],[401,403,429],true)) { throw new SpvResponseException('transient_error','ANAF a refuzat temporar încărcarea (HTTP '.$result['status'].'). Verifică autorizarea sau așteaptă înainte de reluare.'); }
        if($result['status']>=500) { throw new SpvTransportException('Răspuns ANAF incomplet. Verifică mesajele din SPV înainte de a relua încărcarea.'); }
        $data=self::parse($result['body']); $reference=(string)($data['index_incarcare'] ?? $data['id_incarcare'] ?? '');
        if(!preg_match('/^\d+$/D',$reference)) { throw new SpvResponseException('rejected',self::message($data) ?: 'ANAF nu a acceptat încărcarea documentului.'); }
        return ['reference'=>$reference,'state'=>'submitted','response'=>$data+['environment'=>$this->environment]];
    }
    public function status(string $reference): array
    {
        self::identifier($reference); $result=$this->call('GET','stareMesaj',['id_incarcare'=>$reference]);
        if($result['status']!==200) { throw new InvoiceException('Starea ANAF nu este disponibilă (HTTP '.$result['status'].').'); }
        $data=self::parse($result['body']); $status=mb_strtolower(trim((string)($data['stare'] ?? '')));
        $state=match($status) {'ok'=>'accepted','nok','xml cu erori nepreluat de sistem'=>'rejected','in prelucrare','în prelucrare'=>'submitted',default=>throw new InvoiceException(self::message($data) ?: 'ANAF nu a returnat o stare cunoscută.')};
        return ['state'=>$state,'response'=>$data+['environment'=>$this->environment]];
    }
    public function download(string $reference): string
    {
        self::identifier($reference); $result=$this->call('GET','descarcare',['id'=>$reference]);
        if($result['status']!==200 || !str_starts_with($result['body'],"PK\x03\x04")) { throw new InvoiceException('ANAF nu a returnat încă arhiva semnată a documentului.'); } return $result['body'];
    }
    /** @return array<string,mixed> */
    public function messages(string $cif,int $days=7): array
    {
        if(!preg_match('/^\d{2,10}$/D',$cif)) { throw new InvoiceException('CUI invalid.'); }
        $result=$this->call('GET','listaMesajeFactura',['zile'=>max(1,min(60,$days)),'cif'=>$cif]);
        if($result['status']!==200) { throw new InvoiceException('Lista de mesaje ANAF nu este disponibilă.'); }
        $data=json_decode($result['body'],true); if(!is_array($data)) { throw new InvoiceException('ANAF a returnat o listă de mesaje necunoscută.'); } return $data;
    }
    /** @param array<string,int|string> $query @return array{status:int,body:string} */
    private function call(string $method,string $operation,array $query,string $body=''): array
    {
        $environment=$this->environment==='production'?'prod':'test';
        return $this->http->request($method,'https://api.anaf.ro/'.$environment.'/FCTEL/rest/'.$operation.'?'.http_build_query($query),['Authorization: Bearer '.$this->connection->accessToken(),'Content-Type: application/xml','Accept: application/xml, application/json, application/zip'],$body);
    }
    /** @return array<string,mixed> */
    public static function parse(string $body): array
    {
        if(str_contains($body,'<!DOCTYPE') || str_contains($body,'<!ENTITY')) { throw new SpvTransportException('Răspuns ANAF neacceptat.'); }
        $json=json_decode($body,true); if(is_array($json)) { return $json; }
        $doc=new \DOMDocument(); $previous=libxml_use_internal_errors(true);
        try { if(!$doc->loadXML($body,LIBXML_NONET)) { throw new SpvTransportException('ANAF nu a returnat un răspuns XML complet.'); } }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $data=[]; foreach($doc->documentElement->attributes as $attribute) { $data[$attribute->name]=$attribute->value; }
        $errors=[]; foreach($doc->getElementsByTagName('*') as $element) { if($element->hasAttribute('errorMessage')) { $errors[]=$element->getAttribute('errorMessage'); } }
        if($errors) { $data['errors']=$errors; } return $data;
    }
    /** @param array<string,mixed> $data */
    private static function message(array $data): string { return mb_substr(implode(' ',array_filter(array_map('strval',(array)($data['errors'] ?? [$data['eroare'] ?? $data['errorMessage'] ?? ''])))),0,1800); }
    private static function identifier(string $value): void { if(!preg_match('/^\d{1,40}$/D',$value)) { throw new InvoiceException('Referința ANAF nu este validă.'); } }
}
