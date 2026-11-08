<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Shared\Config\Config;
use App\Shared\Support\Money;
use RuntimeException;

/** Builds Checkout exclusively from the persisted order, including its agreed discounts. */
final class StripeCheckoutPayload
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @param array<string,mixed> $order @param list<array<string,mixed>> $items @return array<string,mixed> */
    public function build(array $order, array $items, string $publicToken): array
    {
        $subtotal = (int) $order['subtotal_bani'];
        $discount = (int) $order['discount_bani'];
        $shipping = (int) $order['shipping_bani'];
        $shippingDiscount = (int) ($order['shipping_discount_bani'] ?? 0);
        $feeAmount = (int) ($order['payment_fee_bani'] ?? 0);
        $fees = PaymentFeeService::snapshot($order);
        $total = (int) $order['total_bani'];
        if ($items === [] || $subtotal <= 0 || $discount < 0 || $discount > $subtotal || $shipping < 0 || $shippingDiscount < 0
            || $feeAmount < 0 || array_sum(array_column($fees, 'amount_bani')) !== $feeAmount
            || $total !== $subtotal - $discount + $shipping + $feeAmount || $total <= 0) {
            throw new RuntimeException('Totalul comenzii nu poate fi reconciliat pentru plata cu cardul.');
        }
        $currency = strtolower((string) $order['currency']);
        if ($currency !== 'ron') {
            throw new RuntimeException('Moneda comenzii nu este acceptată.');
        }
        $weights = [];
        foreach ($items as $item) {
            if ((int) $item['quantity'] < 1 || (int) $item['line_total_bani'] < 0) {
                throw new RuntimeException('Produsele comenzii nu sunt valide pentru plată.');
            }
            $weights[] = (int) $item['line_total_bani'];
        }
        if (array_sum($weights) !== $subtotal) {
            throw new RuntimeException('Produsele comenzii nu corespund subtotalului salvat.');
        }

        $lineItems = [];
        foreach ($items as $index => $item) {
            $quantity = (int) $item['quantity'];
            $unit = intdiv($weights[$index], $quantity);
            $extraUnits = $weights[$index] % $quantity;
            $description = array_filter([(string) ($item['variant_summary'] ?? ''), 'SKU: ' . (string) $item['sku']]);
            $product = ['name' => mb_substr((string) $item['product_name'], 0, 250), 'description' => mb_substr(implode(' · ', $description), 0, 500),
                'metadata' => ['line_type' => 'merchandise', 'local_product_id' => (string) ($item['product_id'] ?? ''), 'local_variant_id' => (string) ($item['variant_id'] ?? ''), 'sku' => (string) $item['sku']]];
            $image = (new StripePublicImage($this->config))->url($item['image_path'] ?? null);
            if ($image !== null) {
                $product['images'] = [$image];
            }
            // Preserve exact snapshot totals even for older, fractionally allocated unit prices.
            foreach ([[$quantity - $extraUnits, $unit], [$extraUnits, $unit + 1]] as [$groupQuantity, $groupUnit]) {
                if ($groupQuantity > 0) {
                    $lineItems[] = ['quantity' => $groupQuantity, 'price_data' => ['currency' => $currency, 'unit_amount' => $groupUnit, 'product_data' => $product]];
                }
            }
        }
        $lineItems[] = ['quantity' => 1, 'price_data' => ['currency' => $currency, 'unit_amount' => $shipping, 'product_data' => [
            'name' => (string) ($order['shipping_method_name'] ?? 'Livrare'),
            'description' => ($shipping === 0 ? 'Livrare gratuită pentru această comandă.' : 'Livrare la adresa completată în magazin.')
                . ($shippingDiscount > 0 ? ' Ai economisit ' . Money::format($shippingDiscount) . ' la livrare (cost inițial ' . Money::format($shipping + $shippingDiscount) . ').' : ''),
            'metadata' => ['line_type' => 'shipping'],
        ]]];
        foreach ($fees as $fee) {
            $lineItems[] = ['quantity' => 1, 'price_data' => ['currency' => $currency, 'unit_amount' => $fee['amount_bani'], 'product_data' => [
                'name' => mb_substr($fee['name'], 0, 100), 'description' => 'Serviciu inclus în rezumatul comenzii.',
                'metadata' => ['line_type' => 'operational_fee'],
            ]]];
        }
        if (count($lineItems) > 100) {
            throw new RuntimeException('Comanda conține prea multe poziții pentru o singură plată cu cardul.');
        }
        $metadata = ['order_id' => (string) $order['id'], 'order_number' => (string) $order['order_number']];
        $expires = strtotime((string) $order['created_at']) + 3600;
        if ($expires < time() + 1800) {
            throw new RuntimeException('Fereastra de inițiere a plății a expirat. Verifică disponibilitatea produselor într-o comandă nouă.');
        }
        $payload = [
            'mode' => 'payment', 'ui_mode' => 'hosted', 'locale' => 'ro', 'payment_method_types' => ['card'],
            'automatic_tax' => ['enabled' => false],
            'client_reference_id' => (string) $order['order_number'], 'customer_email' => (string) $order['email'],
            'success_url' => $this->config->rootUrl() . '/comanda/' . rawurlencode($publicToken) . '/?plata=verificare',
            'cancel_url' => $this->config->rootUrl() . '/comanda/' . rawurlencode($publicToken) . '/?plata=anulata',
            'expires_at' => $expires, 'line_items' => $lineItems, 'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata, 'description' => 'Comanda ' . (string) $order['order_number'] . ' · La Măriuca Boutique'],
            'custom_text' => ['submit' => ['message' => 'După plată revii în magazin. Îți trimitem confirmarea comenzii pe email.']],
        ];
        $coupon = $this->coupon($order);
        if ($coupon !== null) {
            $payload['discounts'] = [['coupon' => $coupon['id']]];
        }
        return $payload;
    }

    /** @param array<string,mixed> $order @param list<string> $eligibleProducts @return array<string,mixed>|null */
    public function coupon(array $order, array $eligibleProducts = []): ?array
    {
        $discount = (int) $order['discount_bani'];
        if ($discount <= 0) {
            return null;
        }
        $coupon = [
            'id' => 'lm_order_' . substr(hash('sha256', (string) $order['idempotency_key']), 0, 32),
            'amount_off' => $discount, 'currency' => strtolower((string) $order['currency']), 'duration' => 'once',
            'name' => 'Reducere produse',
            'metadata' => ['order_id' => (string) $order['id'], 'order_number' => (string) $order['order_number']],
        ];
        if ($eligibleProducts !== []) { $coupon['applies_to'] = ['products' => array_values(array_unique($eligibleProducts))]; }
        return $coupon;
    }
}
