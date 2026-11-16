<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Modules\Inventory\StockPagination;
use PHPUnit\Framework\TestCase;

final class StockPaginationTest extends TestCase
{
    public function testOnlyBoundedPageSizesAreAccepted(): void
    {
        foreach ([12, 24, 48, 96] as $size) {
            self::assertSame($size, StockPagination::size((string) $size));
        }
        foreach ([null, '', 'all', 0, -12, 100000, '12.5', ['12'], '12 OR 1=1'] as $invalid) {
            self::assertSame(24, StockPagination::size($invalid));
        }
    }

    public function testNumberedPagesKeepEndpointsAndCurrentNeighbors(): void
    {
        self::assertSame([1], StockPagination::numbers(1, 0));
        self::assertSame([1, 2, 3], StockPagination::numbers(2, 3));
        self::assertSame([1, 2, 3, 4, 5, null, 20], StockPagination::numbers(-1, 20));
        self::assertSame([1, null, 9, 10, 11, null, 20], StockPagination::numbers(10, 20));
        self::assertSame([1, null, 16, 17, 18, 19, 20], StockPagination::numbers(999, 20));
    }
}
