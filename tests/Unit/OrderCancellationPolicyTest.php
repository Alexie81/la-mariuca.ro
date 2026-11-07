<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Orders\OrderCancellationPolicy;
use PHPUnit\Framework\TestCase;

final class OrderCancellationPolicyTest extends TestCase
{
    public function testExactlyFiveDaysIsExcludedForEveryAllowedStatus(): void
    {
        $created = '2026-11-01T10:00:00+00:00';
        $deadline = strtotime('2026-11-06T10:00:00+00:00');
        self::assertSame($deadline, OrderCancellationPolicy::deadline($created));
        foreach (['new', 'confirmed', 'preparing'] as $status) {
            self::assertTrue(OrderCancellationPolicy::allows($status, $created, $deadline - 1));
            self::assertFalse(OrderCancellationPolicy::allows($status, $created, $deadline));
            self::assertFalse(OrderCancellationPolicy::allows($status, $created, $deadline + 1));
        }
        foreach (['shipped', 'delivered', 'cancelled', 'returned', 'manual_review', 'unknown'] as $status) {
            self::assertFalse(OrderCancellationPolicy::allows($status, $created, $deadline - 1));
        }
    }

    public function testWindowIs120HoursAcrossDaylightSavingChanges(): void
    {
        foreach (['2026-03-27T12:00:00+02:00', '2026-10-23T12:00:00+03:00'] as $created) {
            $start = (new \DateTimeImmutable($created))->getTimestamp();
            self::assertSame(432000, OrderCancellationPolicy::deadline($created) - $start);
            self::assertFalse(OrderCancellationPolicy::allows('new', $created, $start - 1));
            self::assertTrue(OrderCancellationPolicy::allows('new', $created, $start));
        }
    }
}
