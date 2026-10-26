<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Shared\Support\RomanianLocations;
use PHPUnit\Framework\TestCase;

final class RomanianLocationsTest extends TestCase
{
    private RomanianLocations $locations;

    protected function setUp(): void
    {
        $this->locations = RomanianLocations::fromProjectRoot(dirname(__DIR__, 2));
    }

    public function testBundledSirutaRegistryContainsAllCountyLevelUnitsAndLocalities(): void
    {
        $counties = $this->locations->counties();

        self::assertCount(42, $counties);
        self::assertSame(13755, array_sum(array_map('count', $counties)));
        self::assertContains('Alba Iulia', $counties['Alba']);
    }

    public function testBucharestSectorsAreCanonicalLocalityOptions(): void
    {
        self::assertSame(
            ['county' => 'București', 'locality' => 'București — Sector 5'],
            $this->locations->canonicalize('Municipiul Bucuresti', 'sectorul 5'),
        );
        self::assertNull($this->locations->canonicalize('București', 'București'));
    }

    public function testUnknownCountyOrLocalityIsRejected(): void
    {
        self::assertNull($this->locations->canonicalize('Cluj', 'Localitate inventată'));
        self::assertNull($this->locations->canonicalize('Județ inventat', 'Cluj-Napoca'));
    }
}
