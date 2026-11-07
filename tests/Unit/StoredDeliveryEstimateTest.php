<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Shipping\StoredDeliveryEstimate;
use PHPUnit\Framework\TestCase;

final class StoredDeliveryEstimateTest extends TestCase
{
    public function testEstimateHandlesSameDaySingleDayAndPickupWithoutInventingDates(): void
    {
        $provider = new StoredDeliveryEstimate();
        self::assertNull($provider->forOrder([]));
        self::assertNull($provider->forOrder(['shipping_estimate'=>['min_days'=>5,'max_days'=>2]]));
        self::assertSame('În aceeași zi lucrătoare', $provider->forOrder(['shipping_estimate'=>['min_days'=>0,'max_days'=>0]])['label']);
        $pickup = $provider->forOrder(['shipping_estimate'=>['min_days'=>1,'max_days'=>1,'type'=>'pickup']]);
        self::assertSame('1 zi lucrătoare', $pickup['label']);
        self::assertSame('Estimare de la confirmarea pregătirii pentru ridicare.', $pickup['detail']);
    }
}
