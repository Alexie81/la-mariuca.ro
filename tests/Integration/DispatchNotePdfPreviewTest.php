<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Modules\Companies\CompanyRepository;
use App\Modules\Invoicing\DispatchNotePdfPreview;
use DateTimeImmutable;
use Tests\DatabaseTestCase;

final class DispatchNotePdfPreviewTest extends DatabaseTestCase
{
    public function testModelUsesLiveCompanyDetailsWithoutIssuingDocumentsOrChangingStock(): void
    {
        $this->pdo->exec("INSERT INTO companies (legal_name,trade_name,email,registered_address,is_default,created_at,updated_at) VALUES ('Firma SRL','Magazin model','firma@example.test','Strada 1',1,'2026-11-14','2026-11-14')");
        $model = new DispatchNotePdfPreview(new CompanyRepository($this->pdo), $this->root);
        $date = new DateTimeImmutable('2026-11-14T23:30:00Z');
        self::assertSame('Firma SRL', $model->data($date)['company']['legal_name']);
        $this->pdo->exec("UPDATE companies SET legal_name='Firma nouă SRL',phone='0700000000'");
        $changes = (int) $this->pdo->query('SELECT total_changes()')->fetchColumn();
        $data = $model->data($date);
        self::assertSame('Firma nouă SRL', $data['company']['legal_name']);
        self::assertSame('0700000000', $data['company']['phone']);
        self::assertSame('15.11.2026', $data['date']);
        self::assertSame(4, $data['quantity']);
        self::assertSame('546,00 Lei', $data['total']);
        self::assertSame('7.644,00 Lei', $model->data($date, 42)['total']);
        $path = sys_get_temp_dir() . '/lm-aviz-' . bin2hex(random_bytes(6)) . '.pdf';
        try {
            $model->pdf($path, $date);
            self::assertStringStartsWith('%PDF-', (string) file_get_contents($path));
            self::assertSame($changes, (int) $this->pdo->query('SELECT total_changes()')->fetchColumn());
        } finally {
            if (is_file($path)) { unlink($path); }
        }
    }

    public function testRequiresCompanyDetails(): void
    {
        $this->expectException(\RuntimeException::class);
        (new DispatchNotePdfPreview(new CompanyRepository($this->pdo), $this->root))->data();
    }
}
