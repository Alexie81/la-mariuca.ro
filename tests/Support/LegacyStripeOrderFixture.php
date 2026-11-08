<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Shared\Config\Config;
use PDO;

/** Builds pre-attempt-schema orders solely to keep legacy webhook compatibility covered. */
final class LegacyStripeOrderFixture
{
    public static function place(PDO $pdo, CartService $cart, Config $config, array $data): array
    {
        $order = (new CheckoutService($pdo, $cart, $config))->place(array_replace($data, ['payment_method' => 'cod']));
        $id = (int) $order['id'];
        $pdo->prepare("UPDATE orders SET payment_method='stripe' WHERE id=:id")->execute(['id' => $id]);
        $pdo->prepare("UPDATE payments SET provider='stripe' WHERE order_id=:id")->execute(['id' => $id]);
        $pdo->prepare('UPDATE inventory_reservations SET expires_at=:expires WHERE order_id=:id')
            ->execute(['expires' => gmdate('c', time() + 3600), 'id' => $id]);
        $pdo->prepare('DELETE FROM order_internal_email_deliveries WHERE order_id=:id')->execute(['id' => $id]);
        $pdo->prepare("DELETE FROM outbox_messages WHERE message_type IN ('order_confirmation','internal_order_notification') AND payload_json=:payload")
            ->execute(['payload' => json_encode(['order_id' => $id], JSON_THROW_ON_ERROR)]);
        $order['payment_method'] = 'stripe';
        return $order;
    }
}
