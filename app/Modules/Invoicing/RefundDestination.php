<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

final class RefundDestination
{
    /** @param array<string,mixed> $input @return array<string,string> */
    public static function normalize(array $input,bool $originalPayment=false): array
    {
        $method=$originalPayment?'original_payment':(string)($input['refund_method'] ?? 'bank');
        if(!in_array($method,$originalPayment?['original_payment']:['bank','cash'],true)) { throw new InvoiceException('Alege restituirea în cont bancar sau în numerar.'); }
        $details=['refund_method'=>$method,'holder'=>'','iban'=>'','bank_name'=>''];
        if($method!=='bank') { return $details; }
        foreach(['holder','iban','bank_name'] as $field) {
            if(!is_string($input[$field] ?? null)) { throw new InvoiceException('Completează titularul contului, IBAN-ul și banca.'); }
            $details[$field]=trim($input[$field]);
        }
        $details['iban']=strtoupper(preg_replace('/\s+/','',$details['iban']) ?? '');
        if(mb_strlen($details['holder'])<3 || mb_strlen($details['holder'])>190 || mb_strlen($details['bank_name'])<2 || mb_strlen($details['bank_name'])>190) { throw new InvoiceException('Completează numele titularului și denumirea băncii.'); }
        if(!self::validIban($details['iban'])) { throw new InvoiceException('IBAN-ul nu este valid. Verifică toate caracterele.'); }
        return $details;
    }
    private static function validIban(string $iban): bool
    {
        if(!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/D',$iban) || (str_starts_with($iban,'RO') && strlen($iban)!==24)) { return false; }
        $remainder=0;
        foreach(str_split(substr($iban,4).substr($iban,0,4)) as $character) { foreach(str_split(ctype_digit($character)?$character:(string)(ord($character)-55)) as $digit) { $remainder=($remainder*10+(int)$digit)%97; } }
        return $remainder===1;
    }
}
