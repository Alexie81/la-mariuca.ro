<?php
declare(strict_types=1);
namespace App\Modules\Inventory;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class NirService
{
    private readonly NirRepository $repository;
    private readonly \App\Modules\Companies\ReceiptLocationService $locations;
    private const MONEY=['net_bani','tax_bani','total_bani','discount_bani','net_ron_bani','tax_ron_bani','total_ron_bani','inventory_bani'];
    private const COSTS=['unit_price','discount_percent','vat_percent','extra_cost_ron','net_override','tax_override','gross_override','unit_cost_ron','last_price','initial_value_bani','remaining_value_bani','value_bani','price_history'];
    public function __construct(PDO $pdo) { $this->repository=new NirRepository($pdo); $this->locations=new \App\Modules\Companies\ReceiptLocationService(new \App\Modules\Companies\CompanyRepository($pdo)); }

    /** @param array<string,mixed> $actor @return array<string,bool> */
    public function permissions(array $actor): array
    {
        $role=(string)($actor['role'] ?? ''); $admin=$role==='administrator'; $manager=$role==='manager';
        $view=$admin || $manager || $role==='operator';
        $map=['view'=>'NIR_VIEW','create'=>'NIR_CREATE','edit'=>'NIR_EDIT_DRAFT','confirm'=>'NIR_CONFIRM','reverse'=>'NIR_REVERSE','export'=>'NIR_EXPORT','costs'=>'NIR_VIEW_COSTS','references'=>'NIR_REFERENCE_MANAGE','suppliers'=>'NIR_SUPPLIER_CREATE','fifo'=>'FIFO_VIEW','opening'=>'FIFO_OPENING_BALANCE_MANAGE'];
        $result=[];
        foreach ($map as $key=>$permission) { $result[$key]=$key==='view' ? $view : ($admin || ($manager && $key!=='opening')); }
        return $result;
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function defaults(array $actor): array
    {
        $this->allow($actor,'view');
        $this->ensureLocations();
        $companies=$this->repository->all('companies',[],'is_default DESC,id'); $company=$companies[0] ?? null;
        $warehouses=$company === null ? [] : $this->repository->all('company_warehouses',['company_id'=>$company['id'],'is_active'=>1],'is_default DESC,id');
        $locations=$this->repository->all('nir_locations',['is_active'=>1],'is_default DESC,id');
        $now=(new DateTimeImmutable('now',new DateTimeZone('Europe/Bucharest')))->format('Y-m-d\TH:i');
        $default=['id'=>null,'row_version'=>0,'status'=>'local','type'=>'supplier_receipt','supplier_id'=>'','warehouse_id'=>$warehouses[0]['id'] ?? '', 'location_id'=>'','invoice_series'=>'','invoice_number'=>'','invoice_date'=>substr($now,0,10),'nir_at'=>$now,'received_at'=>$now,'currency'=>'RON','exchange_rate'=>'1','exchange_rate_date'=>substr($now,0,10),'notes'=>'','lines'=>[$this->emptyLine()],'attachments'=>[]];
        foreach ($locations as $location) { if ((int)$location['warehouse_id']===(int)$default['warehouse_id']) { $default['location_id']=$location['id']; break; } }
        return ['document'=>$default,'values'=>$default,'suppliers'=>$this->repository->all('suppliers',['status'=>'active'],'alias'),'warehouses'=>$warehouses,'locations'=>$locations,'company'=>$company,'permissions'=>$this->permissions($actor),'vat_percent'=>'21'];
    }

    /** @param array<string,mixed> $filters @param array<string,mixed> $actor @return array<string,mixed> */
    public function registry(array $filters,array $actor): array
    {
        $this->allow($actor,'view'); $all=$this->repository->registry($filters); $total=count($all);
        $perPage=max(5,min(100,(int)($filters['per_page'] ?? 20))); $pages=max(1,(int)ceil($total/$perPage)); $page=max(1,min($pages,(int)($filters['page'] ?? 1)));
        $rows=array_map(fn(array $row):array=>$this->redact($this->decorate($row,false),$actor),array_slice($all,($page-1)*$perPage,$perPage));
        return ['items'=>$rows,'documents'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'total_pages'=>$pages,'per_page'=>$perPage,'from'=>$total===0?0:(($page-1)*$perPage+1),'to'=>min($page*$perPage,$total),'counts'=>$this->repository->counts(),'summary'=>$this->repository->counts(),'permissions'=>$this->permissions($actor)];
    }

    /** @param array<string,mixed> $filters @param array<string,mixed> $actor @return list<array<string,mixed>> */
    public function registryForExport(array $filters,array $actor): array
    {
        $this->allow($actor,'export'); $rows=$this->repository->registry($filters);
        if(count($rows)>5000) { throw new NirValidationException(['period'=>'Selectează o perioadă cu maximum 5.000 de documente.']); }
        return array_map(fn(array $row):array=>$this->document((int)$row['id'],$actor),$rows);
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function document(int $id,array $actor): array
    {
        $this->allow($actor,'view'); return $this->redact($this->load($id),$actor);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function save(?int $id,array $input,array $actor): array
    {
        $this->allow($actor,$id===null?'create':'edit');
        return $this->repository->transaction(function()use($id,$input,$actor):array {
            $old=$id===null?null:$this->load($id);
            if($old!==null) { $this->version($old,(int)($input['row_version'] ?? 0)); if($old['status']!=='draft') { throw new NirConflictException('Documentul confirmat poate fi modificat numai prin corectare controlată.'); } }
            [$header,$lines]=$this->prepare($input,$old);
            $now=$this->repository->now(); $actorId=$this->actorId($actor);
            $header+=['updated_by'=>$actorId,'updated_at'=>$now,'row_version'=>$old===null?1:(int)$old['row_version']+1];
            if($id===null) { $header+=['temp_number'=>'DRAFT-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(5))),'type'=>'supplier_receipt','status'=>'draft','created_by'=>$actorId,'created_at'=>$now,'reason'=>'']; $id=$this->repository->insert('nir_documents',$header); }
            else { $this->repository->update('nir_documents',$header,['id'=>$id]); $this->repository->delete('nir_lines',['nir_id'=>$id]); }
            $this->persistLines($id,$lines,$actor,(int)($header['supplier_id'] ?? 0));
            $this->appendAttachments($id,(array)($input['attachments'] ?? []),$actor);
            $this->audit($id,$old===null?'draft_created':'draft_updated',$actor,$old,$this->load($id));
            return $this->document($id,$actor);
        });
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function confirm(int $id,int $version,string $key,bool $acceptWarnings,array $actor): array
    {
        $this->allow($actor,'confirm');
        return $this->repository->transaction(function()use($id,$version,$key,$acceptWarnings,$actor):array {
            $hash=$this->requestHash('confirm',[$id,$version,$acceptWarnings]);
            if(($previous=$this->retry($key,'confirm',$hash))!==null) { return $this->document($previous,$actor); }
            $document=$this->load($id); $this->version($document,$version);
            if($document['status']!=='draft') { throw new NirConflictException('Acest NIR a fost deja confirmat.'); }
            $this->validate($document,$acceptWarnings);
            $this->post($document,$actor);
            $this->remember($key,'confirm',$hash,$id,$id);
            return $this->document($id,$actor);
        });
    }

    /** @param array<string,mixed> $actor */
    public function delete(int $id,int $version,array $actor): void
    {
        $this->allow($actor,'edit');
        $this->repository->transaction(function()use($id,$version,$actor):void {
            $document=$this->load($id); $this->version($document,$version);
            if($document['status']!=='draft') { throw new NirConflictException('Un NIR confirmat nu se șterge. Folosește corectare sau retur către furnizor.'); }
            $this->audit($id,'draft_deleted',$actor,$document,null);
            $this->repository->delete('nir_documents',['id'=>$id]);
        });
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function correct(int $id,int $version,array $input,string $key,bool $acceptWarnings,array $actor): array
    {
        $this->allow($actor,'confirm'); $this->allow($actor,'edit');
        return $this->repository->transaction(function()use($id,$version,$input,$key,$acceptWarnings,$actor):array {
            $hash=$this->requestHash('correct',[$id,$version,$input,$acceptWarnings]);
            if(($previous=$this->retry($key,'correct',$hash))!==null) { return $this->document($previous,$actor); }
            $old=$this->load($id); $this->version($old,$version);
            if(!$old['can_correct']) { throw new NirConflictException('Corectarea este permisă doar pentru recepții confirmate, fără ieșiri din loturile de stoc și fără storno.'); }
            $correctionReason=trim((string)($input['correction_reason'] ?? ''));
            if($correctionReason===''||mb_strlen($correctionReason)>1000) { throw new NirValidationException(['correction_reason'=>'Completează motivul corecției (maximum 1.000 de caractere).']); }
            foreach($old['lines'] as $line) {
                if(!$line['tracked'] || $line['scope_type']===null) { continue; }
                $this->changeBalance($old,$line,-(int)$line['stock_quantity_units']);
            }
            $this->repository->delete('nir_stock_movements',['nir_id'=>$id]);
            $this->repository->delete('nir_fifo_layers',['nir_id'=>$id]);
            foreach($old['lines'] as $line) { if($line['tracked'] && $line['scope_type']!==null) { $this->recalculateCost((int)$old['warehouse_id'],$line); } }
            $this->repository->delete('nir_lines',['nir_id'=>$id]);
            [$header,$lines]=$this->prepare($input,$old);
            $header+=['updated_by'=>$this->actorId($actor),'updated_at'=>$this->repository->now(),'row_version'=>$version+1];
            $this->repository->update('nir_documents',$header,['id'=>$id]);
            $this->persistLines($id,$lines,$actor,(int)($header['supplier_id'] ?? 0));
            $this->appendAttachments($id,(array)($input['attachments'] ?? []),$actor);
            $current=$this->load($id); $this->validate($current,$acceptWarnings);
            $this->post($current,$actor,true);
            $this->audit($id,'corrected',$actor,$old,$this->load($id),['reason'=>$correctionReason]);
            $this->remember($key,'correct',$hash,$id,$id);
            return $this->document($id,$actor);
        });
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function reverse(int $id,int $version,array $input,string $key,array $actor): array
    {
        $this->allow($actor,'reverse');
        return $this->repository->transaction(function()use($id,$version,$input,$key,$actor):array {
            $hash=$this->requestHash('reverse',[$id,$version,$input]); if(($previous=$this->retry($key,'reverse',$hash))!==null) { return $this->document($previous,$actor); }
            $old=$this->load($id); $this->version($old,$version);
            if($old['status']!=='confirmed'||$old['type']!=='supplier_receipt') { throw new NirConflictException('Doar o recepție furnizor confirmată poate fi stornată.'); }
            $reason=trim((string)($input['reason'] ?? ''));
            if($reason===''||mb_strlen($reason)>500) { throw new NirValidationException(['reason'=>'Scrie motivul returului (maximum 500 de caractere).']); }
            $invoiceNumber=trim((string)($input['invoice_number'] ?? '')); $invoiceDate=$this->date((string)($input['invoice_date'] ?? ''),'invoice_date');
            if($invoiceNumber==='') { throw new NirValidationException(['invoice_number'=>'Completează numărul facturii corective.']); }
            $selected=(array)($input['quantities'] ?? $input['lines'] ?? []); $newLines=[]; $allocations=[];
            foreach($old['lines'] as $line) {
                $raw=$selected[$line['id']] ?? '0'; if(is_array($raw)) { $raw=$raw['quantity'] ?? '0'; }
                $quantity=NirDecimal::number($raw,4,'quantities.'.$line['id']); if($quantity->isZero()) { continue; }
                try { $units=NirDecimal::units($quantity->multipliedBy($line['conversion_factor'])); }
                catch(\Brick\Math\Exception\MathException) { throw new NirValidationException(['quantities.'.$line['id']=>'Cantitatea returnată, după conversie, trebuie să aibă cel mult 4 zecimale și să se încadreze în limita numerică acceptată.']); }
                $layers=$this->eligibleLayers((int)$line['id']);
                $available=NirDecimal::sum(array_map(static fn(array $layer):int=>(int)$layer['remaining_units'],$layers));
                if(!$line['tracked']) { $available=(int)$line['stock_quantity_units']-(int)$line['reversed_units']; }
                if($units>$available || $units<=0) { throw new NirValidationException(['quantities.'.$line['id']=>'Cantitatea depășește lotul disponibil în gestiunea NIR-ului. Produsele recepționate în altă gestiune necesită transfer documentat înaintea returului.']); }
                $new=$line; foreach(['id','nir_id','reversed_units','available_units','price_history'] as $field) { unset($new[$field]); }
                $new['original_line_id']=$line['id']; $new['position']=count($newLines)+1;
                foreach(['invoice_quantity','received_quantity','accepted_quantity'] as $field) { $new[$field]=(string)$quantity->negated(); }
                $new['rejected_quantity']='0.0000'; $new['stock_quantity_units']=-$units; $new['stock_quantity']=NirDecimal::quantity(-$units); $new['save_reference']=0;
                $already=$this->repository->all('nir_lines',['original_line_id'=>$line['id']]);
                foreach(self::MONEY as $field) {
                    $used=-NirDecimal::sum(array_map(static fn(array $part):int=>(int)$part[$field],$already));
                    $remaining=(int)$line[$field]-$used;
                    $isLast=$units===(int)$line['stock_quantity_units']-(int)$line['reversed_units'];
                    $new[$field]=-($isLast ? $remaining : BigDecimal::of((int)$line[$field])->multipliedBy($units)->dividedBy((int)$line['stock_quantity_units'],0,RoundingMode::HalfUp)->toInt());
                }
                $newLines[]=$new; $allocations[$new['position']]=['layers'=>$layers,'units'=>$units];
            }
            if($newLines===[]) { throw new NirValidationException(['quantities'=>'Selectează cel puțin o poziție și cantitatea returnată.']); }
            $header=$this->copyHeader($old); $now=$this->repository->now();
            $header=array_replace($header,['number'=>null,'temp_number'=>'DRAFT-RET-'.strtoupper(bin2hex(random_bytes(6))),'type'=>'supplier_return','status'=>'draft','row_version'=>1,'original_id'=>$id,'invoice_series'=>trim((string)($input['invoice_series'] ?? '')),'invoice_number'=>$invoiceNumber,'invoice_date'=>$invoiceDate,'nir_at'=>$now,'received_at'=>$now,'reason'=>$reason,'notes'=>trim((string)($input['notes'] ?? '')),'created_by'=>$this->actorId($actor),'updated_by'=>$this->actorId($actor),'created_at'=>$now,'updated_at'=>$now,'confirmed_at'=>null,'confirmed_by'=>null,'fingerprint'=>null]);
            foreach(self::MONEY as $field) { $header[$field]=NirDecimal::sum(array_column($newLines,$field)); }
            $newId=$this->repository->insert('nir_documents',$header);
            foreach($newLines as $line) { $line['nir_id']=$newId; $this->repository->insert('nir_lines',$line); }
            $this->appendAttachments($newId,(array)($input['attachments'] ?? []),$actor);
            $reverse=$this->load($newId); $fingerprint=$this->duplicates($reverse,(bool)($input['accept_warnings'] ?? false));
            foreach($reverse['lines'] as $line) {
                if(!$line['tracked']||$line['scope_type']===null) { continue; }
                $remaining=-(int)$line['stock_quantity_units']; $allocatedValue=0;
                foreach($allocations[$line['position']]['layers'] as $layer) {
                    if($remaining<=0) { break; } $take=min($remaining,(int)$layer['remaining_units']); if($take<=0) { continue; }
                    $value=$this->layerValue($layer,$take); $allocatedValue+=$value;
                    $this->repository->update('nir_fifo_layers',['remaining_units'=>(int)$layer['remaining_units']-$take,'remaining_value_bani'=>(int)$layer['remaining_value_bani']-$value],['id'=>$layer['id']]);
                    $remaining-=$take;
                }
                $before=$this->changeBalance($reverse,$line,(int)$line['stock_quantity_units'],true);
                $this->movement($reverse,$line,(int)$line['stock_quantity_units'],$before,'NIR_RETURN',$actor);
                $this->repository->update('nir_lines',['inventory_bani'=>-$allocatedValue],['id'=>$line['id']]);
                $this->recalculateCost((int)$reverse['warehouse_id'],$line);
            }
            $reverse=$this->load($newId); $number=$this->repository->nextNumber($now);
            $this->repository->update('nir_documents',['number'=>$number,'status'=>'confirmed','confirmed_by'=>$this->actorId($actor),'confirmed_at'=>$now,'row_version'=>2,'fingerprint'=>$fingerprint,'inventory_bani'=>NirDecimal::sum(array_column($reverse['lines'],'inventory_bani'))],['id'=>$newId]);
            $this->repository->update('nir_documents',['row_version'=>$version+1,'updated_at'=>$now],['id'=>$id]);
            $this->audit($newId,'supplier_return',$actor,null,$this->load($newId),['original_id'=>$id]); $this->event($newId,'nir.supplier_returned');
            $this->remember($key,'reverse',$hash,$id,$newId);
            return $this->document($newId,$actor);
        });
    }

    /** @param list<array<string,mixed>> $metadata @param array<string,mixed> $actor @return array<string,mixed> */
    public function attach(int $id,array $metadata,int $version,array $actor): array
    {
        $this->allow($actor,'edit'); return $this->repository->transaction(function()use($id,$metadata,$version,$actor):array {
            $document=$this->load($id); $this->version($document,$version); $this->appendAttachments($id,$metadata,$actor);
            $this->repository->update('nir_documents',['row_version'=>$version+1,'updated_at'=>$this->repository->now(),'updated_by'=>$this->actorId($actor)],['id'=>$id]);
            return $this->document($id,$actor);
        });
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function detach(int $id,int $attachmentId,int $version,array $actor): array
    {
        $this->allow($actor,'edit'); return $this->repository->transaction(function()use($id,$attachmentId,$version,$actor):array {
            $document=$this->load($id); $this->version($document,$version); $file=$this->repository->one('nir_attachments',['id'=>$attachmentId,'nir_id'=>$id]);
            if($file===null) { throw new NirNotFoundException('Atașamentul nu există.'); }
            $this->repository->delete('nir_attachments',['id'=>$attachmentId,'nir_id'=>$id]);
            $this->repository->update('nir_documents',['row_version'=>$version+1,'updated_at'=>$this->repository->now(),'updated_by'=>$this->actorId($actor)],['id'=>$id]);
            $this->audit($id,'attachment_deleted',$actor,$file,null); return $this->document($id,$actor);
        });
    }

    /** @param array<string,mixed> $actor @return list<array<string,mixed>> */
    public function selectProducts(array $actor): array
    {
        $this->allow($actor,'view'); return array_map(static fn(array $row):array=>array_intersect_key($row,array_flip(['product_id','variant_id','product_name','sku','option_summary','image_path'])),$this->repository->catalog());
    }

    /** @param array<string,mixed> $actor @return list<array<string,mixed>> */
    public function lookupProducts(string $search,int $supplierId,array $actor): array
    {
        $this->allow($actor,'view'); $catalog=$this->repository->catalog(); $q=NirRepository::normalize($search);
        if($q==='') { return array_slice($catalog,0,50); }
        $exact=array_values(array_filter($catalog,static fn(array $p):bool=>in_array($q,[NirRepository::normalize((string)$p['sku']),NirRepository::normalize((string)$p['primary_sku']),NirRepository::normalize((string)$p['product_name']),(string)$p['ean']],true)));
        if($exact!==[]) { return array_slice($exact,0,50); }
        $words=preg_split('/\s+/u',$q) ?: []; $matches=[];
        foreach($catalog as $product) {
            $haystack=NirRepository::normalize(implode(' ',array_map(static fn(string $field):string=>(string)($product[$field] ?? ''),['product_name','sku','primary_sku','ean','brand','category_name','option_summary'])));
            $score=0; foreach($words as $word) { if(str_contains($haystack,$word)) { $score++; } }
            if($score>0) { $product['score']=$score; $matches[]=$product; }
        }
        usort($matches,static fn(array $a,array $b):int=>$b['score']<=>$a['score']); return array_slice($matches,0,50);
    }

    /** @param array<string,mixed> $line @param array<string,mixed> $actor @return array<string,mixed> */
    public function matchLine(int $supplierId,array $line,array $actor): array
    {
        $this->allow($actor,'view'); $refs=$this->repository->all('nir_supplier_references',['supplier_id'=>$supplierId]);
        $code=NirRepository::normalize((string)($line['supplier_code'] ?? '')); $ean=trim((string)($line['supplier_ean'] ?? '')); $name=NirRepository::normalize((string)($line['supplier_name'] ?? ''));
        foreach([['normalized_code',$code,'supplier_code'],['supplier_ean',$ean,'supplier_ean'],['normalized_name',$name,'supplier_name']] as [$field,$needle,$source]) {
            if($needle==='') { continue; }
            $matches=array_values(array_filter($refs,static fn(array $ref):bool=>(string)$ref[$field]===$needle));
            if(count($matches)===1) { return ['matched'=>true,'source'=>$source,'product_id'=>(int)$matches[0]['product_id'],'variant_id'=>$matches[0]['variant_id'],'product'=>$this->product((int)$matches[0]['product_id'],(int)$matches[0]['variant_id']),'reference'=>$this->redact($matches[0],$actor),'suggestions'=>[]]; }
            if(count($matches)>1) { return $this->redact(['matched'=>false,'ambiguous'=>true,'suggestions'=>$matches],$actor); }
        }
        $catalog=$this->repository->catalog();
        foreach([['ean',$ean,'internal_ean'],['sku',NirRepository::normalize((string)($line['internal_sku'] ?? '')),'internal_sku'],['product_name',$name,'internal_name']] as [$field,$needle,$source]) {
            if($needle==='') { continue; }
            $matches=array_values(array_filter($catalog,static fn(array $product):bool=>NirRepository::normalize((string)($product[$field] ?? ''))===$needle));
            if(count($matches)===1) { return ['matched'=>true,'source'=>$source,'product_id'=>$matches[0]['product_id'],'variant_id'=>$matches[0]['variant_id'],'product'=>$matches[0],'suggestions'=>[]]; }
            if(count($matches)>1) { return ['matched'=>false,'ambiguous'=>true,'suggestions'=>array_slice($matches,0,20)]; }
        }
        return ['matched'=>false,'source'=>'suggestions','suggestions'=>$this->lookupProducts((string)($line['supplier_name'] ?? ''),$supplierId,$actor)];
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function reassignReference(int $referenceId,array $input,array $actor): array
    {
        $this->allow($actor,'references'); return $this->repository->transaction(function()use($referenceId,$input,$actor):array {
            $ref=$this->repository->one('nir_supplier_references',['id'=>$referenceId]); if($ref===null) { throw new NirNotFoundException('Referința nu există.'); }
            $reason=trim((string)($input['reason'] ?? '')); if($reason==='') { throw new NirValidationException(['reason'=>'Explică motivul reasocierii.']); }
            $product=$this->product((int)($input['product_id'] ?? 0),(int)($input['variant_id'] ?? 0));
            if($product===null) { throw new NirValidationException(['product_id'=>'Selectează produsul intern.']); }
            $values=['product_id'=>$product['product_id'],'variant_id'=>$product['variant_id'],'updated_at'=>$this->repository->now()];
            if((int)$ref['product_id']!==(int)$product['product_id']||(int)$ref['variant_id']!==(int)$product['variant_id']) { $values+=['last_price'=>null,'last_currency'=>null,'last_received_at'=>null]; }
            $this->repository->update('nir_supplier_references',$values,['id'=>$referenceId]);
            $next=$this->repository->one('nir_supplier_references',['id'=>$referenceId]) ?? [];
            $this->audit(null,'reference_reassigned',$actor,$ref,$next,['reason'=>$reason]); return $next;
        });
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function saveLocation(int $warehouseId,array $input,array $actor): array
    {
        $this->allow($actor,'edit');
        $warehouse=$this->repository->one('company_warehouses',['id'=>$warehouseId]) ?? throw new NirNotFoundException('Gestiunea nu există.');
        try {
            return $this->locations->save((int) $warehouse['company_id'], array_replace($input,['warehouse_id'=>$warehouseId]));
        } catch (\App\Modules\Companies\CompanyValidationException $e) { throw new NirValidationException($e->errors); }
    }

    /** @param array<string,mixed> $filters @param array<string,mixed> $actor @return array<string,mixed> */
    public function fifo(array $filters,array $actor): array
    {
        $this->allow($actor,'fifo'); $where=[];
        if((int)($filters['warehouse_id'] ?? 0)>0) { $where['warehouse_id']=(int)$filters['warehouse_id']; }
        $balances=$this->repository->all('nir_stock_balances',$where); $layers=$this->repository->all('nir_fifo_layers',$where,'received_at,created_at,id'); $reconciliation=[];
        foreach($balances as &$balance) {
            $layerUnits=0; $value=0;
            foreach($layers as $layer) { if($layer['scope_type']===$balance['scope_type']&&(int)$layer['scope_id']===(int)$balance['scope_id']&&(int)$layer['warehouse_id']===(int)$balance['warehouse_id']) { $layerUnits+=(int)$layer['remaining_units']; $value+=(int)$layer['remaining_value_bani']; } }
            $balance['quantity']=NirDecimal::quantity((int)$balance['quantity_units']); $balance['value_bani']=$value;
            $reconciliation[]=['warehouse_id'=>$balance['warehouse_id'],'product_id'=>$balance['product_id'],'variant_id'=>$balance['variant_id'],'sku'=>$balance['sku'],'balance_units'=>(int)$balance['quantity_units'],'fifo_units'=>$layerUnits,'difference_units'=>(int)$balance['quantity_units']-$layerUnits,'balanced'=>(int)$balance['quantity_units']===$layerUnits];
        } unset($balance);
        foreach($layers as &$layer) {
            $line=$this->repository->one('nir_lines',['id'=>$layer['line_id']]) ?? []; $document=$this->repository->one('nir_documents',['id'=>$layer['nir_id']]) ?? [];
            $supplier=json_decode((string)($document['supplier_snapshot'] ?? '{}'),true) ?: [];
            $layer['supplier_alias']=trim((string)($supplier['alias'] ?? '')) ?: (trim((string)($supplier['company_name'] ?? '')) ?: match($document['type'] ?? '') {'opening_balance'=>'Sold inițial','customer_return'=>'Retur client',default=>'Furnizor indisponibil'});
            $layer['operation_type']=$document['type'] ?? 'supplier_receipt';
            $layer+=['product_name'=>$line['product_name'] ?? '', 'sku'=>$line['sku'] ?? '', 'supplier_name'=>$line['supplier_name'] ?? '', 'supplier_code'=>$line['supplier_code'] ?? '', 'invoice_number'=>$document['invoice_number'] ?? '', 'number'=>$document['number'] ?? '', 'currency'=>$document['currency'] ?? 'RON', 'exchange_rate'=>$document['exchange_rate'] ?? '1'];
            $layer['initial_quantity']=NirDecimal::quantity((int)$layer['initial_units']); $layer['remaining_quantity']=NirDecimal::quantity((int)$layer['remaining_units']);
            $layer['consumptions']=$this->repository->all('nir_fifo_consumptions',['layer_id'=>$layer['id']]);
        } unset($layer);
        return $this->redact(['balances'=>$balances,'layers'=>$layers,'movements'=>$this->repository->all('nir_stock_movements',$where,'id DESC'),'pending'=>$this->repository->all('nir_pending_consumptions',$where),'reconciliation'=>$reconciliation,'references'=>$this->repository->all('nir_supplier_references',[],'supplier_id,normalized_code'),'permissions'=>$this->permissions($actor)],$actor);
    }

    /** Separate, audited opening stock; the operator supplies an actual acquisition cost. @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function opening(array $input,string $key,array $actor): array
    {
        $this->allow($actor,'opening');
        return $this->repository->transaction(function()use($input,$key,$actor):array {
            $hash=$this->requestHash('opening',$input); if(($previous=$this->retry($key,'opening',$hash))!==null) { return $this->document($previous,$actor); }
            $reason=trim((string)($input['reason'] ?? '')); if($reason==='') { throw new NirValidationException(['reason'=>'Explică proveniența soldului inițial.']); }
            $quantity=NirDecimal::number($input['quantity'] ?? '0',4,'quantity'); $cost=NirDecimal::number($input['unit_cost_ron'] ?? '0',6,'unit_cost_ron');
            if(!$quantity->isPositive()||!$cost->isPositive()) { throw new NirValidationException(['quantity'=>'Cantitatea și costul real de intrare trebuie să fie pozitive.']); }
            $warehouse=$this->repository->one('company_warehouses',['id'=>(int)($input['warehouse_id'] ?? 0),'is_active'=>1]);
            if($warehouse===null) { throw new NirValidationException(['warehouse_id'=>'Selectează o gestiune activă.']); }
            $this->ensureLocations(); $location=$this->repository->all('nir_locations',['warehouse_id'=>$warehouse['id'],'is_active'=>1],'is_default DESC,id')[0] ?? null;
            if($location===null) { throw new NirValidationException(['location_id'=>'Adaugă sau activează o locație în gestiune înainte de înregistrarea soldului inițial.']); }
            $date=$this->date((string)($input['date'] ?? gmdate('Y-m-d')),'date');
            $raw=['warehouse_id'=>$warehouse['id'],'location_id'=>$location['id'] ?? null,'currency'=>'RON','exchange_rate'=>'1','exchange_rate_date'=>$date,'invoice_date'=>$date,'invoice_number'=>'SOLD-'.substr(hash('sha256',$key),0,12),'nir_at'=>$date.'T12:00','received_at'=>$date.'T12:00','notes'=>$reason,'external_id'=>$key,'lines'=>[array_replace($this->emptyLine(),['product_id'=>$input['product_id'] ?? 0,'variant_id'=>$input['variant_id'] ?? 0,'supplier_name'=>'Sold inițial','invoice_quantity'=>(string)$quantity,'received_quantity'=>(string)$quantity,'accepted_quantity'=>(string)$quantity,'unit_price'=>(string)$cost,'vat_percent'=>'0'])]];
            [$header,$lines]=$this->prepare($raw,null); if($lines===[]||$lines[0]['scope_type']===null) { throw new NirValidationException(['product_id'=>'Selectează un produs urmărit contabil.']); }
            $now=$this->repository->now(); $header+=['temp_number'=>'DRAFT-SOLD-'.bin2hex(random_bytes(6)),'type'=>'opening_balance','status'=>'draft','row_version'=>1,'reason'=>$reason,'created_by'=>$this->actorId($actor),'updated_by'=>$this->actorId($actor),'created_at'=>$now,'updated_at'=>$now];
            $id=$this->repository->insert('nir_documents',$header); $this->persistLines($id,$lines,$actor,0); $this->post($this->load($id),$actor);
            $this->audit($id,'opening_balance',$actor,null,$this->load($id)); $this->remember($key,'opening',$hash,$id,$id); return $this->document($id,$actor);
        });
    }

    /** Hook for posted outgoing invoices only; checkout, payment and commercial status do not call this method.
     * @param array<string,mixed> $target @param array<string,mixed> $actor
     */
    public function consume(int $warehouseId,array $target,int $quantityUnits,string $sourceType,int $sourceId,string $key,array $actor): void
    {
        $this->allow($actor,'confirm');
        if($quantityUnits<=0||$sourceId<=0||!in_array($sourceType,['order_item','invoice_line'],true)) { throw new NirValidationException(['quantity'=>'Consumul trebuie să provină dintr-o factură emisă, cu o cantitate pozitivă.']); }
        $this->repository->transaction(function()use($warehouseId,$target,$quantityUnits,$sourceType,$sourceId,$key,$actor):void {
            $hash=$this->requestHash('consume',[$warehouseId,$target,$quantityUnits,$sourceType,$sourceId]); $request=$this->repository->one('nir_consumption_requests',['request_key'=>$key]);
            if($request!==null) { if($request['request_hash']!==$hash) { throw new NirConflictException('Cheia consumului a fost folosită pentru altă factură.'); } return; }
            if($this->repository->one('company_warehouses',['id'=>$warehouseId,'is_active'=>1])===null) { throw new NirValidationException(['warehouse_id'=>'Gestiunea facturii nu este activă.']); }
            $remaining=$this->allocateConsumption($warehouseId,$target,$quantityUnits,$sourceType,$sourceId,$key,$actor);
            if($remaining>0) { $this->repository->insert('nir_pending_consumptions',['event_key'=>$key,'warehouse_id'=>$warehouseId,'scope_type'=>$target['scope_type'],'scope_id'=>$target['scope_id'],'product_id'=>$target['product_id'],'variant_id'=>$target['variant_id'] ?? null,'sku'=>$target['sku'],'source_type'=>$sourceType,'source_id'=>$sourceId,'quantity_units'=>$remaining,'created_at'=>$this->repository->now()]); }
            $this->repository->insert('nir_consumption_requests',['request_key'=>$key,'request_hash'=>$hash,'created_at'=>$this->repository->now()]);
        });
    }

    /** @param array<string,mixed> $actor @return list<array<string,mixed>> */
    public function pendingReturnReceipts(array $actor): array
    {
        $this->allow($actor,'view'); $today=(new DateTimeImmutable('now',new DateTimeZone('Europe/Bucharest')))->format('Y-m-d'); $pending=[];
        foreach($this->repository->all('order_returns',[],'id DESC') as $return) {
            if($this->repository->one('nir_documents',['return_id'=>$return['id']])!==null) { continue; }
            $reminder=$this->repository->one('nir_return_reminders',['return_id'=>$return['id'],'actor_id'=>$this->actorId($actor)]);
            $order=$this->repository->one('orders',['id'=>$return['order_id']]) ?? []; $items=[];
            foreach($this->repository->all('order_return_items',['return_id'=>$return['id']]) as $item) {
                $ordered=$this->repository->one('order_items',['id'=>$item['order_item_id']]); if($ordered===null || $ordered['accounting_scope_type']===null) { continue; }
                $consumptions=$this->repository->all('nir_fifo_consumptions',['source_type'=>'order_item','source_id'=>$item['order_item_id']]);
                $traceable=NirDecimal::sum(array_map(static fn(array $row):int=>(int)$row['quantity_units']-(int)$row['returned_units'],$consumptions));
                $items[]=array_replace($ordered,$item,['product_name'=>$ordered['product_name'] ?? $ordered['name'] ?? '', 'traceable_quantity'=>NirDecimal::quantity($traceable),'eligible'=>$traceable>=(int)$item['quantity']*10000]);
            }
            if($items===[]) { continue; }
            $pending[]=array_replace($return,['return_id'=>$return['id'],'order_number'=>$order['number'] ?? $order['order_number'] ?? (string)$return['order_id'],'items'=>$items,'dismissed'=>$reminder!==null&&$reminder['dismissed_on']===$today,'eligible'=>!in_array(false,array_column($items,'eligible'),true),'requires_return_invoice'=>true]);
        } return $this->permissions($actor)['costs']?$pending:array_map(fn(array $row):array=>$this->redact($row,$actor),$pending);
    }

    /** @param array<string,mixed> $actor */
    public function dismissReturnReminder(int $returnId,array $actor): void
    {
        $this->allow($actor,'view'); $this->repository->transaction(function()use($returnId,$actor):void {
            if($this->repository->one('order_returns',['id'=>$returnId])===null) { throw new NirNotFoundException('Returul nu există.'); }
            $where=['return_id'=>$returnId,'actor_id'=>$this->actorId($actor)]; $value=['dismissed_on'=>(new DateTimeImmutable('now',new DateTimeZone('Europe/Bucharest')))->format('Y-m-d')];
            if($this->repository->one('nir_return_reminders',$where)===null) { $this->repository->insert('nir_return_reminders',$where+$value); } else { $this->repository->update('nir_return_reminders',$value,$where); }
        });
    }

    /** Requires the real corrective invoice and physical receipt; unknown original cost never becomes a zero-cost layer.
     * @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed>
     */
    public function receiveReturn(int $returnId,array $input,string $key,array $actor): array
    {
        $this->allow($actor,'confirm');
        return $this->repository->transaction(function()use($returnId,$input,$key,$actor):array {
            $hash=$this->requestHash('customer_return',[$returnId,$input]); if(($previous=$this->retry($key,'customer_return',$hash))!==null) { return $this->document($previous,$actor); }
            $return=$this->repository->one('order_returns',['id'=>$returnId]); if($return===null) { throw new NirNotFoundException('Returul nu există.'); }
            if($this->repository->one('nir_documents',['return_id'=>$returnId])!==null) { throw new NirConflictException('Returul are deja un NIR de recepție.'); }
            if(!$this->truth($input['physically_received'] ?? false)) { throw new NirValidationException(['physically_received'=>'Confirmă recepția fizică a produselor.']); }
            $invoice=trim((string)($input['invoice_number'] ?? '')); if($invoice==='') { throw new NirValidationException(['invoice_number'=>'Completează numărul facturii de retur emise.']); }
            $invoiceDate=$this->date((string)($input['invoice_date'] ?? ''),'invoice_date');
            $warehouse=$this->repository->one('company_warehouses',['id'=>(int)($input['warehouse_id'] ?? 0),'is_active'=>1]); if($warehouse===null) { throw new NirValidationException(['warehouse_id'=>'Selectează gestiunea de recepție.']); }
            $order=$this->repository->one('orders',['id'=>$return['order_id']]) ?? []; $plans=[]; $lines=[];
            foreach($this->repository->all('order_return_items',['return_id'=>$returnId]) as $item) {
                $ordered=$this->repository->one('order_items',['id'=>$item['order_item_id']]); if($ordered===null||$ordered['accounting_scope_type']===null) { continue; }
                $remaining=(int)$item['quantity']*10000; $cost=0; $parts=[]; $originalLine=null;
                foreach($this->repository->all('nir_fifo_consumptions',['source_type'=>'order_item','source_id'=>$item['order_item_id']],'id') as $consumption) {
                    if($remaining<=0) { break; } $available=(int)$consumption['quantity_units']-(int)$consumption['returned_units']; $take=min($remaining,$available); if($take<=0) { continue; }
                    $layer=$this->repository->one('nir_fifo_layers',['id'=>$consumption['layer_id']]); if($layer===null) { throw new NirConflictException('Lipsește lotul de stoc original.'); }
                    $originalLine ??= $this->repository->one('nir_lines',['id'=>$layer['line_id']]);
                    $value=$take===$available ? (int)$consumption['value_bani']-(int)$consumption['returned_value_bani'] : BigDecimal::of((int)$consumption['value_bani'])->multipliedBy($take)->dividedBy((int)$consumption['quantity_units'],0,RoundingMode::HalfUp)->toInt();
                    $parts[]=['consumption'=>$consumption,'layer'=>$layer,'units'=>$take,'value'=>$value]; $cost+=$value; $remaining-=$take;
                }
                if($remaining>0||$originalLine===null) { throw new NirValidationException(['return_id'=>'Costurile de intrare originale nu acoperă returul. Emite/reconciliază întâi factura de ieșire; nu se creează costuri fictive.']); }
                $units=(int)$item['quantity']*10000; $quantity=NirDecimal::quantity($units); $line=$originalLine;
                unset($line['id'],$line['nir_id']); $line['position']=count($lines)+1; $line['original_line_id']=null; $line['return_item_id']=$item['id']; $line['save_reference']=0;
                $line['invoice_quantity']=$quantity; $line['received_quantity']=$quantity; $line['accepted_quantity']=$quantity; $line['rejected_quantity']='0.0000'; $line['conversion_factor']='1.0000'; $line['stock_quantity']=$quantity; $line['stock_quantity_units']=$units;
                $line['unit_cost_ron']=(string)NirDecimal::money($cost)->dividedBy(BigDecimal::of($quantity),6,RoundingMode::HalfUp); $line['unit_price']=$line['unit_cost_ron']; $line['price_mode']='exact_vat'; $line['net_override']=(string)NirDecimal::money($cost); $line['tax_override']='0.00'; $line['gross_override']=''; $line['vat_percent']='0'; $line['discount_percent']='0'; $line['extra_cost_ron']='0';
                foreach(self::MONEY as $field) { $line[$field]=in_array($field,['tax_bani','tax_ron_bani','discount_bani'],true)?0:$cost; }
                $lines[]=$line; $plans[$line['position']]=$parts;
            }
            if($lines===[]) { throw new NirValidationException(['return_id'=>'Returul nu conține produse urmărite contabil. Nu este necesar un NIR gol.']); }
            $raw=array_replace($input,['warehouse_id'=>$warehouse['id'],'invoice_number'=>$invoice,'invoice_date'=>$invoiceDate,'currency'=>'RON','exchange_rate'=>'1','exchange_rate_date'=>$invoiceDate,'nir_at'=>$input['nir_at'] ?? $this->repository->now(),'received_at'=>$input['received_at'] ?? $this->repository->now(),'lines'=>[]]);
            [$header]=$this->prepare($raw,null);
            if($header['location_id']===null||$this->repository->one('nir_locations',['id'=>$header['location_id'],'warehouse_id'=>$warehouse['id'],'is_active'=>1])===null) { throw new NirValidationException(['location_id'=>'Selectează o locație activă pentru recepția fizică.']); }
            $now=$this->repository->now(); $header+=['temp_number'=>'DRAFT-RET-CLIENT-'.bin2hex(random_bytes(5)),'type'=>'customer_return','status'=>'confirmed','row_version'=>1,'reason'=>$return['reason'],'return_id'=>$returnId,'order_id'=>$return['order_id'],'created_by'=>$this->actorId($actor),'updated_by'=>$this->actorId($actor),'confirmed_by'=>$this->actorId($actor),'created_at'=>$now,'updated_at'=>$now,'confirmed_at'=>$now,'number'=>$this->repository->nextNumber($header['nir_at'])];
            $header['supplier_snapshot']=$this->json(['alias'=>trim((string)($order['first_name'] ?? '').' '.($order['last_name'] ?? '')),'company_name'=>'Retur client','email'=>$order['email'] ?? '']);
            foreach(self::MONEY as $field) { $header[$field]=NirDecimal::sum(array_column($lines,$field)); }
            $id=$this->repository->insert('nir_documents',$header);
            foreach($lines as $line) {
                $line['nir_id']=$id; $lineId=$this->repository->insert('nir_lines',$line); $line['id']=$lineId;
                $document=$header+['id'=>$id]; $before=$this->changeBalance($document,$line,(int)$line['stock_quantity_units']); $this->movement($document,$line,(int)$line['stock_quantity_units'],$before,'NIR_CUSTOMER_RETURN',$actor);
                foreach($plans[$line['position']] as $part) {
                    $this->repository->insert('nir_fifo_layers',['nir_id'=>$id,'line_id'=>$lineId,'warehouse_id'=>$warehouse['id'],'scope_type'=>$line['scope_type'],'scope_id'=>$line['scope_id'],'initial_units'=>$part['units'],'remaining_units'=>$part['units'],'initial_value_bani'=>$part['value'],'remaining_value_bani'=>$part['value'],'unit_cost_ron'=>$part['layer']['unit_cost_ron'],'received_at'=>$header['received_at'],'created_at'=>$now,'original_layer_id'=>$part['layer']['id']]);
                    $this->repository->update('nir_fifo_consumptions',['returned_units'=>(int)$part['consumption']['returned_units']+$part['units'],'returned_value_bani'=>(int)$part['consumption']['returned_value_bani']+$part['value']],['id'=>$part['consumption']['id']]);
                }
                $this->reconcilePending((int)$warehouse['id'],$line,$actor);
                $this->recalculateCost((int)$warehouse['id'],$line);
            }
            $this->appendAttachments($id,(array)($input['attachments'] ?? []),$actor);
            $this->repository->update('nir_documents',['fingerprint'=>$this->duplicates($this->load($id),(bool)($input['accept_warnings'] ?? false))],['id'=>$id]);
            $this->audit($id,'customer_return_received',$actor,null,$this->load($id),['return_id'=>$returnId]); $this->event($id,'nir.customer_return_received'); $this->remember($key,'customer_return',$hash,$id,$id); return $this->document($id,$actor);
        });
    }

    /** @param array<string,mixed> $document @param array<string,mixed> $line */
    private function changeBalance(array $document,array $line,int $delta,bool $nonnegative=false): int
    {
        $where=['warehouse_id'=>$document['warehouse_id'],'scope_type'=>$line['scope_type'],'scope_id'=>$line['scope_id']]; $balance=$this->repository->one('nir_stock_balances',$where); $before=(int)($balance['quantity_units'] ?? 0); $after=$before+$delta;
        if($nonnegative&&$after<0) { throw new NirConflictException('Operațiunea ar produce un stoc contabil negativ.'); }
        $values=['quantity_units'=>$after,'updated_at'=>$this->repository->now()];
        if($balance===null) { $this->repository->insert('nir_stock_balances',$where+$values+['product_id'=>$line['product_id'],'variant_id'=>$line['scope_type']==='variant'?$line['variant_id']:null,'sku'=>$line['sku'],'unit_cost_ron'=>$line['unit_cost_ron'] ?? '0']); }
        else { $this->repository->update('nir_stock_balances',$values,['id'=>$balance['id']]); }
        return $before;
    }
    /** @param array<string,mixed> $document @param array<string,mixed> $line @param array<string,mixed> $actor */
    private function movement(array $document,array $line,int $delta,int $before,string $type,array $actor,?string $key=null,string $sourceType='nir',?int $sourceId=null): void
    {
        $this->repository->insert('nir_stock_movements',['nir_id'=>$document['id'] ?? null,'line_id'=>$line['id'] ?? null,'warehouse_id'=>$document['warehouse_id'],'scope_type'=>$line['scope_type'],'scope_id'=>$line['scope_id'],'type'=>$type,'quantity_units'=>$delta,'before_units'=>$before,'after_units'=>$before+$delta,'event_key'=>$key ?? $type.':'.$document['id'].':'.$line['id'],'source_type'=>$sourceType,'source_id'=>$sourceId ?? $document['id'] ?? null,'actor_id'=>$this->actorId($actor),'created_at'=>$this->repository->now()]);
    }
    /** @param array<string,mixed> $layer */
    private function layerValue(array $layer,int $units): int
    {
        return $units===(int)$layer['remaining_units'] ? (int)$layer['remaining_value_bani'] : BigDecimal::of((int)$layer['remaining_value_bani'])->multipliedBy($units)->dividedBy((int)$layer['remaining_units'],0,RoundingMode::HalfUp)->toInt();
    }
    /** @param array<string,mixed> $target @param array<string,mixed> $actor */
    private function allocateConsumption(int $warehouseId,array $target,int $quantity,string $sourceType,int $sourceId,string $key,array $actor): int
    {
        $remaining=$quantity; $where=['warehouse_id'=>$warehouseId,'scope_type'=>$target['scope_type'],'scope_id'=>$target['scope_id']];
        foreach($this->repository->all('nir_fifo_layers',$where,'received_at,created_at,id') as $layer) {
            if($remaining<=0) { break; } $take=min($remaining,(int)$layer['remaining_units']); if($take<=0) { continue; }
            $value=$this->layerValue($layer,$take); $this->repository->update('nir_fifo_layers',['remaining_units'=>(int)$layer['remaining_units']-$take,'remaining_value_bani'=>(int)$layer['remaining_value_bani']-$value],['id'=>$layer['id']]);
            $this->repository->insert('nir_fifo_consumptions',['layer_id'=>$layer['id'],'source_type'=>$sourceType,'source_id'=>$sourceId,'quantity_units'=>$take,'value_bani'=>$value,'created_at'=>$this->repository->now()]);
            $before=$this->changeBalance(['warehouse_id'=>$warehouseId],$target,-$take,true);
            $this->movement(['warehouse_id'=>$warehouseId],$target,-$take,$before,'INVOICE_OUT',$actor,'sale:'.hash('sha256',$key).':'.$layer['id'],$sourceType,$sourceId);
            $remaining-=$take;
        }
        $this->recalculateCost($warehouseId,$target); return $remaining;
    }
    /** @param array<string,mixed> $line @param array<string,mixed> $actor */
    private function reconcilePending(int $warehouseId,array $line,array $actor): void
    {
        foreach($this->repository->all('nir_pending_consumptions',['warehouse_id'=>$warehouseId,'scope_type'=>$line['scope_type'],'scope_id'=>$line['scope_id']],'created_at,id') as $pending) {
            $remaining=$this->allocateConsumption($warehouseId,$line,(int)$pending['quantity_units'],$pending['source_type'],(int)$pending['source_id'],$pending['event_key'],$actor);
            if($remaining===0) { $this->repository->delete('nir_pending_consumptions',['id'=>$pending['id']]); } else { $this->repository->update('nir_pending_consumptions',['quantity_units'=>$remaining],['id'=>$pending['id']]); }
        }
    }
    /** @param array<string,mixed> $line */
    private function recalculateCost(int $warehouseId,array $line): void
    {
        $where=['warehouse_id'=>$warehouseId,'scope_type'=>$line['scope_type'],'scope_id'=>$line['scope_id']]; $layers=$this->repository->all('nir_fifo_layers',$where); $units=NirDecimal::sum(array_column($layers,'remaining_units')); $value=NirDecimal::sum(array_column($layers,'remaining_value_bani'));
        $cost=$units>0 ? (string)NirDecimal::money($value)->dividedBy(BigDecimal::of(NirDecimal::quantity($units)),6,RoundingMode::HalfUp) : '0.000000';
        if($this->repository->one('nir_stock_balances',$where)!==null) { $this->repository->update('nir_stock_balances',['unit_cost_ron'=>$cost,'updated_at'=>$this->repository->now()],$where); }
    }

    /** @param array<string,mixed> $input @param array<string,mixed>|null $old @return array{array<string,mixed>,list<array<string,mixed>>} */
    private function prepare(array $input,?array $old): array
    {
        $warehouseId=(int)($input['warehouse_id'] ?? 0); $warehouse=$warehouseId>0?$this->repository->one('company_warehouses',['id'=>$warehouseId]):null;
        $company=$warehouse===null?($this->repository->all('companies',[],'is_default DESC,id')[0] ?? null):$this->repository->one('companies',['id'=>$warehouse['company_id']]);
        $supplierId=(int)($input['supplier_id'] ?? 0); $supplier=$supplierId>0?$this->repository->one('suppliers',['id'=>$supplierId]):null;
        $locationId=(int)($input['location_id'] ?? 0); $location=$locationId>0?$this->repository->one('nir_locations',['id'=>$locationId]):null;
        if($supplierId>0 && $supplier===null) { throw new NirValidationException(['supplier_id'=>'Furnizorul selectat nu există.']); }
        if($warehouseId>0 && $warehouse===null) { throw new NirValidationException(['warehouse_id'=>'Gestiunea selectată nu există.']); }
        if($locationId>0 && ($location===null || (int)$location['warehouse_id']!==$warehouseId)) { throw new NirValidationException(['location_id'=>'Locația nu aparține gestiunii selectate.']); }
        $currency=strtoupper(trim((string)($input['currency'] ?? 'RON'))); if(!preg_match('/^[A-Z]{3}$/D',$currency)) { throw new NirValidationException(['currency'=>'Moneda trebuie să conțină 3 litere (RON, EUR etc.).']); }
        $rate=NirDecimal::number($currency==='RON'?'1':($input['exchange_rate'] ?? '1'),8,'exchange_rate'); if(!$rate->isPositive()) { throw new NirValidationException(['exchange_rate'=>'Cursul trebuie să fie mai mare decât zero.']); }
        $header=['supplier_id'=>$supplierId ?: null,'warehouse_id'=>$warehouseId ?: null,'company_id'=>$company['id'] ?? null,'location_id'=>$locationId ?: null,'currency'=>$currency,'exchange_rate'=>(string)$rate,'vat_in_cost'=>(int)(bool)($input['vat_in_cost'] ?? $old['vat_in_cost'] ?? false)];
        foreach(['invoice_series'=>100,'invoice_number'=>190,'external_id'=>190,'notes'=>10000] as $field=>$max) { $header[$field]=mb_substr(trim((string)($input[$field] ?? '')),0,$max); }
        foreach(['invoice_date','exchange_rate_date'] as $field) { $value=trim((string)($input[$field] ?? '')); $header[$field]=$value===''?'':$this->date($value,$field); }
        foreach(['nir','received'] as $field) { $value=trim((string)($input[$field.'_at'] ?? '')); if($value==='' && isset($input[$field.'_date'])) { $value=$input[$field.'_date'].'T'.($input[$field.'_time'] ?? ''); } $header[$field.'_at']=$value===''?'':$this->instant($value,$field.'_at'); }
        foreach(['supplier'=>$supplier,'company'=>$company,'warehouse'=>$warehouse,'location'=>$location] as $key=>$snapshot) { $header[$key.'_snapshot']=$this->json($snapshot ?? []); }
        $inputLines=$input['lines'] ?? []; if(!is_array($inputLines)||count($inputLines)>1000) { throw new NirValidationException(['lines'=>'Un NIR poate conține maximum 1.000 de poziții.']); }
        $lines=[];
        foreach($inputLines as $index=>$raw) {
            if(!is_array($raw)) { continue; }
            if(trim((string)($raw['supplier_name'] ?? ''))==='' && (int)($raw['product_id'] ?? 0)===0 && (int)($raw['variant_id'] ?? 0)===0) { continue; }
            $raw=array_replace($this->emptyLine(),$raw); $line=[];
            foreach(['supplier_code'=>190,'supplier_name'=>1000,'supplier_ean'=>100,'purchase_unit'=>30,'stock_unit'=>30,'difference_reason'=>50,'difference_notes'=>3000] as $field=>$limit) { $line[$field]=mb_substr(trim((string)($raw[$field] ?? '')),0,$limit); }
            foreach(['invoice_quantity','received_quantity','accepted_quantity','rejected_quantity','conversion_factor','price_mode','unit_price','discount_percent','vat_percent','extra_cost_ron','net_override','tax_override','gross_override'] as $field) { $line[$field]=$raw[$field] ?? ''; }
            if($line['price_mode']==='exact_tax') { $line['price_mode']='exact_vat'; }
            $product=$this->product((int)($raw['product_id'] ?? 0),(int)($raw['variant_id'] ?? 0));
            if(((int)($raw['product_id'] ?? 0)>0||(int)($raw['variant_id'] ?? 0)>0)&&$product===null) { throw new NirValidationException(['lines.'.$index.'.product_id'=>'Produsul sau varianta nu există.']); }
            $line+=['product_id'=>$product['product_id'] ?? null,'variant_id'=>$product['variant_id'] ?? null,'scope_type'=>null,'scope_id'=>null,'tracked'=>(int)$this->truth($raw['tracked'] ?? true),'save_reference'=>(int)$this->truth($raw['save_reference'] ?? false),'position'=>count($lines)+1];
            foreach(['product_name','sku','image_path','category_name','brand'] as $field) { $line[$field]=(string)($product[$field] ?? ''); }
            if($product!==null) {
                $options=json_decode((string)($product['options_json'] ?? '{}'),true); $variantTracked=(int)$product['variant_track']===1 && is_array($options)&&$options!==[];
                if($variantTracked && $line['tracked']) { $line['scope_type']='variant'; $line['scope_id']=$product['variant_id']; }
                elseif((int)$product['product_track']===1 && $line['tracked']) { $line['scope_type']='product'; $line['scope_id']=$product['product_id']; $line['sku']=(string)$product['primary_sku']; }
                else { $line['tracked']=0; }
            }
            $lines[]=NirDecimal::line($line,(string)$rate,(bool)$header['vat_in_cost'],'lines.'.$index);
        }
        foreach(self::MONEY as $field) { $header[$field]=NirDecimal::sum(array_column($lines,$field)); }
        return [$header,$lines];
    }

    /** @return array<string,mixed> */
    private function emptyLine(): array { return ['supplier_code'=>'','supplier_name'=>'','supplier_ean'=>'','product_id'=>'','variant_id'=>'','invoice_quantity'=>'1','received_quantity'=>'1','accepted_quantity'=>'1','rejected_quantity'=>'0','purchase_unit'=>'buc','stock_unit'=>'buc','conversion_factor'=>'1','unit_price'=>'0','discount_percent'=>'0','vat_percent'=>'21','extra_cost_ron'=>'0','price_mode'=>'unit','net_override'=>'','tax_override'=>'','gross_override'=>'','difference_reason'=>'','difference_notes'=>'','save_reference'=>false,'tracked'=>true]; }

    /** @return array<string,mixed>|null */
    private function product(int $productId,int $variantId): ?array
    {
        foreach($this->repository->catalog() as $product) { if(($productId===0 || (int)$product['product_id']===$productId) && ($variantId===0 || (int)$product['variant_id']===$variantId)) { if($productId>0||$variantId>0) { return $product; } } } return null;
    }

    /** @param array<string,mixed> $document */
    private function validate(array $document,bool $acceptWarnings): void
    {
        $errors=[]; $warnings=[];
        if(!$document['supplier_id']) { $errors['supplier_id']='Selectează furnizorul.'; }
        if(!$document['warehouse_id']) { $errors['warehouse_id']='Selectează gestiunea.'; }
        if(!$document['location_id']) { $errors['location_id']='Selectează locația de recepție.'; }
        foreach(['invoice_number'=>'numărul facturii','invoice_date'=>'data facturii','nir_at'=>'data și ora NIR','received_at'=>'data și ora recepției'] as $key=>$label) { if(trim((string)$document[$key])==='') { $errors[$key]='Completează '.$label.'.'; } }
        if($document['currency']!=='RON' && $document['exchange_rate_date']==='') { $errors['exchange_rate_date']='Completează data cursului valutar.'; }
        if($document['lines']===[]) { $errors['lines']='Adaugă cel puțin o poziție.'; }
        $supplier=$document['supplier_id']?$this->repository->one('suppliers',['id'=>$document['supplier_id']]):null;
        $warehouse=$document['warehouse_id']?$this->repository->one('company_warehouses',['id'=>$document['warehouse_id']]):null;
        $location=$document['location_id']?$this->repository->one('nir_locations',['id'=>$document['location_id']]):null;
        if($supplier!==null && $supplier['status']!=='active') { $errors['supplier_id']='Furnizorul este inactiv.'; }
        if($warehouse!==null && !(bool)$warehouse['is_active']) { $errors['warehouse_id']='Gestiunea este inactivă.'; }
        if($location!==null && (!(bool)$location['is_active']||(int)$location['warehouse_id']!==(int)$document['warehouse_id'])) { $errors['location_id']='Locația nu este activă în gestiunea aleasă.'; }
        foreach($document['lines'] as $index=>$line) {
            $prefix='lines.'.$index; $label='Poziția '.($index+1).': ';
            if($line['tracked'] && ($line['product_id']===null||$line['scope_type']===null)) { $errors[$prefix.'.product_id']='Asociază produsul intern.'; }
            if($line['tracked'] && trim((string)$line['sku'])==='') { $errors[$prefix.'.product_id']='Produsul urmărit contabil trebuie să aibă SKU.'; }
            if($line['tracked'] && $line['product_id']!==null) {
                $catalog=$this->product((int)$line['product_id'],(int)$line['variant_id']);
                $options=json_decode((string)($catalog['options_json'] ?? '{}'),true);
                $variantTracked=$catalog!==null&&(int)$catalog['variant_track']===1&&is_array($options)&&$options!==[];
                $scope=$variantTracked?'variant':(($catalog!==null&&(int)$catalog['product_track']===1)?'product':null);
                $scopeId=$scope==='variant'?($catalog['variant_id'] ?? null):($catalog['product_id'] ?? null);
                if($scope!==$line['scope_type']||(int)$scopeId!==(int)$line['scope_id']) { $errors[$prefix.'.product_id']='Urmărirea contabilă a produsului s-a schimbat. Redeschide și salvează ciorna înainte de confirmare.'; }
            }
            if(trim($line['supplier_name'])==='') { $errors[$prefix.'.supplier_name']='Completează denumirea de pe factura furnizorului.'; }
            if(!BigDecimal::of($line['accepted_quantity'])->isPositive()) { $errors[$prefix.'.accepted_quantity']='Cantitatea acceptată trebuie să fie pozitivă.'; }
            if($line['supplier_code']==='') { $warnings[]=$label.'codul furnizorului nu este completat.'; }
            if($document['supplier_id'] && $line['product_id']) { $this->checkReference((int)$document['supplier_id'],$line); }
            if(BigDecimal::of($line['accepted_quantity'])->isGreaterThan($line['received_quantity'])) { $errors[$prefix.'.accepted_quantity']='Cantitatea acceptată depășește cantitatea recepționată.'; }
            if(BigDecimal::of($line['accepted_quantity'])->plus($line['rejected_quantity'])->isGreaterThan($line['received_quantity'])) { $errors[$prefix.'.rejected_quantity']='Cantitățile acceptate și respinse depășesc recepția.'; }
            $difference=!BigDecimal::of($line['invoice_quantity'])->isEqualTo($line['received_quantity']) || !BigDecimal::of($line['invoice_quantity'])->isEqualTo($line['accepted_quantity']);
            if($difference && !in_array($line['difference_reason'],['shortage','excess','damaged','wrong_product','price_difference','vat_difference','other'],true)) { $errors[$prefix.'.difference_reason']='Alege motivul diferențelor cantitative.'; }
            if($line['difference_reason']==='other' && trim($line['difference_notes'])==='') { $errors[$prefix.'.difference_notes']='Explică diferența constatată.'; }
            $previous=$this->priceHistory($line,(int)$document['supplier_id'],(int)$document['id']);
            if($previous['same_supplier']!==null && $previous['same_supplier']['currency']===$document['currency']) {
                $last=BigDecimal::of($previous['same_supplier']['unit_price']);
                if($last->isPositive() && BigDecimal::of($line['unit_price'])->minus($last)->abs()->isGreaterThan($last->multipliedBy('0.25'))) { $warnings[]=$label.'prețul diferă cu peste 25% față de ultima recepție de la acest furnizor.'; }
            }
        }
        if($errors!==[]) { throw new NirValidationException($errors,$warnings); }
        $this->duplicates($document,$acceptWarnings);
        if($warnings!==[]&&!$acceptWarnings) { throw new NirValidationException([],$warnings); }
    }

    /** @param array<string,mixed> $document */
    private function duplicates(array $document,bool $acceptWarnings): string
    {
        $hashes=array_column($document['attachments'],'sha256'); sort($hashes);
        $fingerprint=hash('sha256',$this->json([$document['supplier_id'],NirRepository::normalize($document['invoice_series']),NirRepository::normalize($document['invoice_number']),$document['invoice_date'],$document['total_bani'],$document['currency'],$hashes,$document['external_id']]));
        foreach($this->repository->all('nir_documents',['status'=>'confirmed']) as $other) {
            if((int)$other['id']===(int)$document['id']) { continue; }
            if($other['fingerprint']===$fingerprint) { throw new NirValidationException(['invoice_number'=>'Această factură a fost deja recepționată în '.$other['number'].'.']); }
            $otherHashes=array_column($this->repository->all('nir_attachments',['nir_id'=>$other['id']]),'sha256');
            if(array_intersect($hashes,$otherHashes)!==[]) { throw new NirValidationException(['attachments'=>'Un fișier este deja folosit într-un NIR confirmat: '.$other['number'].'.']); }
            if((int)$other['supplier_id']===(int)$document['supplier_id'] && NirRepository::normalize($other['invoice_series'])===NirRepository::normalize($document['invoice_series']) && NirRepository::normalize($other['invoice_number'])===NirRepository::normalize($document['invoice_number']) && $other['invoice_date']===$document['invoice_date'] && !$acceptWarnings) { throw new NirValidationException([],['Există deja un NIR cu același furnizor, număr și dată de factură: '.$other['number'].'. Verifică înainte de a continua.']); }
        }
        return $fingerprint;
    }

    /** @param array<string,mixed> $document @param array<string,mixed> $actor */
    private function post(array $document,array $actor,bool $correction=false): void
    {
        $now=$this->repository->now(); $id=(int)$document['id']; $number=$document['number'] ?: $this->repository->nextNumber($document['nir_at']);
        foreach($document['lines'] as $line) {
            // Values are recomputed at the final trust boundary, never accepted from the request.
            $computed=NirDecimal::line($line,$document['exchange_rate'],(bool)$document['vat_in_cost']);
            foreach(self::MONEY as $field) { if((int)$computed[$field] !== (int)$line[$field]) { throw new NirConflictException('Valorile documentului necesită recalculare. Salvează din nou ciorna.'); } }
            if($line['tracked'] && $line['scope_type']!==null) {
                $before=$this->changeBalance($document,$line,(int)$line['stock_quantity_units']);
                $this->movement($document,$line,(int)$line['stock_quantity_units'],$before,'NIR_IN',$actor);
                $this->repository->insert('nir_fifo_layers',['nir_id'=>$id,'line_id'=>$line['id'],'warehouse_id'=>$document['warehouse_id'],'scope_type'=>$line['scope_type'],'scope_id'=>$line['scope_id'],'initial_units'=>$line['stock_quantity_units'],'remaining_units'=>$line['stock_quantity_units'],'initial_value_bani'=>$line['inventory_bani'],'remaining_value_bani'=>$line['inventory_bani'],'unit_cost_ron'=>$line['unit_cost_ron'],'received_at'=>$document['received_at'],'created_at'=>$now]);
                $this->reconcilePending((int)$document['warehouse_id'],$line,$actor);
                $this->recalculateCost((int)$document['warehouse_id'],$line);
            }
            if($line['product_id']) {
                $reference=$this->findReference((int)$document['supplier_id'],$line);
                if($reference!==null) { $this->repository->update('nir_supplier_references',['last_price'=>$line['unit_price'],'last_currency'=>$document['currency'],'last_received_at'=>$document['received_at'],'updated_at'=>$now],['id'=>$reference['id']]); }
            }
        }
        $this->repository->update('nir_documents',['status'=>'confirmed','number'=>$number,'fingerprint'=>$this->duplicates($document,true),'confirmed_at'=>$correction?$document['confirmed_at']:$now,'confirmed_by'=>$correction?$document['confirmed_by']:$this->actorId($actor),'updated_at'=>$now,'updated_by'=>$this->actorId($actor),'row_version'=>(int)$document['row_version']+1],['id'=>$id]);
        $this->audit($id,$correction?'reconfirmed':'confirmed',$actor,null,['number'=>$number,'row_version'=>(int)$document['row_version']+1]); $this->event($id,$correction?'nir.corrected':'nir.confirmed');
    }

    /** @param list<array<string,mixed>> $lines @param array<string,mixed> $actor */
    private function persistLines(int $id,array $lines,array $actor,int $supplierId): void
    {
        foreach($lines as $line) { $line['nir_id']=$id; $this->repository->insert('nir_lines',$line); if($line['save_reference'] && $line['product_id'] && $supplierId>0) { $this->allow($actor,'references'); $this->saveReference($supplierId,$line,$actor,$id); } }
    }

    /** @param array<string,mixed> $line @return array<string,mixed>|null */
    private function findReference(int $supplierId,array $line): ?array { return $this->repository->one('nir_supplier_references',['supplier_id'=>$supplierId,'normalized_code'=>$this->referenceKey($line)]); }
    /** @param array<string,mixed> $line */
    private function referenceKey(array $line): string { $code=NirRepository::normalize($line['supplier_code']); return $code!==''?$code:'NAME:'.hash('sha256',NirRepository::normalize($line['supplier_name'])); }
    /** @param array<string,mixed> $line */
    private function checkReference(int $supplierId,array $line): void
    {
        $ref=$this->findReference($supplierId,$line);
        if($ref!==null && ((int)$ref['product_id']!==(int)$line['product_id'] || (int)$ref['variant_id']!==(int)$line['variant_id'])) { throw new NirConflictException('Codul furnizorului „'.$line['supplier_code'].'” este asociat altui produs. Reasociază explicit referința înainte de a continua.'); }
    }
    /** @param array<string,mixed> $line @param array<string,mixed> $actor */
    private function saveReference(int $supplierId,array $line,array $actor,int $nirId): void
    {
        $this->checkReference($supplierId,$line); $ref=$this->findReference($supplierId,$line); $now=$this->repository->now();
        $values=['supplier_id'=>$supplierId,'product_id'=>$line['product_id'],'variant_id'=>$line['variant_id'],'supplier_code'=>$line['supplier_code'],'normalized_code'=>$this->referenceKey($line),'supplier_name'=>$line['supplier_name'],'normalized_name'=>mb_substr(NirRepository::normalize($line['supplier_name']),0,255),'supplier_ean'=>$line['supplier_ean'],'purchase_unit'=>$line['purchase_unit'],'stock_unit'=>$line['stock_unit'],'conversion_factor'=>$line['conversion_factor'],'updated_at'=>$now];
        if($ref===null) {
            $values['created_at']=$now;
            $values['is_primary']=$this->repository->one('nir_supplier_references',['supplier_id'=>$supplierId,'product_id'=>$line['product_id'],'variant_id'=>$line['variant_id'],'is_primary'=>1])===null?1:0;
            $this->repository->insert('nir_supplier_references',$values);
        } else { $this->repository->update('nir_supplier_references',$values,['id'=>$ref['id']]); }
        $this->audit($nirId,'reference_saved',$actor,$ref,$values);
    }

    /** @param list<array<string,mixed>> $metadata @param array<string,mixed> $actor */
    private function appendAttachments(int $id,array $metadata,array $actor): void
    {
        foreach($metadata as $file) {
            if(!is_array($file)||!preg_match('/^[a-f0-9]{64}$/D',(string)($file['sha256'] ?? ''))||!preg_match('/^[A-Za-z0-9_.-]+$/D',(string)($file['stored_name'] ?? ''))) { throw new NirValidationException(['attachments'=>'Metadatele fișierului nu sunt valide.']); }
            if($this->repository->one('nir_attachments',['nir_id'=>$id,'sha256'=>$file['sha256']])!==null) { throw new NirValidationException(['attachments'=>'Același fișier este deja atașat acestui document.']); }
            $values=['nir_id'=>$id,'created_by'=>$this->actorId($actor),'created_at'=>$this->repository->now()];
            foreach(['stored_name','original_name','mime_type','extension','size_bytes','sha256','extraction_status'] as $field) { $values[$field]=$file[$field] ?? ($field==='extraction_status'?'manual':''); }
            $this->repository->insert('nir_attachments',$values); $this->audit($id,'attachment_added',$actor,null,$values);
        }
    }

    private function ensureLocations(): void
    {
        $this->repository->transaction(function():void {
            foreach($this->repository->all('company_warehouses') as $warehouse) {
                if($this->repository->one('nir_locations',['warehouse_id'=>$warehouse['id']])!==null) { continue; }
                $company=$this->repository->one('companies',['id'=>$warehouse['company_id']]) ?? []; $now=$this->repository->now();
                $this->repository->insert('nir_locations',['warehouse_id'=>$warehouse['id'],'name'=>$warehouse['name'],'address'=>($company['physical_address'] ?? '') ?: ($company['registered_address'] ?? ''),'is_active'=>1,'is_default'=>1,'created_at'=>$now,'updated_at'=>$now]);
            }
        });
    }

    /** @return array<string,mixed> */
    private function load(int $id): array
    {
        $row=$this->repository->one('nir_documents',['id'=>$id]); if($row===null) { throw new NirNotFoundException(); } return $this->decorate($row,true);
    }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decorate(array $row,bool $details): array
    {
        foreach(['supplier','company','warehouse','location'] as $key) { $row[$key]=json_decode((string)$row[$key.'_snapshot'],true) ?: []; }
        if (!empty($row['original_id'])) {
            // Use the linked receipt's saved invoice header, never the current supplier data.
            $original = $this->repository->one('nir_documents', ['id'=>$row['original_id']]);
            if ($original !== null) {
                $row['original_number'] = $original['number'] ?: $original['temp_number'];
                foreach (['invoice_series','invoice_number','invoice_date'] as $key) {
                    $row['original_'.$key] = $original[$key];
                }
            }
        }
        $reversals=$this->repository->all('nir_documents',['original_id'=>$row['id'],'status'=>'confirmed']); $row['reversal_status']=$reversals===[]?'none':'partial';
        $lines=$this->repository->all('nir_lines',['nir_id'=>$row['id']],'position');
        $allReversed=$lines!==[];
        foreach($lines as &$line) {
            $parts=$this->repository->all('nir_lines',['original_line_id'=>$line['id']]); $line['reversed_units']=-NirDecimal::sum(array_column($parts,'stock_quantity_units'));
            $layers=$this->eligibleLayers((int)$line['id']); $line['available_units']=$line['tracked']?NirDecimal::sum(array_column($layers,'remaining_units')):max(0,(int)$line['stock_quantity_units']-$line['reversed_units']);
            if((int)$line['stock_quantity_units']>$line['reversed_units']) { $allReversed=false; }
        } unset($line);
        if($reversals!==[] && $allReversed) { $row['reversal_status']='full'; }
        $row['can_reverse']=$row['status']==='confirmed' && $row['type']==='supplier_receipt' && NirDecimal::sum(array_column($lines,'available_units'))>0;
        $row['can_correct']=$row['status']==='confirmed' && $row['type']==='supplier_receipt' && $reversals===[];
        foreach($this->repository->all('nir_fifo_layers',['nir_id'=>$row['id']]) as $layer) { if((int)$layer['initial_units']!==(int)$layer['remaining_units'] || $this->repository->all('nir_fifo_consumptions',['layer_id'=>$layer['id']])!==[]) { $row['can_correct']=false; } }
        $row['display_number']=$row['number'] ?: $row['temp_number']; $row['line_count']=count($lines);
        if($details) {
            $row['lines']=$lines; $row['attachments']=$this->repository->all('nir_attachments',['nir_id'=>$row['id']]); $row['audit']=$this->repository->all('nir_audit',['nir_id'=>$row['id']],'id DESC'); $row['reversals']=$reversals;
            foreach($row['audit'] as &$event) { foreach(['before','after','context'] as $key) { $event[$key]=json_decode($event[$key.'_json'],true); } } unset($event);
            foreach($row['lines'] as &$line) { $line['price_history']=$this->priceHistory($line,(int)$row['supplier_id'],(int)$row['id']); } unset($line);
        }
        return $row;
    }

    /** @param array<string,mixed> $line @return array<string,mixed> */
    private function priceHistory(array $line,int $supplierId,int $excludeId): array
    {
        $result=['same_supplier'=>null,'any_supplier'=>null,'minimum_recent'=>null]; if(!$line['product_id']) { return $result; }
        $history=$this->repository->all('nir_lines',['product_id'=>$line['product_id']],'id DESC'); $recentLimit=gmdate('Y-m-d',time()-180*86400); $candidates=[];
        foreach($history as $candidate) {
            if((int)$candidate['nir_id']===$excludeId || (int)$candidate['variant_id']!==(int)$line['variant_id']) { continue; }
            $doc=$this->repository->one('nir_documents',['id'=>$candidate['nir_id']]); if($doc===null || $doc['status']!=='confirmed'||$doc['type']!=='supplier_receipt') { continue; }
            $candidates[]=['unit_price'=>$candidate['unit_price'],'currency'=>$doc['currency'],'received_at'=>$doc['received_at'],'nir_id'=>$doc['id'],'supplier_id'=>$doc['supplier_id'],'line_id'=>$candidate['id']];
        }
        usort($candidates,static fn(array $a,array $b):int=>strcmp($b['received_at'],$a['received_at']) ?: ($b['line_id']<=>$a['line_id']));
        foreach($candidates as $price) {
            $result['any_supplier'] ??= $price; if((int)$price['supplier_id']===$supplierId) { $result['same_supplier'] ??= $price; }
            if(substr($price['received_at'],0,10)>=$recentLimit && ($result['minimum_recent']===null || ($result['minimum_recent']['currency']===$price['currency'] && BigDecimal::of($price['unit_price'])->isLessThan($result['minimum_recent']['unit_price'])))) { $result['minimum_recent']=$price; }
        }
        return $result;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $actor @return array<string,mixed> */
    private function redact(array $row,array $actor): array
    {
        if($this->permissions($actor)['costs']) { return $row; }
        foreach([...self::MONEY,...self::COSTS,'before_json','after_json','context_json','fingerprint','before','after','context','supplier_snapshot','company_snapshot','warehouse_snapshot','location_snapshot'] as $field) { unset($row[$field]); }
        foreach($row as $key=>$value) { if(str_ends_with((string)$key,'_bani')) { unset($row[$key]); continue; } if(is_array($value)) { if(array_is_list($value)) { $row[$key]=array_map(fn(mixed $item):mixed=>is_array($item)?$this->redact($item,$actor):$item,$value); } else { $row[$key]=$this->redact($value,$actor); } } }
        return $row;
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    private function copyHeader(array $document): array
    {
        $fields=['number','temp_number','type','status','row_version','supplier_id','company_id','warehouse_id','location_id','invoice_series','invoice_number','invoice_date','nir_at','received_at','currency','exchange_rate','exchange_rate_date','notes','external_id','reason','supplier_snapshot','company_snapshot','warehouse_snapshot','location_snapshot','vat_in_cost','fingerprint','original_id','return_id','order_id','created_by','updated_by','confirmed_by','created_at','updated_at','confirmed_at',...self::MONEY];
        return array_intersect_key($document,array_flip($fields));
    }

    /** @param array<string,mixed> $actor */
    private function allow(array $actor,string $permission): void { if(!($this->permissions($actor)[$permission] ?? false)) { throw new \RuntimeException('Nu ai permisiunea necesară pentru această acțiune.',403); } }
    /** @param array<string,mixed> $actor */
    private function actorId(array $actor): ?int { return (int)($actor['id'] ?? 0)>0?(int)$actor['id']:null; }
    /** @param array<string,mixed> $document */
    private function version(array $document,int $version): void { if((int)$document['row_version']!==$version) { throw new NirConflictException(); } }
    private function truth(mixed $value): bool { return in_array($value,[true,1,'1','true','on'],true); }
    private function json(mixed $value): string { return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    private function date(string $value,string $field): string
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Europe/Bucharest')); if($date===false||$date->format('Y-m-d')!==$value) { throw new NirValidationException([$field=>'Data nu este validă.']); } return $value;
    }
    private function instant(string $value,string $field): string
    {
        if(!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?$/D',$value)) { throw new NirValidationException([$field=>'Completează data și ora.']); }
        try { $date=new DateTimeImmutable($value,new DateTimeZone('Europe/Bucharest')); } catch(\Exception) { throw new NirValidationException([$field=>'Data și ora nu sunt valide.']); }
        $errors=DateTimeImmutable::getLastErrors(); if(is_array($errors)&&($errors['warning_count']>0||$errors['error_count']>0)) { throw new NirValidationException([$field=>'Data și ora nu sunt valide.']); }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
    private function requestHash(string $action,mixed $input): string { return hash('sha256',$action.$this->json($this->canonicalRequest($input))); }
    private function canonicalRequest(mixed $input): mixed
    {
        if(!is_array($input)) { return $input; }
        $out=[];
        foreach($input as $field=>$value) {
            if(in_array($field,['_csrf','idempotency_key','intent','modal'],true)) { continue; }
            if($field==='attachments'&&is_array($value)) {
                $files=[]; foreach($value as $file) { if(is_array($file)) { $files[]=['sha256'=>(string)($file['sha256'] ?? ''),'mime_type'=>(string)($file['mime_type'] ?? ''),'size_bytes'=>(int)($file['size_bytes'] ?? 0)]; } }
                usort($files,static fn(array $a,array $b):int=>strcmp($a['sha256'],$b['sha256'])); $out[$field]=$files;
            } else { $out[$field]=$this->canonicalRequest($value); }
        }
        if(!array_is_list($out)) { ksort($out); } return $out;
    }
    private function retry(string $key,string $action,string $hash): ?int
    {
        if(!preg_match('/^[A-Za-z0-9._:-]{8,160}$/D',$key)) { throw new NirValidationException(['idempotency_key'=>'Cheia operațiunii lipsește sau este invalidă. Reîncarcă formularul.']); }
        $existing=$this->repository->one('nir_idempotency',['request_key'=>$key]); if($existing===null) { return null; }
        if($existing['action']!==$action||$existing['request_hash']!==$hash) { throw new NirConflictException('Cheia operațiunii a fost folosită pentru altă cerere.'); } return (int)$existing['result_id'];
    }
    private function remember(string $key,string $action,string $hash,int $id,int $result): void { $this->repository->insert('nir_idempotency',['request_key'=>$key,'action'=>$action,'nir_id'=>$id,'request_hash'=>$hash,'result_id'=>$result,'created_at'=>$this->repository->now()]); }
    /** @param array<string,mixed> $actor @param array<string,mixed> $context */
    private function audit(?int $id,string $action,array $actor,mixed $before,mixed $after,array $context=[]): void { if(is_array($before)) { unset($before['audit']); } if(is_array($after)) { unset($after['audit']); } $this->repository->insert('nir_audit',['nir_id'=>$id,'action'=>$action,'actor_id'=>$this->actorId($actor),'created_at'=>$this->repository->now(),'before_json'=>$this->json($before),'after_json'=>$this->json($after),'context_json'=>$this->json($context)]); }
    private function event(int $id,string $type): void { $this->repository->insert('nir_outbox',['nir_id'=>$id,'event_type'=>$type,'payload_json'=>$this->json(['nir_id'=>$id]),'created_at'=>$this->repository->now()]); }

    /** Returned quantities retain the original supplier lot, even after customer receipt. @return list<array<string,mixed>> */
    private function eligibleLayers(int $lineId): array
    {
        $layers=$this->repository->all('nir_fifo_layers',['line_id'=>$lineId],'received_at,created_at,id'); $all=$this->repository->all('nir_fifo_layers',[],'received_at,created_at,id'); $ids=array_column($layers,'id');
        do { $added=false; foreach($all as $layer) { if($layer['original_layer_id']!==null && in_array($layer['original_layer_id'],$ids,true) && !in_array($layer['id'],$ids,true)) { $ids[]=$layer['id']; $layers[]=$layer; $added=true; } } } while($added);
        $line=$this->repository->one('nir_lines',['id'=>$lineId]); $document=$line===null?null:$this->repository->one('nir_documents',['id'=>$line['nir_id']]);
        return array_values(array_filter($layers,static fn(array $layer):bool=>$document!==null&&(int)$layer['warehouse_id']===(int)$document['warehouse_id']));
    }
}
