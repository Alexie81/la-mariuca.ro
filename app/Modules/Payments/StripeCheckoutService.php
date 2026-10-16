<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Shared\Config\Config;
use PDO;
use RuntimeException;
use Stripe\StripeClient;

final class StripeCheckoutService
{
    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
    }

    public function createSession(int $orderId, string $publicToken, string $idempotencyKey): string
    {
        if (!$this->config->bool('STRIPE_ENABLED') || $this->config->get('STRIPE_SECRET_KEY') === '') {
            throw new RuntimeException('Stripe nu este configurat.');
        }
        $statement = $this->pdo->prepare("SELECT * FROM orders WHERE id=:id AND payment_method='stripe' AND payment_status='pending'");
        $statement->execute(['id' => $orderId]);
        $order = $statement->fetch();
        if (!is_array($order)) {
            throw new RuntimeException('Comanda Stripe nu este eligibilă pentru inițierea plății.');
        }
        $client = new StripeClient($this->config->get('STRIPE_SECRET_KEY'));
        $session = $client->checkout->sessions->create([
            'mode' => 'payment',
            'client_reference_id' => (string) $order['order_number'],
            'customer_email' => (string) $order['email'],
            'success_url' => $this->config->rootUrl() . '/comanda/' . $publicToken . '/?plata=verificare',
            'cancel_url' => $this->config->rootUrl() . '/comanda/' . $publicToken . '/?plata=anulata',
            'expires_at' => time() + 3600,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower((string) $order['currency']),
                    'unit_amount' => (int) $order['total_bani'],
                    'product_data' => ['name' => 'Comanda ' . $order['order_number'] . ' · La Măriuca Boutique'],
                ],
            ]],
            'metadata' => ['order_id' => (string) $orderId, 'order_number' => (string) $order['order_number']],
            'payment_intent_data' => ['metadata' => ['order_id' => (string) $orderId, 'order_number' => (string) $order['order_number']]],
        ], ['idempotency_key' => 'checkout-' . $idempotencyKey]);
        if (!is_string($session->url) || $session->url === '') {
            throw new RuntimeException('Stripe nu a returnat URL-ul sesiunii hosted.');
        }
        $this->pdo->prepare("UPDATE payments SET provider_payment_id=:session,updated_at=:now WHERE order_id=:order AND provider='stripe' AND status='pending'")->execute(['session' => $session->id, 'now' => gmdate('c'), 'order' => $orderId]);
        return $session->url;
    }

    public function markSessionFailure(int $orderId, string $message): void
    {
        $this->pdo->beginTransaction();
        try {
            $now = gmdate('c');
            $this->pdo->prepare("UPDATE orders SET status='manual_review',updated_at=:now WHERE id=:id AND status='new'")->execute(['now' => $now, 'id' => $orderId]);
            $this->pdo->prepare("UPDATE payments SET status='failed',updated_at=:now WHERE order_id=:id AND provider='stripe' AND status='pending'")->execute(['now' => $now, 'id' => $orderId]);
            $this->pdo->prepare("INSERT INTO audit_log (actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES (NULL,'stripe.session_failed','order',:id,:context,:now)")->execute(['id' => $orderId, 'context' => json_encode(['error' => mb_substr($message, 0, 300)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'now' => $now]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
