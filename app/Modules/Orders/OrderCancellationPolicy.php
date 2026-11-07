<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use DateTimeImmutable;
use DateTimeZone;

final class OrderCancellationPolicy
{
    public const WINDOW_SECONDS = 5 * 24 * 60 * 60;

    public static function deadline(string $createdAt): int
    {
        return (new DateTimeImmutable($createdAt, new DateTimeZone('UTC')))->getTimestamp() + self::WINDOW_SECONDS;
    }

    public static function allows(string $status, string $createdAt, ?int $now = null): bool
    {
        $now ??= time();
        $deadline = self::deadline($createdAt);
        return in_array($status, ['new', 'confirmed', 'preparing'], true)
            && $now >= $deadline - self::WINDOW_SECONDS && $now < $deadline;
    }
}
