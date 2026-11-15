<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Modules\Invoicing\{ReturnSettlement,RefundDestination,InvoiceException};
use PHPUnit\Framework\TestCase;
final class ReturnSettlementTest extends TestCase
{
    public function testDisabledCostsAreIgnoredAndTheDefaultIsTheFullSelectedValue(): void
    {
        $plan=ReturnSettlement::calculate(12345,['transport_cost'=>'999999','return_costs'=>[['name'=>'Hidden','amount'=>'100']],'refund_total'=>'1']);
        self::assertSame(12345,$plan['refund_bani']);self::assertSame([],$plan['costs']);self::assertSame(0,$plan['adjustment_bani']);
        self::assertSame(['refund_method'=>'cash','holder'=>'','iban'=>'','bank_name'=>''],RefundDestination::normalize(['refund_method'=>'cash','iban'=>'private ignored']));
    }
    public function testInvalidOrExcessiveCostsAndRefundsAreRejected(): void
    {
        foreach([['transport_enabled'=>'1','transport_cost'=>'-1'],['transport_enabled'=>'1','transport_cost'=>'0'],['transport_enabled'=>'1','transport_cost'=>'101'],['other_costs_enabled'=>'1','return_costs'=>[['name'=>'','amount'=>'2']]],['custom_refund_enabled'=>'1','refund_total'=>'100.01'],['custom_refund_enabled'=>'1','refund_total'=>'-1']] as $input) {
            try { ReturnSettlement::calculate(10000,$input);self::fail('Invalid settlement accepted'); } catch(InvoiceException $e) { self::assertNotSame('',$e->getMessage()); }
        }
    }
    public function testManualTotalCanWaiveSomeCostsOrDeductAnAdditionalAmount(): void
    {
        $input=['transport_enabled'=>'1','transport_cost'=>'10','custom_refund_enabled'=>'1','refund_total'=>'95'];
        self::assertSame(500,ReturnSettlement::calculate(10000,$input)['adjustment_bani']);
        self::assertSame(-1000,ReturnSettlement::calculate(10000,array_replace($input,['refund_total'=>'80']))['adjustment_bani']);
        self::assertSame(0,ReturnSettlement::calculate(10000,array_replace($input,['refund_total'=>'0']))['refund_bani']);
    }
    public function testPartialQuotesPreserveEveryDiscountedCent(): void
    {
        $order=['subtotal_bani'=>1000,'discount_bani'=>1,'items'=>[['id'=>7,'line_total_bani'=>1000,'quantity'=>3,'returnable_quantity'=>3]]];
        $first=ReturnSettlement::quotes($order)[7];self::assertSame(999,$first[3]);
        $order['items'][0]['returnable_quantity']=2;$second=ReturnSettlement::quotes($order)[7];
        self::assertSame(999,$first[1]+$second[2]);
    }
}
