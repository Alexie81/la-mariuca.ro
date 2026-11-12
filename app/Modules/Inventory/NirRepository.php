<?php
declare(strict_types=1);
namespace App\Modules\Inventory;

use PDO;

/** All persistence for receipt workflows. The permanent lock serializes even first-document creation. */
final class NirRepository
{
    private const TABLES = ['nir_documents','nir_lines','nir_locations','nir_supplier_references','nir_stock_balances','nir_stock_movements','nir_fifo_layers','nir_fifo_consumptions','nir_pending_consumptions','nir_consumption_requests','nir_attachments','nir_audit','nir_idempotency','nir_outbox','nir_return_reminders','nir_sequences','suppliers','companies','company_warehouses','products','product_variants','order_returns','order_return_items','orders','order_items','users','accounting_stock_movements'];
    /** @var array<string,list<string>> */
    private array $columns = [];
    public function __construct(private readonly PDO $pdo) {}

    public function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $own = !$this->pdo->inTransaction();
        if ($own) { $this->pdo->beginTransaction(); }
        try {
            $this->pdo->exec('UPDATE nir_write_lock SET revision=revision+1 WHERE id=1');
            $result = $callback();
            if ($own) { $this->pdo->commit(); }
            return $result;
        } catch (\Throwable $exception) {
            if ($own && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $where @return list<array<string,mixed>> */
    public function all(string $table, array $where = [], string $order = 'id'): array
    {
        $table = $this->table($table);
        $parameters=[]; $conditions=[];
        foreach ($where as $field=>$value) {
            $field=$this->column($table,$field);
            if ($value === null) { $conditions[]=$field.' IS NULL'; } else { $conditions[]=$field.'=:w_'.$field; $parameters['w_'.$field]=$value; }
        }
        $orderSql='';
        if ($order !== '') {
            $parts=[];
            foreach (explode(',',$order) as $part) {
                $bits=explode(' ',trim($part)); $parts[]=$this->column($table,$bits[0]).(strtoupper($bits[1] ?? '') === 'DESC' ? ' DESC' : ' ASC');
            }
            $orderSql=' ORDER BY '.implode(',',$parts);
        }
        $query=$this->pdo->prepare('SELECT * FROM '.$table.($conditions === [] ? '' : ' WHERE '.implode(' AND ',$conditions)).$orderSql);
        $query->execute($parameters);
        return $query->fetchAll();
    }

    /** @param array<string,mixed> $where @return array<string,mixed>|null */
    public function one(string $table, array $where): ?array { return $this->all($table,$where,'')[0] ?? null; }

    /** @param array<string,mixed> $values */
    public function insert(string $table, array $values): int
    {
        $table=$this->table($table); $columns=[];
        foreach (array_keys($values) as $field) { $columns[]=$this->column($table,$field); }
        $query=$this->pdo->prepare('INSERT INTO '.$table.' ('.implode(',',$columns).') VALUES (:'.implode(',:',$columns).')');
        $query->execute($values);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $values @param array<string,mixed> $where */
    public function update(string $table, array $values, array $where): void
    {
        $table=$this->table($table); $sets=[]; $filters=[]; $parameters=[];
        if ($where === []) { throw new \LogicException('An explicit update scope is required.'); }
        foreach ($values as $field=>$value) { $field=$this->column($table,$field); $sets[]=$field.'=:v_'.$field; $parameters['v_'.$field]=$value; }
        foreach ($where as $field=>$value) { $field=$this->column($table,$field); $filters[]=$field.'=:w_'.$field; $parameters['w_'.$field]=$value; }
        $this->pdo->prepare('UPDATE '.$table.' SET '.implode(',',$sets).' WHERE '.implode(' AND ',$filters))->execute($parameters);
    }

    /** @param array<string,mixed> $where */
    public function delete(string $table, array $where): void
    {
        $table=$this->table($table); $filters=[];
        if ($where === []) { throw new \LogicException('An explicit delete scope is required.'); }
        foreach ($where as $field=>$value) { $filters[]=$this->column($table,$field).'=:'.$field; }
        $this->pdo->prepare('DELETE FROM '.$table.' WHERE '.implode(' AND ',$filters))->execute($where);
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function registry(array $filters): array
    {
        $where=[]; $params=[];
        $status=(string)($filters['status'] ?? $filters['stare'] ?? '');
        if (in_array($status,['draft','confirmed'],true)) { $where[]='d.status=:status'; $params['status']=$status; }
        if (in_array($status,['supplier_return','reversed','storno'],true)) { $where[]="d.type='supplier_return'"; }
        $dates=[];
        foreach (['from'=>'>=','to'=>'<'] as $field=>$operator) {
            $value=trim((string)($filters[$field] ?? ''));
            if ($value!=='') {
                if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)) { throw new NirValidationException([$field=>'Perioada selectată nu este validă.']); }
                $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('Europe/Bucharest'));
                if($date===false||$date->format('Y-m-d')!==$value) { throw new NirValidationException([$field=>'Perioada selectată nu este validă.']); }
                $dates[$field]=$value;
                if($field==='to') { $date=$date->modify('+1 day'); }
                $where[]='d.nir_at'.$operator.':'.$field; $params[$field]=$date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            }
        }
        if(isset($dates['from'],$dates['to'])&&$dates['from']>$dates['to']) { throw new NirValidationException(['to'=>'Data de sfârșit trebuie să fie după data de început sau în aceeași zi.']); }
        $query=$this->pdo->prepare('SELECT d.*,s.alias AS supplier_alias,s.company_name AS supplier_legal_name,(SELECT COUNT(*) FROM nir_lines l WHERE l.nir_id=d.id) AS line_count,(SELECT COUNT(*) FROM nir_attachments a WHERE a.nir_id=d.id) AS attachment_count FROM nir_documents d LEFT JOIN suppliers s ON s.id=d.supplier_id'.($where === [] ? '' : ' WHERE '.implode(' AND ',$where)).' ORDER BY d.created_at DESC,d.id DESC');
        $query->execute($params);
        $rows=$query->fetchAll();
        $search=self::normalize((string)($filters['search'] ?? $filters['cautare'] ?? ''));
        if ($search !== '') {
            $terms=explode(' ',$search);
            $rows=array_values(array_filter($rows,static function(array $row) use($terms): bool {
                $displayNumber=$row['number'] ?: (!empty($row['draft_sequence'])?'Draft #'.(int)$row['draft_sequence']:'Draft');
                $haystack=self::normalize($displayNumber.' '.implode(' ',array_map(static fn(string $key):string=>(string)($row[$key] ?? ''),['number','temp_number','invoice_series','invoice_number','external_id','supplier_alias','supplier_legal_name','supplier_snapshot'])));
                foreach ($terms as $term) { if (!str_contains($haystack,$term)) { return false; } } return true;
            }));
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function catalog(): array
    {
        return $this->pdo->query("SELECT p.id AS product_id,p.name AS product_name,p.primary_sku,p.track_in_conta AS product_track,p.brand,p.slug,p.status,c.name AS category_name,v.id AS variant_id,v.sku,v.ean,v.option_summary,v.options_json,v.track_in_conta AS variant_track,v.status AS variant_status,COALESCE(vm.public_path,pm.public_path,'') AS image_path FROM products p LEFT JOIN categories c ON c.id=p.primary_category_id LEFT JOIN product_variants v ON v.product_id=p.id LEFT JOIN media vm ON vm.id=v.image_media_id LEFT JOIN media pm ON pm.id=(SELECT pi.media_id FROM product_images pi WHERE pi.product_id=p.id ORDER BY pi.position,pi.media_id LIMIT 1) ORDER BY p.name,v.is_default DESC,v.id")->fetchAll();
    }

    public static function normalize(string $text): string
    {
        if (class_exists(\Normalizer::class)) { $text=\Normalizer::normalize($text,\Normalizer::FORM_KC) ?: $text; }
        return mb_strtoupper(trim(preg_replace('/\s+/u',' ',$text) ?? $text),'UTF-8');
    }

    public function nextNumber(string $date): string
    {
        $year=(int)(new \DateTimeImmutable($date,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('Y'); $sequence=$this->one('nir_sequences',['year'=>$year]);
        $number=$sequence === null ? 1 : (int)$sequence['next_number'];
        if ($sequence === null) { $this->insert('nir_sequences',['year'=>$year,'next_number'=>2]); } else { $this->update('nir_sequences',['next_number'=>$number+1],['year'=>$year]); }
        return sprintf('LM-NIR-%d-%d',$year,$number);
    }

    /** Called only under the shared NIR write lock, independently of confirmed numbering. */
    public function nextDraftNumber(): int
    {
        $number=(int)$this->pdo->query('SELECT next_number FROM nir_draft_sequence WHERE id=1')->fetchColumn();
        $this->pdo->exec('UPDATE nir_draft_sequence SET next_number=next_number+1 WHERE id=1');
        return $number;
    }

    /** @return array{total:int,draft:int,confirmed:int} */
    public function counts(): array
    {
        $result=['total'=>0,'draft'=>0,'confirmed'=>0];
        foreach ($this->pdo->query('SELECT status,COUNT(*) AS total FROM nir_documents GROUP BY status')->fetchAll() as $row) { $result['total']+=(int)$row['total']; if (isset($result[$row['status']])) { $result[$row['status']]=(int)$row['total']; } }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function pendingReturnNotifications(int $actorId,string $localDate): array
    {
        $query=$this->pdo->prepare("SELECT r.id AS return_id,r.order_id,r.created_at,o.order_number FROM order_returns r JOIN orders o ON o.id=r.order_id WHERE NOT EXISTS (SELECT 1 FROM nir_documents n WHERE n.return_id=r.id AND n.status='confirmed') AND EXISTS (SELECT 1 FROM order_return_items ri JOIN order_items oi ON oi.id=ri.order_item_id WHERE ri.return_id=r.id AND oi.accounting_scope_type IS NOT NULL) AND NOT EXISTS (SELECT 1 FROM nir_return_reminders d WHERE d.return_id=r.id AND d.actor_id=:actor AND d.dismissed_on=:today) ORDER BY r.created_at DESC,r.id DESC LIMIT 200");
        $query->execute(['actor'=>$actorId,'today'=>$localDate]); return $query->fetchAll();
    }

    private function table(string $table): string
    {
        if (!in_array($table,self::TABLES,true)) { throw new \LogicException('Unsupported receipt table.'); }
        return $table;
    }
    private function column(string $table,string $column): string
    {
        if (!isset($this->columns[$table])) {
            $query=$this->pdo->query('SELECT * FROM '.$this->table($table).' LIMIT 0'); $this->columns[$table]=[];
            for($index=0;$index<$query->columnCount();$index++) { $metadata=$query->getColumnMeta($index); if (is_array($metadata)) { $this->columns[$table][]=(string)$metadata['name']; } }
        }
        if (!in_array($column,$this->columns[$table],true)) { throw new \LogicException('Unsupported receipt column: '.$table.'.'.$column); }
        return $column;
    }
}
