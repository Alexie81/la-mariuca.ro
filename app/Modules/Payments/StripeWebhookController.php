<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Modules\Admin\AdminPushService;
use App\Shared\Config\Config;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Stripe\Webhook;

final class StripeWebhookController
{
    public function __construct(private readonly PDO $pdo, private readonly Config $config, private readonly ?AdminPushService $push = null)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->config->bool('STRIPE_ENABLED') || $this->config->get('STRIPE_WEBHOOK_SECRET') === '') {
            return new Response(404);
        }
        $payload = (string) $request->getBody();
        try {
            $event = Webhook::constructEvent($payload, $request->getHeaderLine('Stripe-Signature'), $this->config->get('STRIPE_WEBHOOK_SECRET'));
        } catch (\Throwable) {
            return new Response(400);
        }
        $now = gmdate('c');
        try {
            $this->pdo->prepare("INSERT INTO payment_events (provider,provider_event_id,event_type,payload_json,status,received_at) VALUES ('stripe',:id,:type,:payload,'received',:now)")->execute([
                'id' => $event->id, 'type' => $event->type, 'payload' => $payload, 'now' => $now,
            ]);
        } catch (\PDOException) {
            return new Response(200);
        }

        if ($event->type === 'checkout.session.completed') {
            $object = $event->data->object;
            $objectData = $object->toArray();
            $metadata = is_array($objectData['metadata'] ?? null) ? $objectData['metadata'] : [];
            $orderId = (int) ($metadata['order_id'] ?? 0);
            $statement = $this->pdo->prepare('SELECT total_bani,currency,payment_status FROM orders WHERE id=:id AND payment_method=\'stripe\'');
            $statement->execute(['id' => $orderId]);
            $order = $statement->fetch();
            $amount = (int) ($objectData['amount_total'] ?? -1);
            $currency = strtoupper((string) ($objectData['currency'] ?? ''));
            if (!is_array($order) || $amount !== (int) $order['total_bani'] || $currency !== (string) $order['currency']) {
                $this->pdo->prepare("UPDATE payment_events SET status='failed',error_message='Order, amount or currency mismatch',processed_at=:now WHERE provider_event_id=:id")->execute(['now' => $now, 'id' => $event->id]);
                return new Response(200);
            }
            $this->pdo->beginTransaction();
            try {
                $this->pdo->prepare("UPDATE payments SET provider_payment_id=:provider_id,status='paid',updated_at=:now WHERE order_id=:order_id AND provider='stripe' AND status<>'paid'")->execute(['provider_id' => (string) ($objectData['payment_intent'] ?? ''), 'now' => $now, 'order_id' => $orderId]);
                $this->pdo->prepare("UPDATE orders SET payment_status='paid',status=CASE WHEN status='new' THEN 'confirmed' ELSE status END,updated_at=:now WHERE id=:id AND payment_status<>'paid'")->execute(['now' => $now, 'id' => $orderId]);
                $this->pdo->prepare("UPDATE payment_events SET status='processed',processed_at=:now WHERE provider_event_id=:id")->execute(['now' => $now, 'id' => $event->id]);
                $this->push?->queuePayment($orderId, 'paid');
                $this->pdo->commit();
                try {
                    $this->push?->processPending(20);
                } catch (\Throwable) {
                    // Confirmarea Stripe nu este anulată de o indisponibilitate Web Push.
                }
            } catch (\Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                return new Response(500);
            }
        } else {
            $this->pdo->prepare("UPDATE payment_events SET status='processed',processed_at=:now WHERE provider_event_id=:id")->execute(['now' => $now, 'id' => $event->id]);
        }
        return new Response(200);
    }
}
