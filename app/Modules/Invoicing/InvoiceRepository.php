<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use PDO;

final class InvoiceRepository
{
    private const TABLES = ['invoices','invoice_lines','invoice_actions','invoice_audit','invoice_public_links','invoice_receipts','invoice_receipt_allocations','invoice_return_requests','invoice_reminder_dismissals','invoice_refunds','invoice_automation_intents','outbox_messages','orders','order_items','users','company_warehouses','inventory_reservations','inventory_movements','audit_log','invoice_return_policy','invoice_fiscal_jobs','payments','order_document_operations','order_return_documents','order_returns','order_return_items','order_return_requests','order_refunds'];
    public function __construct(private readonly PDO $pdo) {}
    public function pdo(): PDO { return $this->pdo; }
    /** Called only after the NIR's stock was reversed and the NIR deleted. Action tombstones remain. */
    public function deleteReceipt(int $receiptId): void
    {
        $receipt=$this->one('invoice_receipts',['id'=>$receiptId]); if($receipt===null) { return; }
        $query=$this->pdo->prepare("UPDATE invoice_actions SET action='receive_deleted' WHERE invoice_id=:id AND action='receive'"); $query->execute(['id'=>$receipt['invoice_id']]);
        $query=$this->pdo->prepare('UPDATE invoices SET row_version=row_version+1,updated_at=:now WHERE id=:id'); $query->execute(['id'=>$receipt['invoice_id'],'now'=>$this->now()]);
        $query=$this->pdo->prepare('DELETE FROM invoice_receipt_allocations WHERE receipt_id=:id'); $query->execute(['id'=>$receiptId]);
        $query=$this->pdo->prepare('DELETE FROM invoice_receipts WHERE id=:id'); $query->execute(['id'=>$receiptId]);
    }
    /** Documents of the current fulfilment, excluding earlier fully cancelled cycles.
     * @return list<array<string,mixed>>
     */
    public function currentOrderInvoices(int $orderId): array
    {
        $query=$this->pdo->prepare('SELECT i.* FROM invoices i JOIN orders o ON o.id=i.order_id WHERE i.order_id=:id AND i.deleted_at IS NULL AND i.id>o.invoice_cycle_start_id ORDER BY i.id');
        $query->execute(['id'=>$orderId]);return $query->fetchAll();
    }
    public function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
    public function hasConfirmedCardPayment(int $orderId): bool
    {
        $query=$this->pdo->prepare("SELECT 1 FROM orders o WHERE o.id=:id AND ((o.payment_method='stripe' AND o.payment_status IN ('paid','partially_refunded','refunded')) OR EXISTS (SELECT 1 FROM payments p WHERE p.order_id=o.id AND p.provider='stripe' AND (p.status IN ('paid','partially_refunded','refunded') OR p.refunded_bani>0)))");
        $query->execute(['id'=>$orderId]);return $query->fetchColumn()!==false;
    }
    /** Read-only presentation data; the editor validates availability and price again on save.
     * @return array<string,mixed>|null
     */
    public function productPreview(string $sku): ?array
    {
        $query=$this->pdo->prepare($this->productSelect()." WHERE LOWER(v.sku)=:sku AND v.status='active' AND p.status='published' AND v.price_bani>0 LIMIT 1");
        $query->execute(['sku'=>mb_strtolower($sku)]);
        $product=$query->fetch();
        return is_array($product)?$product:null;
    }
    private function productSelect(): string
    {
        return "SELECT v.id AS variant_id,v.product_id,v.sku,v.option_summary,v.price_bani,p.name,
            COALESCE(vm.public_path,
                (SELECT m.public_path FROM product_images pi JOIN media m ON m.id=pi.media_id WHERE pi.product_id=p.id AND m.public_path IS NOT NULL ORDER BY pi.position,pi.media_id LIMIT 1),
                (SELECT m.public_path FROM product_categories pc JOIN categories c ON c.id=pc.category_id JOIN media m ON m.id=c.image_media_id WHERE pc.product_id=p.id AND m.public_path IS NOT NULL ORDER BY CASE WHEN c.parent_id IS NULL THEN 0 ELSE 1 END,c.position,c.id LIMIT 1)
            ) AS image_path
            FROM product_variants v JOIN products p ON p.id=v.product_id LEFT JOIN media vm ON vm.id=v.image_media_id";
    }
    /** @return list<int> */
    public function productIdsForSku(string $query): array
    {
        $statement=$this->pdo->prepare("SELECT DISTINCT p.id FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.status='active' AND p.status='published' AND v.price_bani>0 AND LOWER(v.sku) LIKE ? ESCAPE '!' ORDER BY p.id");
        $statement->execute(['%'.strtr(mb_strtolower($query),['!'=>'!!','%'=>'!%','_'=>'!_']).'%']);
        return array_map('intval',$statement->fetchAll(PDO::FETCH_COLUMN));
    }
    /** @param list<int>|null $productIds Ranked product matches, or null for browsing.
     * @return list<array<string,mixed>>
     */
    public function productChoices(?array $productIds=null,int $offset=0,int $limit=25): array
    {
        if($productIds===[]) { return []; }
        $where=" WHERE v.status='active' AND p.status='published' AND v.price_bani>0";
        $params=[]; $order='p.name,v.option_summary,v.id';
        if($productIds!==null) {
            $productIds=array_values(array_unique(array_map('intval',$productIds)));
            $where.=' AND p.id IN ('.implode(',',array_fill(0,count($productIds),'?')).')';
            $params=$productIds; $rank=[];
            foreach($productIds as $index=>$id) { $rank[]='WHEN '.$id.' THEN '.$index; }
            $order='CASE p.id '.implode(' ',$rank).' END,v.option_summary,v.id';
        }
        $sql=$this->productSelect().$where.' ORDER BY '.$order;
        if($limit>0) { $sql.=' LIMIT '.min(100,$limit).' OFFSET '.max(0,$offset); }
        $query=$this->pdo->prepare($sql); $query->execute($params); return $query->fetchAll();
    }
    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $own = !$this->pdo->inTransaction();
        if ($own) { $this->pdo->beginTransaction(); }
        try {
            // Same order in every invoice operation. Serialize with NIR changes as well.
            $this->pdo->exec('UPDATE invoice_configuration SET revision=revision WHERE id=1');
            $this->pdo->exec('UPDATE nir_write_lock SET revision=revision+1 WHERE id=1');
            $result = $work();
            if ($own) { $this->pdo->commit(); }
            return $result;
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }
    /** @param array<string,mixed> $where @return list<array<string,mixed>> */
    public function all(string $table, array $where = []): array
    {
        $this->table($table); $conditions=[]; $params=[];
        foreach ($where as $field=>$value) {
            $this->field($field);
            $conditions[]=$field.($value===null?' IS NULL':'=:'.$field);
            if ($value!==null) { $params[$field]=$value; }
        }
        $sql='SELECT * FROM '.$table.($conditions===[]?'':' WHERE '.implode(' AND ',$conditions));
        if ($this->pdo->inTransaction() && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') { $sql.=' FOR UPDATE'; }
        $q=$this->pdo->prepare($sql); $q->execute($params); return $q->fetchAll();
    }
    /** @param array<string,mixed> $where @return array<string,mixed>|null */
    public function one(string $table,array $where): ?array { return $this->all($table,$where)[0] ?? null; }
    /** @param array<string,mixed> $data */
    public function insert(string $table,array $data): int
    {
        $this->table($table); foreach(array_keys($data) as $field) { $this->field($field); }
        $this->pdo->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES (:'.implode(',:',array_keys($data)).')')->execute($data);
        return (int)$this->pdo->lastInsertId();
    }
    /** @param array<string,mixed> $data @param array<string,mixed> $where */
    public function update(string $table,array $data,array $where): void
    {
        $this->table($table); if($where===[]) { throw new \LogicException('Missing scope'); }
        $sets=[]; $filters=[]; $params=[];
        foreach($data as $field=>$value) { $this->field($field); $sets[]=$field.'=:v_'.$field; $params['v_'.$field]=$value; }
        foreach($where as $field=>$value) { $this->field($field); $filters[]=$field.'=:w_'.$field; $params['w_'.$field]=$value; }
        $this->pdo->prepare('UPDATE '.$table.' SET '.implode(',',$sets).' WHERE '.implode(' AND ',$filters))->execute($params);
    }
    public function nextNumber(string $code): int
    {
        $q=$this->pdo->prepare('SELECT MAX(active_number) FROM invoices WHERE series_code=:code'); $q->execute(['code'=>$code]);
        $number=(int)$q->fetchColumn();
        $q=$this->pdo->prepare('SELECT MAX(invoice_number) FROM invoice_configuration_snapshots WHERE series_code=:code'); $q->execute(['code'=>$code]);
        return max($number,(int)$q->fetchColumn())+1;
    }
    public function latestId(): int { return (int)$this->pdo->query('SELECT MAX(id) FROM invoices WHERE deleted_at IS NULL')->fetchColumn(); }
    public function firstIssueDate(): ?string
    {
        $value=$this->pdo->query('SELECT MIN(issued_at) FROM invoices WHERE deleted_at IS NULL')->fetchColumn();
        return is_string($value) && $value!==''?(new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('Y-m-d'):null;
    }
    public function latestInSeries(string $code): int
    {
        $query=$this->pdo->prepare('SELECT id FROM invoices WHERE series_code=:code AND deleted_at IS NULL ORDER BY active_number DESC,id DESC LIMIT 1');
        $query->execute(['code'=>$code]);return (int)$query->fetchColumn();
    }
    public function usedSeries(int $id): bool
    {
        $q=$this->pdo->prepare('SELECT id FROM invoices WHERE series_id=:id LIMIT 1'); $q->execute(['id'=>$id]); return $q->fetchColumn()!==false;
    }
    /** @return list<array<string,mixed>> */
    public function eligibleOrders(): array
    {
        return $this->pdo->query("SELECT o.id,o.order_number,o.first_name,o.last_name,o.total_bani,o.currency FROM orders o WHERE o.status NOT IN ('cancelled','returned','manual_review') AND (o.payment_method='cod' OR o.payment_status='paid') AND NOT EXISTS(SELECT 1 FROM invoices i WHERE i.primary_order_id=o.id) ORDER BY o.id DESC LIMIT 100")->fetchAll();
    }
    /** @param array<string,mixed> $filters @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,from:int,to:int,per_page:int,search:array<string,mixed>} */
    public function registry(array $filters,bool $export=false): array
    {
        $where=['invoices.deleted_at IS NULL']; $args=[];
        $search=InvoiceSearch::parse($filters['cautare'] ?? ''); $scopes=[$filters];
        foreach($search['conditions'] as $condition) { $scopes[]=[$condition['field']=>$condition['value']]; }
        // Typed filters and recognised phrases intersect; a search never silently clears a chosen filter.
        foreach($scopes as $scopeIndex=>$scope) {
            foreach(['kind','payment_state','payment_method','email_state','fiscal_state'] as $field) {
                if(!empty($scope[$field]) && is_string($scope[$field])) { $parameter=$field.'_'.$scopeIndex; $where[]='invoices.'.$field.'=:'.$parameter; $args[$parameter]=$scope[$field]; }
            }
            $spvStates=match(!empty($scope['fiscal_state'])?'':($scope['spv_group'] ?? '')) {
                'not_sent'=>['unconfigured','not_sent','queued','scheduled','awaiting_configuration'],
                'sent'=>['submitted','accepted'],
                'attention'=>['rejected','transient_error','uncertain'],default=>[],
            };
            if($spvStates!==[]) { $where[]="fiscal_state IN ('".implode("','",$spvStates)."')"; }
            if(($scope['document_group'] ?? '')==='credits') { $where[]="invoices.kind IN ('return','correction_negative')"; }
            if(($scope['document_group'] ?? '')==='sales') { $where[]="invoices.kind IN ('sale','corrected_positive')"; }
            if(($scope['payment_group'] ?? '')==='outstanding') { $where[]="invoices.payment_state IN ('unpaid','partial')"; }
            if(($scope['email_group'] ?? '')==='not_sent') { $where[]="invoices.email_state IN ('not_sent','queued','failed','previewed','needs_resend')"; }
            if(in_array($scope['customer_type'] ?? '',['person','company'],true)) {
                $expression=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.customer.type'))":"json_extract(snapshot_json,'$.customer.type')";
                $parameter='customer_type_'.$scopeIndex; $where[]=$expression.'=:'.$parameter; $args[$parameter]=$scope['customer_type'];
            }
            if(in_array($scope['receipt'] ?? '',['pending','received'],true)) {
                $where[]="invoices.kind IN ('return','correction_negative')";
                $where[]="EXISTS(SELECT 1 FROM invoice_lines l WHERE l.invoice_id=invoices.id AND l.kind='product' AND l.quantity_units<0)";
                $where[]=$this->receiptNirSql().($scope['receipt']==='pending'?' IS NULL':' IS NOT NULL');
            }
        }
        foreach(['from'=>'>=','to'=>'<'] as $field=>$operator) {
            $value=(string)($filters[$field] ?? ''); if($value==='') { continue; }
            $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('Europe/Bucharest'));
            if(!$d || $d->format('Y-m-d')!==$value) { throw new InvoiceException('Alege o perioadă validă.'); }
            if($field==='to') { $d=$d->modify('+1 day'); }
            $where[]='invoices.issued_at'.$operator.':'.$field; $args[$field]=$d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        foreach($search['terms'] as $n=>$token) { $where[]='(search_text LIKE :s'.$n.' OR LOWER(fiscal_reference) LIKE :f'.$n.')'; $args['s'.$n]='%'.str_replace(['%','_'],['',''],$token).'%'; $args['f'.$n]=$args['s'.$n]; }
        $scope=implode(' AND ',$where); $q=$this->pdo->prepare('SELECT COUNT(*) FROM invoices WHERE '.$scope); $q->execute($args); $total=(int)$q->fetchColumn();
        $perPage=(int)($filters['per_page'] ?? 15); if(!in_array($perPage,[10,15,25,50],true)) { $perPage=15; }
        $pages=max(1,(int)ceil($total/$perPage)); $page=max(1,min($pages,(int)($filters['page'] ?? 1))); $offset=($page-1)*$perPage;
        $q=$this->pdo->prepare("SELECT invoices.*,n.id AS receipt_nir_id,n.number AS receipt_nir_number,
            (SELECT environment FROM invoice_fiscal_jobs WHERE invoice_id=invoices.id) AS fiscal_environment,
            EXISTS(SELECT 1 FROM invoice_lines l WHERE l.invoice_id=invoices.id AND l.kind='product' AND l.quantity_units<0) AS has_return_products
            FROM invoices LEFT JOIN nir_documents n ON n.id=".$this->receiptNirSql().' WHERE '.$scope.' ORDER BY invoices.id DESC'.($export?'':' LIMIT '.$perPage.' OFFSET '.$offset)); $q->execute($args);
        return ['items'=>$q->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>$pages,'from'=>$total===0?0:$offset+1,'to'=>min($offset+$perPage,$total),'per_page'=>$perPage,'search'=>$search];
    }
    /** Confirmed receipt document, including the older order-return entry point. */
    private function receiptNirSql(): string
    {
        return "COALESCE((SELECT n.id FROM nir_documents n JOIN invoice_receipts r ON r.id=n.invoice_receipt_id
            WHERE r.invoice_id=invoices.id AND r.reversed_at IS NULL AND n.type='customer_return' AND n.status='confirmed' ORDER BY n.id DESC LIMIT 1),
            (SELECT n.id FROM nir_documents n JOIN order_return_documents d ON d.return_id=n.return_id
            WHERE d.invoice_id=invoices.id AND n.type='customer_return' AND n.status='confirmed' ORDER BY n.id DESC LIMIT 1))";
    }
    /** @return array<string,mixed> */
    public function summary(): array
    {
        $summary=$this->pdo->query("SELECT COUNT(*) AS count,COALESCE(SUM(CASE WHEN gross_bani>0 THEN gross_bani ELSE 0 END),0) AS sales,COALESCE(SUM(CASE WHEN gross_bani<0 THEN -gross_bani ELSE 0 END),0) AS credits,COALESCE(SUM(CASE WHEN kind IN ('return','correction_negative') THEN 1 ELSE 0 END),0) AS credit_count FROM invoices WHERE deleted_at IS NULL")->fetch();
        $summary['outstanding']=0; $summary['collected']=0;
        $received=[]; $refunded=[];
        foreach($this->pdo->query('SELECT order_id,paid_bani FROM invoices WHERE primary_order_id IS NOT NULL AND deleted_at IS NULL')->fetchAll() as $row) { $received[$row['order_id']]=(int)$row['paid_bani']; }
        foreach($this->pdo->query("SELECT i.order_id,a.after_json FROM invoice_audit a JOIN invoices i ON i.id=a.invoice_id WHERE i.kind='corrected_positive' AND i.deleted_at IS NULL AND a.action='payment_recorded'")->fetchAll() as $row) { $received[$row['order_id']]=($received[$row['order_id']] ?? 0)+(int)(json_decode($row['after_json'],true)['amount_bani'] ?? 0); }
        // A recorded order balance remains authoritative even when the invoice is frozen.
        foreach($this->pdo->query("SELECT order_id,amount_bani FROM payments WHERE provider='manual_cod'")->fetchAll() as $row) { $received[$row['order_id']]=(int)$row['amount_bani']; }
        foreach($this->pdo->query('SELECT i.order_id,SUM(r.amount_bani) AS amount FROM invoice_refunds r JOIN invoices i ON i.id=r.invoice_id WHERE r.order_refund_id IS NULL GROUP BY i.order_id')->fetchAll() as $row) { $refunded[$row['order_id']]=(int)$row['amount']; }
        foreach($this->pdo->query('SELECT order_id,SUM(amount_bani) AS amount FROM order_refunds GROUP BY order_id')->fetchAll() as $row) { $refunded[$row['order_id']]=($refunded[$row['order_id']] ?? 0)+(int)$row['amount']; }
        $legacy=[];
        foreach($this->pdo->query('SELECT order_id,SUM(refunded_bani) AS amount FROM payments GROUP BY order_id')->fetchAll() as $row) { $legacy[$row['order_id']]=(int)$row['amount']; }
        foreach($this->pdo->query('SELECT order_id,SUM(refund_bani) AS amount FROM order_returns WHERE voided_at IS NULL GROUP BY order_id')->fetchAll() as $row) { $legacy[$row['order_id']]=max($legacy[$row['order_id']] ?? 0,(int)$row['amount']); }
        foreach($legacy as $orderId=>$amount) { $refunded[$orderId]=($refunded[$orderId] ?? 0)+$amount; }
        foreach($this->pdo->query('SELECT order_id,SUM(gross_bani) AS total FROM invoices WHERE deleted_at IS NULL GROUP BY order_id')->fetchAll() as $row) {
            $netCash=($received[$row['order_id']] ?? 0)-($refunded[$row['order_id']] ?? 0);
            $netInvoiced=(int)$row['total'];
            $summary['outstanding']+=max(0,$netInvoiced-$netCash);
            // A credit reduces the invoiced amount once; its later refund must not subtract it twice.
            $summary['collected']+=max(0,min($netInvoiced,$netCash));
        }
        return $summary;
    }
    public function orderNet(int $orderId): int
    {
        $q=$this->pdo->prepare('SELECT COALESCE(SUM(gross_bani),0) FROM invoices WHERE order_id=:id AND deleted_at IS NULL'); $q->execute(['id'=>$orderId]); return (int)$q->fetchColumn();
    }
    public function hasUnreturnedProducts(int $orderId): bool
    {
        $q=$this->pdo->prepare("SELECT l.quantity_units,COALESCE((SELECT SUM(-c.quantity_units) FROM invoice_lines c JOIN invoices ci ON ci.id=c.invoice_id WHERE c.parent_line_id=l.id AND c.quantity_units<0 AND ci.deleted_at IS NULL),0) AS credited FROM invoice_lines l JOIN invoices i ON i.id=l.invoice_id WHERE i.order_id=:id AND i.deleted_at IS NULL AND i.kind IN ('sale','corrected_positive') AND l.kind='product'");
        $q->execute(['id'=>$orderId]); foreach($q->fetchAll() as $line) { if((int)$line['quantity_units']>(int)$line['credited']) { return true; } } return false;
    }
    /** Amount actually captured is not multiplied by replacement invoices.
     * @return array{received:int,refunded:int}
     */
    public function orderCash(int $orderId): array
    {
        $root=$this->one('invoices',['primary_order_id'=>$orderId]); $received=(int)($root['paid_bani'] ?? 0);
        $q=$this->pdo->prepare("SELECT a.after_json FROM invoice_audit a JOIN invoices i ON i.id=a.invoice_id WHERE i.order_id=:id AND i.kind='corrected_positive' AND i.deleted_at IS NULL AND a.action='payment_recorded'"); $q->execute(['id'=>$orderId]);
        foreach($q->fetchAll() as $row) { $received+=(int)(json_decode($row['after_json'],true)['amount_bani'] ?? 0); }
        $manual=$this->one('payments',['order_id'=>$orderId,'provider'=>'manual_cod']);
        if($manual) { $received=(int)$manual['amount_bani']; }
        elseif(!$root) {
            $q=$this->pdo->prepare("SELECT COALESCE(SUM(amount_bani),0) FROM payments WHERE order_id=:id AND status IN ('paid','succeeded','refunded','partially_refunded')"); $q->execute(['id'=>$orderId]); $received=(int)$q->fetchColumn();
            if($received===0) { $order=$this->one('orders',['id'=>$orderId]); if(($order['payment_status'] ?? '')==='paid') { $received=(int)$order['total_bani']; } }
        }
        $q=$this->pdo->prepare('SELECT COALESCE(SUM(r.amount_bani),0) FROM invoice_refunds r JOIN invoices i ON i.id=r.invoice_id WHERE i.order_id=:id AND r.order_refund_id IS NULL'); $q->execute(['id'=>$orderId]);$refunded=(int)$q->fetchColumn();
        $q=$this->pdo->prepare('SELECT COALESCE(SUM(amount_bani),0) FROM order_refunds WHERE order_id=:id');$q->execute(['id'=>$orderId]);$refunded+=(int)$q->fetchColumn();
        $q=$this->pdo->prepare('SELECT COALESCE(SUM(refunded_bani),0) FROM payments WHERE order_id=:id');$q->execute(['id'=>$orderId]);$legacy=(int)$q->fetchColumn();
        $q=$this->pdo->prepare('SELECT COALESCE(SUM(refund_bani),0) FROM order_returns WHERE order_id=:id AND voided_at IS NULL');$q->execute(['id'=>$orderId]);$legacy=max($legacy,(int)$q->fetchColumn());
        return ['received'=>$received,'refunded'=>$refunded+$legacy];
    }
    /** Optimistic token also distinguishes changes made within the same second. */
    public function editVersion(int $orderId): string
    {
        $order=$this->one('orders',['id'=>$orderId]) ?? throw new InvoiceException('Comanda nu există.',404);
        $items=$this->all('order_items',['order_id'=>$orderId]);
        usort($items,static fn(array $a,array $b):int=>(int)$a['id']<=>(int)$b['id']);
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM order_document_operations WHERE order_id=:id');
        $q->execute(['id'=>$orderId]);
        return hash('sha256',InvoiceSnapshot::json([$order,$items,(int)$q->fetchColumn()]));
    }
    /** Optimistic token also distinguishes changes made within the same second. */
    public function paymentVersion(int $orderId): string
    {
        $order=$this->one('orders',['id'=>$orderId]) ?? throw new InvoiceException('Comanda nu există.',404);
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM order_document_operations WHERE order_id=:id');
        $q->execute(['id'=>$orderId]);
        return hash('sha256',InvoiceSnapshot::json([
            $order['updated_at'],$order['status'],$order['payment_status'],$order['total_bani'],$order['payment_method'],
            $this->orderCash($orderId),$this->orderNet($orderId),(int)$q->fetchColumn(),
        ]));
    }
    /** @return list<array<string,mixed>> */
    public function trace(int $lineId): array
    {
        $q=$this->pdo->prepare("SELECT c.*,l.nir_id,l.unit_cost_ron,l.received_at,n.number AS nir_number,n.invoice_series,n.invoice_number,n.supplier_snapshot,w.name AS warehouse_name FROM nir_fifo_consumptions c JOIN nir_fifo_layers l ON l.id=c.layer_id LEFT JOIN nir_documents n ON n.id=l.nir_id LEFT JOIN company_warehouses w ON w.id=l.warehouse_id WHERE c.source_type='invoice_line' AND c.source_id=:id ORDER BY c.id");
        $q->execute(['id'=>$lineId]); $rows=$q->fetchAll();
        $origins=(new \App\Modules\Inventory\NirRepository($this->pdo))->lotOrigins(array_map('intval',array_column($rows,'layer_id')));
        foreach($rows as &$row) { $row['origin']=$origins[(int)$row['layer_id']] ?? null; } unset($row);
        return $rows;
    }
    public function pendingUnits(int $lineId): int
    {
        $q=$this->pdo->prepare("SELECT COALESCE(SUM(quantity_units),0) FROM nir_pending_consumptions WHERE source_type='invoice_line' AND source_id=:id"); $q->execute(['id'=>$lineId]); return (int)$q->fetchColumn();
    }
    public function creditedUnits(int $lineId): int
    {
        $q=$this->pdo->prepare('SELECT COALESCE(SUM(-l.quantity_units),0) FROM invoice_lines l JOIN invoices i ON i.id=l.invoice_id WHERE l.parent_line_id=:id AND i.deleted_at IS NULL AND l.quantity_units<0'); $q->execute(['id'=>$lineId]); return (int)$q->fetchColumn();
    }
    /** @return list<array<string,mixed>> */
    public function children(int $id): array
    {
        $q=$this->pdo->prepare('SELECT * FROM invoices WHERE (parent_id=:parent OR replacement_for_id=:replacement) AND deleted_at IS NULL ORDER BY id'); $q->execute(['parent'=>$id,'replacement'=>$id]); return $q->fetchAll();
    }
    public static function normalize(string $text): string { return trim(preg_replace('/[^a-z0-9@]+/u',' ',strtr(mb_strtolower($text),['ă'=>'a','â'=>'a','î'=>'i','ș'=>'s','ş'=>'s','ț'=>'t','ţ'=>'t'])) ?? ''); }
    private function table(string $table): void { if(!in_array($table,self::TABLES,true)) { throw new \LogicException('Invalid invoice table'); } }
    private function field(string $field): void { if(!preg_match('/^[a-z][a-z0-9_]*$/D',$field)) { throw new \LogicException('Invalid invoice field'); } }
}
