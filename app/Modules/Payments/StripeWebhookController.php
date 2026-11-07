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
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("INSERT INTO payment_events (provider,provider_event_id,event_type,payload_json,status,received_at) VALUES ('stripe',:id,:type,:payload,'received',:now)")->execute([
                'id' => $event->id, 'type' => $event->type, 'payload' => $payload, 'now' => $now,
            ]);
        } catch (\PDOException) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $duplicate = $this->pdo->prepare("SELECT id FROM payment_events WHERE provider='stripe' AND provider_event_id=:id");
            $duplicate->execute(['id' => $event->id]);
            return new Response($duplicate->fetchColumn() === false ? 500 : 200);
        }

        $paymentNotificationOrderId = null;
        try {
            if (in_array($event->type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
                $objectData = $event->data->object->toArray();
                $metadata = is_array($objectData['metadata'] ?? null) ? $objectData['metadata'] : [];
                $orderId = (int) ($metadata['order_id'] ?? 0);
                $amount = (int) ($objectData['amount_total'] ?? -1);
                $currency = strtoupper((string) ($objectData['currency'] ?? ''));
                $stripePaymentStatus = (string) ($objectData['payment_status'] ?? '');
                if ($stripePaymentStatus !== 'paid') {
                    if ($event->type === 'checkout.session.async_payment_succeeded') {
                        $this->pdo->prepare("UPDATE payment_events SET status='failed',error_message='Stripe success event is not paid',processed_at=:now WHERE provider_event_id=:id")
                            ->execute(['now' => $now, 'id' => $event->id]);
                    } else {
                        $this->pdo->prepare("UPDATE payment_events SET status='processed',processed_at=:now WHERE provider_event_id=:id")
                            ->execute(['now' => $now, 'id' => $event->id]);
                    }
                    $this->pdo->commit();
                    return new Response(200);
                }
                $lockSql = 'SELECT total_bani,currency,status,payment_status,email FROM orders WHERE id=:id AND payment_method=\'stripe\''
                    . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '');
                $statement = $this->pdo->prepare($lockSql);
                $statement->execute(['id' => $orderId]);
                $order = $statement->fetch();
                if (!is_array($order) || $amount !== (int) $order['total_bani'] || $currency !== (string) $order['currency']) {
                    $this->pdo->prepare("UPDATE payment_events SET status='failed',error_message='Order, amount or currency mismatch',processed_at=:now WHERE provider_event_id=:id")->execute(['now' => $now, 'id' => $event->id]);
                    $this->pdo->commit();
                    return new Response(200);
                }
                $this->pdo->prepare("UPDATE payments SET provider_payment_id=:provider_id,status='paid',updated_at=:now WHERE order_id=:order_id AND provider='stripe' AND status<>'paid'")->execute(['provider_id' => (string) ($objectData['payment_intent'] ?? ''), 'now' => $now, 'order_id' => $orderId]);
                $this->pdo->prepare("UPDATE orders SET payment_status='paid',status=CASE WHEN status='new' THEN 'confirmed' WHEN status='cancelled' THEN 'manual_review' ELSE status END,updated_at=:now WHERE id=:id AND payment_status<>'paid'")->execute(['now' => $now, 'id' => $orderId]);
                if ($order['payment_status'] !== 'paid') {
                    // The order lock serializes distinct success events. Preserve legacy queued confirmations too.
                    $confirmationPayload = json_encode(['order_id' => $orderId], JSON_THROW_ON_ERROR);
                    $existingEmail = $this->pdo->prepare("SELECT id FROM outbox_messages WHERE message_type='order_confirmation' AND payload_json=:payload LIMIT 1");
                    $existingEmail->execute(['payload' => $confirmationPayload]);
                    if ($existingEmail->fetchColumn() === false) {
                        $this->pdo->prepare("INSERT INTO outbox_messages (message_type,recipient,payload_json,status,attempts,available_at,created_at) VALUES ('order_confirmation',:recipient,:payload,'pending',0,:now,:now)")
                            ->execute(['recipient' => $order['email'], 'payload' => $confirmationPayload, 'now' => $now]);
                    }
                }
                if ($order['payment_status'] !== 'paid' && in_array($order['status'], ['new', 'cancelled'], true)) {
                    $nextStatus = $order['status'] === 'new' ? 'confirmed' : 'manual_review';
                    $this->pdo->prepare("INSERT INTO audit_log (actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES (NULL,:action,'order',:id,:context,:now)")->execute([
                        'action' => $nextStatus === 'confirmed' ? 'order.confirm' : 'order.manual_review',
                        'id' => $orderId,
                        'context' => json_encode(['from'=>$order['status'], 'to'=>$nextStatus, 'source'=>'stripe'], JSON_THROW_ON_ERROR),
                        'now' => $now,
                    ]);
                }
                $this->pdo->prepare("UPDATE coupon_redemptions SET status='confirmed',updated_at=:now WHERE order_id=:order AND status IN ('reserved','released')")
                    ->execute(['now' => $now, 'order' => $orderId]);
                $this->pdo->prepare("UPDATE payment_events SET status='processed',processed_at=:now WHERE provider_event_id=:id")->execute(['now' => $now, 'id' => $event->id]);
                $this->push?->queuePayment($orderId, 'paid');
                $paymentNotificationOrderId = $orderId;
            } elseif (in_array($event->type, ['checkout.session.expired', 'checkout.session.async_payment_failed', 'payment_intent.canceled'], true)) {
                $objectData = $event->data->object->toArray();
                $metadata = is_array($objectData['metadata'] ?? null) ? $objectData['metadata'] : [];
                $orderId = (int) ($metadata['order_id'] ?? 0);
                $lockSql = 'SELECT id,status,payment_status FROM orders WHERE id=:id AND payment_method=\'stripe\''
                    . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '');
                $statement = $this->pdo->prepare($lockSql);
                $statement->execute(['id' => $orderId]);
                $order = $statement->fetch();
                if (!is_array($order)) {
                    $this->pdo->prepare("UPDATE payment_events SET status='failed',error_message='Stripe order metadata missing or invalid',processed_at=:now WHERE provider_event_id=:id")
                        ->execute(['now' => $now, 'id' => $event->id]);
                    $this->pdo->commit();
                    return new Response(200);
                }
                if ((string) $order['payment_status'] !== 'paid') {
                    if (!in_array((string) $order['status'], ['new', 'manual_review', 'cancelled'], true)) {
                        $this->pdo->prepare("UPDATE payment_events SET status='failed',error_message='Stripe order cannot be cancelled from current status',processed_at=:now WHERE provider_event_id=:id")
                            ->execute(['now' => $now, 'id' => $event->id]);
                        $this->pdo->commit();
                        return new Response(200);
                    }
                    $reservationSql = "SELECT * FROM inventory_reservations WHERE order_id=:order AND status='active'"
                        . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '');
                    $reservations = $this->pdo->prepare($reservationSql);
                    $reservations->execute(['order' => $orderId]);
                    foreach ($reservations->fetchAll() as $reservation) {
                        $release = $this->pdo->prepare('UPDATE inventory SET reserved_quantity=reserved_quantity-:quantity_delta,updated_at=:now WHERE variant_id=:variant AND reserved_quantity>=:quantity_check');
                        $release->bindValue(':quantity_delta', (int) $reservation['quantity'], PDO::PARAM_INT);
                        $release->bindValue(':quantity_check', (int) $reservation['quantity'], PDO::PARAM_INT);
                        $release->bindValue(':now', $now);
                        $release->bindValue(':variant', (int) $reservation['variant_id'], PDO::PARAM_INT);
                        $release->execute();
                        if ($release->rowCount() !== 1) {
                            throw new \RuntimeException('Stoc inconsistent la anularea plății Stripe.');
                        }
                        $releaseReservation = $this->pdo->prepare("UPDATE inventory_reservations SET status='released',updated_at=:now WHERE id=:id AND status='active'");
                        $releaseReservation->execute(['now' => $now, 'id' => $reservation['id']]);
                        if ($releaseReservation->rowCount() !== 1) {
                            throw new \RuntimeException('Rezervarea nu mai este activă la anularea plății Stripe.');
                        }
                        $this->pdo->prepare("INSERT INTO inventory_movements (variant_id,type,physical_delta,reserved_delta,reason,reference_type,reference_id,created_at) VALUES (:variant,'release',0,:reserved,'Anulare automată plată Stripe','order',:order,:now)")
                            ->execute(['variant' => $reservation['variant_id'], 'reserved' => -((int) $reservation['quantity']), 'order' => $orderId, 'now' => $now]);
                    }
                    $this->pdo->prepare("UPDATE orders SET status='cancelled',payment_status='failed',cancelled_at=COALESCE(cancelled_at,:cancelled),updated_at=:updated WHERE id=:id AND payment_status<>'paid'")
                        ->execute(['cancelled' => $now, 'updated' => $now, 'id' => $orderId]);
                    if ($order['status'] !== 'cancelled') {
                        $this->pdo->prepare("INSERT INTO audit_log (actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES (NULL,'order.cancel','order',:id,:context,:now)")->execute([
                            'id' => $orderId,
                            'context' => json_encode(['from'=>$order['status'], 'to'=>'cancelled', 'source'=>'stripe'], JSON_THROW_ON_ERROR),
                            'now' => $now,
                        ]);
                    }
                    $this->pdo->prepare("UPDATE payments SET status='failed',updated_at=:now WHERE order_id=:order AND provider='stripe' AND status='pending'")
                        ->execute(['now' => $now, 'order' => $orderId]);
                    $this->pdo->prepare("UPDATE coupon_redemptions SET status='released',updated_at=:now WHERE order_id=:order AND status='reserved'")
                        ->execute(['now' => $now, 'order' => $orderId]);
                }
                $this->pdo->prepare("UPDATE payment_events SET status='processed',processed_at=:now WHERE provider_event_id=:id")
                    ->execute(['now' => $now, 'id' => $event->id]);
            } else {
                $this->pdo->prepare("UPDATE payment_events SET status='processed',processed_at=:now WHERE provider_event_id=:id")
                    ->execute(['now' => $now, 'id' => $event->id]);
            }
            $this->pdo->commit();
        } catch (\Throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return new Response(500);
        }
        if ($paymentNotificationOrderId !== null) {
            try {
                $this->push?->processPending(20);
            } catch (\Throwable) {
                // Confirmarea Stripe nu este anulată de o indisponibilitate Web Push.
            }
        }
        return new Response(200);
    }
}
