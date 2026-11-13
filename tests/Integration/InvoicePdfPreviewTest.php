<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Modules\Companies\CompanyRepository;
use App\Modules\Invoicing\InvoiceConfigurationRepository;
use App\Modules\Invoicing\InvoicePdfPreview;
use DateTimeImmutable;
use Tests\DatabaseTestCase;

final class InvoicePdfPreviewTest extends DatabaseTestCase
{
    public function testPreviewUsesCurrentDatabaseSettingsWithoutIssuingAnInvoice(): void
    {
        $this->pdo->exec("INSERT INTO companies (legal_name,trade_name,email,registered_address,is_default,created_at,updated_at) VALUES ('Firma inițială SRL','Magazin test','firma@example.test','Strada Inițială 1',1,'2026-11-13','2026-11-13')");
        $preview = new InvoicePdfPreview(new CompanyRepository($this->pdo), new InvoiceConfigurationRepository($this->pdo), $this->root);
        $date = new DateTimeImmutable('2026-11-13T23:30:00Z');
        $first = $preview->data($date);
        self::assertSame('Firma inițială SRL', $first['company']['legal_name']);
        self::assertSame('14.11.2026', $first['date']);
        self::assertSame('0', $first['vat']);
        self::assertSame('487,00 Lei', $first['net']);
        $this->pdo->exec("UPDATE companies SET legal_name='Firma actualizată SRL', registered_address='Strada Nouă 2', email='nou@example.test', is_vat_payer=1,vat_rate_basis_points=2100");
        $this->pdo->exec("UPDATE invoice_configuration SET due_days=5,default_notes='Mulțumim pentru comandă.'");
        $this->pdo->exec("UPDATE invoice_series SET code='MODEL' WHERE id=1");
        $changes = (int) $this->pdo->query('SELECT total_changes()')->fetchColumn();
        $updated = $preview->data($date);
        self::assertSame('Firma actualizată SRL', $updated['company']['legal_name']);
        self::assertStringContainsString('Strada Nouă 2', $updated['company']['address_display']);
        self::assertSame('nou@example.test', $updated['company']['email']);
        self::assertSame('MODEL 1', $updated['number']);
        self::assertSame('19.11.2026', $updated['due_date']);
        self::assertSame('Mulțumim pentru comandă.', $updated['notes']);
        self::assertSame('21', $updated['vat']);
        self::assertSame('402,48 Lei', $updated['net']);
        self::assertSame('84,52 Lei', $updated['tax']);
        self::assertSame('487,00 Lei', $updated['total']);
        $path = sys_get_temp_dir() . '/lm-invoice-preview-' . bin2hex(random_bytes(8)) . '.pdf';
        try {
            $preview->pdf($path, $date);
            self::assertStringStartsWith('%PDF-', (string) file_get_contents($path));
            self::assertSame($changes, (int) $this->pdo->query('SELECT total_changes()')->fetchColumn());
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM invoice_configuration_snapshots')->fetchColumn());
        } finally {
            if (is_file($path)) { unlink($path); }
        }
    }

    public function testMissingCompanyDoesNotInventSellerInformation(): void
    {
        $preview = new InvoicePdfPreview(new CompanyRepository($this->pdo), new InvoiceConfigurationRepository($this->pdo), $this->root);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Completează mai întâi datele firmei.');
        $preview->data();
    }
}
