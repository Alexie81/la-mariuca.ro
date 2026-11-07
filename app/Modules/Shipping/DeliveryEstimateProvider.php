<?php

declare(strict_types=1);

namespace App\Modules\Shipping;

/** Replace this provider to resolve live carrier/holiday/postcode estimates without changing email templates. */
interface DeliveryEstimateProvider
{
    /** @param array<string,mixed> $order @return array{label:string,detail:string}|null */
    public function forOrder(array $order): ?array;
}
