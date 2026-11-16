<?php
declare(strict_types=1);
namespace App\Modules\Inventory;

use PDO;

/** Read-only accounting journal. No online reservation or document is changed here. */
final class StockLedgerRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function pdo(): PDO { return $this->pdo; }

    /** @return array<string,mixed> */
    public function companyProfile(): array
    {
        $row=$this->pdo->query('SELECT * FROM companies ORDER BY is_default DESC,id ASC LIMIT 1')->fetch();
        return is_array($row)?$row:[];
    }

    private function balancesSql(): string
    {
        return AccountingStockReadModel::documentMovementsSql();
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function registry(array $filters, bool $export=false): array
    {
        $params=[]; $where=["p.status<>'archived'"];
        $search=trim(mb_substr((string)($filters['q'] ?? ''),0,150));
        if ($search!=='') {
            $exact=$this->pdo->prepare("SELECT DISTINCT p.id FROM products p WHERE LOWER(p.name)=LOWER(:name) OR LOWER(p.primary_sku)=LOWER(:sku)
                OR EXISTS(SELECT 1 FROM product_variants v WHERE v.product_id=p.id AND (LOWER(v.sku)=LOWER(:variant) OR v.ean=:ean))
                OR EXISTS(SELECT 1 FROM nir_supplier_references r WHERE r.product_id=p.id AND LOWER(r.supplier_code)=LOWER(:supplier_code))");
            $exact->execute(['name'=>$search,'sku'=>$search,'variant'=>$search,'ean'=>$search,'supplier_code'=>$search]); $ids=$exact->fetchAll(PDO::FETCH_COLUMN);
            if ($ids!==[]) {
                $where[]='p.id IN ('.implode(',',array_map('intval',$ids)).')';
            } else {
                foreach(array_slice(preg_split('/\s+/u',$search) ?: [],0,8) as $index=>$term) {
                    $fields=['p.name','p.primary_sku','p.brand','s.alias','s.company_name','c.name']; $or=[];
                    foreach($fields as $i=>$field) { $key='q'.$index.'_'.$i; $or[]=$this->normalSql($field).' LIKE :'.$key." ESCAPE '!'"; $params[$key]='%'.$this->needle($term).'%'; }
                    foreach(['variant'=>"SELECT 1 FROM product_variants sv WHERE sv.product_id=p.id AND (".$this->normalSql('sv.sku')." LIKE :v{$index} ESCAPE '!')",
                        'nir'=>"SELECT 1 FROM nir_lines nl JOIN nir_documents nd ON nd.id=nl.nir_id WHERE nl.product_id=p.id AND (".$this->normalSql('nd.number')." LIKE :n{$index} ESCAPE '!' OR ".$this->normalSql('nd.invoice_number')." LIKE :d{$index} ESCAPE '!')"] as $query) { $or[]='EXISTS('.$query.')'; }
                    foreach(['v','n','d'] as $prefix) { $params[$prefix.$index]='%'.$this->needle($term).'%'; }
                    $where[]='('.implode(' OR ',$or).')';
                }
            }
        }
        $alert=(string)($filters['alert'] ?? '');
        if($alert==='negative') { $where[]='COALESCE(b.units,0)<0'; }
        elseif($alert==='low') { $where[]='p.accounting_minimum_units>0 AND COALESCE(b.units,0)<=p.accounting_minimum_units'; }
        $from=' FROM products p LEFT JOIN suppliers s ON s.id=p.supplier_id LEFT JOIN categories c ON c.id=p.primary_category_id
            LEFT JOIN categories parent_category ON parent_category.id=c.parent_id
            LEFT JOIN categories grandparent_category ON grandparent_category.id=parent_category.parent_id
            LEFT JOIN media category_media ON category_media.id=c.image_media_id
            LEFT JOIN media parent_category_media ON parent_category_media.id=parent_category.image_media_id
            LEFT JOIN media grandparent_category_media ON grandparent_category_media.id=grandparent_category.image_media_id
            LEFT JOIN (SELECT product_id,SUM(quantity_units) units,COUNT(*) movement_count,
                SUM(CASE WHEN quantity_units>0 THEN quantity_units ELSE 0 END) incoming_units,
                SUM(CASE WHEN quantity_units<0 THEN -quantity_units ELSE 0 END) outgoing_units
                FROM ('.$this->balancesSql().') all_movements GROUP BY product_id) b ON b.product_id=p.id
            WHERE '.implode(' AND ',$where);
        $q=$this->pdo->prepare('SELECT COUNT(*)'.$from); $q->execute($params); $count=(int)$q->fetchColumn();
        $size=StockPagination::size($filters['per_page'] ?? null); $pages=max(1,(int)ceil($count/$size)); $page=max(1,min($pages,(int)($filters['page'] ?? 1)));
        $sql="SELECT p.id,p.name,p.slug,p.status,p.primary_sku AS sku,p.track_in_conta,p.accounting_minimum_units,c.name AS category,
            CASE WHEN COALESCE(b.movement_count,0)>0 THEN 1 ELSE 0 END AS accounting_tracked,
            COALESCE(b.units,0) AS accounting_units,COALESCE(b.incoming_units,0) AS incoming_units,COALESCE(b.outgoing_units,0) AS outgoing_units,
            COALESCE(
                (SELECT m.public_path FROM product_images pi JOIN media m ON m.id=pi.media_id WHERE pi.product_id=p.id AND m.public_path IS NOT NULL ORDER BY pi.position,pi.media_id LIMIT 1),
                (SELECT vm.public_path FROM product_variants image_variant JOIN media vm ON vm.id=image_variant.image_media_id WHERE image_variant.product_id=p.id AND vm.public_path IS NOT NULL ORDER BY image_variant.is_default DESC,image_variant.id LIMIT 1),
                category_media.public_path,parent_category_media.public_path,grandparent_category_media.public_path
            ) AS image_path,
            CASE WHEN EXISTS(SELECT 1 FROM product_images pi WHERE pi.product_id=p.id) OR EXISTS(SELECT 1 FROM product_variants image_variant WHERE image_variant.product_id=p.id AND image_variant.image_media_id IS NOT NULL) THEN 0 ELSE 1 END AS image_fallback,
            COALESCE((SELECT SUM(i.physical_quantity-i.reserved_quantity) FROM product_variants v JOIN inventory i ON i.variant_id=v.id WHERE v.product_id=p.id AND v.status='active' AND v.stock_unlimited=0),0) AS online_quantity,
            COALESCE((SELECT SUM(i.physical_quantity) FROM product_variants v JOIN inventory i ON i.variant_id=v.id WHERE v.product_id=p.id AND v.status='active' AND v.stock_unlimited=0),0) AS online_physical,
            COALESCE((SELECT SUM(i.reserved_quantity) FROM product_variants v JOIN inventory i ON i.variant_id=v.id WHERE v.product_id=p.id AND v.status='active' AND v.stock_unlimited=0),0) AS online_reserved,
            (SELECT COUNT(*) FROM product_variants v WHERE v.product_id=p.id AND v.status='active' AND v.stock_unlimited=1) AS unlimited_variants,
            (SELECT COUNT(*) FROM product_variants v WHERE v.product_id=p.id AND v.status='active' AND v.stock_unlimited=0) AS numeric_variants,
            CASE WHEN EXISTS(SELECT 1 FROM product_variants v WHERE v.product_id=p.id AND v.status='active' AND v.stock_unlimited=1) THEN 1 ELSE 0 END AS unlimited,
            COALESCE((SELECT SUM(f.remaining_value_bani) FROM nir_fifo_layers f JOIN nir_lines nl ON nl.id=f.line_id WHERE nl.product_id=p.id),0) AS value_bani,
            COALESCE((SELECT SUM(pc.quantity_units) FROM nir_pending_consumptions pc WHERE pc.product_id=p.id),0) AS pending_units".$from.' ORDER BY p.name,p.id';
        if(!$export) { $sql.=' LIMIT '.$size.' OFFSET '.(($page-1)*$size); }
        $q=$this->pdo->prepare($sql); $q->execute($params);
        return ['items'=>$q->fetchAll(),'total'=>$count,'page'=>$page,'pages'=>$pages,'per_page'=>$size,'from'=>$count ? ($page-1)*$size+1:0,'to'=>min($page*$size,$count)];
    }

    /** @return array<string,mixed>|null */
    public function product(int $id): ?array
    {
        $q=$this->pdo->prepare("SELECT p.*,c.name AS category,
            COALESCE(
                (SELECT m.public_path FROM product_images pi JOIN media m ON m.id=pi.media_id WHERE pi.product_id=p.id AND m.public_path IS NOT NULL ORDER BY pi.position,pi.media_id LIMIT 1),
                (SELECT vm.public_path FROM product_variants image_variant JOIN media vm ON vm.id=image_variant.image_media_id WHERE image_variant.product_id=p.id AND vm.public_path IS NOT NULL ORDER BY image_variant.is_default DESC,image_variant.id LIMIT 1),
                category_media.public_path,parent_category_media.public_path,grandparent_category_media.public_path
            ) AS image_path,
            CASE WHEN EXISTS(SELECT 1 FROM product_images pi WHERE pi.product_id=p.id) OR EXISTS(SELECT 1 FROM product_variants image_variant WHERE image_variant.product_id=p.id AND image_variant.image_media_id IS NOT NULL) THEN 0 ELSE 1 END AS image_fallback
            FROM products p LEFT JOIN categories c ON c.id=p.primary_category_id
            LEFT JOIN categories parent_category ON parent_category.id=c.parent_id
            LEFT JOIN categories grandparent_category ON grandparent_category.id=parent_category.parent_id
            LEFT JOIN media category_media ON category_media.id=c.image_media_id
            LEFT JOIN media parent_category_media ON parent_category_media.id=parent_category.image_media_id
            LEFT JOIN media grandparent_category_media ON grandparent_category_media.id=grandparent_category.image_media_id
            WHERE p.id=:id");
        $q->execute(['id'=>$id]); return $q->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function scopes(int $id): array
    {
        $rows=$this->movements($id); $scopes=[];
        foreach($rows as $row) {
            $key=$row['warehouse_id'].'|'.$row['scope_type'].'|'.$row['scope_id'];
            if(!isset($scopes[$key])) {
                $scopes[$key]=['scope_type'=>$row['scope_type'],'scope_id'=>$row['scope_id'],'sku'=>$row['nir_sku'] ?? $row['invoice_sku'] ?? '','quantity_units'=>0,'warehouse'=>$row['warehouse']];
            }
            $scopes[$key]['quantity_units']+=(int)$row['quantity_units'];
        }
        return array_values($scopes);
    }

    /** Quantities are chronological; before/after values of individual warehouses are never summed as a running balance.
     * @return list<array<string,mixed>>
     */
    public function movements(int $id): array
    {
        $q=$this->pdo->prepare("SELECT m.id,m.created_at,m.type,m.quantity_units,m.source_type,m.source_id,m.scope_type,m.scope_id,m.warehouse_id,
            w.name AS warehouse,u.first_name,u.last_name,n.id AS nir_id,n.number AS nir_number,n.supplier_snapshot,n.invoice_series,n.invoice_number,n.status AS nir_status,n.invoice_date,n.notes AS nir_notes,n.nir_at AS nir_registered_at,
            il.invoice_id,i.series_code,i.number AS invoice_number_out,i.deleted_at AS invoice_deleted,i.issued_at AS invoice_date_out,i.order_id,i.snapshot_json AS invoice_snapshot,o.order_number,il.quantity_units AS invoice_units,il.gross_bani AS sale_bani,
            nl.stock_quantity_units AS nir_units,
            CASE WHEN n.type='customer_return' THEN COALESCE(nl.inventory_bani,0) WHEN nl.tracked=0 THEN NULL ELSE nl.inventory_bani END AS acquisition_bani,nl.stock_unit AS unit,nl.sku AS nir_sku,il.sku AS invoice_sku,
            (SELECT SUM(c.value_bani) FROM nir_fifo_consumptions c WHERE c.source_type='invoice_line' AND c.source_id=il.id) AS allocated_bani,
            (SELECT SUM(pc.quantity_units) FROM nir_pending_consumptions pc WHERE pc.source_type='invoice_line' AND pc.source_id=il.id) AS pending_units
            FROM (".AccountingStockReadModel::documentMovementsSql().") m LEFT JOIN company_warehouses w ON w.id=m.warehouse_id LEFT JOIN users u ON u.id=m.actor_id
            LEFT JOIN nir_documents n ON n.id=m.nir_id LEFT JOIN nir_lines nl ON nl.id=m.line_id
            LEFT JOIN invoice_lines il ON m.source_type='invoice_line' AND il.id=m.source_id LEFT JOIN invoices i ON i.id=il.invoice_id LEFT JOIN orders o ON o.id=i.order_id
            WHERE m.product_id=:product
            ORDER BY m.created_at,m.id");
        $q->execute(['product'=>$id]); $rows=$q->fetchAll();
        foreach($rows as &$row) {
            // Keep the audit timestamp intact; the source document determines the reporting period.
            $row['movement_at']=!empty($row['nir_registered_at'])?$row['nir_registered_at']:
                (in_array($row['type'],['INVOICE_OUT','INVOICE_OUT_PENDING'],true) && !empty($row['invoice_date_out'])?$row['invoice_date_out']:$row['created_at']);
        } unset($row);
        usort($rows,static fn(array $a,array $b):int=>[strtotime($a['movement_at']),strtotime($a['created_at']),(int)$a['id']]<=>[strtotime($b['movement_at']),strtotime($b['created_at']),(int)$b['id']]); return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function variants(int $id): array
    {
        $q=$this->pdo->prepare('SELECT v.id,v.sku,v.option_summary,v.stock_unlimited,v.track_in_conta,v.status,COALESCE(i.physical_quantity,0) physical_quantity,COALESCE(i.reserved_quantity,0) reserved_quantity,COALESCE(i.physical_quantity-i.reserved_quantity,0) online_quantity FROM product_variants v LEFT JOIN inventory i ON i.variant_id=v.id WHERE v.product_id=:id ORDER BY v.is_default DESC,v.id');
        $q->execute(['id'=>$id]); return $q->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function onlineAdjustments(int $productId): array
    {
        $q=$this->pdo->prepare('SELECT adjustment.*,variant.sku,variant.option_summary,actor.first_name,actor.last_name FROM stock_online_adjustments adjustment JOIN product_variants variant ON variant.id=adjustment.variant_id LEFT JOIN users actor ON actor.id=adjustment.actor_user_id WHERE adjustment.product_id=:product ORDER BY adjustment.created_at DESC,adjustment.id DESC LIMIT 20');
        $q->execute(['product'=>$productId]); return $q->fetchAll();
    }

    private function normalSql(string $field): string
    {
        $sql="LOWER(COALESCE($field,''))";
        foreach(['ă'=>'a','â'=>'a','î'=>'i','ș'=>'s','ş'=>'s','ț'=>'t','ţ'=>'t'] as $a=>$b) { $sql="REPLACE($sql,'$a','$b')"; } return $sql;
    }
    private function needle(string $text): string
    {
        return strtr(mb_strtolower($text),['ă'=>'a','â'=>'a','î'=>'i','ș'=>'s','ş'=>'s','ț'=>'t','ţ'=>'t','!'=>'!!','%'=>'!%','_'=>'!_']);
    }
}
