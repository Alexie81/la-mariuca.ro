<?php

declare(strict_types=1);

namespace App\Modules\Invoicing;

use PDO;

final class DispatchNoteRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function pdo(): PDO { return $this->pdo; }
    public function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

    public function latestId(): int
    {
        return (int)$this->pdo->query('SELECT MAX(id) FROM dispatch_notes WHERE deleted_at IS NULL')->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function activeForOrder(int $orderId): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM dispatch_notes WHERE active_order_id=:order AND deleted_at IS NULL LIMIT 1');
        $query->execute(['order'=>$orderId]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM dispatch_notes WHERE id=:id LIMIT 1');
        $query->execute(['id'=>$id]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function action(string $key): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM dispatch_note_actions WHERE request_key=:key LIMIT 1');
        $query->execute(['key'=>$key]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    public function order(int $id): array
    {
        $query = $this->pdo->prepare('SELECT * FROM orders WHERE id=:id LIMIT 1');
        $query->execute(['id'=>$id]);
        $row = $query->fetch();
        if (!is_array($row)) { throw new InvoiceException('Comanda nu există.',404); }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function orderItems(int $id): array
    {
        $query = $this->pdo->prepare("SELECT oi.*,COALESCE(vm.public_path,(SELECT m.public_path FROM product_images pi JOIN media m ON m.id=pi.media_id WHERE pi.product_id=oi.product_id AND m.public_path IS NOT NULL ORDER BY pi.position,pi.media_id LIMIT 1)) AS image_path FROM order_items oi LEFT JOIN product_variants v ON v.id=oi.variant_id LEFT JOIN media vm ON vm.id=v.image_media_id WHERE oi.order_id=:id ORDER BY oi.id");
        $query->execute(['id'=>$id]);
        return $query->fetchAll();
    }

    /** @return array<string,mixed> */
    public function company(): array
    {
        $row = $this->pdo->query('SELECT * FROM companies ORDER BY is_default DESC,id LIMIT 1')->fetch();
        if (!is_array($row)) { throw new InvoiceException('Completează mai întâi datele firmei.'); }
        return $row;
    }

    public function fiscalBlocksDeletion(int $orderId): bool
    {
        $query = $this->pdo->prepare("SELECT COUNT(*) FROM invoices WHERE order_id=:order AND deleted_at IS NULL AND (fiscal_attempted_at IS NOT NULL OR fiscal_reference IS NOT NULL OR fiscal_state NOT IN ('unconfigured','not_sent','queued','scheduled','awaiting_configuration'))");
        $query->execute(['order'=>$orderId]);
        return (int)$query->fetchColumn() > 0;
    }

    /** @return array{series:string,number:int} */
    public function nextNumber(string $issuedAt): array
    {
        $year = (int)(new \DateTimeImmutable($issuedAt,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('Y');
        $query = $this->pdo->prepare('SELECT next_number FROM dispatch_note_sequences WHERE year=:year');
        $query->execute(['year'=>$year]);
        $number = $query->fetchColumn();
        if ($number === false) {
            $number = 1;
            $this->pdo->prepare('INSERT INTO dispatch_note_sequences(year,next_number) VALUES(:year,2)')->execute(['year'=>$year]);
        } else {
            $this->pdo->prepare('UPDATE dispatch_note_sequences SET next_number=next_number+1 WHERE year=:year')->execute(['year'=>$year]);
        }
        return ['series'=>'LM-AVZ-'.$year,'number'=>(int)$number];
    }

    /** @param array<string,mixed> $data */
    public function insert(array $data): int
    {
        $this->pdo->prepare('INSERT INTO dispatch_notes(document_key,order_id,active_order_id,series_code,number,issued_at,snapshot_json,row_version,created_by,deleted_at,updated_at) VALUES(:document_key,:order_id,:active_order_id,:series_code,:number,:issued_at,:snapshot_json,:row_version,:created_by,:deleted_at,:updated_at)')->execute($data);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $id,array $data): void
    {
        $allowed=['active_order_id','snapshot_json','row_version','deleted_at','updated_at']; $sets=[]; $params=['id'=>$id];
        foreach($data as $field=>$value) {
            if(!in_array($field,$allowed,true)) { throw new \LogicException('Invalid dispatch note field'); }
            $sets[]=$field.'=:'.$field; $params[$field]=$value;
        }
        $this->pdo->prepare('UPDATE dispatch_notes SET '.implode(',',$sets).' WHERE id=:id')->execute($params);
    }

    public function remember(string $key,string $action,string $hash,int $id): void
    {
        $this->pdo->prepare('INSERT INTO dispatch_note_actions(request_key,action,request_hash,dispatch_note_id,created_at) VALUES(:key,:action,:hash,:id,:created)')->execute(['key'=>$key,'action'=>$action,'hash'=>$hash,'id'=>$id,'created'=>$this->now()]);
    }

    /** @param array<string,mixed> $context */
    public function audit(int $id,int $actor,string $action,array $context): void
    {
        $this->pdo->prepare('INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES(:actor,:action,\'dispatch_note\',:id,:context,:created)')->execute(['actor'=>$actor,'action'=>$action,'id'=>$id,'context'=>InvoiceSnapshot::json($context),'created'=>$this->now()]);
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $own=!$this->pdo->inTransaction(); if($own){$this->pdo->beginTransaction();}
        try {
            $this->pdo->exec('UPDATE nir_write_lock SET revision=revision+1 WHERE id=1');
            $result=$work(); if($own){$this->pdo->commit();} return $result;
        } catch(\Throwable $error) { if($own&&$this->pdo->inTransaction()){$this->pdo->rollBack();} throw $error; }
    }
}
