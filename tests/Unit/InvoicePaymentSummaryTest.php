<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Modules\Invoicing\InvoicePaymentSummary;
use PHPUnit\Framework\TestCase;

final class InvoicePaymentSummaryTest extends TestCase
{
    public function testCurrentCashTakesPrecedenceOverFrozenUnpaidSnapshot(): void
    {
        $invoice=['kind'=>'sale','gross_bani'=>10000,'paid_bani'=>0,'payment_state'=>'unpaid'];
        $paid=InvoicePaymentSummary::forDocument($invoice,['received'=>10000,'refunded'=>0],10000);
        self::assertSame('Achitată',$paid['label']); self::assertSame(0,$paid['outstanding']);
        self::assertSame(10000,$paid['received']); self::assertSame(0,$invoice['paid_bani']);
        $partial=InvoicePaymentSummary::forDocument($invoice,['received'=>3000,'refunded'=>0],10000);
        self::assertSame('Achitată parțial',$partial['label']); self::assertSame(7000,$partial['outstanding']);
    }

    public function testCreditsAndRefundsDoNotCreateCashOrDoubleSubtractIt(): void
    {
        $credit=['kind'=>'return','gross_bani'=>-4000,'refunded_bani'=>0];
        $unpaid=InvoicePaymentSummary::forDocument($credit,['received'=>0,'refunded'=>0],6000);
        self::assertSame(0,$unpaid['document_refund_due']); self::assertSame('Retur înregistrat',$unpaid['label']);
        $pending=InvoicePaymentSummary::forDocument($credit,['received'=>10000,'refunded'=>0],6000);
        self::assertSame(4000,$pending['refundDue']); self::assertSame(4000,$pending['document_refund_due']);
        self::assertSame(0,$pending['outstanding']); self::assertSame('De restituit',$pending['label']);
        $credit['refunded_bani']=1500;
        $partial=InvoicePaymentSummary::forDocument($credit,['received'=>10000,'refunded'=>1500],6000);
        self::assertSame(2500,$partial['refundDue']); self::assertSame(1500,$partial['document_refunded']);
        self::assertSame(2500,$partial['document_refund_due']); self::assertSame('Rambursată parțial',$partial['label']);
        $credit['refunded_bani']=4000;
        $complete=InvoicePaymentSummary::forDocument($credit,['received'=>10000,'refunded'=>4000],6000);
        self::assertSame(0,$complete['outstanding']); self::assertSame(0,$complete['refundDue']);
        self::assertSame('Rambursată',$complete['label']);
    }
}
