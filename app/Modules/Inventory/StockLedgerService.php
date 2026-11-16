<?php
declare(strict_types=1);
namespace App\Modules\Inventory;

use App\Modules\Invoicing\InvoiceCalculator;
use App\Modules\Invoicing\InvoiceException;

final class StockLedgerService
{
    public function __construct(private readonly StockLedgerRepository $repository) {}

    /** @param array<string,mixed> $actor */
    public function allow(array $actor, bool $write=false): void
    {
        $q=$this->repository->pdo()->prepare('SELECT role FROM users WHERE id=:id AND status=:status');
        $q->execute(['id'=>(int)($actor['id'] ?? 0),'status'=>'active']);
        if(!in_array($q->fetchColumn(),$write?['administrator','manager']:['administrator','manager','operator'],true)) { throw new InvoiceException('Nu ai acces la evidența stocului.',403); }
    }

    /** @param array<string,mixed> $filters @param array<string,mixed> $actor @return array<string,mixed> */
    public function registry(array $filters,array $actor,bool $export=false): array
    {
        $this->allow($actor,$export); $result=$this->repository->registry($filters,$export);
        foreach($result['items'] as &$row) {
            $row['quantity']=InvoiceCalculator::quantity((int)$row['accounting_units']);
            $row['minimum']=InvoiceCalculator::quantity((int)$row['accounting_minimum_units']);
            $row['pending']=InvoiceCalculator::quantity((int)$row['pending_units']);
            $row['alert']=(int)$row['accounting_units']<0?'negative':((int)$row['accounting_units']<=(int)$row['accounting_minimum_units']?'low':'ok');
        } unset($row); return $result;
    }

