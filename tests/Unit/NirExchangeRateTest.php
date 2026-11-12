<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Modules\Inventory\NirExchangeRateService;
use PHPUnit\Framework\TestCase;
final class NirExchangeRateTest extends TestCase
{
    public function testLatestPreviousQuoteAndMultiplierUseDecimalMath(): void
    {
        $xml='<DataSet xmlns="http://www.bnr.ro/xsd"><Body><Cube date="2026-11-06"><Rate currency="JPY" multiplier="100">3.1234</Rate></Cube><Cube date="2026-11-09"><Rate currency="JPY" multiplier="100">3.1456</Rate></Cube></Body></DataSet>';
        $service=new NirExchangeRateService(dirname(__DIR__,2));
        self::assertSame(['currency'=>'JPY','rate'=>'0.03123400','date'=>'2026-11-06'],$service->parse($xml,'JPY','2026-11-08'));
        self::assertNull($service->parse('<!DOCTYPE a [<!ENTITY x SYSTEM "file:///secret">]><a>&x;</a>','EUR','2026-11-08'));
    }

    public function testFutureDateValidationUsesBucharestRegardlessOfPhpDefaultTimezone(): void
    {
        $service=new NirExchangeRateService(dirname(__DIR__,2));
        $today=new \DateTimeImmutable('now',new \DateTimeZone('Europe/Bucharest'));
        $previous=date_default_timezone_get();
        try {
            foreach(['Pacific/Kiritimati','Etc/GMT+12'] as $timezone) {
                date_default_timezone_set($timezone);
                self::assertSame('1.00000000',$service->lookup('RON',$today->format('Y-m-d'))['rate']);
                try {
                    $service->lookup('RON',$today->modify('+1 day')->format('Y-m-d'));
                    self::fail('Tomorrow in Bucharest must be rejected independently of the PHP timezone.');
                } catch (\InvalidArgumentException $exception) {
                    self::assertStringContainsString('viitor',$exception->getMessage());
                }
            }
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testOfficialArchiveEndpointFallsBackAcrossNewYearAndCachesTheValidFeed(): void
    {
        $root=sys_get_temp_dir().'/lamariuca-bnr-endpoint-'.bin2hex(random_bytes(8));
        $requests=[];
        $fetch=static function(string $url) use (&$requests): ?string {
            $requests[]=$url;
            return str_ends_with($url,'2025.xml')
                ? '<DataSet xmlns="http://www.bnr.ro/xsd"><Body><Cube date="2025-01-03"><Rate currency="EUR">5.0</Rate></Cube></Body></DataSet>'
                : '<DataSet xmlns="http://www.bnr.ro/xsd"><Body><Cube date="2024-12-31"><Rate currency="EUR">4.9741</Rate></Cube></Body></DataSet>';
        };
        try {
            $service=new NirExchangeRateService($root,$fetch);
            $quote=$service->lookup('EUR','2025-01-01');
            self::assertSame(['https://curs.bnr.ro/files/xml/years/nbrfxrates2025.xml','https://curs.bnr.ro/files/xml/years/nbrfxrates2024.xml'],$requests);
            self::assertSame('2024-12-31',$quote['date']);
            self::assertSame('4.97410000',$quote['rate']);
            self::assertSame('BNR',$quote['source']);
            self::assertFileExists($root.'/storage/cache/bnr-2024.xml');
            $requests=[];
            self::assertSame($quote,$service->lookup('EUR','2025-01-01'));
            self::assertSame(['https://curs.bnr.ro/files/xml/years/nbrfxrates2025.xml'],$requests);
        } finally {
            foreach(glob($root.'/storage/cache/*.xml') ?: [] as $path) { unlink($path); }
            if(is_dir($root.'/storage/cache')) { rmdir($root.'/storage/cache');rmdir($root.'/storage');rmdir($root); }
        }
    }

    public function testUnavailableOfficialFeedsLeaveManualEntryAvailable(): void
    {
        $requests=[];
        $service=new NirExchangeRateService(sys_get_temp_dir().'/lamariuca-bnr-failed-'.bin2hex(random_bytes(8)),static function(string $url) use (&$requests): ?string { $requests[]=$url; return null; });
        $quote=$service->lookup('EUR','2025-03-02');
        self::assertFalse($quote['success']);
        self::assertTrue($quote['manual']);
        self::assertCount(2,$requests);
    }
}
