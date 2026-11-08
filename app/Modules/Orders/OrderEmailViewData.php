<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use App\Modules\Shipping\DeliveryEstimateProvider;
use App\Modules\Shipping\StoredDeliveryEstimate;
use App\Shared\Config\Config;
use App\Shared\Support\Money;
use DateTimeImmutable;
use DateTimeZone;

/** Presentation only: the caller must authorize the recipient and order first. */
final class OrderEmailViewData
{
    public function __construct(
        private readonly Config $config,
        private readonly OrderEmailImages $images,
        private readonly DeliveryEstimateProvider $deliveryEstimates = new StoredDeliveryEstimate(),
    ) {}

    /** @param array<string,mixed> $order @return array<string,mixed> */
    public function forOrder(array $order): array
    {
        $card = $order['payment_method'] === 'stripe';
        $paymentMethod = $card ? 'Card online' : 'Ramburs';
        $paymentState = match ((string) $order['payment_status']) {
            'paid' => 'Plătit',
            'refunded' => 'Rambursat',
            'partially_refunded' => 'Rambursat parțial',
            'failed' => 'Plată eșuată',
            default => $card ? 'Plată în așteptare'
                : (($order['status'] ?? '') === 'cancelled' ? 'Fără plată la livrare' : 'De achitat la livrare'),
        };
        $items = [];
        foreach ($order['items'] as $item) {
            $items[] = [
                'name' => $item['product_name'], 'variant' => $item['variant_summary'], 'sku' => $item['sku'],
                'quantity' => (int) $item['quantity'], 'unit_price' => Money::format((int) $item['unit_price_bani']),
                'total' => Money::format((int) $item['line_total_bani']),
                'image' => $this->images->productUrl($item['image_path'] ?? null),
                'product_url' => $item['product_url'] ?? null,
            ];
        }
        $shippingDiscount = (int) ($order['shipping_discount_bani'] ?? 0);
        $standardShipping = (int) $order['shipping_bani'] + $shippingDiscount;
        $totals = [['label' => 'Subtotal produse', 'value' => Money::format((int) $order['subtotal_bani']), 'saving' => false]];
        if ((int) $order['discount_bani'] > 0) {
            $totals[] = ['label' => 'Reducere produse', 'value' => '−' . Money::format((int) $order['discount_bani']), 'saving' => true];
        }
        $totals[] = ['label' => 'Livrare', 'value' => $standardShipping === 0 ? 'Gratuită' : Money::format($standardShipping), 'saving' => false];
        if ($shippingDiscount > 0) {
            $totals[] = ['label' => 'Reducere livrare', 'value' => '−' . Money::format($shippingDiscount), 'saving' => true];
        }
        foreach ($order['payment_fees'] ?? \App\Modules\Payments\PaymentFeeService::snapshot($order) as $fee) {
            $totals[] = ['label' => $fee['name'], 'value' => Money::format($fee['amount_bani']), 'saving' => false];
        }
        if ((int) $order['tax_bani'] > 0) {
            $totals[] = ['label' => 'Taxe', 'value' => Money::format((int) $order['tax_bani']), 'saving' => false];
        }
        $saving = (int) $order['discount_bani'] + $shippingDiscount;
        return [
            'first_name' => trim((string) $order['first_name']), 'number' => $order['order_number'],
            'date' => (new DateTimeImmutable((string) $order['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Bucharest'))->format('d.m.Y · H:i'),
            'recipient_name' => trim($order['first_name'] . ' ' . ($order['last_name'] ?? '')),
            'address' => $order['shipping_address'], 'phone' => $order['phone'], 'email' => $order['email'],
            'shipping_method' => $order['shipping_method_name'] ?: 'Livrare standard',
            'delivery_estimate' => $this->deliveryEstimates->forOrder($order),
            'payment_method' => $paymentMethod, 'payment_state' => $paymentState, 'paid' => $order['payment_status'] === 'paid',
            'items' => $items, 'quantity' => $order['quantity'], 'totals' => $totals,
            'total' => Money::format((int) $order['total_bani']), 'savings' => $saving > 0 ? Money::format($saving) : null,
            'savings_icon' => $this->config->rootUrl() . '/assets/images/email-savings-confetti.gif',
            'summary_icon' => $this->config->rootUrl() . '/assets/images/email-order-receipt-v2.png',
            'delivery_icon' => $this->config->rootUrl() . '/assets/images/email-order-truck-v2.png',
            'payment_icon' => $this->config->rootUrl() . '/assets/images/email-order-' . ($card ? 'card' : 'cash') . '-v2.png',
        ];
    }
}
