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
}
