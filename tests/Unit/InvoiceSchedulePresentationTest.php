<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Modules\Invoicing\InvoicePresentation;
use PHPUnit\Framework\TestCase;

final class InvoiceSchedulePresentationTest extends TestCase
{
    public function testPendingDateIsPreservedWhenAuthorizationIsMissing(): void
    {
        $schedule=InvoicePresentation::schedule('awaiting_configuration',['available_at'=>'2026-12-01T09:00:00Z']);
        self::assertSame('Trimitere programată',$schedule['label']);
        self::assertSame('2026-12-01T09:00:00Z',$schedule['at']);
        self::assertStringContainsString('Suspendată',$schedule['description']);
        self::assertSame('Trimitere neprogramată',InvoicePresentation::schedule('not_sent',null)['label']);
    }

    public function testCompletedOrUncertainUploadNeverKeepsAnOldPendingDate(): void
    {
        foreach(['processing','submitted','accepted','rejected','uncertain'] as $state) {
            self::assertNull(InvoicePresentation::schedule($state,['available_at'=>'2026-12-01T09:00:00Z'])['at']);
        }
        self::assertSame('Trimitere efectuată',InvoicePresentation::schedule('accepted',null)['label']);
    }

    public function testRetryDateDisappearsWhenAutomaticAttemptsAreExhausted(): void
    {
        $job=['available_at'=>'2026-12-01T09:00:00Z','attempts'=>4];
        self::assertSame('Reîncercare programată',InvoicePresentation::schedule('transient_error',$job)['label']);
        $job['attempts']=5;
        $schedule=InvoicePresentation::schedule('transient_error',$job);
        self::assertNull($schedule['at']);
        self::assertSame('Retrimitere neprogramată',$schedule['label']);
    }
}
