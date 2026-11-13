<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use PDO;

final class InvoiceRepository
{
    private const TABLES = ['invoices','invoice_lines','invoice_actions','invoice_audit','invoice_public_links','invoice_receipts','invoice_receipt_allocations','invoice_return_requests','invoice_reminder_dismissals','invoice_refunds','invoice_automation_intents','outbox_messages','orders','order_items','users','company_warehouses','inventory_reservations','inventory_movements','audit_log','invoice_return_policy','invoice_fiscal_jobs'];
    public function __construct(private readonly PDO $pdo) {}
    public function pdo(): PDO { return $this->pdo; }
    public function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
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
    public function usedSeries(int $id): bool
    {
        $q=$this->pdo->prepare('SELECT id FROM invoices WHERE series_id=:id LIMIT 1'); $q->execute(['id'=>$id]); return $q->fetchColumn()!==false;
    }
    /** @return list<array<string,mixed>> */
    public function eligibleOrders(): array
    {
        return $this->pdo->query("SELECT o.id,o.order_number,o.first_name,o.last_name,o.total_bani,o.currency FROM orders o WHERE o.status NOT IN ('cancelled','returned','manual_review') AND (o.payment_method='cod' OR o.payment_status='paid') AND NOT EXISTS(SELECT 1 FROM invoices i WHERE i.primary_order_id=o.id) ORDER BY o.id DESC LIMIT 100")->fetchAll();
    }
    /** @param array<string,mixed> $filters @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,from:int,to:int} */
    public function registry(array $filters,bool $export=false): array
    {
        $where=['deleted_at IS NULL']; $args=[];
        foreach(['kind','payment_state','payment_method','email_state','fiscal_state'] as $field) {
            if(!empty($filters[$field]) && is_string($filters[$field])) { $where[]=$field.'=:'.$field; $args[$field]=$filters[$field]; }
        }
        if(in_array($filters['customer_type'] ?? '',['person','company'],true)) {
            $expression=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.customer.type'))":"json_extract(snapshot_json,'$.customer.type')";
            $where[]=$expression.'=:customer_type'; $args['customer_type']=$filters['customer_type'];
        }
        if(in_array($filters['receipt'] ?? '',['pending','received'],true)) {
            $where[]="kind IN ('return','correction_negative')";
            $where[]=($filters['receipt']==='pending'?'NOT ':'').'EXISTS(SELECT 1 FROM invoice_receipts r WHERE r.invoice_id=invoices.id AND r.reversed_at IS NULL)';
        }
        foreach(['from'=>'>=','to'=>'<'] as $field=>$operator) {
            $value=(string)($filters[$field] ?? ''); if($value==='') { continue; }
            $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('Europe/Bucharest'));
            if(!$d || $d->format('Y-m-d')!==$value) { throw new InvoiceException('Alege o perioadă validă.'); }
            if($field==='to') { $d=$d->modify('+1 day'); }
            $where[]='issued_at'.$operator.':'.$field; $args[$field]=$d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        $tokens=preg_split('/\s+/',self::normalize(substr((string)($filters['cautare'] ?? ''),0,300)),-1,PREG_SPLIT_NO_EMPTY) ?: [];
        foreach(array_slice($tokens,0,8) as $n=>$token) { $where[]='(search_text LIKE :s'.$n.' OR LOWER(fiscal_reference) LIKE :f'.$n.')'; $args['s'.$n]='%'.str_replace(['%','_'],['',''],$token).'%'; $args['f'.$n]=$args['s'.$n]; }
        $scope=implode(' AND ',$where); $q=$this->pdo->prepare('SELECT COUNT(*) FROM invoices WHERE '.$scope); $q->execute($args); $total=(int)$q->fetchColumn();
        $pages=max(1,(int)ceil($total/15)); $page=max(1,min($pages,(int)($filters['page'] ?? 1))); $offset=($page-1)*15;
        $q=$this->pdo->prepare('SELECT * FROM invoices WHERE '.$scope.' ORDER BY id DESC'.($export?'':' LIMIT 15 OFFSET '.$offset)); $q->execute($args);
        return ['items'=>$q->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>$pages,'from'=>$total===0?0:$offset+1,'to'=>min($offset+15,$total)];
    }
    /** @return array<string,mixed> */
    public function summary(): array
    {
        $summary=$this->pdo->query("SELECT COUNT(*) AS count,COALESCE(SUM(CASE WHEN gross_bani>0 THEN gross_bani ELSE 0 END),0) AS sales,COALESCE(SUM(CASE WHEN gross_bani<0 THEN -gross_bani ELSE 0 END),0) AS credits FROM invoices WHERE deleted_at IS NULL")->fetch();
        $summary['outstanding']=0;
        $received=[]; $refunded=[];
        foreach($this->pdo->query('SELECT order_id,paid_bani FROM invoices WHERE primary_order_id IS NOT NULL AND deleted_at IS NULL')->fetchAll() as $row) { $received[$row['order_id']]=(int)$row['paid_bani']; }
        foreach($this->pdo->query("SELECT i.order_id,a.after_json FROM invoice_audit a JOIN invoices i ON i.id=a.invoice_id WHERE i.kind='corrected_positive' AND i.deleted_at IS NULL AND a.action='payment_recorded'")->fetchAll() as $row) { $received[$row['order_id']]=($received[$row['order_id']] ?? 0)+(int)(json_decode($row['after_json'],true)['amount_bani'] ?? 0); }
        foreach($this->pdo->query('SELECT i.order_id,SUM(r.amount_bani) AS amount FROM invoice_refunds r JOIN invoices i ON i.id=r.invoice_id GROUP BY i.order_id')->fetchAll() as $row) { $refunded[$row['order_id']]=(int)$row['amount']; }
        foreach($this->pdo->query('SELECT order_id,SUM(gross_bani) AS total FROM invoices WHERE deleted_at IS NULL GROUP BY order_id')->fetchAll() as $row) { $summary['outstanding']+=max(0,(int)$row['total']-($received[$row['order_id']] ?? 0)+($refunded[$row['order_id']] ?? 0)); }
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
        $q=$this->pdo->prepare('SELECT COALESCE(SUM(r.amount_bani),0) FROM invoice_refunds r JOIN invoices i ON i.id=r.invoice_id WHERE i.order_id=:id'); $q->execute(['id'=>$orderId]);
        return ['received'=>$received,'refunded'=>(int)$q->fetchColumn()];
    }
    /** @return list<array<string,mixed>> */
    public function trace(int $lineId): array
    {
        $q=$this->pdo->prepare("SELECT c.*,l.nir_id,l.unit_cost_ron,l.received_at,n.number AS nir_number,n.invoice_series,n.invoice_number,n.supplier_snapshot,w.name AS warehouse_name FROM nir_fifo_consumptions c JOIN nir_fifo_layers l ON l.id=c.layer_id LEFT JOIN nir_documents n ON n.id=l.nir_id LEFT JOIN company_warehouses w ON w.id=l.warehouse_id WHERE c.source_type='invoice_line' AND c.source_id=:id ORDER BY c.id");
        $q->execute(['id'=>$lineId]); return $q->fetchAll();
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
