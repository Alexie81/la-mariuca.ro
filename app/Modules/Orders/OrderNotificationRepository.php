<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use PDO;

final class OrderNotificationRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<string> */
    public function recipients(): array
    {
        $json = $this->pdo->query('SELECT recipients_json FROM order_notification_settings WHERE id=1')->fetchColumn();
        $recipients = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($recipients) ? array_values($recipients) : [];
    }

    public function canManage(int $actorId): bool
    {
        $statement = $this->pdo->prepare("SELECT id FROM users WHERE id=:id AND role IN ('administrator','operator') AND status='active'");
        $statement->execute(['id' => $actorId]);
        return $statement->fetchColumn() !== false;
    }

    /** @param list<string> $recipients */
    public function save(array $recipients, int $actorId): void
    {
        $now = $this->now();
        $this->pdo->prepare('UPDATE order_notification_settings SET recipients_json=:recipients,updated_by=:actor,updated_at=:now WHERE id=1')
            ->execute(['recipients' => json_encode($recipients, JSON_THROW_ON_ERROR), 'actor' => $actorId, 'now' => $now]);
        // Keep a useful audit without duplicating recipient contact information in logs.
        $this->pdo->prepare("INSERT INTO audit_log (actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES (:actor,'order_notification.settings_updated','order_notification_settings',1,:context,:now)")
            ->execute(['actor' => $actorId, 'context' => json_encode(['recipient_count' => count($recipients)], JSON_THROW_ON_ERROR), 'now' => $now]);
    }

    /** @return array<string,mixed>|null */
    public function lockedOrder(int $orderId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id,payment_method,payment_status,status FROM orders WHERE id=:id'
            . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
        $statement->execute(['id' => $orderId]);
        $order = $statement->fetch();
        return is_array($order) ? $order : null;
    }

    public function hasDelivery(int $orderId, string $recipient): bool
    {
        $statement = $this->pdo->prepare('SELECT id FROM order_internal_email_deliveries WHERE order_id=:order_id AND recipient=:recipient');
        $statement->execute(['order_id' => $orderId, 'recipient' => $recipient]);
        return $statement->fetchColumn() !== false;
    }

    public function enqueue(int $orderId, string $recipient): void
    {
        $now = $this->now();
        $this->pdo->prepare("INSERT INTO outbox_messages (message_type,recipient,payload_json,status,attempts,available_at,created_at) VALUES ('internal_order_notification',:recipient,:payload,'pending',0,:available,:created)")
            ->execute(['recipient' => $recipient, 'payload' => json_encode(['order_id' => $orderId], JSON_THROW_ON_ERROR), 'available' => $now, 'created' => $now]);
        $outboxId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO order_internal_email_deliveries (order_id,recipient,outbox_message_id,created_at) VALUES (:order_id,:recipient,:outbox,:now)')
            ->execute(['order_id' => $orderId, 'recipient' => $recipient, 'outbox' => $outboxId, 'now' => $now]);
    }

    private function now(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? gmdate('Y-m-d H:i:s') : gmdate('c');
    }
}
