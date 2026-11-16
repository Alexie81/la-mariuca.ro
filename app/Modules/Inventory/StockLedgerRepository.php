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
        return 'SELECT product_id,quantity_units FROM nir_stock_balances UNION ALL
            SELECT l.product_id,l.quantity*10000 FROM accounting_stock_balances l WHERE NOT EXISTS
            (SELECT 1 FROM nir_stock_balances b WHERE b.scope_type=l.scope_type AND b.scope_id=l.scope_id)';
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function registry(array $filters, bool $export=false): array
    {
        $params=[]; $where=["(p.track_in_conta=1 OR EXISTS(SELECT 1 FROM product_variants av WHERE av.product_id=p.id AND av.track_in_conta=1 AND av.options_json NOT IN ('','{}','[]')))"];
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
        elseif($alert==='low') { $where[]='COALESCE(b.units,0)<=p.accounting_minimum_units'; }
        $from=' FROM products p LEFT JOIN suppliers s ON s.id=p.supplier_id LEFT JOIN categories c ON c.id=p.primary_category_id
            LEFT JOIN (SELECT product_id,SUM(quantity_units) units FROM ('.$this->balancesSql().') all_balances GROUP BY product_id) b ON b.product_id=p.id
            WHERE '.implode(' AND ',$where);
        $q=$this->pdo->prepare('SELECT COUNT(*)'.$from); $q->execute($params); $count=(int)$q->fetchColumn();
        $size=24; $pages=max(1,(int)ceil($count/$size)); $page=max(1,min($pages,(int)($filters['page'] ?? 1)));
        $sql="SELECT p.id,p.name,p.slug,p.status,p.primary_sku AS sku,p.accounting_minimum_units,c.name AS category,s.alias AS supplier,
            COALESCE(b.units,0) AS accounting_units,
            (SELECT m.public_path FROM product_images pi JOIN media m ON m.id=pi.media_id WHERE pi.product_id=p.id ORDER BY pi.position,pi.media_id LIMIT 1) AS image_path,
            COALESCE((SELECT SUM(i.physical_quantity-i.reserved_quantity) FROM product_variants v JOIN inventory i ON i.variant_id=v.id WHERE v.product_id=p.id AND v.status='active'),0) AS online_quantity,
            CASE WHEN EXISTS(SELECT 1 FROM product_variants v WHERE v.product_id=p.id AND v.status='active' AND v.stock_unlimited=1) THEN 1 ELSE 0 END AS unlimited,
            COALESCE((SELECT SUM(f.remaining_value_bani) FROM nir_fifo_layers f JOIN nir_lines nl ON nl.id=f.line_id WHERE nl.product_id=p.id),0) AS value_bani,
            COALESCE((SELECT SUM(pc.quantity_units) FROM nir_pending_consumptions pc WHERE pc.product_id=p.id),0) AS pending_units".$from.' ORDER BY p.name,p.id';
        if(!$export) { $sql.=' LIMIT '.$size.' OFFSET '.(($page-1)*$size); }
        $q=$this->pdo->prepare($sql); $q->execute($params);
        return ['items'=>$q->fetchAll(),'total'=>$count,'page'=>$page,'pages'=>$pages,'from'=>$count ? ($page-1)*$size+1:0,'to'=>min($page*$size,$count)];
    }

    /** @return array<string,mixed>|null */
    public function product(int $id): ?array
    {
        $q=$this->pdo->prepare('SELECT p.*,s.alias AS supplier,c.name AS category,
            (SELECT m.public_path FROM product_images pi JOIN media m ON m.id=pi.media_id WHERE pi.product_id=p.id ORDER BY pi.position,pi.media_id LIMIT 1) AS image_path
            FROM products p LEFT JOIN suppliers s ON s.id=p.supplier_id LEFT JOIN categories c ON c.id=p.primary_category_id WHERE p.id=:id');
        $q->execute(['id'=>$id]); return $q->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function scopes(int $id): array
    {
        $q=$this->pdo->prepare("SELECT b.scope_type,b.scope_id,b.sku,b.quantity_units,w.name AS warehouse FROM nir_stock_balances b JOIN company_warehouses w ON w.id=b.warehouse_id WHERE b.product_id=:canonical
            UNION ALL SELECT l.scope_type,l.scope_id,l.sku,l.quantity*10000,'Evidență anterioară' FROM accounting_stock_balances l WHERE l.product_id=:legacy
            AND NOT EXISTS(SELECT 1 FROM nir_stock_balances b WHERE b.scope_type=l.scope_type AND b.scope_id=l.scope_id)");
        $q->execute(['canonical'=>$id,'legacy'=>$id]); return $q->fetchAll();
    }

    /** Quantities are chronological; before/after values of individual warehouses are never summed as a running balance.
     * @return list<array<string,mixed>>
     */
    public function movements(int $id): array
    {
        $q=$this->pdo->prepare("SELECT m.id,m.created_at,m.type,m.quantity_units,m.source_type,m.source_id,m.scope_type,m.scope_id,m.warehouse_id,
            w.name AS warehouse,u.first_name,u.last_name,n.id AS nir_id,n.number AS nir_number,n.supplier_snapshot,n.invoice_series,n.invoice_number,n.status AS nir_status,n.invoice_date,n.notes AS nir_notes,n.nir_at AS nir_registered_at,
            il.invoice_id,i.series_code,i.number AS invoice_number_out,i.deleted_at AS invoice_deleted,i.issued_at AS invoice_date_out,i.order_id,i.snapshot_json AS invoice_snapshot,o.order_number,il.quantity_units AS invoice_units,il.gross_bani AS sale_bani,
            nl.stock_quantity_units AS nir_units,nl.inventory_bani AS acquisition_bani,nl.stock_unit AS unit,nl.sku AS nir_sku,il.sku AS invoice_sku,
            (SELECT SUM(c.value_bani) FROM nir_fifo_consumptions c WHERE c.source_type='invoice_line' AND c.source_id=il.id) AS allocated_bani,
            (SELECT SUM(pc.quantity_units) FROM nir_pending_consumptions pc WHERE pc.source_type='invoice_line' AND pc.source_id=il.id) AS pending_units
            FROM nir_stock_movements m JOIN company_warehouses w ON w.id=m.warehouse_id LEFT JOIN users u ON u.id=m.actor_id
            LEFT JOIN nir_documents n ON n.id=m.nir_id LEFT JOIN nir_lines nl ON nl.id=m.line_id
            LEFT JOIN invoice_lines il ON m.source_type='invoice_line' AND il.id=m.source_id LEFT JOIN invoices i ON i.id=il.invoice_id LEFT JOIN orders o ON o.id=i.order_id
            WHERE (m.scope_type='product' AND m.scope_id=:product) OR (m.scope_type='variant' AND EXISTS(SELECT 1 FROM product_variants v WHERE v.id=m.scope_id AND v.product_id=:variant_product)) ORDER BY m.created_at,m.id");
        $q->execute(['product'=>$id,'variant_product'=>$id]); $rows=$q->fetchAll();
        $q=$this->pdo->prepare("SELECT m.id,m.created_at,m.type,m.quantity_delta*10000 AS quantity_units,m.reference_type AS source_type,m.reference_id AS source_id,m.sku AS nir_sku,u.first_name,u.last_name
            FROM accounting_stock_movements m LEFT JOIN users u ON u.id=m.actor_user_id WHERE m.product_id=:id
            AND NOT EXISTS(SELECT 1 FROM nir_stock_balances b WHERE b.scope_type=m.scope_type AND b.scope_id=m.scope_id) ORDER BY m.created_at,m.id");
        $q->execute(['id'=>$id]);
        foreach($q->fetchAll() as $row) { $rows[]=$row+['legacy'=>true,'warehouse'=>'Evidență anterioară','unit'=>'buc']; }
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
        $q=$this->pdo->prepare('SELECT v.id,v.sku,v.option_summary,v.stock_unlimited,v.status,COALESCE(i.physical_quantity-i.reserved_quantity,0) online_quantity FROM product_variants v LEFT JOIN inventory i ON i.variant_id=v.id WHERE v.product_id=:id ORDER BY v.is_default DESC,v.id');
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
