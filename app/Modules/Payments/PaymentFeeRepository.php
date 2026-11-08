<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use PDO;

final class PaymentFeeRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(?string $method = null): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM payment_operational_fees WHERE deleted_at IS NULL'
            . ($method === null ? '' : ' AND payment_method=:method') . ' ORDER BY payment_method,id');
        $statement->execute($method === null ? [] : ['method' => $method]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM payment_operational_fees WHERE id=:id AND deleted_at IS NULL');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function save(string $method, string $name, int $amount, int $actorId, ?int $id): int
    {
        $now = $this->now();
        if ($id === null) {
            $this->pdo->prepare('INSERT INTO payment_operational_fees (payment_method,name,amount_bani,created_by,created_at,updated_at) VALUES (:method,:name,:amount,:actor,:created,:updated)')
                ->execute(['method' => $method, 'name' => $name, 'amount' => $amount, 'actor' => $actorId, 'created' => $now, 'updated' => $now]);
            $id = (int) $this->pdo->lastInsertId();
        } else {
            $this->pdo->prepare('UPDATE payment_operational_fees SET payment_method=:method,name=:name,amount_bani=:amount,updated_at=:now WHERE id=:id AND deleted_at IS NULL')
                ->execute(['method' => $method, 'name' => $name, 'amount' => $amount, 'now' => $now, 'id' => $id]);
        }
        return $id;
    }

    public function delete(int $id): void
    {
        $now = $this->now();
        $this->pdo->prepare('UPDATE payment_operational_fees SET deleted_at=:deleted,updated_at=:updated WHERE id=:id AND deleted_at IS NULL')
            ->execute(['deleted' => $now, 'updated' => $now, 'id' => $id]);
    }

    /** @param array<string,mixed> $context */
    public function audit(int $id, int $actorId, string $action, array $context): void
    {
        $this->pdo->prepare("INSERT INTO audit_log (actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES (:actor,:action,'payment_operational_fee',:id,:context,:now)")
            ->execute(['actor' => $actorId, 'action' => $action, 'id' => $id, 'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'now' => $this->now()]);
    }

    private function now(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? gmdate('Y-m-d H:i:s') : gmdate('c');
    }
}
