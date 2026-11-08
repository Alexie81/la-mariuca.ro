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
        $query = $this->pdo->prepare('SELECT id,order_id,reason,order_status_at_request,created_at,status,decision_reason,resolved_at FROM order_cancellation_requests WHERE order_id=:order');
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
        $query = $this->pdo->prepare('SELECT id,status,created_at,email,payment_method,payment_status FROM orders WHERE id=:id' . $lock);
        $query->execute(['id' => $orderId]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    public function addCancellationRequest(int $orderId, string $reason, string $orderStatus, string $now): int
    {
        $this->pdo->prepare("INSERT INTO order_cancellation_requests(order_id,reason,order_status_at_request,created_at,decision_reason) VALUES (:order,:reason,:status,:now,'')")
            ->execute(['order'=>$orderId, 'reason'=>$reason, 'status'=>$orderStatus, 'now'=>$now]);
        $requestId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES (NULL,'order.cancellation_requested','order',:order,:context,:now)")
            ->execute(['order'=>$orderId, 'context'=>json_encode(['request_id'=>$requestId, 'source'=>'customer_tracking', 'reason'=>$reason], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'now'=>$now]);
        return $requestId;
    }

    public function canReviewCancellation(int $actorId): bool
    {
        $statement = $this->pdo->prepare("SELECT id FROM users WHERE id=:id AND role IN ('administrator','operator') AND status='active'");
        $statement->execute(['id'=>$actorId]);
        return $statement->fetchColumn() !== false;
    }

    public function resolveCancellation(int $requestId, string $status, string $reason, int $actorId, string $now): bool
    {
        $statement = $this->pdo->prepare("UPDATE order_cancellation_requests SET status=:status,decision_reason=:reason,resolved_by=:actor,resolved_at=:now WHERE id=:id AND status='pending'");
        $statement->execute(['status'=>$status, 'reason'=>$reason, 'actor'=>$actorId, 'now'=>$now, 'id'=>$requestId]);
        return $statement->rowCount() === 1;
    }

    public function auditCancellationDecision(int $orderId, int $requestId, string $decision, string $reason, int $actorId, string $now): void
    {
        $this->pdo->prepare("INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES (:actor,:action,'order',:order,:context,:now)")
            ->execute(['actor'=>$actorId, 'action'=>'order.cancellation_' . $decision, 'order'=>$orderId,
                'context'=>json_encode(['request_id'=>$requestId, 'decision'=>$decision, 'reason'=>$reason], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'now'=>$now]);
    }

    /** Called only while inserting the unique request or resolving it under the order lock. */
    public function queueCancellationEmail(int $orderId, int $requestId, string $recipient, string $type, string $now): void
    {
        if (!in_array($type, ['order_cancellation_received','internal_order_cancellation','order_cancellation_decision'], true)) {
            throw new \InvalidArgumentException('Tip de notificare de anulare invalid.');
        }
        $this->pdo->prepare("INSERT INTO outbox_messages(message_type,recipient,payload_json,status,attempts,available_at,created_at) VALUES (:type,:recipient,:payload,'pending',0,:now,:now)")
            ->execute(['type'=>$type, 'recipient'=>$recipient, 'payload'=>json_encode(['order_id'=>$orderId, 'request_id'=>$requestId], JSON_THROW_ON_ERROR), 'now'=>$now]);
    }

    /** @return list<string> */
    public function internalNotificationRecipients(): array
    {
        return (new OrderNotificationRepository($this->pdo))->recipients();
    }

    public function now(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? gmdate('Y-m-d H:i:s') : gmdate('c');
    }
}
