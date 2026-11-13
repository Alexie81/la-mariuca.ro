<?php
declare(strict_types=1);
namespace App\Modules\Inventory;

use App\Modules\Invoicing\InvoiceCalculator;
use App\Modules\Invoicing\InvoiceException;
use App\Modules\Invoicing\InvoiceRepository;
use PDO;

/** Accounting postings are separate from the online availability owned by checkout. */
final class InvoiceStockService
{
    private readonly NirRepository $stock;
    private readonly InvoiceRepository $invoices;
    public function __construct(private readonly PDO $pdo) { $this->stock=new NirRepository($pdo); $this->invoices=new InvoiceRepository($pdo); }
    /** @param array<string,mixed> $line @param array<string,mixed> $actor */
    public function issue(array $line,array $actor): void
    {
        if($line['scope_type']===null) { return; }
        if((int)$line['warehouse_id']<=0) { throw new InvoiceException('Alege o gestiune activă pentru produsele urmărite contabil.'); }
        $legacy=$this->stock->all('nir_stock_movements',['source_type'=>'order_item','source_id'=>$line['order_item_id']]);
        $consumed=array_sum(array_map(static fn(array $move):int=>min(0,(int)$move['quantity_units']),$legacy));
        if($consumed<0) {
            if(-$consumed!==(int)$line['quantity_units']) { throw new InvoiceException('Există o ieșire contabilă anterioară cu altă cantitate. Verifică documentul înainte de emitere.'); }
            foreach($legacy as $move) { if((int)$move['warehouse_id']!==(int)$line['warehouse_id']) { throw new InvoiceException('Alege gestiunea ieșirii contabile deja înregistrate.'); } }
            foreach(['nir_fifo_consumptions','nir_pending_consumptions','nir_stock_movements'] as $table) {
                foreach($this->stock->all($table,['source_type'=>'order_item','source_id'=>$line['order_item_id']]) as $row) { $this->stock->update($table,['source_type'=>'invoice_line','source_id'=>$line['id']],['id'=>$row['id']]); }
            }
            return;
        }
        (new NirService($this->pdo))->consume((int)$line['warehouse_id'],$this->target($line),(int)$line['quantity_units'],'invoice_line',(int)$line['id'],'invoice-line:'.$line['id'],$actor);
    }
    /** @param array<string,mixed> $line @param array<string,mixed> $actor */
    public function undoIssue(array $line,array $actor,string $event): void
    {
        if($line['scope_type']===null) { return; }
        foreach($this->stock->all('nir_fifo_consumptions',['source_type'=>'invoice_line','source_id'=>$line['id']]) as $part) {
            if((int)$part['returned_units']>0) { throw new InvoiceException('Produsele au fost deja primite înapoi. Documentul nu poate fi modificat.',409); }
            $layer=$this->stock->one('nir_fifo_layers',['id'=>$part['layer_id']]);
            if(!$layer) { throw new InvoiceException('Legătura cu intrarea în stoc nu mai este disponibilă.',409); }
            $this->stock->update('nir_fifo_layers',['remaining_units'=>(int)$layer['remaining_units']+(int)$part['quantity_units'],'remaining_value_bani'=>(int)$layer['remaining_value_bani']+(int)$part['value_bani']],['id'=>$layer['id']]);
            $this->stock->delete('nir_fifo_consumptions',['id'=>$part['id']]);
        }
        foreach($this->stock->all('nir_pending_consumptions',['source_type'=>'invoice_line','source_id'=>$line['id']]) as $pending) { $this->stock->delete('nir_pending_consumptions',['id'=>$pending['id']]); }
        $this->balance($line,(int)$line['quantity_units'],$actor,$event,'INVOICE_REVERSED');
        $this->reconcile($line,$actor);
    }
    /** Return actual goods at their historical acquisition cost. Unknown costs cancel pending shortages, never create zero-cost lots.
     * @param array<string,mixed> $credit @param array<string,mixed> $source @param array<string,mixed> $actor
     */
    public function receive(int $receiptId,array $credit,array $source,int $units,array $actor): void
    {
        if($source['scope_type']===null || $units===0) { return; }
        $remaining=$units;
        foreach($this->stock->all('nir_fifo_consumptions',['source_type'=>'invoice_line','source_id'=>$source['id']]) as $consumption) {
            $available=(int)$consumption['quantity_units']-(int)$consumption['returned_units']; $take=min($remaining,$available);
            if($take<=0) { continue; }
            $layer=$this->stock->one('nir_fifo_layers',['id'=>$consumption['layer_id']]); if(!$layer) { throw new InvoiceException('Intrarea originală nu este disponibilă.'); }
            $value=$take===$available ? (int)$consumption['value_bani']-(int)$consumption['returned_value_bani'] : InvoiceCalculator::ratio((int)$consumption['value_bani'],$take,(int)$consumption['quantity_units']);
            $newLayer=$this->stock->insert('nir_fifo_layers',['nir_id'=>$layer['nir_id'],'line_id'=>$layer['line_id'],'warehouse_id'=>$source['warehouse_id'],'scope_type'=>$source['scope_type'],'scope_id'=>$source['scope_id'],
                'initial_units'=>$take,'remaining_units'=>$take,'initial_value_bani'=>$value,'remaining_value_bani'=>$value,'unit_cost_ron'=>$layer['unit_cost_ron'],
                'received_at'=>$this->stock->now(),'created_at'=>$this->stock->now(),'original_layer_id'=>$layer['id']]);
            $this->stock->update('nir_fifo_consumptions',['returned_units'=>(int)$consumption['returned_units']+$take,'returned_value_bani'=>(int)$consumption['returned_value_bani']+$value],['id'=>$consumption['id']]);
            $this->invoices->insert('invoice_receipt_allocations',['receipt_id'=>$receiptId,'invoice_line_id'=>$credit['id'],'consumption_id'=>$consumption['id'],'layer_id'=>$newLayer,'quantity_units'=>$take,'value_bani'=>$value,'source_kind'=>'layer']);
            $remaining-=$take; if($remaining===0) { break; }
        }
        foreach($this->stock->all('nir_pending_consumptions',['source_type'=>'invoice_line','source_id'=>$source['id']]) as $pending) {
            $take=min($remaining,(int)$pending['quantity_units']); if($take<=0) { continue; }
            $left=(int)$pending['quantity_units']-$take;
            if($left===0) { $this->stock->delete('nir_pending_consumptions',['id'=>$pending['id']]); } else { $this->stock->update('nir_pending_consumptions',['quantity_units'=>$left],['id'=>$pending['id']]); }
            $this->invoices->insert('invoice_receipt_allocations',['receipt_id'=>$receiptId,'invoice_line_id'=>$credit['id'],'consumption_id'=>null,'layer_id'=>null,'quantity_units'=>$take,'value_bani'=>0,'source_kind'=>'pending']);
            $remaining-=$take;
        }
        if($remaining>0) { throw new InvoiceException('Cantitatea primită depășește ieșirea contabilă disponibilă pentru retur.'); }
        $this->balance($source,$units,$actor,'receipt:'.$receiptId.':'.$credit['id'],'CUSTOMER_RETURN_IN');
        $this->reconcile($source,$actor);
    }
    /** @param array<string,mixed> $actor */
    public function undoReceipt(int $receiptId,array $actor): void
    {
        foreach($this->invoices->all('inventory_movements',['reference_type'=>'invoice_receipt','reference_id'=>$receiptId]) as $move) {
            $q=$this->pdo->prepare('UPDATE inventory SET physical_quantity=physical_quantity-:quantity,updated_at=:now WHERE variant_id=:variant AND physical_quantity-reserved_quantity>=:available');
            foreach(['quantity'=>(int)$move['physical_delta'],'available'=>(int)$move['physical_delta'],'variant'=>(int)$move['variant_id'],'now'=>$this->stock->now()] as $key=>$value) { $q->bindValue(':'.$key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR); } $q->execute();
            if($q->rowCount()!==1) { throw new InvoiceException('Produsele reintrate în disponibilitatea online sunt deja rezervate sau vândute. Returul nu poate fi șters.',409); }
            $this->invoices->insert('inventory_movements',['variant_id'=>$move['variant_id'],'type'=>'adjust','physical_delta'=>-(int)$move['physical_delta'],'reserved_delta'=>0,'reason'=>'Inversarea primirii returului','reference_type'=>'invoice_receipt_undo','reference_id'=>$receiptId,'actor_user_id'=>$actor['id'],'created_at'=>$this->stock->now()]);
        }
        foreach($this->invoices->all('invoice_receipt_allocations',['receipt_id'=>$receiptId]) as $part) {
            $credit=$this->invoices->one('invoice_lines',['id'=>$part['invoice_line_id']]);
            $source=$this->invoices->one('invoice_lines',['id'=>$credit['parent_line_id']]);
            if($part['source_kind']==='layer') {
                $layer=$this->stock->one('nir_fifo_layers',['id'=>$part['layer_id']]);
                if(!$layer || (int)$layer['remaining_units']!==(int)$layer['initial_units'] || $this->stock->all('nir_fifo_consumptions',['layer_id'=>$layer['id']])!==[]) { throw new InvoiceException('Produsele primite înapoi au fost deja folosite într-o altă ieșire. Returul nu poate fi șters.',409); }
                $consumption=$this->stock->one('nir_fifo_consumptions',['id'=>$part['consumption_id']]);
                $this->stock->update('nir_fifo_consumptions',['returned_units'=>(int)$consumption['returned_units']-(int)$part['quantity_units'],'returned_value_bani'=>(int)$consumption['returned_value_bani']-(int)$part['value_bani']],['id'=>$consumption['id']]);
                $this->stock->delete('nir_fifo_layers',['id'=>$layer['id']]);
            } else {
                $this->stock->insert('nir_pending_consumptions',$this->target($source)+['event_key'=>'receipt-undo:'.$part['id'],'warehouse_id'=>$source['warehouse_id'],'source_type'=>'invoice_line','source_id'=>$source['id'],'quantity_units'=>$part['quantity_units'],'created_at'=>$this->stock->now()]);
            }
            $this->balance($source,-(int)$part['quantity_units'],$actor,'undo-receipt:'.$part['id'],'CUSTOMER_RETURN_REVERSED');
            $this->reconcile($source,$actor);
        }
    }
    /** Restore online pieces only if this order actually shipped/reserved them; unlimited supplier stock is untouched.
     * @param array<string,mixed> $credit @param array<string,mixed> $source @param array<string,mixed> $actor
     */
    public function receiveOnline(int $receiptId,array $credit,array $source,int $units,array $actor): void
    {
        if($units===0 || !$source['variant_id']) { return; }
        $invoice=$this->invoices->one('invoices',['id'=>$source['invoice_id']]);
        $reservation=$this->invoices->one('inventory_reservations',['order_id'=>$invoice['order_id'],'variant_id'=>$source['variant_id'],'status'=>'consumed']);
        if(!$reservation) { return; }
        if($units%10000!==0) { throw new InvoiceException('Disponibilitatea online a acestui produs folosește bucăți întregi.'); }
        $q=$this->pdo->prepare("SELECT COALESCE(SUM(m.physical_delta),0) FROM inventory_movements m JOIN invoice_receipts r ON r.id=m.reference_id JOIN invoices i ON i.id=r.invoice_id WHERE m.reference_type='invoice_receipt' AND i.order_id=:order_id AND m.variant_id=:variant AND r.reversed_at IS NULL");
        $q->execute(['order_id'=>$invoice['order_id'],'variant'=>$source['variant_id']]); $already=(int)$q->fetchColumn(); $quantity=intdiv($units,10000);
        if($already+$quantity>(int)$reservation['quantity']) { throw new InvoiceException('Cantitatea ar depăși produsele expediate efectiv pentru această comandă. Verifică primirile deja înregistrate.'); }
        $q=$this->pdo->prepare('UPDATE inventory SET physical_quantity=physical_quantity+:quantity,updated_at=:now WHERE variant_id=:variant'); $q->execute(['quantity'=>$quantity,'now'=>$this->stock->now(),'variant'=>$source['variant_id']]);
        if($q->rowCount()!==1) { throw new InvoiceException('Lipsește disponibilitatea online a produsului.'); }
        $this->invoices->insert('inventory_movements',['variant_id'=>$source['variant_id'],'type'=>'return','physical_delta'=>$quantity,'reserved_delta'=>0,'reason'=>'Primire retur · poziția '.$credit['id'],'reference_type'=>'invoice_receipt','reference_id'=>$receiptId,'actor_user_id'=>$actor['id'],'created_at'=>$this->stock->now()]);
    }
    /** @param array<string,mixed> $line @return array<string,mixed> */
    private function target(array $line): array { return ['scope_type'=>$line['scope_type'],'scope_id'=>$line['scope_id'],'product_id'=>$line['product_id'],'variant_id'=>$line['scope_type']==='variant'?$line['variant_id']:null,'sku'=>$line['sku']]; }
    /** @param array<string,mixed> $line @param array<string,mixed> $actor */
    private function balance(array $line,int $delta,array $actor,string $key,string $type): void
    {
        $where=['warehouse_id'=>$line['warehouse_id'],'scope_type'=>$line['scope_type'],'scope_id'=>$line['scope_id']];
        $balance=$this->stock->one('nir_stock_balances',$where) ?? throw new InvoiceException('Lipsește evidența contabilă a produsului.');
        $before=(int)$balance['quantity_units']; $after=$before+$delta;
        $this->stock->update('nir_stock_balances',['quantity_units'=>$after,'updated_at'=>$this->stock->now()],['id'=>$balance['id']]);
        $this->stock->insert('nir_stock_movements',$where+['nir_id'=>null,'line_id'=>null,'type'=>$type,'quantity_units'=>$delta,'before_units'=>$before,'after_units'=>$after,'event_key'=>$key,'source_type'=>'invoice_line','source_id'=>$line['id'],'actor_id'=>$actor['id'],'created_at'=>$this->stock->now()]);
    }
    /** @param array<string,mixed> $line @param array<string,mixed> $actor */
    private function reconcile(array $line,array $actor): void { (new NirService($this->pdo))->reconcileInvoiceStock((int)$line['warehouse_id'],$this->target($line),$actor); }
}
