<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use PDO;
use RuntimeException;

final class OrderService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        return $this->pdo->query("SELECT * FROM orders ORDER BY id DESC LIMIT {$limit}")->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findForAdmin(int $orderId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $orderId]);
        $order = $statement->fetch();
        if (!is_array($order)) {
            return null;
        }

        $items = $this->pdo->prepare('SELECT * FROM order_items WHERE order_id = :id ORDER BY id');
        $items->execute(['id' => $orderId]);
        $order['items'] = $items->fetchAll();

        $payments = $this->pdo->prepare('SELECT provider,provider_payment_id,amount_bani,refunded_bani,currency,status,created_at,updated_at FROM payments WHERE order_id = :id ORDER BY id DESC');
        $payments->execute(['id' => $orderId]);
        $order['payments'] = $payments->fetchAll();
        $order['shipping_address'] = $this->decodeJson((string) $order['shipping_address_json']);
        $order['billing_address'] = $this->decodeJson((string) $order['billing_address_json']);
        $order['company'] = $this->decodeJson((string) $order['company_json']);

        return $order;
    }

    /** @return array<string, mixed>|null */
    public function byPublicToken(string $token): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orders WHERE public_token_hash = :token');
        $statement->execute(['token' => hash('sha256', $token)]);
        $order = $statement->fetch();
        if (!is_array($order)) {
            return null;
        }
        $items = $this->pdo->prepare('SELECT * FROM order_items WHERE order_id = :id ORDER BY id');
        $items->execute(['id' => $order['id']]);
        $order['items'] = $items->fetchAll();
        return $order;
    }

    public function transition(int $orderId, string $action, ?string $awb, int $actorId): void
    {
        $allowed = [
            'confirm' => ['from' => ['new'], 'to' => 'confirmed'],
            'prepare' => ['from' => ['confirmed'], 'to' => 'preparing'],
            'ship' => ['from' => ['confirmed', 'preparing'], 'to' => 'shipped'],
            'deliver' => ['from' => ['shipped'], 'to' => 'delivered'],
            'cancel' => ['from' => ['new', 'confirmed', 'preparing'], 'to' => 'cancelled'],
        ];
        if (!isset($allowed[$action])) {
            throw new RuntimeException('Acțiune de comandă invalidă.');
        }
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id');
            $select->execute(['id' => $orderId]);
            $order = $select->fetch();
            if (!is_array($order) || !in_array($order['status'], $allowed[$action]['from'], true)) {
                throw new RuntimeException('Tranziția nu este permisă din starea curentă.');
            }
            $now = gmdate('c');
            if ($action === 'ship') {
                $reservations = $this->pdo->prepare("SELECT * FROM inventory_reservations WHERE order_id = :id AND status = 'active'");
                $reservations->execute(['id' => $orderId]);
                foreach ($reservations->fetchAll() as $reservation) {
                    $update = $this->pdo->prepare('UPDATE inventory SET physical_quantity = physical_quantity - :physical_delta, reserved_quantity = reserved_quantity - :reserved_delta, updated_at = :now WHERE variant_id = :variant AND reserved_quantity >= :reserved_check AND physical_quantity >= :physical_check');
                    $update->bindValue(':physical_delta', (int) $reservation['quantity'], PDO::PARAM_INT);
                    $update->bindValue(':reserved_delta', (int) $reservation['quantity'], PDO::PARAM_INT);
                    $update->bindValue(':reserved_check', (int) $reservation['quantity'], PDO::PARAM_INT);
                    $update->bindValue(':physical_check', (int) $reservation['quantity'], PDO::PARAM_INT);
                    $update->bindValue(':now', $now);
                    $update->bindValue(':variant', (int) $reservation['variant_id'], PDO::PARAM_INT);
                    $update->execute();
                    if ($update->rowCount() !== 1) {
                        throw new RuntimeException('Stoc inconsistent; expedierea necesită verificare manuală.');
                    }
                    $this->pdo->prepare("UPDATE inventory_reservations SET status = 'consumed', updated_at = :now WHERE id = :id AND status = 'active'")->execute(['now' => $now, 'id' => $reservation['id']]);
                    $this->pdo->prepare("INSERT INTO inventory_movements (variant_id, type, physical_delta, reserved_delta, reason, reference_type, reference_id, actor_user_id, created_at) VALUES (:variant, 'ship', :physical, :reserved, 'Expediere comandă', 'order', :order, :actor, :now)")->execute([
                        'variant' => $reservation['variant_id'], 'physical' => -((int) $reservation['quantity']), 'reserved' => -((int) $reservation['quantity']), 'order' => $orderId, 'actor' => $actorId, 'now' => $now,
                    ]);
                }
            } elseif ($action === 'cancel') {
                $reservations = $this->pdo->prepare("SELECT * FROM inventory_reservations WHERE order_id = :id AND status = 'active'");
                $reservations->execute(['id' => $orderId]);
                foreach ($reservations->fetchAll() as $reservation) {
                    $release = $this->pdo->prepare('UPDATE inventory SET reserved_quantity = reserved_quantity - :quantity_delta, updated_at = :now WHERE variant_id = :variant AND reserved_quantity >= :quantity_check');
                    $release->bindValue(':quantity_delta', (int) $reservation['quantity'], PDO::PARAM_INT);
                    $release->bindValue(':quantity_check', (int) $reservation['quantity'], PDO::PARAM_INT);
                    $release->bindValue(':now', $now);
                    $release->bindValue(':variant', (int) $reservation['variant_id'], PDO::PARAM_INT);
                    $release->execute();
                    $this->pdo->prepare("UPDATE inventory_reservations SET status = 'released', updated_at = :now WHERE id = :id AND status = 'active'")->execute(['now' => $now, 'id' => $reservation['id']]);
                    $this->pdo->prepare("INSERT INTO inventory_movements (variant_id, type, physical_delta, reserved_delta, reason, reference_type, reference_id, actor_user_id, created_at) VALUES (:variant, 'release', 0, :reserved, 'Anulare comandă', 'order', :order, :actor, :now)")->execute([
                        'variant' => $reservation['variant_id'], 'reserved' => -((int) $reservation['quantity']), 'order' => $orderId, 'actor' => $actorId, 'now' => $now,
                    ]);
                }
            }
            $extra = $action === 'ship' ? ', shipped_at = :now2, awb = :awb' : ($action === 'cancel' ? ', cancelled_at = :now2' : '');
            $params = ['status' => $allowed[$action]['to'], 'now' => $now, 'id' => $orderId];
            if ($action === 'ship') {
                $params['now2'] = $now;
                $params['awb'] = trim((string) $awb) ?: null;
            } elseif ($action === 'cancel') {
                $params['now2'] = $now;
            }
            $this->pdo->prepare("UPDATE orders SET status = :status, updated_at = :now{$extra} WHERE id = :id")->execute($params);
            $this->pdo->prepare("INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id, context_json, created_at) VALUES (:actor, :action, 'order', :id, :context, :now)")->execute([
                'actor' => $actorId, 'action' => 'order.' . $action, 'id' => $orderId, 'context' => json_encode(['from' => $order['status'], 'to' => $allowed[$action]['to']], JSON_THROW_ON_ERROR), 'now' => $now,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $value): array
    {
        if ($value === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
