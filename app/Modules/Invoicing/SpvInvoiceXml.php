<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** UBL Invoice 2.1, including negative type-380 corrections. */
final class SpvInvoiceXml
{
    private const CBC='urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    private const CAC='urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    /** @param array<string,mixed> $invoice */
    public function build(array $invoice,bool $forSubmission=true): string
    {
        $doc=new DOMDocument('1.0','UTF-8'); $doc->formatOutput=true;
        if(!$forSubmission) { $doc->appendChild($doc->createComment(' Export local din datele facturii. Nu reprezintă validare sau transmitere ANAF. ')); }
        $root=$doc->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2','Invoice'); $doc->appendChild($root);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/','xmlns:cac',self::CAC); $root->setAttributeNS('http://www.w3.org/2000/xmlns/','xmlns:cbc',self::CBC);
        $this->add($root,'cbc:CustomizationID','urn:cen.eu:en16931:2017#compliant#urn:efactura.mfinante.ro:CIUS-RO:1.0.1');
        $this->add($root,'cbc:ID',$invoice['series_code'].' '.$invoice['number']);
        $this->add($root,'cbc:IssueDate',(new \DateTimeImmutable($invoice['issued_at']))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('Y-m-d'));
        $this->add($root,'cbc:DueDate',$invoice['due_date']); $this->add($root,'cbc:InvoiceTypeCode','380');
        if(!empty($invoice['notes'])) { $this->add($root,'cbc:Note',$invoice['notes']); }
        $currency=$invoice['currency']; $this->add($root,'cbc:DocumentCurrencyCode',$currency);
        if(!empty($invoice['parent_document_number'])) { $ref=$this->add($this->add($root,'cac:BillingReference'),'cac:InvoiceDocumentReference'); $this->add($ref,'cbc:ID',$invoice['parent_document_number']); }
        $snapshot=$invoice['snapshot']; $company=$snapshot['company']; $customer=$snapshot['customer'];
        $this->party($root,'AccountingSupplierParty',[
            'name'=>$company['legal_name'],'id'=>$company['tax_id'],'vat'=>(bool)$company['is_vat_payer'],
            'street'=>$company['registered_address'],'city'=>$company['city'],'county'=>$company['county'] ?? '',
            'postal_code'=>$company['postal_code'] ?? '', 'country'=>$company['country'],'email'=>$company['email'] ?? '',
        ],$forSubmission);
        $buyerCompany=$customer['company'] ?? []; $addr=$customer['address'];
        $this->party($root,'AccountingCustomerParty',[
            'name'=>$customer['type']==='company'?($buyerCompany['name'] ?? $buyerCompany['company_name'] ?? ''):$customer['name'],
            'id'=>$customer['type']==='company'?($buyerCompany['tax_id'] ?? $buyerCompany['cui'] ?? ''):'0000000000000',
            'vat'=>$customer['type']==='company' && str_starts_with(strtoupper($buyerCompany['tax_id'] ?? $buyerCompany['cui'] ?? ''),'RO'),
            'street'=>$addr['address'] ?? $addr['street'] ?? '', 'city'=>$addr['city'] ?? '', 'county'=>$addr['county'] ?? '',
            'postal_code'=>$addr['postal_code'] ?? '', 'country'=>$addr['country'] ?? $addr['country_code'] ?? 'RO','email'=>$customer['email'],
        ],$forSubmission);
        $means=$this->add($root,'cac:PaymentMeans'); $this->add($means,'cbc:PaymentMeansCode',$invoice['payment_method']==='stripe'?'48':'10');
        if($invoice['payment_method']!=='stripe' && !empty($company['iban'])) { $account=$this->add($means,'cac:PayeeFinancialAccount'); $this->add($account,'cbc:ID',preg_replace('/\s/','',$company['iban'])); }
        $vatPayer=(bool)$company['is_vat_payer']; $groups=[];
        foreach($invoice['lines'] as $line) { $rate=(int)$line['vat_basis_points']; if(!isset($groups[$rate])) { $groups[$rate]=['net'=>0,'tax'=>0]; } $groups[$rate]['net']+=(int)$line['net_bani']; $groups[$rate]['tax']+=(int)$line['tax_bani']; }
        $tax=$this->add($root,'cac:TaxTotal'); $this->money($tax,'TaxAmount',(int)$invoice['tax_bani'],$currency);
        foreach($groups as $rate=>$totals) {
            $sub=$this->add($tax,'cac:TaxSubtotal'); $this->money($sub,'TaxableAmount',$totals['net'],$currency); $this->money($sub,'TaxAmount',$totals['tax'],$currency);
            $this->taxCategory($sub,'TaxCategory',$rate,$vatPayer);
        }
        $total=$this->add($root,'cac:LegalMonetaryTotal');
        foreach(['LineExtensionAmount'=>'net_bani','TaxExclusiveAmount'=>'net_bani','TaxInclusiveAmount'=>'gross_bani','PayableAmount'=>'gross_bani'] as $tag=>$field) { $this->money($total,$tag,(int)$invoice[$field],$currency); }
        foreach($invoice['lines'] as $index=>$line) {
            $entry=$this->add($root,'cac:InvoiceLine'); $this->add($entry,'cbc:ID',(string)($index+1));
            $qty=$this->add($entry,'cbc:InvoicedQuantity',InvoiceCalculator::quantity((int)$line['quantity_units'])); $qty->setAttribute('unitCode',$line['unit']==='serv'?'E48':'H87');
            $this->money($entry,'LineExtensionAmount',(int)$line['net_bani'],$currency);
            $item=$this->add($entry,'cac:Item'); $this->add($item,'cbc:Name',$line['name']);
            if($line['sku']!=='') { $this->add($this->add($item,'cac:SellersItemIdentification'),'cbc:ID',$line['sku']); }
            $this->taxCategory($item,'ClassifiedTaxCategory',(int)$line['vat_basis_points'],$vatPayer);
            $price=$this->add($entry,'cac:Price');
            // Snapshot's net amount already includes the allocated discount. Avoid applying it twice.
            $value=(string)BigDecimal::of(abs((int)$line['net_bani']))->multipliedBy(100)->dividedBy(abs((int)$line['quantity_units']),8,RoundingMode::HalfUp);
            $amount=$this->add($price,'cbc:PriceAmount',$value); $amount->setAttribute('currencyID',$currency);
        }
        return (string)$doc->saveXML();
    }
    /** Structural and arithmetic preflight; authority validation remains distinct. @return list<string> */
    public function validate(string $xml): array
    {
        if(str_contains($xml,'<!DOCTYPE') || str_contains($xml,'<!ENTITY')) { return ['Declarațiile externe nu sunt permise.']; }
        $doc=new DOMDocument(); $previous=libxml_use_internal_errors(true);
        try { if(!$doc->loadXML($xml,LIBXML_NONET)) { return ['Documentul XML nu este valid.']; } }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $x=new DOMXPath($doc); $x->registerNamespace('i','urn:oasis:names:specification:ubl:schema:xsd:Invoice-2'); $x->registerNamespace('cbc',self::CBC); $x->registerNamespace('cac',self::CAC);
        $errors=[];
        foreach(['cbc:ID','cbc:IssueDate','cbc:DueDate','cbc:DocumentCurrencyCode','cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName','cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:CompanyID','cac:AccountingCustomerParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName'] as $path) {
            if(trim((string)$x->evaluate('string(/i:Invoice/'.$path.')'))==='') { $errors[]='Lipsește câmpul '.$path.'.'; }
        }
        if($x->evaluate('count(/i:Invoice/cac:InvoiceLine)')<1) { $errors[]='Factura trebuie să conțină cel puțin o poziție.'; }
        foreach($x->query('//cac:PostalAddress') ?: [] as $address) { foreach(['StreetName','CityName','CountrySubentity'] as $field) { if(trim((string)$x->evaluate('string(cbc:'.$field.')',$address))==='') { $errors[]='Completează strada, localitatea și județul ambilor parteneri.'; break; } } }
        $sum=BigDecimal::zero(); foreach($x->query('/i:Invoice/cac:InvoiceLine/cbc:LineExtensionAmount') ?: [] as $node) { $sum=$sum->plus($node->textContent); }
        $net=(string)$x->evaluate('string(/i:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount)');
        $tax=(string)$x->evaluate('string(/i:Invoice/cac:TaxTotal/cbc:TaxAmount)'); $gross=(string)$x->evaluate('string(/i:Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount)');
        try { if(!$sum->isEqualTo($net) || !BigDecimal::of($net)->plus($tax)->isEqualTo($gross)) { $errors[]='Sumele XML nu corespund totalurilor facturii.'; } }
        catch(\Throwable) { $errors[]='Sumele XML nu sunt valide.'; }
        return array_values(array_unique($errors));
    }
    /** @param array<string,mixed> $data */
    private function party(DOMElement $root,string $type,array $data,bool $forSubmission): void
    {
        $party=$this->add($this->add($root,'cac:'.$type),'cac:Party'); $address=$this->add($party,'cac:PostalAddress');
        try { $country=self::country($data['country']); }
        catch(InvoiceException $error) { if($forSubmission) { throw $error; } $country=$data['country']; }
        $this->add($address,'cbc:StreetName',$data['street']); $this->add($address,'cbc:CityName',$data['city']);
        if($data['postal_code']!=='') { $this->add($address,'cbc:PostalZone',$data['postal_code']); }
        try { $county=$country==='RO'?self::county($data['county']):$data['county']; }
        catch(InvoiceException $error) { if($forSubmission) { throw $error; } $county=$data['county']; }
        $this->add($address,'cbc:CountrySubentity',$county); $this->add($this->add($address,'cac:Country'),'cbc:IdentificationCode',$country);
        if($data['vat']) { $scheme=$this->add($party,'cac:PartyTaxScheme'); $id=strtoupper(preg_replace('/\s/','',$data['id'])); $this->add($scheme,'cbc:CompanyID',str_starts_with($id,$country)?$id:$country.$id); $this->add($this->add($scheme,'cac:TaxScheme'),'cbc:ID','VAT'); }
        $entity=$this->add($party,'cac:PartyLegalEntity'); $this->add($entity,'cbc:RegistrationName',$data['name']); $this->add($entity,'cbc:CompanyID',preg_replace('/^RO/i','',preg_replace('/\s/','',$data['id'])));
        if($data['email']!=='') { $this->add($this->add($party,'cac:Contact'),'cbc:ElectronicMail',$data['email']); }
    }
    private function taxCategory(DOMElement $parent,string $tag,int $rate,bool $vatPayer): void
    {
        $category=$this->add($parent,'cac:'.$tag); $this->add($category,'cbc:ID',$vatPayer?($rate>0?'S':'Z'):'O');
        if($vatPayer) { $this->add($category,'cbc:Percent',number_format($rate/100,2,'.','')); }
        elseif($tag==='TaxCategory') { $this->add($category,'cbc:TaxExemptionReasonCode','VATEX-EU-O'); $this->add($category,'cbc:TaxExemptionReason','Neînregistrat în scopuri de TVA'); }
        $this->add($this->add($category,'cac:TaxScheme'),'cbc:ID','VAT');
    }
    private function money(DOMElement $parent,string $tag,int $bani,string $currency): void { $element=$this->add($parent,'cbc:'.$tag,(string)BigDecimal::of($bani)->dividedBy(100,2)); $element->setAttribute('currencyID',$currency); }
    private function add(DOMElement $parent,string $tag,string $value=''): DOMElement
    {
        $doc=$parent->ownerDocument; $element=$doc->createElementNS(str_starts_with($tag,'cbc:')?self::CBC:self::CAC,$tag); if($value!=='') { $element->appendChild($doc->createTextNode($value)); } $parent->appendChild($element); return $element;
    }
    private static function country(string $value): string
    {
        $value=mb_strtoupper(trim($value)); if(in_array($value,['ROMÂNIA','ROMANIA','ROUMANIE'],true)) { return 'RO'; } if(preg_match('/^[A-Z]{2}$/D',$value)) { return $value; } throw new InvoiceException('Țara trebuie să aibă un cod ISO de două litere în datele de facturare.');
    }
    private static function county(string $value): string
    {
        $normalized=mb_strtolower(strtr(trim($value),['ș'=>'s','ş'=>'s','ț'=>'t','ţ'=>'t','ă'=>'a','â'=>'a','î'=>'i','Ș'=>'s','Ț'=>'t','Ă'=>'a','Â'=>'a','Î'=>'i']));
        $names=['alba'=>'AB','arad'=>'AR','arges'=>'AG','bacau'=>'BC','bihor'=>'BH','bistrita-nasaud'=>'BN','botosani'=>'BT','brasov'=>'BV','braila'=>'BR','buzau'=>'BZ','caras-severin'=>'CS','calarasi'=>'CL','cluj'=>'CJ','constanta'=>'CT','covasna'=>'CV','dambovita'=>'DB','dolj'=>'DJ','galati'=>'GL','giurgiu'=>'GR','gorj'=>'GJ','harghita'=>'HR','hunedoara'=>'HD','ialomita'=>'IL','iasi'=>'IS','ilfov'=>'IF','maramures'=>'MM','mehedinti'=>'MH','mures'=>'MS','neamt'=>'NT','olt'=>'OT','prahova'=>'PH','satu mare'=>'SM','salaj'=>'SJ','sibiu'=>'SB','suceava'=>'SV','teleorman'=>'TR','timis'=>'TM','tulcea'=>'TL','vaslui'=>'VS','valcea'=>'VL','vrancea'=>'VN','bucuresti'=>'B'];
        if(isset($names[$normalized])) { return 'RO-'.$names[$normalized]; }
        $code=strtoupper($value); if(in_array(preg_replace('/^RO-/','',$code),array_values($names),true)) { return str_starts_with($code,'RO-')?$code:'RO-'.$code; }
        throw new InvoiceException('Selectează județul corect al emitentului și al clientului înainte de trimiterea în SPV.');
    }
}
