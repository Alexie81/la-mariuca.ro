<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use App\Modules\Inventory\InvoiceStockService;
use DateTimeImmutable;
use DateTimeZone;

final class InvoiceService
{
    public const KINDS=['sale'=>'Factură','return'=>'Factură de retur','correction_negative'=>'Corecție storno','corrected_positive'=>'Factură corectată'];
    public function __construct(private readonly InvoiceRepository $repository,private readonly InvoiceSnapshot $snapshots,private readonly InvoiceStockService $stock) {}
    /** @param array<string,mixed> $actor */
    public function allow(array $actor,string $permission='view'): void
    {
        $user=$this->repository->one('users',['id'=>(int)($actor['id'] ?? 0),'status'=>'active']);
        $roles=$permission==='view'?['administrator','manager','operator']:($permission==='delete'?['administrator']:['administrator','manager']);
        if(!$user || !in_array($user['role'],$roles,true)) { throw new InvoiceException('Nu ai permisiunea necesară pentru această operațiune.',403); }
    }
    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function document(int $id,array $actor): array { $this->allow($actor); return $this->decorate($this->load($id)); }
    /** @return array<string,mixed> */
    public function load(int $id): array
    {
        $invoice=$this->repository->one('invoices',['id'=>$id,'deleted_at'=>null]) ?? throw new InvoiceException('Factura nu este disponibilă.',404);
        $invoice['snapshot']=json_decode($invoice['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        $invoice['lines']=$this->repository->all('invoice_lines',['invoice_id'=>$id]);
        usort($invoice['lines'],static fn(array $a,array $b):int=>(int)$a['position']<=>(int)$b['position']);
        return $invoice;
    }
    /** @param array<string,mixed> $filters @param array<string,mixed> $actor @return array<string,mixed> */
    public function registry(array $filters,array $actor): array
    {
        $this->allow($actor); $page=$this->repository->registry($filters);
        $page['items']=array_map(fn(array $row):array=>$this->brief($row),$page['items']);
        return $page+['summary'=>$this->repository->summary(),'eligible_orders'=>$this->repository->eligibleOrders(),'pending_receipts'=>$this->pendingReceipts($actor)];
    }
    /** @param array<string,mixed> $actor @param array<string,mixed> $billing @return array<string,mixed> */
    public function preview(int $orderId,array $actor,array $billing=[]): array { $this->allow($actor,'issue'); return $this->snapshots->fromOrder($orderId,$billing,false); }
    /** @param array<string,mixed> $input @param array<string,mixed> $actor */
    public function editOrder(int $orderId,array $input,array $actor): void
    {
        $this->allow($actor,'edit');
        $this->repository->transaction(function()use($orderId,$input,$actor):void {
            $this->key($input); $this->reason($input);
            if($this->repository->one('invoices',['primary_order_id'=>$orderId])) { throw new InvoiceException('Comanda are deja factură. Folosește actualizarea sau corecția facturii.',409); }
            $order=$this->repository->one('orders',['id'=>$orderId]) ?? throw new InvoiceException('Comanda nu există.');
            if(($input['order_version'] ?? '')!==$order['updated_at']) { throw new InvoiceException('Comanda s-a modificat. Reîncarcă formularul.',409); }
            (new InvoiceOrderEditor($this->repository->pdo()))->apply($orderId,$input,$actor);
            if(isset($input['billing'])) {
                $preview=$this->snapshots->fromOrder($orderId,(array)$input['billing']); $customer=$preview['snapshot']['customer'];
                $names=explode(' ',$customer['name'],2);
                $this->repository->update('orders',['first_name'=>$names[0],'last_name'=>$names[1] ?? '','email'=>$customer['email'],'phone'=>$customer['phone'],'customer_type'=>$customer['type'],'company_json'=>InvoiceSnapshot::json($customer['company']),'billing_address_json'=>InvoiceSnapshot::json($customer['address'])],['id'=>$orderId]);
            }
        });
    }
    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function issue(int $orderId,array $input,array $actor): array
    {
        $this->allow($actor,'issue');
        return $this->repository->transaction(function()use($orderId,$input,$actor):array {
            $key=$this->key($input); $hash=$this->hash([$orderId,$input['billing'] ?? [],$input['warehouse_id'] ?? null]);
            if($retry=$this->retry($key,'issue',$hash)) { return $retry; }
            $existing=$this->repository->one('invoices',['primary_order_id'=>$orderId]);
            if($existing) { return $this->decorate($this->load((int)$existing['id'])); }
            $data=$this->snapshots->fromOrder($orderId,(array)($input['billing'] ?? []));
            $invoice=$this->create('sale',$orderId,$data['snapshot'],$data['lines'],$actor,$key,null,null,'',isset($input['warehouse_id'])?(int)$input['warehouse_id']:null);
            $this->remember($key,'issue',$hash,(int)$invoice['id']); return $this->decorate($invoice);
        });
    }
    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function credit(int $id,array $input,array $actor,string $kind='return'): array
    {
        $this->allow($actor,'return');
        return $this->repository->transaction(function()use($id,$input,$actor,$kind):array {
            $key=$this->key($input); $hash=$this->hash([$id,$input]); if($retry=$this->retry($key,$kind,$hash)) { return $retry; }
            $original=$this->load($id); $this->version($original,$input); $reason=$this->reason($input);
            if(!in_array($original['kind'],['sale','corrected_positive'],true)) { throw new InvoiceException('Returul se emite pentru o factură pozitivă.'); }
            $requested=(array)($input['quantities'] ?? []); $lines=[]; $productLeft=false; $selectedShipping=false;
            foreach($original['lines'] as $line) {
                $already=$this->repository->creditedUnits((int)$line['id']); $available=(int)$line['quantity_units']-$already;
                $units=InvoiceCalculator::units((string)($requested[$line['id']] ?? '0'));
                if($line['kind']==='product' && $units<$available) { $productLeft=true; }
                if($units===0) { continue; }
                if($line['kind']==='shipping') { $selectedShipping=true; }
                $lines[]=InvoiceCalculator::credit($line,$units,$already);
            }
            if($lines===[]) { throw new InvoiceException('Selectează cel puțin o poziție și cantitatea returnată.'); }
            if($kind==='return' && $selectedShipping && $productLeft) { throw new InvoiceException('Livrarea poate fi restituită numai la returul integral al produselor rămase.'); }
            $retention=InvoiceCalculator::bani((string)($input['retention'] ?? '0'));
            $refundable=-InvoiceCalculator::totals($lines)['gross_bani'];
            if($retention>$refundable) { throw new InvoiceException('Costul reținut nu poate depăși valoarea returnată.'); }
            if($retention>0) {
                $name=trim((string)($input['retention_name'] ?? '')); if($name==='') { throw new InvoiceException('Precizează denumirea costului reținut.'); }
                $vat=(bool)$original['snapshot']['company']['is_vat_payer']?(int)$original['snapshot']['company']['vat_rate_basis_points']:0;
                $lines[]=InvoiceCalculator::line(InvoiceSnapshot::serviceIdentity($name,'retention'),10000,$retention,0,$vat);
            }
            $snapshot=$original['snapshot']; $snapshot['original_number']=$original['series_code'].' '.$original['number']; $snapshot['original_issued_at']=$original['issued_at']; $snapshot['paid_bani']=0;
            $order=$this->repository->one('orders',['id'=>$original['order_id']]);
            $snapshot['order_status_before']=$order['status']; $snapshot['order_payment_before']=$order['payment_status'];
            $result=$this->create($kind,(int)$original['order_id'],$snapshot,$lines,$actor,$key,$id,null,$reason,null);
            $this->touch($original,['row_version'=>(int)$original['row_version']+1]);
            if(!$productLeft && $kind==='return' && !$this->repository->hasUnreturnedProducts((int)$original['order_id'])) { $this->repository->update('orders',['status'=>'returned','updated_at'=>$this->repository->now()],['id'=>$original['order_id']]); }
            $this->remember($key,$kind,$hash,(int)$result['id']); return $this->decorate($result);
        });
    }
    /** A correction pair is atomic. Replacement uses validated server order prices and corrected billing.
     * @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed>
     */
    public function correct(int $id,array $input,array $actor): array
    {
        $this->allow($actor,'correct');
        return $this->repository->transaction(function()use($id,$input,$actor):array {
            $key=$this->key($input); $hash=$this->hash([$id,$input]); if($retry=$this->retry($key,'correct',$hash)) { return $retry; }
            $original=$this->load($id); $this->version($original,$input); $reason=$this->reason($input);
            if($original['kind']!=='sale' || $this->repository->children($id)!==[]) { throw new InvoiceException('Corecția automată este disponibilă pentru factura inițială, fără retururi sau alte corecții.',409); }
            $quantities=(array)($input['quantities'] ?? []);
            if(!array_key_exists('quantities',$input)) { foreach($original['lines'] as $line) { $quantities[$line['id']]=InvoiceCalculator::quantity((int)$line['quantity_units']); } }
            $unaffected=[];
            foreach($original['lines'] as $line) {
                $selected=InvoiceCalculator::units((string)($quantities[$line['id']] ?? '0'));
                if($selected>(int)$line['quantity_units']) { throw new InvoiceException('Cantitatea corectată depășește cantitatea facturată.'); }
                $identity=$line['kind']==='product'?'item:'.$line['order_item_id']:$line['kind'].':'.$line['name'];
                $unaffected[$identity]=(int)$line['quantity_units']-$selected;
            }
            $credit=$this->credit($id,['request_key'=>$key.':negative','version'=>$input['version'],'reason'=>$reason,'quantities'=>$quantities],$actor,'correction_negative');
            if(isset($input['items'])) { (new InvoiceOrderEditor($this->repository->pdo()))->apply((int)$original['order_id'],$input,$actor); }
            $replacement=$this->snapshots->fromOrder((int)$original['order_id'],(array)($input['billing'] ?? []));
            $replacement['snapshot']['company']=$original['snapshot']['company'];
            $replacement['snapshot']['logo_data']=$original['snapshot']['logo_data'];
            $replacement['snapshot']['original_number']=$original['series_code'].' '.$original['number'];
            $lines=[]; $vat=(bool)$original['snapshot']['company']['is_vat_payer']?(int)$original['snapshot']['company']['vat_rate_basis_points']:0;
            foreach($replacement['lines'] as $line) {
                $identity=$line['kind']==='product'?'item:'.$line['order_item_id']:$line['kind'].':'.$line['name'];
                $remaining=$unaffected[$identity] ?? 0; unset($unaffected[$identity]);
                $units=(int)$line['quantity_units']-$remaining;
                if($units<0) { throw new InvoiceException('Selectează pentru corecție toate cantitățile eliminate din comandă.'); }
                if($units===0) { continue; }
                $gross=InvoiceCalculator::ratio((int)$line['gross_bani'],$units,(int)$line['quantity_units']);
                $discount=InvoiceCalculator::ratio((int)$line['discount_bani'],$units,(int)$line['quantity_units']);
                $lines[]=InvoiceCalculator::line($line,$units,$gross+$discount,$discount,$vat);
            }
            if(array_sum($unaffected)>0) { throw new InvoiceException('Selectează pentru corecție și pozițiile eliminate din comandă.'); }
            if($lines===[]) { throw new InvoiceException('Corecția are nevoie de poziții înlocuitoare. Pentru o eliminare fără înlocuire folosește returul.'); }
            $result=$this->create('corrected_positive',(int)$original['order_id'],$replacement['snapshot'],$lines,$actor,$key.':positive',$id,(int)$credit['id'],$reason,null);
            $this->queueEmail((int)$credit['id'],$key.':email-negative',$actor);
            $this->queueEmail((int)$result['id'],$key.':email-positive',$actor);
            $this->remember($key,'correct',$hash,(int)$result['id']); return $this->decorate($result);
        });
    }
    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function edit(int $id,array $input,array $actor): array
    {
        $this->allow($actor,'edit');
        return $this->repository->transaction(function()use($id,$input,$actor):array {
            $key=$this->key($input); $hash=$this->hash([$id,$input]); if($retry=$this->retry($key,'edit',$hash)) { return $retry; }
            $invoice=$this->load($id); $this->version($invoice,$input); $this->unlocked($invoice); $reason=$this->reason($input);
            if($invoice['kind']!=='sale' || $this->repository->children($id)!==[]) { throw new InvoiceException('Această factură are documente dependente. Folosește un document de corecție.',409); }
            $snapshot=$invoice['snapshot']; $financial=[];
            if(isset($input['items'])) {
                foreach($invoice['lines'] as $line) { $this->stock->undoIssue($line,$actor,'edit:'.$key.':'.$line['id']); }
                (new InvoiceOrderEditor($this->repository->pdo()))->apply((int)$invoice['order_id'],$input,$actor);
                $rebuilt=$this->snapshots->fromOrder((int)$invoice['order_id'],(array)($input['billing'] ?? []));
                $vat=(bool)$snapshot['company']['is_vat_payer']?(int)$snapshot['company']['vat_rate_basis_points']:0;
                $lines=[]; foreach($rebuilt['lines'] as $line) {
                    $lines[]=InvoiceCalculator::line($line,(int)$line['quantity_units'],(int)$line['gross_bani']+(int)$line['discount_bani'],(int)$line['discount_bani'],$vat);
                }
                $this->repository->pdo()->prepare('DELETE FROM invoice_lines WHERE invoice_id=:id')->execute(['id'=>$id]);
                foreach($lines as $position=>$line) {
                    $line['invoice_id']=$id; $line['position']=$position+1; $line['warehouse_id']=$invoice['lines'][0]['warehouse_id'] ?? null;
                    $line['id']=$this->repository->insert('invoice_lines',$line); $this->stock->issue($line,$actor);
                }
                $financial=InvoiceCalculator::totals($lines);
                if((int)$invoice['paid_bani']>$financial['gross_bani']) { throw new InvoiceException('Valoarea nouă este mai mică decât suma încasată. Înregistrează diferența printr-un retur valoric.'); }
                $financial['payment_state']=(int)$invoice['paid_bani']>=$financial['gross_bani']?'paid':((int)$invoice['paid_bani']>0?'partial':'unpaid');
            }
            $customer=array_replace($snapshot['customer'],(array)($input['billing'] ?? [])); InvoiceSnapshot::validateCustomer($customer); $snapshot['customer']=$customer;
            $notes=trim((string)($input['notes'] ?? $invoice['notes'])); if(mb_strlen($notes)>5000) { throw new InvoiceException('Notele sunt prea lungi.'); }
            $currentLines=$this->repository->all('invoice_lines',['invoice_id'=>$id]);
            $this->touch($invoice,$financial+['snapshot_json'=>InvoiceSnapshot::json($snapshot),'notes'=>$notes,'email_state'=>$invoice['email_state']==='not_sent'?'not_sent':'needs_resend','search_text'=>$this->search(array_replace($invoice,$financial),$snapshot,$currentLines)]);
            $this->cancelQueuedEmail($id);
            $updated=$this->load($id); $this->audit($id,'edited',$actor,$reason,$invoice,$updated); $this->remember($key,'edit',$hash,$id); return $this->decorate($updated);
        });
    }
    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function receive(int $id,array $input,array $actor): array
    {
        $this->allow($actor,'receive');
        return $this->repository->transaction(function()use($id,$input,$actor):array {
            $key=$this->key($input); $hash=$this->hash([$id,$input]); if($retry=$this->retry($key,'receive',$hash)) { return $retry; }
            $invoice=$this->load($id); $this->version($invoice,$input);
            if(!in_array($invoice['kind'],['return','correction_negative'],true)) { throw new InvoiceException('Primirea fizică se înregistrează pentru un retur sau storno.'); }
            if($this->repository->one('invoice_receipts',['invoice_id'=>$id])) { throw new InvoiceException('Primirea produselor a fost deja înregistrată.',409); }
            $plans=[]; $hasProduct=false;
            foreach($invoice['lines'] as $line) {
                if($line['kind']!=='product' || (int)$line['quantity_units']>=0) { continue; } $hasProduct=true;
                $received=InvoiceCalculator::units((string)($input['received'][$line['id']] ?? '0'));
                $restock=InvoiceCalculator::units((string)($input['restock'][$line['id']] ?? '0'));
                if($received>abs((int)$line['quantity_units']) || $restock>$received) { throw new InvoiceException('Cantitatea pentru stoc nu poate depăși cantitatea primită sau returnată.'); }
                $plans[]=['line_id'=>$line['id'],'received_units'=>$received,'restock_units'=>$restock,'quarantine_units'=>$received-$restock,'missing_units'=>abs((int)$line['quantity_units'])-$received];
            }
            if(!$hasProduct) { throw new InvoiceException('Documentul nu conține produse pentru primire.'); }
            $notes=$this->reason($input);
            $receipt=$this->repository->insert('invoice_receipts',['invoice_id'=>$id,'actor_id'=>$actor['id'],'received_at'=>$this->repository->now(),'lines_json'=>InvoiceSnapshot::json($plans),'notes'=>$notes,'reversed_at'=>null]);
            foreach($plans as $plan) {
                $line=$this->repository->one('invoice_lines',['id'=>$plan['line_id']]); $source=$this->repository->one('invoice_lines',['id'=>$line['parent_line_id']]);
                $this->stock->receive($receipt,$line,$source,$plan['restock_units'],$actor);
                $this->stock->receiveOnline($receipt,$line,$source,$plan['restock_units'],$actor);
            }
            $this->touch($invoice,[]); $this->audit($id,'physically_received',$actor,$notes,[],['receipt_id'=>$receipt,'lines'=>$plans]); $this->remember($key,'receive',$hash,$id);
            return $this->decorate($this->load($id));
        });
    }
    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function payment(int $id,array $input,array $actor): array
    {
        $this->allow($actor,'payment');
        return $this->repository->transaction(function()use($id,$input,$actor):array {
            $key=$this->key($input); $hash=$this->hash([$id,$input]); if($retry=$this->retry($key,'payment',$hash)) { return $retry; }
            $invoice=$this->load($id); $this->version($invoice,$input); $reason=$this->reason($input);
            $amount=InvoiceCalculator::bani((string)($input['amount'] ?? '0')); if($amount<=0) { throw new InvoiceException('Introdu suma încasată sau restituită.'); }
            if((int)$invoice['gross_bani']<0) {
                $refunds=array_sum(array_column($this->repository->all('invoice_refunds',['invoice_id'=>$id]),'amount_bani'));
                if($amount+$refunds>abs((int)$invoice['gross_bani'])) { throw new InvoiceException('Suma depășește valoarea de restituit.'); }
                $cash=$this->repository->orderCash((int)$invoice['order_id']);
                if($amount>$cash['received']-$cash['refunded']) { throw new InvoiceException('Nu poți înregistra o restituire mai mare decât banii încasați efectiv pentru comandă.'); }
                $this->repository->insert('invoice_refunds',['invoice_id'=>$id,'amount_bani'=>$amount,'reference'=>$reason,'actor_id'=>$actor['id'],'created_at'=>$this->repository->now()]);
                $this->repository->update('orders',['payment_status'=>$cash['refunded']+$amount===$cash['received']?'refunded':'partially_refunded','updated_at'=>$this->repository->now()],['id'=>$invoice['order_id']]);
                $this->touch($invoice,[]);
            } else {
                if($invoice['payment_method']==='stripe') { throw new InvoiceException('Încasarea online este stabilită numai de procesatorul de plăți.'); }
                $cash=$this->repository->orderCash((int)$invoice['order_id']);
                if($amount>max(0,$this->repository->orderNet((int)$invoice['order_id'])-$cash['received']+$cash['refunded'])) { throw new InvoiceException('Suma depășește restul de plată al comenzii, după retururi și corecții.'); }
                $paid=(int)$invoice['paid_bani']+$amount; if($paid>(int)$invoice['gross_bani']) { throw new InvoiceException('Suma depășește restul de plată.'); }
                $this->touch($invoice,['paid_bani'=>$paid,'payment_state'=>$paid===(int)$invoice['gross_bani']?'paid':'partial']);
                if($cash['received']+$amount-$cash['refunded'] >= $this->repository->orderNet((int)$invoice['order_id'])) { $this->repository->update('orders',['payment_status'=>'paid','updated_at'=>$this->repository->now()],['id'=>$invoice['order_id']]); }
            }
            $this->audit($id,'payment_recorded',$actor,$reason,['paid_bani'=>$invoice['paid_bani']],['amount_bani'=>$amount,'external_transfer_performed'=>false]);
            $this->remember($key,'payment',$hash,$id); return $this->decorate($this->load($id));
        });
    }
    /** @param array<string,mixed> $input @param array<string,mixed> $actor */
    public function delete(int $id,array $input,array $actor): void
    {
        $this->allow($actor,'delete');
        $this->repository->transaction(function()use($id,$input,$actor):void {
            $key=$this->key($input); $hash=$this->hash([$id,$input]); $prior=$this->repository->one('invoice_actions',['request_key'=>$key]);
            if($prior) { if($prior['action']==='delete' && $prior['request_hash']===$hash) { return; } throw new InvoiceException('Cheia acțiunii este deja folosită.',409); }
            $invoice=$this->load($id); $this->version($invoice,$input); $this->unlocked($invoice); $reason=$this->reason($input);
            if($this->deleteBlocker($invoice)!=='') { throw new InvoiceException($this->deleteBlocker($invoice),409); }
            $before=$this->decorate($invoice);
            $receipt=$this->repository->one('invoice_receipts',['invoice_id'=>$id]);
            if($receipt) { $this->stock->undoReceipt((int)$receipt['id'],$actor); $this->repository->update('invoice_receipts',['reversed_at'=>$this->repository->now()],['id'=>$receipt['id']]); }
            if(in_array($invoice['kind'],['sale','corrected_positive'],true)) { foreach($invoice['lines'] as $line) { $this->stock->undoIssue($line,$actor,'delete-invoice:'.$id.':'.$line['id']); } }
            else {
                $snapshot=$invoice['snapshot'];
                $order=$this->repository->one('orders',['id'=>$invoice['order_id']]);
                $changes=['updated_at'=>$this->repository->now()];
                // Reverse the status caused by this credit, never later delivery/payment events.
                if($order['status']==='returned') { $changes['status']=$snapshot['order_status_before']; }
                $cash=$this->repository->orderCash((int)$invoice['order_id']);
                $restoredTotal=$this->repository->orderNet((int)$invoice['order_id'])-(int)$invoice['gross_bani'];
                if($cash['refunded']===0) { $changes['payment_status']=$cash['received']>0 && $cash['received'] >= $restoredTotal?'paid':'pending'; }
                $this->repository->update('orders',$changes,['id'=>$invoice['order_id']]);
            }
            foreach($this->repository->all('invoice_public_links',['invoice_id'=>$id,'revoked_at'=>null]) as $link) { $this->repository->update('invoice_public_links',['revoked_at'=>$this->repository->now()],['id'=>$link['id']]); }
            $this->touch($invoice,['active_number'=>null,'primary_order_id'=>null,'deleted_at'=>$this->repository->now()]);
            $this->cancelQueuedEmail($id);
            $this->audit($id,'deleted',$actor,$reason,$before,['number_reusable'=>true]); $this->remember($key,'delete',$hash,$id);
        });
    }
    /** @param array<string,mixed> $invoice */
    public function deleteBlocker(array $invoice): string
    {
        if($invoice['fiscal_attempted_at']!==null || $invoice['fiscal_reference']!==null || !in_array($invoice['fiscal_state'],['unconfigured','not_sent'],true)) { return 'Documentul este blocat de o încercare de transmitere fiscală.'; }
        if($this->repository->latestId()!==(int)$invoice['id']) { return 'Se poate șterge numai ultimul document emis.'; }
        if($this->repository->children((int)$invoice['id'])!==[]) { return 'Documentul are retururi sau corecții asociate.'; }
        if($this->repository->all('invoice_refunds',['invoice_id'=>$invoice['id']])!==[]) { return 'Pentru acest document este înregistrată o restituire de bani.'; }
        if($this->repository->one('invoice_return_requests',['invoice_id'=>$invoice['id'],'state'=>'pending'])) { return 'Există o solicitare de retur în analiză pentru acest document.'; }
        return '';
    }
    /** @param array<string,mixed> $actor @return string */
    public function publicLink(int $id,array $actor,bool $revoke=false): string
    {
        $this->allow($actor,'public_link');
        return $this->repository->transaction(function()use($id,$actor,$revoke):string {
            $this->load($id);
            foreach($this->repository->all('invoice_public_links',['invoice_id'=>$id,'revoked_at'=>null]) as $link) { $this->repository->update('invoice_public_links',['revoked_at'=>$this->repository->now()],['id'=>$link['id']]); }
            $token='';
            if(!$revoke) { $token=bin2hex(random_bytes(32)); $this->repository->insert('invoice_public_links',['invoice_id'=>$id,'token_hash'=>hash('sha256',$token),'created_at'=>$this->repository->now(),'revoked_at'=>null]); }
            $this->audit($id,$revoke?'public_link_revoked':'public_link_created',$actor,'',[],[]); return $token;
        });
    }
    /** @return array<string,mixed> */
    public function publicDocument(string $token): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token)) { throw new InvoiceException('Linkul nu este disponibil.',404); }
        $link=$this->repository->one('invoice_public_links',['token_hash'=>hash('sha256',$token),'revoked_at'=>null]);
        if(!$link) { throw new InvoiceException('Linkul nu este disponibil.',404); } return $this->load((int)$link['invoice_id']);
    }
    /** @param array<string,mixed> $actor */
    public function queueEmail(int $id,string $key,array $actor,bool $automatic=false): void
    {
        $this->allow($actor,'email');
        $this->repository->transaction(function()use($id,$key,$actor,$automatic):void {
            $invoice=$this->load($id); $request=['request_key'=>$key]; $this->key($request);
            if($this->repository->one('outbox_messages',['deduplication_key'=>$key])) { return; }
            $email=$invoice['snapshot']['customer']['email']; if(!filter_var($email,FILTER_VALIDATE_EMAIL)) { throw new InvoiceException('Adresa de email nu este validă.'); }
            $token=bin2hex(random_bytes(32));
            $this->repository->insert('invoice_public_links',['invoice_id'=>$id,'token_hash'=>hash('sha256',$token),'created_at'=>$this->repository->now(),'revoked_at'=>null]);
            $this->repository->insert('outbox_messages',['deduplication_key'=>$key,'message_type'=>'invoice','recipient'=>$email,'payload_json'=>InvoiceSnapshot::json(['invoice_id'=>$id,'order_id'=>$invoice['order_id'],'automatic'=>$automatic,'document_hash'=>self::documentHash($invoice),'public_token'=>$token]),'status'=>'pending','attempts'=>0,'available_at'=>$this->repository->now(),'created_at'=>$this->repository->now()]);
            $this->repository->update('invoices',['email_state'=>'queued'],['id'=>$id]); $this->audit($id,'email_queued',$actor,'',[],['recipient'=>$email]);
        });
    }
    /** @param array<string,mixed> $invoice */
    public static function documentHash(array $invoice): string
    {
        // UI decoration (trace, formatting, available quantities) is not document content.
        $fields=array_flip(['position','parent_line_id','order_item_id','product_id','variant_id','kind','name','sku','unit','quantity_units','unit_gross','unit_net','vat_basis_points','discount_bani','net_bani','tax_bani','gross_bani','scope_type','scope_id','warehouse_id','snapshot_json']);
        $lines=array_map(static function(array $line)use($fields):array { $line=array_intersect_key($line,$fields); ksort($line); return $line; },$invoice['lines']);
        return hash('sha256',InvoiceSnapshot::json([$invoice['series_code'],$invoice['number'],$invoice['issued_at'],$invoice['snapshot_json'],$lines,$invoice['notes'],$invoice['gross_bani'],$invoice['due_date']]));
    }
    /** @param array<string,mixed> $actor @return list<array<string,mixed>> */
    public function pendingReceipts(array $actor): array
    {
        $dismissed=$this->repository->one('invoice_reminder_dismissals',['actor_id'=>$actor['id']]);
        if(($dismissed['dismissed_on'] ?? '')===(new DateTimeImmutable('now',new DateTimeZone('Europe/Bucharest')))->format('Y-m-d')) { return []; }
        $pending=[];
        foreach(['return','correction_negative'] as $kind) {
            foreach($this->repository->all('invoices',['kind'=>$kind,'deleted_at'=>null]) as $invoice) {
                if($this->repository->one('invoice_receipts',['invoice_id'=>$invoice['id']])) { continue; }
                if($this->repository->one('invoice_lines',['invoice_id'=>$invoice['id'],'kind'=>'product'])) { $pending[]=$this->brief($invoice); }
            }
        }
        return $pending;
    }
    /** @param array<string,mixed> $actor */
    public function dismissReminders(array $actor): void
    {
        $this->allow($actor); $this->repository->transaction(function()use($actor):void {
            $data=['dismissed_on'=>(new DateTimeImmutable('now',new DateTimeZone('Europe/Bucharest')))->format('Y-m-d')];
            if($this->repository->one('invoice_reminder_dismissals',['actor_id'=>$actor['id']])) { $this->repository->update('invoice_reminder_dismissals',$data,['actor_id'=>$actor['id']]); }
            else { $this->repository->insert('invoice_reminder_dismissals',$data+['actor_id'=>$actor['id']]); }
        });
    }
    /** @param array<string,mixed> $actor @param array<string,mixed> $input */
    public function saveReturnPolicy(array $input,array $actor): void
    {
        $this->allow($actor,'delete'); $days=(string)($input['days'] ?? '');
        if(!ctype_digit($days) || (int)$days<1 || (int)$days>365) { throw new InvoiceException('Perioada trebuie să fie între 1 și 365 de zile.'); }
        $this->repository->transaction(function()use($input,$actor,$days):void {
            $this->repository->update('invoice_return_policy',['days'=>(int)$days,'allow_companies'=>isset($input['allow_companies'])?1:0,'allow_personalized'=>isset($input['allow_personalized'])?1:0,'updated_at'=>$this->repository->now(),'updated_by'=>$actor['id']],['id'=>1]);
        });
    }
    /** @return array<string,mixed> */
    public function returnPolicy(): array { return $this->repository->one('invoice_return_policy',['id'=>1]) ?? []; }
    /** @param array<string,mixed> $invoice @return array<string,mixed> */
    public function returnEligibility(array $invoice): array
    {
        $policy=$this->returnPolicy(); $order=$this->repository->one('orders',['id'=>$invoice['order_id']]);
        $q=$this->repository->pdo()->prepare("SELECT MAX(created_at) FROM audit_log WHERE entity_type='order' AND entity_id=:id AND action='order.deliver'"); $q->execute(['id'=>$invoice['order_id']]); $delivered=$q->fetchColumn();
        $deadline=$delivered?(new DateTimeImmutable($delivered))->modify('+'.(int)$policy['days'].' days')->getTimestamp():0;
        $reason='';
        if(!in_array($invoice['kind'],['sale','corrected_positive'],true)) { $reason='Returul se solicită pentru o factură de vânzare.'; }
        elseif($order['status']!=='delivered' || !$delivered) { $reason='Returul poate fi solicitat după înregistrarea livrării.'; }
        elseif(time()>=$deadline) { $reason='Perioada de solicitare a returului s-a încheiat. Contactează magazinul pentru ajutor.'; }
        elseif($invoice['snapshot']['customer']['type']==='company' && !$policy['allow_companies']) { $reason='Pentru achizițiile pe firmă, contactează magazinul pentru condițiile de retur.'; }
        $eligible=[];
        foreach($invoice['lines'] as $line) {
            $detail=json_decode($line['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            if($line['kind']!=='product' || (!$policy['allow_personalized'] && !empty($detail['personalization']))) { continue; }
            $units=max(0,(int)$line['quantity_units']-$this->repository->creditedUnits((int)$line['id']));
            if($units>0) { $eligible[]=$line+['available_units'=>$units]; }
        }
        if($eligible===[] && $reason==='') { $reason='Nu există produse eligibile pentru un nou retur. Contactează magazinul dacă ai nevoie de ajutor.'; }
        return ['allowed'=>$reason==='','reason'=>$reason,'lines'=>$eligible,'deadline'=>$deadline,'days'=>$policy['days']];
    }
    /** Customer authorization is tied to the verified tracking session, never a submitted order ID.
     * @param array<string,mixed> $input @param array<string,mixed>|null $actor @return array<string,mixed>
     */
    public function requestReturn(int $id,array $input,?array $actor=null,?int $authorizedOrder=null): array
    {
        if($actor!==null) { $this->allow($actor,'return'); }
        return $this->repository->transaction(function()use($id,$input,$actor,$authorizedOrder):array {
            $invoice=$this->load($id);
            if($actor===null && (int)$invoice['order_id']!==$authorizedOrder) { throw new InvoiceException('Verifică din nou accesul la comandă.',403); }
            $key=$this->key($input); $reason=$this->reason($input);
            $prior=$this->repository->one('invoice_return_requests',['request_key'=>$key]);
            if($prior) { if((int)$prior['invoice_id']!==$id) { throw new InvoiceException('Operațiune deja folosită.',409); } return $prior; }
            if($this->repository->one('invoice_return_requests',['invoice_id'=>$id,'state'=>'pending'])) { throw new InvoiceException('Ai deja o solicitare în analiză pentru această factură.'); }
            $eligibility=$this->returnEligibility($invoice);
            if($actor===null && !$eligibility['allowed']) { throw new InvoiceException($eligibility['reason']); }
            $eligibleIds=array_column($eligibility['lines'],'id'); $lines=[];
            foreach($invoice['lines'] as $line) {
                $units=InvoiceCalculator::units((string)($input['quantities'][$line['id']] ?? '0')); if(!$units) { continue; }
                if($line['kind']!=='product' || ($actor===null && !in_array($line['id'],$eligibleIds,true)) || $units>(int)$line['quantity_units']-$this->repository->creditedUnits((int)$line['id'])) { throw new InvoiceException('Cantitatea solicitată nu este eligibilă pentru retur.'); }
                $lines[]=['line_id'=>$line['id'],'name'=>$line['name'],'sku'=>$line['sku'],'quantity_units'=>$units,'gross_bani'=>-InvoiceCalculator::credit($line,$units,$this->repository->creditedUnits((int)$line['id']))['gross_bani']];
            }
            if(!$lines) { throw new InvoiceException('Alege cel puțin un produs și cantitatea.'); }
            $method=$invoice['payment_method']==='stripe'?'original_payment':(string)($input['refund_method'] ?? 'bank');
            if(!in_array($method,['original_payment','bank'],true) || ($invoice['payment_method']!=='stripe' && $method==='original_payment')) { throw new InvoiceException('Alege o metodă validă de restituire.'); }
            $iban=strtoupper(preg_replace('/\s+/','',(string)($input['iban'] ?? '')) ?? ''); $holder=trim((string)($input['holder'] ?? ''));
            if($method==='bank' && (!$this->validIban($iban) || mb_strlen($holder)<3)) { throw new InvoiceException('Completează un IBAN valid și numele titularului contului.'); }
            $request=['lines'=>$lines,'refund_method'=>$method,'iban'=>$method==='bank'?$iban:'','holder'=>$method==='bank'?$holder:'','return_shipping_payer'=>'customer','requested_by'=>$actor['id'] ?? null];
            $requestId=$this->repository->insert('invoice_return_requests',['invoice_id'=>$id,'order_id'=>$invoice['order_id'],'request_key'=>$key,'reason'=>$reason,'request_json'=>InvoiceSnapshot::json($request),'decision_json'=>'{}','created_at'=>$this->repository->now()]);
            $this->audit($id,'return_requested',$actor ?? ['id'=>null],$reason,[],['request_id'=>$requestId,'lines'=>$lines]);
            $this->queueReturnNotice($invoice,$requestId,'received','Am primit solicitarea ta de retur. Verificăm produsele și te anunțăm după analiză.');
            return $this->repository->one('invoice_return_requests',['id'=>$requestId]);
        });
    }
    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function decideReturn(int $id,array $input,array $actor): array
    {
        $this->allow($actor,'return');
        return $this->repository->transaction(function()use($id,$input,$actor):array {
            $request=$this->repository->one('invoice_return_requests',['id'=>(int)($input['return_request_id'] ?? 0),'invoice_id'=>$id]) ?? throw new InvoiceException('Solicitarea nu există.');
            if($request['state']!=='pending') { return $this->decorate($this->load((int)($request['credit_invoice_id'] ?: $id))); }
            $invoice=$this->load($id); $this->version($invoice,$input); $this->key($input); $reason=$this->reason($input);
            $payload=json_decode($request['request_json'],true,512,JSON_THROW_ON_ERROR); $decisions=[]; $quantities=[];
            foreach($payload['lines'] as $line) {
                $decision=$input['decisions'][$line['line_id']] ?? null;
                if(!is_array($decision) || !isset($decision['quantity'])) { throw new InvoiceException('Soluționează fiecare produs din solicitare.'); }
                $units=InvoiceCalculator::units((string)$decision['quantity']); $note=trim((string)($decision['reason'] ?? ''));
                if($units>(int)$line['quantity_units']) { throw new InvoiceException('Cantitatea acceptată depășește solicitarea.'); }
                if($units<(int)$line['quantity_units'] && mb_strlen($note)<3) { throw new InvoiceException('Explică refuzul total sau parțial pentru fiecare produs.'); }
                $decisions[]=['line_id'=>$line['line_id'],'accepted_units'=>$units,'reason'=>$note]; if($units>0) { $quantities[$line['line_id']]=InvoiceCalculator::quantity($units); }
            }
            $credit=null;
            if($quantities) {
                if(!empty($input['refund_shipping'])) { foreach($invoice['lines'] as $line) { if($line['kind']==='shipping') { $quantities[$line['id']]=InvoiceCalculator::quantity(max(0,(int)$line['quantity_units']-$this->repository->creditedUnits((int)$line['id']))); } } }
                $credit=$this->credit($id,['request_key'=>'return-decision:'.$request['id'],'version'=>$invoice['row_version'],'reason'=>$reason,'quantities'=>$quantities,'retention'=>$input['retention'] ?? '0','retention_name'=>$input['retention_name'] ?? 'Transport retur'],$actor);
            }
            $accepted=array_sum(array_column($decisions,'accepted_units')); $requested=array_sum(array_column($payload['lines'],'quantity_units'));
            $state=$credit?($accepted<$requested?'partial':'accepted'):'rejected';
            $this->repository->update('invoice_return_requests',['state'=>$state,'decision_json'=>InvoiceSnapshot::json($decisions),'credit_invoice_id'=>$credit['id'] ?? null,'decided_at'=>$this->repository->now(),'decided_by'=>$actor['id']],['id'=>$request['id']]);
            $this->audit($id,'return_decided',$actor,$reason,[],['request_id'=>$request['id'],'decisions'=>$decisions]);
            $message=$credit?'Am analizat returul și am emis documentul pentru cantitățile acceptate.':'Solicitarea de retur nu a fost acceptată.';
            foreach($decisions as $decision) { $message.="\n".($decision['reason'] ?: 'Poziție acceptată integral.'); }
            $this->queueReturnNotice($invoice,(int)$request['id'],$state,$message."\n".$reason);
            return $credit ?? $this->decorate($this->load($id));
        });
    }
    /** @param array<string,mixed> $invoice */
    private function queueReturnNotice(array $invoice,int $requestId,string $state,string $message): void
    {
        $key='invoice-return:'.$requestId.':'.$state;
        if($this->repository->one('outbox_messages',['deduplication_key'=>$key])) { return; }
        $this->repository->insert('outbox_messages',['deduplication_key'=>$key,'message_type'=>'invoice_return_notice','recipient'=>$invoice['snapshot']['customer']['email'],
            'payload_json'=>InvoiceSnapshot::json(['invoice_id'=>$invoice['id'],'return_notice'=>$state,'message'=>$message]),'status'=>'pending','attempts'=>0,'available_at'=>$this->repository->now(),'created_at'=>$this->repository->now()]);
    }
    /** @param array<string,mixed> $actor */
    public function cancelOrder(int $orderId,string $reason,array $actor): void
    {
        $this->allow($actor,'return');
        $this->repository->transaction(function()use($orderId,$reason,$actor):void {
            $original=$this->repository->one('invoices',['primary_order_id'=>$orderId]); if(!$original) { return; }
            $invoice=$this->load((int)$original['id']); $input=['request_key'=>'cancel-order:'.$orderId,'version'=>$invoice['row_version'],'reason'=>trim($reason) ?: 'Anularea comenzii'];
            if($this->deleteBlocker($invoice)==='' && ($actor['role'] ?? '')==='administrator') { $this->delete((int)$invoice['id'],$input,$actor); return; }
            foreach($this->repository->all('invoices',['order_id'=>$orderId,'deleted_at'=>null]) as $document) {
                if(!in_array($document['kind'],['sale','corrected_positive'],true)) { continue; }
                $invoice=$this->load((int)$document['id']); $quantities=[];
                foreach($invoice['lines'] as $line) { $remaining=(int)$line['quantity_units']-$this->repository->creditedUnits((int)$line['id']); if($remaining>0) { $quantities[$line['id']]=InvoiceCalculator::quantity($remaining); } }
                if($quantities) {
                    $credit=$this->credit((int)$invoice['id'],['request_key'=>'cancel-order:'.$orderId.':'.$invoice['id'],'version'=>$invoice['row_version'],'reason'=>$input['reason'],'quantities'=>$quantities],$actor);
                    $this->queueEmail((int)$credit['id'],'cancel-credit-email:'.$orderId.':'.$invoice['id'],$actor);
                }
            }
        });
    }
    /** @param array<string,mixed> $snapshot @param list<array<string,mixed>> $lines @param array<string,mixed> $actor @return array<string,mixed> */
    private function create(string $kind,int $orderId,array $snapshot,array $lines,array $actor,string $key,?int $parent,?int $replacement,string $reason,?int $warehouse): array
    {
        $settings=(new InvoiceConfigurationRepository($this->repository->pdo()))->settings();
        $number=$this->repository->nextNumber($settings['active_code']); $now=$this->repository->now(); $totals=InvoiceCalculator::totals($lines);
        $positive=in_array($kind,['sale','corrected_positive'],true); $paid=$positive?min((int)$snapshot['paid_bani'],max(0,$totals['gross_bani'])):0;
        if($warehouse===null) { $warehouses=$this->repository->all('company_warehouses',['is_active'=>1]); usort($warehouses,static fn(array $a,array $b):int=>(int)$b['is_default']<=>(int)$a['is_default']); $warehouse=(int)($warehouses[0]['id'] ?? 0); }
        $data=['document_key'=>$key,'primary_order_id'=>$kind==='sale'?$orderId:null,'order_id'=>$orderId,'parent_id'=>$parent,'replacement_for_id'=>$replacement,'kind'=>$kind,
            'series_id'=>$settings['active_series_id'],'series_code'=>$settings['active_code'],'number'=>$number,'active_number'=>$number,'issued_at'=>$now,
            'due_date'=>(new DateTimeImmutable($now))->setTimezone(new DateTimeZone('Europe/Bucharest'))->modify('+'.(int)$settings['due_days'].' days')->format('Y-m-d'),
            'currency'=>$snapshot['currency'],'payment_method'=>$snapshot['payment_method'],'payment_state'=>$positive?($paid===$totals['gross_bani']?'paid':($paid>0?'partial':'unpaid')):'credit','paid_bani'=>$paid,
            'snapshot_json'=>InvoiceSnapshot::json($snapshot),'search_text'=>'','reason'=>$reason,'notes'=>$settings['default_notes'],'created_by'=>$actor['id'],'updated_at'=>$now]+$totals;
        $data['search_text']=$this->search($data,$snapshot,$lines);
        $id=$this->repository->insert('invoices',$data);
        foreach($lines as $position=>$line) {
            unset($line['id'],$line['invoice_id']); $line['invoice_id']=$id; $line['position']=$position+1;
            $line['warehouse_id']=$line['warehouse_id'] ?? ($line['scope_type']===null?null:$warehouse);
            $lineId=$this->repository->insert('invoice_lines',$line); $line['id']=$lineId;
            if($positive) { $this->stock->issue($line,$actor); }
        }
        $invoice=$this->load($id); $this->audit($id,'issued',$actor,$reason,[],$invoice); return $invoice;
    }
    /** @param array<string,mixed> $invoice @param array<string,mixed> $data */
    private function touch(array $invoice,array $data): void { $this->repository->update('invoices',$data+['row_version'=>(int)$invoice['row_version']+1,'updated_at'=>$this->repository->now()],['id'=>$invoice['id']]); }
    /** @param array<string,mixed> $invoice @param array<string,mixed> $input */
    private function version(array $invoice,array $input): void { if((string)($input['version'] ?? '')!==(string)$invoice['row_version']) { throw new InvoiceException('Documentul a fost schimbat în altă fereastră. Reîncarcă înainte de a continua.',409); } }
    /** @param array<string,mixed> $invoice */
    private function unlocked(array $invoice): void { if($invoice['fiscal_attempted_at']!==null || $invoice['fiscal_reference']!==null || !in_array($invoice['fiscal_state'],['unconfigured','not_sent'],true)) { throw new InvoiceException('Documentul a intrat în fluxul fiscal și nu mai poate fi modificat sau șters.',409); } }
    /** @param array<string,mixed> $input */
    private function reason(array $input): string { $reason=trim((string)($input['reason'] ?? '')); if(mb_strlen($reason)<3 || mb_strlen($reason)>2000) { throw new InvoiceException('Completează un motiv clar, între 3 și 2.000 de caractere.'); } return $reason; }
    /** @param array<string,mixed> $input */
    private function key(array $input): string { $key=(string)($input['request_key'] ?? ''); if(!preg_match('/^[a-zA-Z0-9:_-]{8,120}$/D',$key)) { throw new InvoiceException('Redeschide formularul pentru a continua în siguranță.'); } return $key; }
    private function hash(mixed $data): string { return hash('sha256',InvoiceSnapshot::json($data)); }
    private function validIban(string $iban): bool
    {
        if(!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/D',$iban) || (str_starts_with($iban,'RO') && strlen($iban)!==24)) { return false; }
        $remainder=0;
        foreach(str_split(substr($iban,4).substr($iban,0,4)) as $character) { foreach(str_split(ctype_digit($character)?$character:(string)(ord($character)-55)) as $digit) { $remainder=($remainder*10+(int)$digit)%97; } }
        return $remainder===1;
    }
    /** @return array<string,mixed>|null */
    private function retry(string $key,string $action,string $hash): ?array
    {
        $prior=$this->repository->one('invoice_actions',['request_key'=>$key]); if(!$prior) { return null; }
        if($prior['action']!==$action || $prior['request_hash']!==$hash) { throw new InvoiceException('Această operațiune a fost deja folosită cu alte date.',409); }
        return $this->decorate($this->load((int)$prior['invoice_id']));
    }
    private function remember(string $key,string $action,string $hash,int $id): void { $this->repository->insert('invoice_actions',['request_key'=>$key,'action'=>$action,'request_hash'=>$hash,'invoice_id'=>$id,'created_at'=>$this->repository->now()]); }
    /** @param array<string,mixed> $actor */
    private function audit(int $id,string $action,array $actor,string $reason,mixed $before,mixed $after): void { $this->repository->insert('invoice_audit',['invoice_id'=>$id,'actor_id'=>$actor['id'],'action'=>$action,'reason'=>$reason,'before_json'=>InvoiceSnapshot::json($before),'after_json'=>InvoiceSnapshot::json($after),'created_at'=>$this->repository->now()]); }
    /** @param array<string,mixed> $invoice @param array<string,mixed> $snapshot @param list<array<string,mixed>> $lines */
    private function search(array $invoice,array $snapshot,array $lines): string
    {
        return InvoiceRepository::normalize($invoice['series_code'].' '.$invoice['number'].' '.$snapshot['order_number'].' '.($snapshot['original_number'] ?? '').' '.InvoiceSnapshot::json($snapshot['customer']).' '.implode(' ',array_column($lines,'name')).' '.implode(' ',array_column($lines,'sku')).' '.InvoiceCalculator::money((int)$invoice['gross_bani'],$invoice['currency']).' '.$invoice['currency'].' '.(self::KINDS[$invoice['kind']] ?? ''));
    }
    /** @param array<string,mixed> $invoice @return array<string,mixed> */
    private function brief(array $invoice): array
    {
        $snapshot=$invoice['snapshot'] ?? json_decode($invoice['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        return $invoice+['display_number'=>$invoice['series_code'].' '.$invoice['number'],'kind_label'=>self::KINDS[$invoice['kind']],'customer'=>$snapshot['customer'],'order_number'=>$snapshot['order_number'],
            'total_display'=>InvoiceCalculator::money((int)$invoice['gross_bani'],$invoice['currency']),'date_display'=>(new DateTimeImmutable($invoice['issued_at']))->setTimezone(new DateTimeZone('Europe/Bucharest'))->format('d.m.Y, H:i'),
            'payment_label'=>['paid'=>'Achitată','partial'=>'Achitată parțial','unpaid'=>'Neachitată','credit'=>'Retur / corecție'][$invoice['payment_state']]];
    }
    /** @param array<string,mixed> $invoice @return array<string,mixed> */
    private function decorate(array $invoice): array
    {
        $invoice=$this->brief($invoice); $invoice['related']=array_map(fn(array $row):array=>$this->brief($row),$this->repository->children((int)$invoice['id']));
        $invoice['parent']=$invoice['parent_id']?$this->repository->one('invoices',['id'=>$invoice['parent_id']]):null;
        $invoice['receipt']=$this->repository->one('invoice_receipts',['invoice_id'=>$invoice['id']]);
        if($invoice['receipt']) {
            $invoice['receipt']['lines']=json_decode($invoice['receipt']['lines_json'],true,512,JSON_THROW_ON_ERROR);
            $names=array_column($invoice['lines'],'name','id');
            foreach($invoice['receipt']['lines'] as &$receivedLine) { $receivedLine['name']=$names[$receivedLine['line_id']] ?? 'Articol'; } unset($receivedLine);
        }
        $invoice['audit']=$this->repository->all('invoice_audit',['invoice_id'=>$invoice['id']]);
        $invoice['refunds']=$this->repository->all('invoice_refunds',['invoice_id'=>$invoice['id']]);
        $invoice['refunded_bani']=array_sum(array_column($invoice['refunds'],'amount_bani'));
        $invoice['return_requests']=$this->repository->all('invoice_return_requests',['invoice_id'=>$invoice['id']]);
        foreach($invoice['return_requests'] as &$request) { $request['details']=json_decode($request['request_json'],true,512,JSON_THROW_ON_ERROR); $request['decisions']=json_decode($request['decision_json'],true,512,JSON_THROW_ON_ERROR); } unset($request);
        $invoice['delete_blocker']=$this->deleteBlocker($invoice); $invoice['editable']=$invoice['kind']==='sale' && $invoice['related']===[] && $invoice['fiscal_attempted_at']===null && $invoice['fiscal_reference']===null && in_array($invoice['fiscal_state'],['unconfigured','not_sent'],true);
        foreach($invoice['lines'] as &$line) {
            $line['snapshot']=json_decode($line['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            $line['quantity_display']=InvoiceCalculator::quantity((int)$line['quantity_units']); $line['total_display']=InvoiceCalculator::money((int)$line['gross_bani'],$invoice['currency']);
            $line['available_units']=max(0,(int)$line['quantity_units']-$this->repository->creditedUnits((int)$line['id']));
            $line['trace']=$this->repository->trace((int)$line['id']); $line['pending_units']=$this->repository->pendingUnits((int)$line['id']);
        } unset($line);
        return $invoice;
    }
    private function cancelQueuedEmail(int $id): void
    {
        foreach($this->repository->all('outbox_messages',['message_type'=>'invoice']) as $message) {
            $payload=json_decode($message['payload_json'],true);
            if((int)($payload['invoice_id'] ?? 0)===$id && in_array($message['status'],['pending','failed'],true)) {
                $this->repository->update('outbox_messages',['status'=>'failed','attempts'=>5,'last_error'=>'Documentul a fost modificat sau eliminat. Generează o trimitere nouă.'],['id'=>$message['id']]);
            }
        }
    }
}
