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

    public function testTaxUsesRoundedNetAndCostIncludesOnlyExplicitlyAllocatedAmounts(): void
    {
        $rounded=NirDecimal::line(['accepted_quantity'=>'1','unit_price'=>'0.0249','vat_percent'=>'21'],'1');
        self::assertSame(2,$rounded['net_bani']); self::assertSame(0,$rounded['tax_bani']); self::assertSame(2,$rounded['total_bani']);
        $cost=NirDecimal::line(['accepted_quantity'=>'2','unit_price'=>'10','discount_percent'=>'25','vat_percent'=>'21','extra_cost_ron'=>'3.335','conversion_factor'=>'2.5'],'1',true);
        self::assertSame(500,$cost['discount_bani']); self::assertSame(1500,$cost['net_bani']); self::assertSame(315,$cost['tax_bani']); self::assertSame(2149,$cost['inventory_bani']); self::assertSame('4.298000',$cost['unit_cost_ron']); self::assertSame(50000,$cost['stock_quantity_units']);
    }

    public function testFullDiscountAndDocumentaryLinesNeverInventAcquisitionCost(): void
    {
        $free=NirDecimal::line(['accepted_quantity'=>'3','unit_price'=>'10','discount_percent'=>'100'],'1');
        self::assertSame(0,$free['total_bani']); self::assertSame(0,$free['inventory_bani']); self::assertSame(3000,$free['discount_bani']);
        $documentary=NirDecimal::line(['accepted_quantity'=>'3','unit_price'=>'10','tracked'=>false,'extra_cost_ron'=>'5'],'1',true);
        self::assertSame(3630,$documentary['total_bani']); self::assertSame(0,$documentary['inventory_bani']); self::assertSame('0.000000',$documentary['unit_cost_ron']);
        $draft=NirDecimal::line(['accepted_quantity'=>'0','unit_price'=>'10'],'1'); self::assertSame(0,$draft['stock_quantity_units']); self::assertSame(0,$draft['total_bani']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidLines')]
    public function testInvalidNumericInputsProduceValidationErrors(array $line): void
    {
        $this->expectException(NirValidationException::class); NirDecimal::line($line,'1');
    }

    public static function invalidLines(): iterable
    {
        yield 'negative quantity'=>[['accepted_quantity'=>'-1']];
        yield 'negative price'=>[['unit_price'=>'-0.01']];
        yield 'negative allocation'=>[['extra_cost_ron'=>'-1']];
        yield 'zero conversion'=>[['conversion_factor'=>'0']];
        yield 'overflow quantity'=>[['accepted_quantity'=>'9999999999','conversion_factor'=>'9999999999']];
        yield 'overflow money'=>[['accepted_quantity'=>'9999999999','unit_price'=>'9999999999']];
        yield 'quantity precision'=>[['accepted_quantity'=>'1.00001']];
        yield 'unit precision'=>[['unit_price'=>'0.0000001']];
        yield 'discount over 100'=>[['discount_percent'=>'100.000001']];
        yield 'VAT over 100'=>[['vat_percent'=>'101']];
        yield 'exponential notation'=>[['unit_price'=>'1e2']];
        yield 'boolean input'=>[['unit_price'=>true]];
        yield 'array input'=>[['unit_price'=>['10']]];
        yield 'unknown mode'=>[['price_mode'=>'unknown']];
        yield 'net precision'=>[['price_mode'=>'line_net','net_override'=>'10.001']];
        yield 'negative exact tax'=>[['price_mode'=>'exact_vat','net_override'=>'10','tax_override'=>'-1']];
        yield 'negative gross'=>[['price_mode'=>'gross','gross_override'=>'-1']];
    }
}