    /** @param array<string,mixed> $filters @param array<string,mixed> $actor @return array<string,mixed> */
    public function detail(int $id,array $filters,array $actor): array
    {
        $this->allow($actor); $product=$this->repository->product($id) ?? throw new InvoiceException('Produsul nu este disponibil.',404);
        $scopes=$this->repository->scopes($id); $variants=$this->repository->variants($id);
        if(!(bool)$product['track_in_conta'] && $scopes===[]) { throw new InvoiceException('Produsul nu este urmărit contabil.',404); }
        $rows=$this->repository->movements($id); $balance=0; $incoming=0; $outgoing=0; $journal=[];
        $fiscalOnly=(bool)($filters['documented_only'] ?? false);
        $openingValue=0; $incomingValue=0; $outgoingValue=0; $runningValue=0; $pending=0; $openingPending=0;
        $from=$this->date((string)($filters['from'] ?? '')); $to=$this->date((string)($filters['to'] ?? ''));
        if($from!=='' && $to!=='' && $from>$to) { throw new InvoiceException('Data de început trebuie să fie înaintea datei de sfârșit.'); }
        $opening=0;
        foreach($rows as $row) {
            $row['documented']=(!empty($row['nir_id']) && ($row['nir_status'] ?? '')==='confirmed') || (!empty($row['invoice_id']) && empty($row['invoice_deleted']) && in_array($row['type'],['INVOICE_OUT','INVOICE_OUT_PENDING'],true));
            if($fiscalOnly && !$row['documented']) { continue; }
            $delta=(int)$row['quantity_units']; $balance+=$delta;
            $localDate=(new \DateTimeImmutable($row['movement_at']))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('Y-m-d');
            $row['balance_units']=$balance; $row['quantity']=InvoiceCalculator::quantity($delta); $row['balance']=InvoiceCalculator::quantity($balance);
            $row['document']=$row['nir_number'] ?? (isset($row['invoice_number_out'])?$row['series_code'].' '.$row['invoice_number_out']:'');
            $row['document_path']=!empty($row['nir_id'])?'admin/nir-uri/'.$row['nir_id'].'/':(!empty($row['invoice_id']) && empty($row['invoice_deleted'])?'admin/facturi/'.$row['invoice_id'].'/':null);
            if($row['document']==='') { $row['document']=$row['source_type']==='deleted_nir'?'NIR eliminat · #'.$row['source_id']:'Înregistrare anterioară'; }
            $row['label']=match($row['type']) {'INVOICE_OUT','INVOICE_OUT_PENDING','sale'=>'Factură de ieșire','INVOICE_REVERSED'=>'Inversare factură','CUSTOMER_RETURN_IN','NIR_CUSTOMER_RETURN','return'=>'Retur client','CUSTOMER_RETURN_REVERSED'=>'Inversare retur','NIR_DELETED'=>'Eliminare document','NIR_SUPPLIER_RETURN'=>'Retur furnizor','manual_adjustment'=>'Ajustare internă',default=>$delta>0?'Intrare în stoc':'Ieșire din stoc'};
            $row['cost_bani']=null;
            if(!empty($row['nir_units'])) { $row['cost_bani']=InvoiceCalculator::ratio((int)$row['acquisition_bani'],abs($delta),abs((int)$row['nir_units'])); }
            elseif(in_array($row['type'],['INVOICE_OUT','INVOICE_OUT_PENDING'],true) && !empty($row['invoice_units']) && $row['allocated_bani']!==null) {
                $row['cost_bani']=InvoiceCalculator::ratio((int)$row['allocated_bani'],abs($delta),(int)$row['invoice_units']);
            }
            $row['pending_cost_units']=in_array($row['type'],['INVOICE_OUT','INVOICE_OUT_PENDING'],true)?min(abs($delta),(int)($row['pending_units'] ?? ($row['cost_bani']===null?abs($delta):0))):($row['cost_bani']===null?abs($delta):0);
            $runningValue+=($delta<0?-1:1)*($row['cost_bani'] ?? 0); $pending+=$row['pending_cost_units'];
            if($from!=='' && $localDate<$from) { $opening=$balance; $openingValue=$runningValue; $openingPending=$pending; continue; }
            if($to!=='' && $localDate>$to) { continue; }
            $incoming+=max(0,$delta); $outgoing+=max(0,-$delta);
            if($delta>0) { $incomingValue+=$row['cost_bani'] ?? 0; } else { $outgoingValue+=$row['cost_bani'] ?? 0; }
            $row['sale_value_bani']=isset($row['sale_bani']) && !empty($row['invoice_units'])?InvoiceCalculator::ratio((int)$row['sale_bani'],abs($delta),abs((int)$row['invoice_units'])):null;
            $row['operator']=trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? '')) ?: 'Sistem';
            $row['date_display']=(new \DateTimeImmutable($row['movement_at']))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y, H:i');
            $row['partner']=json_decode((string)($row['supplier_snapshot'] ?? '{}'),true)['company_name'] ?? json_decode((string)($row['invoice_snapshot'] ?? '{}'),true)['customer']['name'] ?? '';
            $row['sku']=$row['nir_sku'] ?? $row['invoice_sku'] ?? $product['primary_sku'];
            $row['unit']=$row['unit'] ?? 'buc';
            $journal[]=$row;
        }
        $current=array_sum(array_column($scopes,'quantity_units'));
        $onlineAdjustments=$this->repository->onlineAdjustments($id);
        foreach($onlineAdjustments as &$adjustment) {
            $adjustment['operator']=trim((string)$adjustment['first_name'].' '.(string)$adjustment['last_name']) ?: 'Sistem';
            $adjustment['date_display']=(new \DateTimeImmutable((string)$adjustment['created_at']))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y, H:i');
        } unset($adjustment);
        return ['product'=>$product,'scopes'=>$scopes,'variants'=>$variants,'movements'=>$journal,'online_adjustments'=>$onlineAdjustments,'opening_units'=>$opening,'incoming_units'=>$incoming,'outgoing_units'=>$outgoing,
            'closing_units'=>$opening+$incoming-$outgoing,'current_units'=>$current,'consistent'=>$balance===$current,'minimum'=>InvoiceCalculator::quantity((int)$product['accounting_minimum_units']),
            'online'=>array_sum(array_column(array_filter($variants,static fn(array $v):bool=>$v['status']==='active'),'online_quantity')),
            'opening_value_bani'=>$openingValue,'incoming_value_bani'=>$incomingValue,'outgoing_value_bani'=>$outgoingValue,'closing_value_bani'=>$openingValue+$incomingValue-$outgoingValue,
            'pending_cost_units'=>$openingPending+array_sum(array_column($journal,'pending_cost_units')),'documented_only'=>$fiscalOnly,
            'unlimited'=>in_array(1,array_map('intval',array_column(array_filter($variants,static fn(array $v):bool=>$v['status']==='active'),'stock_unlimited')),true),'filters'=>['from'=>$from,'to'=>$to]];
    }

    /** A consistent read snapshot for all worksheets in the accounting export.
     * @param array<string,mixed> $filters @param array<string,mixed> $actor @return array<string,mixed>
     */
    public function accountingReport(array $filters,array $actor): array
    {
        $filters=$this->period($filters);
        $this->allow($actor,true); $pdo=$this->repository->pdo(); $own=!$pdo->inTransaction(); if($own) { $pdo->beginTransaction(); }
        try {
            $registry=$this->registry($filters,$actor,true); $details=[];
            foreach($registry['items'] as $item) { $details[]=$this->detail((int)$item['id'],array_replace($filters,['documented_only'=>true]),$actor); }
            if($own) { $pdo->commit(); }
            return ['details'=>$details,'filters'=>$filters,'generated_at'=>gmdate('c')];
        } catch(\Throwable $error) { if($own && $pdo->inTransaction()) { $pdo->rollBack(); } throw $error; }
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function period(array $filters): array
    {
        $today=new \DateTimeImmutable('today',new \DateTimeZone('Europe/Bucharest'));
        $preset=(string)($filters['period'] ?? 'custom');
        $start=match($preset) {
            'all'=>null,'year'=>$today->modify('first day of January'),'six'=>$today->modify('-6 months'),
            'three'=>$today->modify('-3 months'),'previous'=>$today->modify('first day of last month'),
            'month'=>$today->modify('first day of this month'),'custom'=>false,
            default=>throw new InvoiceException('Alege o perioadă validă pentru export.')
        };
        if($start!==false) {
            $filters['from']=$start?->format('Y-m-d') ?? '';
            $filters['to']=($preset==='previous'?$today->modify('last day of last month'):$today)->format('Y-m-d');
        }
        $filters['from']=$this->date((string)($filters['from'] ?? ''));
        $filters['to']=$this->date((string)($filters['to'] ?? ''));
        if($filters['from']!=='' && $filters['to']!=='' && $filters['from']>$filters['to']) { throw new InvoiceException('Data de început trebuie să fie înaintea datei de sfârșit.'); }
        return $filters;
    }

    /** @param array<string,mixed> $actor */
    public function threshold(int $id,string $value,array $actor): void
    {
        $this->allow($actor,true); $units=NirDecimal::units(NirDecimal::number($value,4,'minimum'));
        $pdo=$this->repository->pdo(); $pdo->beginTransaction();
        try {
            $pdo->exec('UPDATE nir_write_lock SET revision=revision+1 WHERE id=1');
            $product=$this->repository->product($id) ?? throw new InvoiceException('Produsul nu este disponibil.',404);
            if((int)$product['accounting_minimum_units']!==$units) {
                $q=$pdo->prepare('UPDATE products SET accounting_minimum_units=:units WHERE id=:id'); $q->execute(['units'=>$units,'id'=>$id]);
                $q=$pdo->prepare("INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES(:actor,'stock.threshold','product',:id,:context,:now)");
                $q->execute(['actor'=>$actor['id'],'id'=>$id,'context'=>json_encode(['before_units'=>(int)$product['accounting_minimum_units'],'after_units'=>$units],JSON_THROW_ON_ERROR),'now'=>gmdate('c')]);
            }
            $pdo->commit();
        } catch(\Throwable $e) { if($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
    }

    /** @param array<string,mixed> $actor @return array{before:int,after:int,delta:int,duplicate:bool} */
    public function adjustOnline(int $productId,int $variantId,string $direction,string $quantity,string $reason,string $requestKey,array $actor): array
    {
        $this->allow($actor,true);
        $amount=filter_var($quantity,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
        if($amount===false) { throw new InvoiceException('Cantitatea ajustării trebuie să fie un număr întreg între 1 și 1.000.000.'); }
        if(!in_array($direction,['increase','decrease'],true)) { throw new InvoiceException('Alege dacă stocul online crește sau scade.'); }
        $reason=trim((string)preg_replace('/\s+/u',' ',strip_tags($reason)));
        if(mb_strlen($reason)<3 || mb_strlen($reason)>500) { throw new InvoiceException('Motivul ajustării trebuie să aibă între 3 și 500 de caractere.'); }
        $requestKey=trim($requestKey);
        if($requestKey==='' || mb_strlen($requestKey)>200) { throw new InvoiceException('Cheia operațiunii nu este validă. Reîncarcă pagina și încearcă din nou.'); }
        $requestHash=hash('sha256',$requestKey);
        $delta=($direction==='increase'?1:-1)*(int)$amount;
        $pdo=$this->repository->pdo(); $own=!$pdo->inTransaction(); if($own) { $pdo->beginTransaction(); }
        try {
            $pdo->exec('UPDATE nir_write_lock SET revision=revision+1 WHERE id=1');
            $existing=$pdo->prepare('SELECT product_id,variant_id,before_quantity,after_quantity,physical_delta,reason FROM stock_online_adjustments WHERE request_key=:key');
            $existing->execute(['key'=>$requestHash]); $done=$existing->fetch();
            if(is_array($done)) {
                if((int)$done['product_id']!==$productId || (int)$done['variant_id']!==$variantId || (int)$done['physical_delta']!==$delta || (string)$done['reason']!==$reason) {
                    throw new InvoiceException('Cheia operațiunii a fost deja folosită pentru altă ajustare.',409);
                }
                if($own) { $pdo->commit(); }
                return ['before'=>(int)$done['before_quantity'],'after'=>(int)$done['after_quantity'],'delta'=>(int)$done['physical_delta'],'duplicate'=>true];
            }
            $suffix=$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $query=$pdo->prepare('SELECT v.id,v.product_id,v.stock_unlimited,i.physical_quantity,i.reserved_quantity FROM product_variants v JOIN inventory i ON i.variant_id=v.id WHERE v.id=:variant AND v.product_id=:product'.$suffix);
            $query->execute(['variant'=>$variantId,'product'=>$productId]); $variant=$query->fetch();
            if(!is_array($variant)) { throw new InvoiceException('Varianta nu aparține produsului selectat.',404); }
            if((int)$variant['stock_unlimited']===1) { throw new InvoiceException('O variantă cu stoc nelimitat nu folosește ajustări numerice online.'); }
            $before=(int)$variant['physical_quantity']; $after=$before+$delta;
            if($after<0) { throw new InvoiceException('Stocul fizic online nu poate deveni negativ.'); }
            if($after<(int)$variant['reserved_quantity']) { throw new InvoiceException('Ajustarea ar coborî stocul sub cantitatea deja rezervată.'); }
            $now=gmdate('c');
            $insert=$pdo->prepare('INSERT INTO stock_online_adjustments(request_key,product_id,variant_id,physical_delta,before_quantity,after_quantity,reason,actor_user_id,created_at) VALUES(:key,:product,:variant,:delta,:before,:after,:reason,:actor,:now)');
            $insert->execute(['key'=>$requestHash,'product'=>$productId,'variant'=>$variantId,'delta'=>$delta,'before'=>$before,'after'=>$after,'reason'=>$reason,'actor'=>$actor['id'],'now'=>$now]);
            $pdo->prepare('UPDATE inventory SET physical_quantity=:quantity,updated_at=:now WHERE variant_id=:variant')->execute(['quantity'=>$after,'now'=>$now,'variant'=>$variantId]);
            $adjustmentId=(int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO inventory_movements(variant_id,type,physical_delta,reserved_delta,reason,reference_type,reference_id,actor_user_id,created_at) VALUES(:variant,'adjust',:delta,0,:reason,'manual_online_adjustment',:reference,:actor,:now)")->execute(['variant'=>$variantId,'delta'=>$delta,'reason'=>$reason,'reference'=>$adjustmentId,'actor'=>$actor['id'],'now'=>$now]);
            $pdo->prepare("INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES(:actor,'stock.online_adjusted','product',:product,:context,:now)")->execute(['actor'=>$actor['id'],'product'=>$productId,'context'=>json_encode(['variant_id'=>$variantId,'before'=>$before,'after'=>$after,'delta'=>$delta,'reason'=>$reason,'accounting_stock_changed'=>false],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'now'=>$now]);
            if($own) { $pdo->commit(); }
            return ['before'=>$before,'after'=>$after,'delta'=>$delta,'duplicate'=>false];
        } catch(\Throwable $error) { if($own && $pdo->inTransaction()) { $pdo->rollBack(); } throw $error; }
    }

    private function date(string $value): string
    {
        if($value==='') { return ''; }
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if(!$date || $date->format('Y-m-d')!==$value) { throw new InvoiceException('Selectează o dată validă.'); } return $value;
    }
}
