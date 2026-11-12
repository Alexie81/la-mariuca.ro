<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Modules\Inventory\NirDecimal;
use App\Modules\Inventory\NirValidationException;
use PHPUnit\Framework\TestCase;
final class NirDecimalTest extends TestCase
{
    public function testDecimalRoundingAndCurrencyDifferenceAreExact(): void
    {
        $line=NirDecimal::line(['accepted_quantity'=>'3','unit_price'=>'0.333333','vat_percent'=>'21'],'4.97512345');
        self::assertSame(100,$line['net_bani']); self::assertSame(21,$line['tax_bani']); self::assertSame(121,$line['total_bani']);
        self::assertSame(498,$line['net_ron_bani']); self::assertSame(602,$line['total_ron_bani']); self::assertSame(104,$line['tax_ron_bani']);
    }
    public function testExplicitVatAndGrossModesPreserveInvoiceRounding(): void
    {
        $exact=NirDecimal::line(['accepted_quantity'=>'3','price_mode'=>'exact_vat','net_override'=>'10.00','tax_override'=>'2.09','vat_percent'=>'21'],'1');
        self::assertSame(209,$exact['tax_bani']); self::assertSame(1209,$exact['total_bani']); self::assertSame('3.333333',$exact['unit_price']);
        $gross=NirDecimal::line(['price_mode'=>'gross','gross_override'=>'12.10','vat_percent'=>'21'],'1'); self::assertSame(1000,$gross['net_bani']); self::assertSame(210,$gross['tax_bani']);
    }
    public function testFractionalConversionMustBeRepresentableWithFourDecimals(): void
    {
        $this->expectException(NirValidationException::class); NirDecimal::line(['accepted_quantity'=>'0.0001','conversion_factor'=>'0.0001'],'1');
    }
}
