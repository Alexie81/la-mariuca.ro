<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use PDO;

final class OrderTrackingRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed>|null */
    public function identity(int $id): ?array
    {
        $query = $this->pdo->prepare('SELECT id,order_number,email,public_token_hash FROM orders WHERE id=:id');
        $query->execute(['id' => $id]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    public function lookup(string $number, string $email): ?int
    {
        $query = $this->pdo->prepare('SELECT id,email FROM orders WHERE order_number=:number LIMIT 1');
        $query->execute(['number' => strtoupper(trim($number))]);
        $row = $query->fetch();
        if (!is_array($row) || !hash_equals(mb_strtolower(trim((string) $row['email'])), mb_strtolower(trim($email)))) {
            return null;
        }
        return (int) $row['id'];
    }

    /** @return list<array<string,mixed>> */
    public function events(int $orderId): array
    {
        $query = $this->pdo->prepare("SELECT action,context_json,created_at FROM audit_log WHERE entity_type='order' AND entity_id=:id ORDER BY id");
        $query->execute(['id' => $orderId]);
        return $query->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function documents(int $orderId): array
    {
        $query = $this->pdo->prepare('SELECT id,document_number,private_path,created_at FROM order_documents WHERE order_id=:id AND revoked_at IS NULL ORDER BY id DESC');
        $query->execute(['id' => $orderId]);
        return $query->fetchAll();
    }

    public function addInvoice(int $orderId, string $number, string $privatePath): int
    {
        $query = $this->pdo->prepare('INSERT INTO order_documents(order_id,document_number,private_path,created_at) VALUES (:order,:number,:path,:now)');
        $query->execute(['order' => $orderId, 'number' => $number, 'path' => $privatePath, 'now' => gmdate('c')]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function cancellationRequest(int $orderId): ?array
    {
        $query = $this->pdo->prepare('SELECT id,reason,order_status_at_request,created_at FROM order_cancellation_requests WHERE order_id=:order');
        $query->execute(['order' => $orderId]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed>|null */
    public function lockCancellationOrder(int $orderId): ?array
    {
        $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $query = $this->pdo->prepare('SELECT id,status,created_at FROM orders WHERE id=:id' . $lock);
        $query->execute(['id' => $orderId]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    public function addCancellationRequest(int $orderId, string $reason, string $orderStatus, string $now): int
    {
        $this->pdo->prepare('INSERT INTO order_cancellation_requests(order_id,reason,order_status_at_request,created_at) VALUES (:order,:reason,:status,:now)')
            ->execute(['order'=>$orderId, 'reason'=>$reason, 'status'=>$orderStatus, 'now'=>$now]);
        $requestId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES (NULL,'order.cancellation_requested','order',:order,:context,:now)")
            ->execute(['order'=>$orderId, 'context'=>json_encode(['request_id'=>$requestId, 'source'=>'customer_tracking', 'reason'=>$reason], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'now'=>$now]);
        return $requestId;
    }
}
