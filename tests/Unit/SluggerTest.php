<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Shared\Support\Slugger;
use PHPUnit\Framework\TestCase;

final class SluggerTest extends TestCase
{
    public function testRemovesRomanianDiacritics(): void
    {
        self::assertSame('rochita-de-botez-ingeras', Slugger::slug('Rochiță de botez „Îngeraș”'));
    }
}
