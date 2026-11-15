<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Modules\Invoicing\{InvoiceExportSelection,InvoiceException};
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoiceExportSelectionTest extends TestCase
{
    public function testRomanianYearBoundaryAndRollingMonthsDoNotOverflow(): void
    {
        $periods=InvoiceExportSelection::periods(new DateTimeImmutable('2026-12-31T22:30:00Z'));
        self::assertSame('2027-01-01',$periods['year']['from']); self::assertSame('2027-01-01',$periods['year']['to']);
        self::assertSame('2026-12-01',$periods['last_month']['from']); self::assertSame('2026-12-31',$periods['last_month']['to']);
        $periods=InvoiceExportSelection::periods(new DateTimeImmutable('2026-05-31T12:00:00Z'));
        self::assertSame('2026-02-28',$periods['3_months']['from']); self::assertSame('2025-11-30',$periods['6_months']['from']);
        self::assertSame('2026-04-30',$periods['last_month']['to']); self::assertSame('2026-05-31',$periods['month']['to']);
        self::assertSame('2024-02-29',InvoiceExportSelection::periods(new DateTimeImmutable('2024-05-31'))['3_months']['from']);
    }

    public function testPresetsOverrideDatesAndOptionalFiltersNeverIncludePagination(): void
    {
        $input=['period'=>'month','format'=>'zip','from'=>'1900-01-01','to'=>'1900-01-01','keep_filters'=>'1','filters'=>['kind'=>'return','cautare'=>'Ana','page'=>'3','per_page'=>'10','from'=>'1900-01-01']];
        $result=InvoiceExportSelection::resolve($input,new DateTimeImmutable('2026-11-15T12:00:00Z'));
        self::assertSame(['kind'=>'return','cautare'=>'Ana','from'=>'2026-11-01','to'=>'2026-11-15'],array_replace(['kind'=>'','cautare'=>''],$result['filters']));
        self::assertSame('zip',$result['format']);
        self::assertSame([],InvoiceExportSelection::resolve(array_replace($input,['period'=>'all','keep_filters'=>'0']))['filters']);
        self::assertSame('2026-10-25',InvoiceExportSelection::resolve(['period'=>'custom','from'=>'2026-10-25','to'=>'2026-10-25'])['to']);
    }

    #[DataProvider('invalidSelections')]
    public function testInvalidSelectionsAreRejected(array $input): void
    {
        $this->expectException(InvoiceException::class); InvoiceExportSelection::resolve($input);
    }

    public static function invalidSelections(): iterable
    {
        yield [['period'=>'custom','from'=>'2026-02-30','to'=>'2026-03-01']];
        yield [['period'=>'custom','from'=>'2026-11-02','to'=>'2026-11-01']];
        yield [['period'=>'custom','from'=>'2026-11-01']];
        yield [['period'=>'custom','from'=>['2026-11-01'],'to'=>'2026-11-02']];
        yield [['period'=>'unknown']]; yield [['period'=>['month']]]; yield [['format'=>'pdf']];
        yield [['keep_filters'=>'1','filters'=>'invalid']]; yield [['keep_filters'=>'1','filters'=>['kind'=>['sale']]]];
    }
}
