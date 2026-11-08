<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

final class StripeCatalogApi implements StripeCatalogGateway
{
    public function __construct(private readonly StripeClient $client) {}

    public function accountId(): string
    {
        return (string) $this->client->accounts->retrieve()->id;
    }

    public function saveProduct(string $id, array $data, string $idempotencyKey): void
    {
        try {
            $this->client->products->retrieve($id);
        } catch (InvalidRequestException $error) {
            if ($error->getStripeCode() !== 'resource_missing') { throw $error; }
            // Stripe accepts an empty string to clear images on update, but not on creation.
            $creation = $data;
            if (($creation['images'] ?? null) === '') { unset($creation['images']); }
            $this->client->products->create(['id' => $id] + $creation, ['idempotency_key' => $idempotencyKey . '-create']);
            return;
        }
        $this->client->products->update($id, $data, ['idempotency_key' => $idempotencyKey . '-update']);
    }

    public function createPrice(array $data, string $idempotencyKey): string
    {
        return (string) $this->client->prices->create($data, ['idempotency_key' => $idempotencyKey])->id;
    }

    public function findPrice(string $lookupKey): ?string
    {
        $prices = $this->client->prices->all(['lookup_keys' => [$lookupKey], 'limit' => 1]);
        return isset($prices->data[0]) ? (string) $prices->data[0]->id : null;
    }

    public function setPriceActive(string $id, bool $active): void
    {
        $this->client->prices->update($id, ['active' => $active]);
    }
}
