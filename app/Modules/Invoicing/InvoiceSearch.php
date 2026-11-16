<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

/** Romanian search vocabulary mapped to the same structured filters as the registry. */
final class InvoiceSearch
{
    /** @return array{conditions:list<array{field:string,value:string,label:string}>,terms:list<string>,labels:list<string>,navigation_query:string} */
    public static function parse(mixed $query): array
    {
        $query=is_string($query)?mb_substr($query,0,300):'';
        preg_match_all('/"[^"]*"|„[^”]*”|\S+/u',$query,$matches);
        $raw=$matches[0]; $words=[]; $protected=[];
        foreach($raw as $index=>$word) {
            $words[$index]=str_replace('e mail','email',InvoiceRepository::normalize($word));
            // Quoted names, emails and document identifiers always remain literal text.
            $protected[$index]=str_starts_with($word,'"') || str_starts_with($word,'„') || str_contains($word,'@') || preg_match('/[0-9]/',$word)===1;
        }
        $rules=self::rules(); $conditions=[]; $terms=[]; $navigation=$raw;
        $stop=['a','ai','ale','al','cu','de','din','in','la','pe','pentru','si','un','o','toate','factura','facturi','facturile','document','documente','documentele','client','clientul'];
        for($i=0,$count=count($words);$i<$count;$i++) {
            $matched=false;
            for($length=min(8,$count-$i);$length>0;$length--) {
                if(in_array(true,array_slice($protected,$i,$length),true)) { continue; }
                $phrase=implode(' ',array_slice($words,$i,$length));
                if(!isset($rules[$phrase])) { continue; }
                [$field,$value,$label]=$rules[$phrase];
                $conditions[$field.':'.$value]=['field'=>$field,'value'=>$value,'label'=>$label];
                if(in_array($field,['document_group','kind','spv_group','fiscal_state','receipt'],true)) {
                    for($j=$i;$j<$i+$length;$j++) { unset($navigation[$j]); }
                }
                $i+=$length-1; $matched=true; break;
            }
            if(!$matched && $words[$i]!=='' && ($protected[$i] || !in_array($words[$i],$stop,true))) {
                $quoted=str_starts_with($raw[$i],'"') || str_starts_with($raw[$i],'„');
                array_push($terms,...($quoted?[$words[$i]]:explode(' ',$words[$i])));
            }
        }
        return ['conditions'=>array_values($conditions),'terms'=>array_values(array_unique($terms)),
            'labels'=>array_column(array_values($conditions),'label'),'navigation_query'=>implode(' ',$navigation)];
    }

    /** @return array<string,array{string,string,string}> */
    private static function rules(): array
    {
        $rules=[];
        $add=static function(array $phrases,string $field,string $value,string $label)use(&$rules):void {
            foreach($phrases as $phrase) { $rules[$phrase]=[$field,$value,$label]; }
        };
        $add(['retur','retururi','returnate','returnata','returnat','storno','stornate','facturi de retur','factura de retur'],'document_group','credits','Facturi de retur');
        $add(['fara retur','fara retururi','fara stornari','vanzari','facturi de vanzare'],'document_group','sales','Facturi de vânzare');
        $add(['corectata','corectate','facturi corectate'],'kind','corrected_positive','Facturi corectate');
        $add(['retur pentru corectie','retururi pentru corectie'],'kind','correction_negative','Retururi pentru corecție');
        $add(['fara nir','fara nir de retur','nir lipsa','nir de retur lipsa','nereceptionate','nereceptionat','nereceptionata','de receptionat'],'receipt','pending','NIR de retur lipsă');
        $add(['cu nir','cu nir de retur','nir generat','nir de retur generat','receptionate','receptionat','receptionata'],'receipt','received','NIR de retur generat');
        $add(['de incasat','ramas de incasat','sold restant','restante'],'payment_group','outstanding','Rămase de încasat');
        foreach(['platit','platita','platite','achitat','achitata','achitate','incasat','incasata','incasate'] as $word) {
            $add([$word,$word.' integral'],'payment_state','paid','Achitate integral');
            $add([$word.' partial'],'payment_state','partial','Achitate parțial');
            $add(['ne'.$word,'nu '.$word,'nu sunt '.$word,'nu este '.$word,'nu e '.$word],'payment_state','unpaid','Neachitate');
        }
        $add(['partial','partiale'],'payment_state','partial','Achitate parțial');
        $add(['fara plata','fara incasari'],'payment_state','unpaid','Neachitate');
        $add(['card','card online','stripe','plata online'],'payment_method','stripe','Card online');
        $add(['ramburs','plata la livrare','numerar','cash'],'payment_method','cod','Ramburs');
        $add(['persoana juridica','persoane juridice','firma','firme','companii'],'customer_type','company','Persoane juridice');
        $add(['persoana fizica','persoane fizice'],'customer_type','person','Persoane fizice');
        foreach(['trimis','trimisa','trimise','transmis','transmisa','transmise'] as $word) {
            foreach(['spv','anaf'] as $channel) {
                $add([$word.' in '.$channel,$word.' la '.$channel,$channel.' '.$word],'spv_group','sent','Trimise în SPV');
                $negative=['ne'.$word.' in '.$channel,'ne'.$word.' la '.$channel,$channel.' ne'.$word,
                    'nu '.$word.' in '.$channel,'nu sunt '.$word.' in '.$channel,'nu este '.$word.' in '.$channel];
                $add($negative,'spv_group','not_sent','Netrimise în SPV');
            }
            $add(['ne'.$word],'spv_group','not_sent','Netrimise în SPV');
            $add([$word.' pe email',$word.' prin email','email '.$word],'email_state','sent','Email trimis');
            $add(['ne'.$word.' pe email','ne'.$word.' prin email','email ne'.$word,'nu sunt '.$word.' pe email'],'email_group','not_sent','Email netrimis');
        }
        $add(['fara spv','netrimise spv','netransmise spv'],'spv_group','not_sent','Netrimise în SPV');
        $add(['spv trimis','spv trimisa','spv trimise'],'spv_group','sent','Trimise în SPV');
        foreach(['acceptat','acceptata','acceptate','validat','validata','validate'] as $word) {
            $add([$word,$word.' de anaf',$word.' in spv','spv '.$word,'anaf '.$word],'fiscal_state','accepted','Acceptate de ANAF');
        }
        foreach(['respins','respinsa','respinse','refuzat','refuzata','refuzate'] as $word) {
            $add([$word,$word.' de anaf',$word.' in spv','spv '.$word,'anaf '.$word],'fiscal_state','rejected','Respinse de ANAF');
        }
        $add(['erori spv','spv cu erori','probleme spv'],'spv_group','attention','SPV · necesită atenție');
        $add(['fara email','email netrimis','email netrimise'],'email_group','not_sent','Email netrimis');
        $add(['email esuat','email esuate','email cu erori'],'email_state','failed','Email eșuat');
        $add(['email in asteptare','email in coada'],'email_state','queued','Email în așteptare');
        $add(['email previzualizat','email previzualizate'],'email_state','previewed','Email previzualizat local');
        return $rules;
    }
}
