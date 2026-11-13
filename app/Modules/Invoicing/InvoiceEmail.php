<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use App\Shared\Config\Config;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class InvoiceEmail
{
    public function __construct(private readonly InvoiceRepository $repository,private readonly Config $config,private readonly string $root) {}
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function render(array $payload,string $recipient): array
    {
        $invoice=InvoiceModule::service($this->repository->pdo(),$this->root)->load((int)($payload['invoice_id'] ?? 0));
        $snapshot=$invoice['snapshot']; $customer=$snapshot['customer'];
        $notice=isset($payload['return_notice']);
        if(mb_strtolower($recipient)!==mb_strtolower($customer['email']) || (!$notice && !hash_equals((string)($payload['document_hash'] ?? ''),InvoiceService::documentHash($invoice)))) { throw new InvoiceException('Factura a fost actualizată. Pregătește un email nou pentru varianta actuală.'); }
        $base=$this->config->rootUrl(); $image=fn(string $name):string=>$base.'/assets/images/'.$name;
        $number=$invoice['series_code'].' '.$invoice['number']; $negative=in_array($invoice['kind'],['return','correction_negative'],true);
        $items=[]; $quantity=0;
        foreach($invoice['lines'] as $line) {
            $detail=json_decode($line['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            $quantity+=(int)$line['quantity_units'];
            $items[]=['name'=>$line['name'],'variant'=>$detail['variant'] ?? '', 'sku'=>$line['sku'], 'quantity'=>InvoiceCalculator::quantity((int)$line['quantity_units']),
                'unit_price'=>InvoiceCalculator::money(\Brick\Math\BigDecimal::of($line['unit_gross'])->multipliedBy(100)->toScale(0,\Brick\Math\RoundingMode::HalfUp)->toInt()), 'total'=>InvoiceCalculator::money((int)$line['gross_bani']),
                'image'=>$detail['image_data'] ?? '', 'product_url'=>empty($detail['product_slug'])?null:$base.'/produs/'.rawurlencode($detail['product_slug']).'/'];
        }
        $subject=($negative?'Factura de retur ':'Factura ').$number.' · La Măriuca';
        $data=['subject'=>$subject,'preheader'=>'Documentul este atașat în format PDF.','first_name'=>explode(' ',$customer['name'])[0],
            'badge'=>$negative?'Document de retur':'Factura ta este pregătită','message'=>$negative?'Am pregătit documentul de retur.':'Toate detaliile, într-un singur document.',
            'introduction'=>$negative?'Factura atașată arată valorile returnate. Restituirea banilor și verificarea produselor sunt urmărite separat.':'Îți trimitem factura pentru comanda '.$snapshot['order_number'].'. Păstreaz-o împreună cu documentele cumpărăturii.',
            'number'=>$number,'order_number'=>$snapshot['order_number'],'invoice_number'=>$number,'date'=>(new \DateTimeImmutable($invoice['issued_at']))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y · H:i'),
            'items'=>$items,'quantity'=>InvoiceCalculator::quantity(abs($quantity)),'totals'=>[
                ['label'=>'Valoare fără TVA','value'=>InvoiceCalculator::money((int)$invoice['net_bani']),'saving'=>false],
                ['label'=>'TVA','value'=>InvoiceCalculator::money((int)$invoice['tax_bani']),'saving'=>false]],
            'total'=>InvoiceCalculator::money((int)$invoice['gross_bani']),'savings'=>(int)$invoice['discount_bani']>0?InvoiceCalculator::money((int)$invoice['discount_bani']):null,
            'paid'=>$invoice['payment_state']==='paid','payment_method'=>$invoice['payment_method']==='stripe'?'Card online':'Ramburs','payment_state'=>['paid'=>'Plătit','partial'=>'Achitat parțial','unpaid'=>'De achitat','credit'=>'Document de retur'][$invoice['payment_state']],
            'recipient_name'=>$customer['name'],'email'=>$customer['email'],'phone'=>$customer['phone'],'address'=>$snapshot['shipping_address'],'shipping_method'=>$snapshot['shipping_method'], 'delivery_estimate'=>'',
            'tracking_url'=>$base.'/factura/'.($payload['public_token'] ?? '').'/','shop_url'=>$base.'/','contact_email'=>$this->config->get('CONTACT_EMAIL','contact@la-mariuca.ro'),
            'contact_url'=>$base.'/pagina/contact/','delivery_url'=>$base.'/pagina/livrare-si-retur/','terms_url'=>$base.'/pagina/termeni-si-conditii/','privacy_url'=>$base.'/pagina/confidentialitate/',
            'logo_url'=>$image('logo-la-mariuca-transparent.png'),'status_icon'=>$image('email-status-confirmed.png'),'status_color'=>'#28765d',
            'summary_icon'=>$image('email-order-receipt-v2.png'),'delivery_icon'=>$image('email-order-truck-v2.png'),'payment_icon'=>$image('email-order-'.($invoice['payment_method']==='stripe'?'card':'cash').'-v2.png'),
            'savings_icon'=>$image('email-savings-confetti.gif'),'anpc_logo_url'=>$image('anpc-sal.png'),'cab_logo_url'=>$image('cab-it-mark.png'),'care_badge_url'=>$image('email-customer-care.png'),'heart_icon_url'=>$image('icon-favorite-hearts.png'),'year'=>date('Y')];
        $twig=new Environment(new FilesystemLoader($this->root.'/resources/views'),['autoescape'=>'html']);
        if($notice) {
            $state=(string)$payload['return_notice'];
            $title=match($state){'received'=>'Am primit solicitarea de retur','accepted'=>'Solicitarea de retur a fost acceptată','partial'=>'Solicitarea de retur a fost acceptată parțial',default=>'Răspuns la solicitarea de retur'};
            $tracking=new \App\Modules\Orders\OrderTrackingService(new \App\Modules\Orders\OrderTrackingRepository($this->repository->pdo()),new \App\Modules\Orders\OrderService($this->repository->pdo()),$this->config,$this->root);
            $data['subject']=$title.' · '.$snapshot['order_number']; $data['badge']='Returul tău'; $data['message']=$title;
            $data['introduction']=(string)$payload['message']; $data['tracking_url']=$tracking->emailUrl((int)$invoice['order_id'],$recipient); $data['preheader']=$title;
            return ['subject'=>$data['subject'],'html'=>$twig->render('emails/invoice-return-notice.twig',$data),'text'=>'Bună, '.$customer['name']."!\n\n".$data['introduction']."\n\nUrmărește comanda: ".$data['tracking_url'],'attachment'=>null,'attachment_name'=>null];
        }
        $exports=new InvoiceExportService($this->repository,$this->root);
        return ['subject'=>$subject,'html'=>$twig->render('emails/invoice.twig',$data),'text'=>'Bună, '.$customer['name']."!\n\n".$data['introduction']."\nFactura ".$number.' · '.$data['total']."\nDescarcă factura: ".$data['tracking_url']."\n\nEchipa La Măriuca",
            'attachment'=>$exports->pdf($invoice),'attachment_name'=>$exports->filename($invoice).'.pdf'];
    }
}
