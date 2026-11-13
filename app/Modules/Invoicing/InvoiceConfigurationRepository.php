<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use PDO;

final class InvoiceConfigurationRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function hasIssued(int $seriesId): bool
    {
        $q=$this->pdo->prepare('SELECT id FROM invoices WHERE series_id=:id LIMIT 1'); $q->execute(['id'=>$seriesId]); return $q->fetchColumn()!==false;
    }

    /** @return array<string,mixed> */
    public function settings(): array
    {
        return $this->pdo->query('SELECT c.*,s.code AS active_code,s.label AS active_label FROM invoice_configuration c JOIN invoice_series s ON s.id=c.active_series_id WHERE c.id=1' . $this->readLock())->fetch();
    }

    /** @return list<array<string,mixed>> */
    public function series(): array
    {
        return $this->pdo->query('SELECT s.*,CASE WHEN s.id=c.active_series_id THEN 1 ELSE 0 END AS in_use FROM invoice_series s CROSS JOIN invoice_configuration c WHERE s.deleted_at IS NULL ORDER BY in_use DESC,s.id ASC' . $this->readLock())->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM invoice_series WHERE id=:id AND deleted_at IS NULL' . $this->readLock());
        $query->execute(['id'=>$id]);
        return $query->fetch() ?: null;
    }

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
            // Singleton write lock serializes configuration and invoice issuance, including on MySQL.
            $this->pdo->exec('UPDATE invoice_configuration SET revision=revision WHERE id=1');
            $result = $callback();
            if ($own) { $this->pdo->commit(); }
            return $result;
        } catch (\Throwable $error) {
            if ($own && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    public function hasCode(string $code, ?int $except): bool
    {
        $query = $this->pdo->prepare('SELECT id FROM invoice_series WHERE code=:code AND id<>:id AND deleted_at IS NULL' . $this->readLock());
        $query->execute(['code'=>$code,'id'=>$except ?? 0]);
        return $query->fetchColumn() !== false;
    }

    public function saveSeries(?int $id, string $code, string $label): int
    {
        $values = ['code'=>$code,'label'=>$label,'now'=>gmdate('c')];
        if ($id === null) {
            $this->pdo->prepare('INSERT INTO invoice_series (code,label,created_at,updated_at) VALUES (:code,:label,:created,:now)')->execute($values + ['created'=>$values['now']]);
            return (int) $this->pdo->lastInsertId();
        }
        $this->pdo->prepare('UPDATE invoice_series SET code=:code,label=:label,updated_at=:now WHERE id=:id')->execute($values + ['id'=>$id]);
        return $id;
    }

    public function activate(int $id): void
    {
        $this->pdo->prepare('UPDATE invoice_configuration SET active_series_id=:id WHERE id=1')->execute(['id'=>$id]);
    }

    public function remove(int $id): void
    {
        $this->pdo->prepare('UPDATE invoice_series SET deleted_at=:now WHERE id=:id')->execute(['now'=>gmdate('c'),'id'=>$id]);
    }

    public function saveDefaults(int $days, string $notes): void
    {
        $this->pdo->prepare('UPDATE invoice_configuration SET due_days=:days,default_notes=:notes WHERE id=1')->execute(['days'=>$days,'notes'=>$notes]);
    }

    /** @param array<string,mixed> $context */
    public function changed(int $actorId, string $action, array $context): void
    {
        $now = gmdate('c');
        $this->pdo->prepare('UPDATE invoice_configuration SET revision=revision+1,updated_at=:now WHERE id=1')->execute(['now'=>$now]);
        $this->pdo->prepare("INSERT INTO audit_log (actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES (:actor,:action,'invoice_configuration',1,:context,:now)")->execute([
            'actor'=>$actorId > 0 ? $actorId : null,'action'=>'invoice_configuration.' . $action,
            'context'=>json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),'now'=>$now,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function snapshot(string $key): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM invoice_configuration_snapshots WHERE document_key=:key' . $this->readLock());
        $query->execute(['key'=>$key]);
        return $query->fetch() ?: null;
    }

    /** @param array<string,mixed> $data */
    public function insertSnapshot(array $data): void
    {
        $query = $this->pdo->prepare('SELECT document_key FROM invoice_configuration_snapshots WHERE series_code=:code AND invoice_number=:number' . $this->readLock());
        $query->execute(['code'=>$data['series_code'],'number'=>$data['invoice_number']]);
        if ($query->fetchColumn() !== false) {
            throw new InvoiceConfigurationException('Acest număr este deja folosit în seria aleasă.');
        }
        $this->pdo->prepare('INSERT INTO invoice_configuration_snapshots (document_key,series_id,series_code,series_label,invoice_number,due_days,default_notes,issued_at,due_date,configuration_revision) VALUES (:document_key,:series_id,:series_code,:series_label,:invoice_number,:due_days,:default_notes,:issued_at,:due_date,:configuration_revision)')->execute($data);
    }

    private function readLock(): string
    {
        // A current read is needed even if the caller's MySQL invoice transaction
        // already established a repeatable-read snapshot before taking our lock.
        return $this->pdo->inTransaction() && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
