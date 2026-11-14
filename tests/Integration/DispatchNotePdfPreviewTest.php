<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Modules\Companies\CompanyRepository;
use App\Modules\Companies\CompanyStampStorage;
use App\Modules\Invoicing\DispatchNotePdfPreview;
use DateTimeImmutable;
use Tests\DatabaseTestCase;

final class DispatchNotePdfPreviewTest extends DatabaseTestCase
{
    public function testModelUsesLiveCompanyDetailsWithoutIssuingDocumentsOrChangingStock(): void
    {
        $this->pdo->exec("INSERT INTO companies (legal_name,trade_name,email,registered_address,is_default,created_at,updated_at) VALUES ('Firma SRL','Magazin model','firma@example.test','Strada 1',1,'2026-11-14','2026-11-14')");
        $model = new DispatchNotePdfPreview(new CompanyRepository($this->pdo), $this->root, new CompanyStampStorage($this->root));
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
        (new DispatchNotePdfPreview(new CompanyRepository($this->pdo), $this->root, new CompanyStampStorage($this->root)))->data();
    }

    public function testRecipientAndCurrentPrivateCompanyStampAreIncluded(): void
    {
        $this->pdo->exec("INSERT INTO companies (legal_name,is_default,created_at,updated_at) VALUES ('Firma SRL',1,'2026-11-14','2026-11-14')");
        $stamps = new CompanyStampStorage($this->root);
        $source = $this->root . '/public/assets/images/apple-touch-icon-lm.png';
        $name = $stamps->store(new \Slim\Psr7\UploadedFile($source, 'stamp.png', 'image/png', (int) filesize($source)));
        try {
            $this->pdo->prepare('UPDATE companies SET stamp_path=:stamp')->execute(['stamp'=>$name]);
            $model = new DispatchNotePdfPreview(new CompanyRepository($this->pdo), $this->root, $stamps);
            $data = $model->data();
            self::assertSame('data:image/png;base64,' . base64_encode((string) file_get_contents((string) $stamps->path($name))), $data['stamp_data']);
            $twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader($this->root . '/resources/views'), ['autoescape'=>'html','strict_variables'=>true]);
            $html = $twig->render('admin/invoices/dispatch-note-preview.twig', $data);
            self::assertMatchesRegularExpression('/PRIMIREA MĂRFII<\/div><div class="name">Ana Popescu<\/div>/', $html);
            self::assertStringContainsString('alt="Ștampila firmei"', $html);
            self::assertStringNotContainsString('storage/private', $html);
            $this->pdo->exec('UPDATE companies SET stamp_path=NULL');
            self::assertSame('', $model->data()['stamp_data']);
            $this->pdo->prepare('UPDATE companies SET stamp_path=:stamp')->execute(['stamp'=>$name]);
            $stamps->remove($name);
            self::assertSame('', $model->data()['stamp_data']);
        } finally {
            $stamps->remove($name);
        }
    }
}
