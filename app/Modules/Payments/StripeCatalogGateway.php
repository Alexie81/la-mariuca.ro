<?php

declare(strict_types=1);

namespace App\Modules\Payments;

interface StripeCatalogGateway
{
    public function accountId(): string;

    /** @param array<string,mixed> $data */
    public function saveProduct(string $id, array $data, string $idempotencyKey): void;

    /** @param array<string,mixed> $data */
    public function createPrice(array $data, string $idempotencyKey): string;

    public function findPrice(string $lookupKey): ?string;

    public function setPriceActive(string $id, bool $active): void;
}
