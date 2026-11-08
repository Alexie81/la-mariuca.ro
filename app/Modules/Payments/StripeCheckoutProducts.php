<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use RuntimeException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Service\ProductService;
use Stripe\StripeClientInterface;

/** Gives merchandise stable IDs so a discount never leaks onto shipping or services. */
final class StripeCheckoutProducts
{
    public function __construct(private readonly StripeClientInterface $client)
    {
    }

    /** @param array<string,mixed> $payload @return array{payload:array<string,mixed>,merchandise_product_ids:list<string>} */
    public function prepare(array $payload): array
    {
        $products = new ProductService($this->client);
        $eligible = [];
        foreach ($payload['line_items'] as &$line) {
            $data = $line['price_data']['product_data'] ?? null;
            if (!is_array($data) || ($data['metadata']['line_type'] ?? '') !== 'merchandise') {
                continue;
            }
            // Identical name/variant/photo snapshots reuse a product across orders and retries.
            $id = 'lm_checkout_' . substr(hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 0, 40);
            if (!in_array($id, $eligible, true)) {
                try {
                    $product = $products->create(['id' => $id] + $data, ['idempotency_key' => $id]);
                } catch (InvalidRequestException $error) {
                    // Stripe idempotency keys expire; our immutable snapshot ID does not.
                    if ($error->getHttpStatus() !== 400) { throw $error; }
                    try { $product = $products->retrieve($id); }
                    catch (\Throwable) { throw $error; }
                }
                if ($product->id !== $id || $product->name !== $data['name']
                    || (string) $product->description !== (string) ($data['description'] ?? '')
                    || ($product->images ?? []) !== ($data['images'] ?? [])
                    || ($product->deleted ?? false)) {
                    throw new RuntimeException('Produsul procesatorului nu corespunde detaliilor comenzii.');
                }
                $eligible[] = $id;
            }
            unset($line['price_data']['product_data']);
            $line['price_data']['product'] = $id;
        }
        unset($line);
        if ($eligible === []) { throw new RuntimeException('Reducerea nu are produse eligibile în comandă.'); }
        return ['payload' => $payload, 'merchandise_product_ids' => $eligible];
    }
}
