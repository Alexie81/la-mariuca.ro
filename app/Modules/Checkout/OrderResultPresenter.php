<?php

declare(strict_types=1);

namespace App\Modules\Checkout;

use App\Shared\Config\Config;
use DateTimeImmutable;
use DateTimeZone;

final class OrderResultPresenter
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @param array<string,mixed> $order
     *  @return array<string,mixed>
     */
    public function present(array $order, string $returnStatus, string $publicToken, bool $isPaymentAttempt = false): array
    {
        $method = (string) $order['payment_method'];
        $payment = (string) $order['payment_status'];
        $status = (string) $order['status'];
        $failed = $payment === 'failed'
            || ($payment === 'pending' && ($order['latest_payment_status'] ?? '') === 'failed');

        // The provider return URL is a display hint, never evidence of payment or cancellation.
        $state = $isPaymentAttempt ? match (true) {
            $failed => 'card_failed',
            $returnStatus === 'anulata' => 'card_cancelled',
            default => 'card_pending',
        } : match (true) {
            in_array($payment, ['refunded', 'partially_refunded'], true) => 'payment_refunded',
            $status === 'returned' => 'order_returned',
            $method === 'stripe' && $failed => 'card_failed',
            $status === 'manual_review' => 'payment_review',
            $status === 'cancelled' => 'order_cancelled',
            $method === 'stripe' && $payment === 'paid' => 'card_paid',
            $method === 'cod' && $payment === 'paid' => 'cash_paid',
            $method === 'cod' => 'cash_placed',
            $method === 'stripe' && $payment === 'pending' && $returnStatus === 'anulata' => 'card_cancelled',
            default => 'card_pending',
        };
        $statusLabel = $isPaymentAttempt ? ($failed ? 'Plată nefinalizată' : 'În așteptarea plății') : match ($status) {
            'new' => 'Comandă primită',
            'confirmed' => 'Confirmată',
            'preparing' => 'În pregătire',
            'shipped' => 'Expediată',
            'delivered' => 'Livrată',
            'cancelled' => 'Anulată',
            'returned' => 'Returnată',
            default => 'În verificare',
        };
        $paymentLabel = match (true) {
            $payment === 'paid' => 'Plătită',
            $payment === 'refunded' => 'Rambursată',
            $payment === 'partially_refunded' => 'Rambursată parțial',
            $failed => 'Eșuată',
            $state === 'cash_placed' => 'Ramburs la livrare',
            $state === 'card_cancelled' => 'Neconfirmată',
            default => 'În așteptare',
        };
        $totalLabel = match (true) {
            $isPaymentAttempt => 'Total de plată',
            $payment === 'paid' => 'Total achitat',
            $state === 'cash_placed' => 'De achitat la livrare',
            default => 'Total comandă',
        };
        $quantity = 0;
        foreach ($order['items'] as $item) {
            $quantity += (int) $item['quantity'];
        }

        return [
            'state' => $state,
            'status_label' => $statusLabel,
            'payment_label' => $paymentLabel,
            'total_label' => $totalLabel,
            'placed_at_label' => (new DateTimeImmutable((string) $order['created_at'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Europe/Bucharest'))->format('d.m.Y, H:i'),
            'item_count' => $quantity,
            'can_refresh' => $method === 'stripe' && in_array($state, ['card_pending', 'card_cancelled', 'card_failed', 'payment_review'], true),
            'refresh_path' => $this->config->path('comanda/' . rawurlencode($publicToken) . '/'),
        ];
    }
}
