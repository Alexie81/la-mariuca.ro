<?php

declare(strict_types=1);

namespace App\Modules\Payments;

final class StripeCheckoutCustomer
{
    /** @param array<string,mixed> $order @return array<string,mixed> */
    public static function details(array $order): array
    {
        $shipping = json_decode((string) ($order['shipping_address_json'] ?? '{}'), true);
        $billing = json_decode((string) ($order['billing_address_json'] ?? '{}'), true);
        $shipping = is_array($shipping) ? $shipping : [];
        $billing = is_array($billing) && $billing !== [] ? $billing : $shipping;
        $name = trim((string) ($order['first_name'] ?? '') . ' ' . (string) ($order['last_name'] ?? ''));
        return [
            'email' => (string) $order['email'], 'name' => $name,
            'phone' => mb_substr((string) ($order['phone'] ?? ''), 0, 20),
            'address' => self::address($billing),
            'shipping' => ['name' => $name, 'phone' => mb_substr((string) ($order['phone'] ?? ''), 0, 20), 'address' => self::address($shipping)],
            'preferred_locales' => ['ro'],
            'metadata' => isset($order['checkout_attempt_id']) ? ['checkout_attempt_id' => (string) $order['checkout_attempt_id'], 'checkout_attempt_key' => (string) $order['idempotency_key']]
                : ['order_id' => (string) $order['id'], 'order_number' => (string) $order['order_number']],
        ];
    }

    /** @param array<string,mixed> $address @return array<string,string> */
    private static function address(array $address): array
    {
        return array_filter([
            'line1' => mb_substr((string) ($address['address'] ?? ''), 0, 200),
            'city' => (string) ($address['city'] ?? ''), 'state' => (string) ($address['county'] ?? ''),
            'postal_code' => (string) ($address['postal_code'] ?? ''), 'country' => 'RO',
        ], static fn (string $value): bool => $value !== '');
    }
}
