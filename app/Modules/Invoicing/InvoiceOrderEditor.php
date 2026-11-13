<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use PDO;

/** Changes the commercial promise atomically; posted accounting entries are handled by InvoiceService. */
final class InvoiceOrderEditor
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string,mixed> $input @param array<string,mixed> $actor */
    public function apply(int $orderId,array $input,array $actor): void
    {
        if(!$this->pdo->inTransaction()) { throw new \LogicException('Order edits require a transaction'); }
        $repo=new InvoiceRepository($this->pdo); $order=$repo->one('orders',['id'=>$orderId]) ?? throw new InvoiceException('Comanda nu există.');
        if(in_array($order['status'],['cancelled','returned','manual_review'],true)) { throw new InvoiceException('Comanda nu mai poate fi modificată.'); }
        $rows=$repo->all('order_items',['order_id'=>$orderId]); $byId=[]; $before=[]; $after=[]; $selected=[]; $seen=[];
        $cumulative=0; $allocated=0;
        foreach($rows as $row) {
            $cumulative+=(int)$row['line_total_bani']; $next=InvoiceCalculator::ratio((int)$order['discount_bani'],$cumulative,(int)$order['subtotal_bani']);
            $row['agreed_total']=(int)$row['line_total_bani']-($next-$allocated); $allocated=$next; $byId[(int)$row['id']]=$row;
            $before[(int)$row['variant_id']]=($before[(int)$row['variant_id']] ?? 0)+(int)$row['quantity'];
        }
        $submitted=(array)($input['items'] ?? []);
        foreach($submitted as $item) {
            if(!is_array($item)) { throw new InvoiceException('Poziție invalidă.'); }
            $id=(int)($item['id'] ?? 0); $raw=trim((string)($item['quantity'] ?? ''));
            if(!preg_match('/^\d{1,4}$/D',$raw) || (int)$raw>999) { throw new InvoiceException('Cantitatea trebuie să fie între 0 și 999 de bucăți.'); }
            $quantity=(int)$raw; if($quantity===0) { continue; }
            if($id>0) {
                $row=$byId[$id] ?? throw new InvoiceException('Produsul nu aparține comenzii.');
                if(isset($seen[$id])) { throw new InvoiceException('Aceeași poziție este introdusă de două ori.'); } $seen[$id]=true;
                $row['line_total_bani']=InvoiceCalculator::ratio($row['agreed_total'],$quantity,(int)$row['quantity']);
                $row['unit_price_bani']=InvoiceCalculator::ratio($row['line_total_bani'],1,$quantity); $row['quantity']=$quantity; unset($row['agreed_total']);
            } else {
                $sku=trim((string)($item['sku'] ?? '')); if($sku==='') { throw new InvoiceException('Scrie SKU-ul exact al produsului nou.'); }
                $q=$this->pdo->prepare("SELECT v.*,p.name,p.primary_sku,p.track_in_conta AS product_track,p.status AS product_status FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.sku=:sku"); $q->execute(['sku'=>$sku]); $v=$q->fetch();
                if(!$v || $v['status']!=='active' || $v['product_status']!=='published' || (int)$v['price_bani']<=0) { throw new InvoiceException('SKU-ul nu corespunde unui produs activ cu preț valid.'); }
                $variantScope=(int)$v['track_in_conta']===1 && json_decode($v['options_json'],true)!==[];
                $scope=$variantScope?'variant':((int)$v['product_track']===1?'product':null);
                $row=['id'=>0,'order_id'=>$orderId,'product_id'=>$v['product_id'],'variant_id'=>$v['id'],'product_name'=>$v['name'],'variant_summary'=>$v['option_summary'],'sku'=>$v['sku'],
                    'quantity'=>$quantity,'unit_price_bani'=>$v['price_bani'],'personalization_bani'=>0,'line_total_bani'=>(int)$v['price_bani']*$quantity,'tax_bani'=>0,'personalization_json'=>'{}',
                    'accounting_scope_type'=>$scope,'accounting_scope_id'=>$scope==='variant'?$v['id']:($scope==='product'?$v['product_id']:null),'accounting_sku'=>$scope==='variant'?$v['sku']:($scope==='product'?$v['primary_sku']:null)];
            }
            $variant=(int)$row['variant_id']; if(isset($after[$variant])) { throw new InvoiceException('Păstrează o singură poziție pentru fiecare variantă.'); }
            $after[$variant]=$quantity; $selected[]=$row;
        }
        if($selected===[]) { throw new InvoiceException('Păstrează cel puțin un produs în comandă.'); }
        $subtotal=array_sum(array_column($selected,'line_total_bani'));
        $shipping=isset($input['shipping'])?InvoiceCalculator::bani((string)$input['shipping']):(int)$order['shipping_bani'];
        $fees=array_sum(array_column(json_decode($order['payment_fees_json'] ?? '[]',true,512,JSON_THROW_ON_ERROR),'amount_bani'));
        $total=$subtotal+$shipping+$fees;
        $method=(string)($input['payment_method'] ?? $order['payment_method']);
        if($method!==$order['payment_method']) { throw new InvoiceException('Metoda de plată se păstrează. O plată online trebuie confirmată prin procesator, nu schimbată manual.'); }
        // Preserve captured money: changing a paid card order cannot invent a new payment.
        if($method==='stripe' && $order['payment_status']==='paid' && $total!==(int)$order['total_bani']) { throw new InvoiceException('Pentru o comandă achitată online, păstrează totalul încasat sau folosește returul și o comandă nouă pentru diferență.'); }
        foreach(array_unique([...array_keys($before),...array_keys($after)]) as $variant) {
            $delta=($after[$variant] ?? 0)-($before[$variant] ?? 0); if($delta===0) { continue; }
            $q=$this->pdo->prepare('SELECT v.status,p.status AS product_status,v.stock_unlimited FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.id=:id'); $q->execute(['id'=>$variant]); $v=$q->fetch();
            if($delta>0 && (!$v || $v['status']!=='active' || $v['product_status']!=='published')) { throw new InvoiceException('Produsul adăugat nu mai este disponibil.'); }
            $q=$this->pdo->prepare('SELECT * FROM inventory_reservations WHERE order_id=:order_id AND variant_id=:variant'); $q->execute(['order_id'=>$orderId,'variant'=>$variant]); $reservation=$q->fetch();
            if((bool)($v['stock_unlimited'] ?? false) && !$reservation) { continue; }
            $shipped=in_array($order['status'],['shipped','delivered'],true);
            if($shipped && $delta<0) { throw new InvoiceException('Produsele expediate se scot prin retur. Nu le elimina direct din comandă.'); }
            $q=$this->pdo->prepare('UPDATE inventory SET '.($shipped?'physical_quantity=physical_quantity-:delta':'reserved_quantity=reserved_quantity+:delta').',updated_at=:now WHERE variant_id=:variant AND (:growth<=0 OR physical_quantity-reserved_quantity>=:needed) AND reserved_quantity+:reserved>=0');
            foreach(['delta'=>$delta,'now'=>$repo->now(),'variant'=>$variant,'growth'=>$delta,'needed'=>$delta,'reserved'=>$shipped?0:$delta] as $parameter=>$value) { $q->bindValue(':'.$parameter,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR); }
            $q->execute();
            if($q->rowCount()!==1) { throw new InvoiceException('Stocul online nu permite cantitatea nouă. Redu cantitatea sau verifică produsul.'); }
            $data=['quantity'=>$after[$variant] ?? 0,'status'=>($after[$variant] ?? 0)===0?'released':($shipped?'consumed':'active'),'updated_at'=>$repo->now()];
            if($reservation) { $repo->update('inventory_reservations',$data,['id'=>$reservation['id']]); }
            else { $repo->insert('inventory_reservations',$data+['order_id'=>$orderId,'variant_id'=>$variant,'created_at'=>$repo->now()]); }
            $repo->insert('inventory_movements',['variant_id'=>$variant,'type'=>$shipped?'ship':'reserve','physical_delta'=>$shipped?-$delta:0,'reserved_delta'=>$shipped?0:$delta,'reason'=>'Actualizare controlată a comenzii','reference_type'=>'order','reference_id'=>$orderId,'actor_user_id'=>$actor['id'],'created_at'=>$repo->now()]);
        }
        foreach($selected as $row) {
            $id=(int)$row['id']; unset($row['id']);
            if($id>0) { $repo->update('order_items',$row,['id'=>$id]); } else { $repo->insert('order_items',$row); }
        }
        foreach($byId as $id=>$row) { if(!isset($seen[$id])) { $q=$this->pdo->prepare('DELETE FROM order_items WHERE id=:id'); $q->execute(['id'=>$id]); } }
        $repo->update('orders',['subtotal_bani'=>$subtotal,'discount_bani'=>0,'shipping_bani'=>$shipping,'shipping_discount_bani'=>0,'total_bani'=>$total,'updated_at'=>$repo->now()],['id'=>$orderId]);
        $repo->insert('audit_log',['actor_user_id'=>$actor['id'],'action'=>'order.invoice_edit','entity_type'=>'order','entity_id'=>$orderId,'context_json'=>InvoiceSnapshot::json(['before'=>$rows,'after'=>$selected,'reason'=>$input['reason'] ?? '','prices'=>'agreed_final_preserved']),'created_at'=>$repo->now()]);
    }
}
