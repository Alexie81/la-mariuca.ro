<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Shared\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[DataProvider('values')]
    public function testParsesAndFormatsDeterministically(string $input, int $bani, string $formatted): void
    {
        self::assertSame($bani, Money::parse($input));
        self::assertSame($formatted, Money::format($bani));
    }

    public static function values(): array
    {
        return [['0', 0, '0,00 Lei'], ['129,9', 12990, '129,90 Lei'], ['1.299,05', 129905, '1.299,05 Lei']];
    }

    public function testRejectsMoreThanTwoDecimals(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::parse('10,999');
    }
}
