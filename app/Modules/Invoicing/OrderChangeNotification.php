<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use App\Modules\Orders\OrderService;
use App\Shared\Support\Money;

/** Customer-safe snapshots; queued with the edit, delivered only after commit. */
final class OrderChangeNotification
{
    public function __construct(private readonly InvoiceRepository $repository) {}

    /** @return array<string,mixed> */
    public function snapshot(int $orderId): array
    {
        $order=(new OrderService($this->repository->pdo()))->detailsForTracking($orderId) ?? throw new InvoiceException('Comanda nu există.',404);
        $snapshot=array_intersect_key($order,array_flip(['id','order_number','first_name','last_name','email','phone','customer_type','status','payment_status','payment_method','created_at','shipping_method_name','shipping_estimate','shipping_address','subtotal_bani','discount_bani','shipping_bani','shipping_discount_bani','payment_fees','tax_bani','total_bani']));
        $snapshot['billing_address']=json_decode($order['billing_address_json'],true,512,JSON_THROW_ON_ERROR);
        $snapshot['company']=json_decode($order['company_json'],true,512,JSON_THROW_ON_ERROR);
        $snapshot['items']=array_map(static fn(array $item):array=>array_intersect_key($item,array_flip(['product_name','variant_summary','sku','quantity','unit_price_bani','line_total_bani','image_path'])), $order['items']);
        $snapshot['quantity']=array_sum(array_column($snapshot['items'],'quantity'));
        return $snapshot;
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after @param array<string,mixed> $actor */
    public function queue(array $before,array $after,array $actor): void
    {
        $changes=$this->changes($before,$after);
        if($changes===[]) { return; }
        if(!filter_var($after['email'],FILTER_VALIDATE_EMAIL)) { throw new InvoiceException('Completează un email valid pentru notificarea clientului.'); }
        $id=$this->repository->insert('outbox_messages',['message_type'=>'order_details_changed','recipient'=>$after['email'],'payload_json'=>InvoiceSnapshot::json(['order_id'=>(int)$after['id'],'order'=>$after,'changes'=>$changes]),'status'=>'pending','attempts'=>0,'available_at'=>$this->repository->now(),'created_at'=>$this->repository->now()]);
        $this->repository->insert('audit_log',['actor_user_id'=>$actor['id'],'action'=>'order.details_email_queued','entity_type'=>'order','entity_id'=>$after['id'],'context_json'=>InvoiceSnapshot::json(['outbox_id'=>$id,'recipient'=>$after['email'],'changed_fields'=>array_column($changes,'label')]),'created_at'=>$this->repository->now()]);
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after @return list<array{label:string,before:string,after:string}> */
    private function changes(array $before,array $after): array
    {
        $changes=[];
        foreach(['first_name'=>'Prenume','last_name'=>'Nume','email'=>'Email','phone'=>'Telefon','customer_type'=>'Tip client','company'=>'Datele firmei','billing_address'=>'Adresa de facturare','shipping_address'=>'Adresa de livrare','shipping_method_name'=>'Metoda de livrare','shipping_bani'=>'Costul livrării','discount_bani'=>'Reducerea produselor','total_bani'=>'Total comandă'] as $key=>$label) {
            $a=$before[$key] ?? ''; $b=$after[$key] ?? '';
            if($a==$b) { continue; }
            $display=static function(mixed $value)use($key):string {
                if(str_ends_with($key,'_bani')) { return Money::format((int)$value); }
                if($key==='customer_type') { return $value==='company'?'Persoană juridică':'Persoană fizică'; }
                if(is_array($value)) { return implode(', ',array_filter(array_map(static fn($v):string=>is_scalar($v)?(string)$v:'',$value))); }
                return (string)$value;
            };
            $changes[]=['label'=>$label,'before'=>$display($a),'after'=>$display($b)];
        }
        $old=[]; $new=[];
        foreach($before['items'] as $item) { $old[$item['sku']]=$item; }
        foreach($after['items'] as $item) { $new[$item['sku']]=$item; }
        foreach(array_unique([...array_keys($old),...array_keys($new)]) as $sku) {
            $a=$old[$sku] ?? null; $b=$new[$sku] ?? null;
            $display=static fn(?array $item):string=>$item ? $item['quantity'].' buc. × '.Money::format((int)$item['unit_price_bani']).' · '.Money::format((int)$item['line_total_bani']) : '—';
            if($a && $b && $display($a)===$display($b) && $a['product_name']===$b['product_name']) { continue; }
            $changes[]=['label'=>($b['product_name'] ?? $a['product_name']).' · '.$sku.(!$a?' (adăugat)':(!$b?' (eliminat)':'')),'before'=>$display($a),'after'=>$display($b)];
        }
        return $changes;
    }
}
