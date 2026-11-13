<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use App\Modules\Companies\CompanyRepository;
use App\Modules\Orders\OrderService;
use PDO;

final class InvoiceSnapshot
{
    public function __construct(private readonly PDO $pdo,private readonly string $root) {}
    /** @param array<string,mixed> $billing @return array{snapshot:array<string,mixed>,lines:list<array<string,mixed>>} */
    public function fromOrder(int $orderId,array $billing=[],bool $validate=true): array
    {
        $order=(new OrderService($this->pdo))->findForAdmin($orderId) ?? throw new InvoiceException('Comanda nu există.',404);
        if(in_array($order['status'],['cancelled','returned','manual_review'],true)) { throw new InvoiceException('Comanda nu este eligibilă pentru emiterea unei facturi.'); }
        if($order['payment_method']==='stripe' && $order['payment_status']!=='paid') { throw new InvoiceException('Factura cu card se emite numai după plata confirmată de procesator.'); }
        if(in_array($order['payment_status'],['refunded','partially_refunded'],true)) { throw new InvoiceException('Verifică rambursarea comenzii înainte de facturare.'); }
        if($order['currency']!=='RON') { throw new InvoiceException('Această comandă necesită un curs valutar înainte de facturare.'); }
        $company=(new CompanyRepository($this->pdo))->allCompanies()[0] ?? throw new InvoiceException('Completează datele firmei înainte de emitere.');
        foreach(['legal_name','tax_id','registered_address','city','country'] as $field) {
            if(trim((string)$company[$field])==='') { throw new InvoiceException('Completează denumirea legală, CUI și sediul firmei în Detaliile firmei.'); }
        }
        $customer=['name'=>trim($order['first_name'].' '.$order['last_name']),'email'=>$order['email'],'phone'=>$order['phone'],
            'type'=>$order['customer_type'],'company'=>$order['company'],'address'=>$order['billing_address']];
        // Historical checkout addresses are Romanian-only and predate the country field.
        if(!isset($customer['address']['country'])) { $code=$customer['address']['country_code'] ?? 'RO'; $customer['address']['country']=$code==='RO'?'România':$code; }
        if($billing!==[]) {
            foreach(['name','email','phone','type'] as $field) { if(isset($billing[$field])) { $customer[$field]=trim((string)$billing[$field]); } }
            foreach(['address','company'] as $field) { if(isset($billing[$field]) && is_array($billing[$field])) { $customer[$field]=$billing[$field]; } }
        }
        if($validate) { self::validateCustomer($customer); }
        $vat=(bool)$company['is_vat_payer']?(int)$company['vat_rate_basis_points']:0;
        $subtotal=(int)$order['subtotal_bani']; $discount=(int)$order['discount_bani'];
        if($subtotal<=0 || $discount<0 || $discount>$subtotal || $order['items']===[]) { throw new InvoiceException('Totalurile comenzii nu permit emiterea.'); }
        if(array_sum(array_column($order['items'],'line_total_bani'))!==$subtotal) { throw new InvoiceException('Subtotalul produselor nu corespunde comenzii. Corectează comanda înainte de emitere.'); }
        $lines=[]; $cumulative=0; $allocated=0;
        foreach($order['items'] as $item) {
            $cumulative+=(int)$item['line_total_bani']; $next=InvoiceCalculator::ratio($discount,$cumulative,$subtotal); $part=$next-$allocated; $allocated=$next;
            $image=$this->image((string)($item['image_path'] ?? ''));
            $identity=['order_item_id'=>$item['id'],'product_id'=>$item['product_id'],'variant_id'=>$item['variant_id'],'kind'=>'product','name'=>$item['product_name'],'sku'=>$item['sku'],'unit'=>'buc',
                'scope_type'=>$item['accounting_scope_type'],'scope_id'=>$item['accounting_scope_id'],
                'snapshot_json'=>self::json(['variant'=>$item['variant_summary'],'image_data'=>$image,'image_path'=>$item['image_path'] ?? '', 'product_slug'=>$item['product_slug'] ?? '', 'personalization'=>$item['personalization']])];
            $lines[]=InvoiceCalculator::line($identity,(int)$item['quantity']*10000,(int)$item['line_total_bani'],$part,$vat);
        }
        $shipping=(int)$order['shipping_bani']; $shippingDiscount=(int)($order['shipping_discount_bani'] ?? 0);
        if($shipping+$shippingDiscount>0) { $lines[]=InvoiceCalculator::line(self::serviceIdentity('Livrare · '.($order['shipping_method_name'] ?: 'Curier'),'shipping'),10000,$shipping+$shippingDiscount,$shippingDiscount,$vat); }
        foreach($order['payment_fees'] as $fee) { $lines[]=InvoiceCalculator::line(self::serviceIdentity($fee['name'],'fee'),10000,(int)$fee['amount_bani'],0,$vat); }
        if(InvoiceCalculator::totals($lines)['gross_bani']!==(int)$order['total_bani']) { throw new InvoiceException('Totalul facturii nu corespunde totalului comenzii. Verifică reducerile și costurile.'); }
        return ['snapshot'=>['company'=>$company,'customer'=>$customer,'order_number'=>$order['order_number'],'order_id'=>$orderId,'order_created_at'=>$order['created_at'],
            'order_status_before'=>$order['status'],'order_payment_before'=>$order['payment_status'],'shipping_address'=>$order['shipping_address'],
            'shipping_method'=>$order['shipping_method_name'],'currency'=>$order['currency'],'payment_method'=>$order['payment_method'],
            'paid_bani'=>$order['payment_status']==='paid'?(int)$order['total_bani']:0,'logo_data'=>$this->image('/assets/images/logo-la-mariuca-transparent.png')], 'lines'=>$lines];
    }
    /** @return array<string,mixed> */
    public static function serviceIdentity(string $name,string $kind): array
    {
        return ['order_item_id'=>null,'product_id'=>null,'variant_id'=>null,'kind'=>$kind,'name'=>$name,'sku'=>'','unit'=>'serv','scope_type'=>null,'scope_id'=>null,'snapshot_json'=>'{}'];
    }
    /** @param array<string,mixed> $customer */
    public static function validateCustomer(array $customer): void
    {
        if(trim((string)($customer['name'] ?? ''))==='' || !filter_var($customer['email'] ?? '',FILTER_VALIDATE_EMAIL) || !is_array($customer['address'] ?? null) || $customer['address']===[]) { throw new InvoiceException('Completează numele, emailul și adresa de facturare ale clientului.'); }
        if(!in_array($customer['type'] ?? '',['person','company'],true)) { throw new InvoiceException('Tipul clientului nu este valid.'); }
        $address=$customer['address'];
        foreach(['city','county','country'] as $field) { if(trim((string)($address[$field] ?? ''))==='') { throw new InvoiceException('Completează localitatea, județul și țara din adresa de facturare.'); } }
        if(trim((string)($address['address'] ?? $address['street'] ?? ''))==='') { throw new InvoiceException('Completează strada și numărul din adresa de facturare.'); }
        if($customer['type']==='company' && (trim((string)($customer['company']['name'] ?? $customer['company']['company_name'] ?? ''))==='' || trim((string)($customer['company']['tax_id'] ?? $customer['company']['cui'] ?? ''))==='')) { throw new InvoiceException('Pentru persoană juridică, completează denumirea firmei și CUI-ul clientului.'); }
    }
    private function image(string $relative): string
    {
        if($relative==='' || str_contains($relative,'..') || str_contains($relative,':')) { return ''; }
        $base=realpath($this->root.'/public'); $path=realpath($this->root.'/public/'.ltrim($relative,'/'));
        if(!$base || !$path || !str_starts_with($path,$base.DIRECTORY_SEPARATOR) || !is_file($path) || filesize($path)>3_000_000) { return ''; }
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if(!in_array($mime,['image/png','image/jpeg','image/webp'],true)) { return ''; }
        // A small immutable copy survives later catalog image replacement/deletion.
        $im=@imagecreatefromstring((string)file_get_contents($path)); if(!$im) { return ''; }
        $width=imagesx($im); $height=imagesy($im); $scale=min(1,400/max($width,$height));
        $copy=imagescale($im,max(1,(int)($width*$scale)),max(1,(int)($height*$scale)));
        if(!$copy) { return ''; } imagesavealpha($copy,true); ob_start(); imagepng($copy,null,6); $bytes=(string)ob_get_clean();
        return 'data:image/png;base64,'.base64_encode($bytes);
    }
    public static function json(mixed $value): string { return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
}
