<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use App\Modules\Companies\CompanyRepository;
use DateTimeImmutable;
use DateTimeZone;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/** Read-only design preview. Never allocates an invoice number or writes a document. */
final class InvoicePdfPreview
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly InvoiceConfigurationRepository $configuration,
        private readonly string $root,
    ) {}

    /** @return array<string,mixed> */
    public function data(?DateTimeImmutable $date = null): array
    {
        $company = $this->companies->allCompanies()[0] ?? throw new \RuntimeException('Completează mai întâi datele firmei.');
        $settings = $this->configuration->settings();
        $date = ($date ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Europe/Bucharest'));
        $vat = (bool) $company['is_vat_payer'] ? (int) $company['vat_rate_basis_points'] : 0;
        $lines = []; $net = 0; $tax = 0; $total = 0;
        // Illustrative items/recipient only. They are not saved as an order or invoice.
        foreach ([
            ['name'=>'Cardigan „Norișor”','sku'=>'LM-26-009','quantity'=>1,'unit_bani'=>18900,'image'=>'cardigan-norisor.webp'],
            ['name'=>'Balerini „Rosa”','sku'=>'LM-26-022','quantity'=>2,'unit_bani'=>14900,'image'=>'balerini-rosa.webp'],
        ] as $item) {
            $gross = $item['unit_bani'] * $item['quantity'];
            $base = intdiv($gross * 10000 + intdiv(10000 + $vat, 2), 10000 + $vat);
            $unit = intdiv($item['unit_bani'] * 10000 + intdiv(10000 + $vat, 2), 10000 + $vat);
            $lines[] = $item + ['unit_net'=>$this->money($unit),'net'=>$this->money($base),'total'=>$this->money($gross),'image_data'=>$this->image('assets/images/products/collection-2026/' . $item['image'])];
            $net += $base; $tax += $gross - $base; $total += $gross;
        }
        $company['address_display'] = implode(', ', array_filter([$company['registered_address'],$company['postal_code'],$company['city'],$company['county'],$company['country']], static fn($v): bool => trim((string) $v) !== ''));
        $company['iban_display'] = trim(chunk_split((string) $company['iban'], 4, ' '));
        $company['capital_display'] = $company['share_capital_bani'] === null ? '' : $this->money((int) $company['share_capital_bani']);
        $company['website_display'] = preg_replace('~^https?://~i', '', rtrim((string) $company['website'], '/'));
        return [
            'company'=>$company,'lines'=>$lines,'preview'=>true,'number'=>$settings['active_code'] . ' 1',
            'date'=>$date->format('d.m.Y'),'due_date'=>$date->modify('+' . (int) $settings['due_days'] . ' days')->format('d.m.Y'),
            'notes'=>$settings['default_notes'],'vat'=>rtrim(rtrim(number_format($vat / 100, 2, ',', ''), '0'), ','),
            'net'=>$this->money($net),'tax'=>$this->money($tax),'total'=>$this->money($total),
            'customer'=>['name'=>'Ana Popescu','address'=>'Strada Exemplului nr. 12, 010101, București, România','phone'=>'07xx xxx xxx','email'=>'client@example.test'],
            'logo'=>$this->image('assets/images/logo-la-mariuca-transparent.png'),
            'monogram'=>$this->image('assets/images/apple-touch-icon-lm.png'),
            'font_regular'=>$this->fileUrl('public/assets/fonts/nunito-sans-pdf-regular.ttf'),
            'font_bold'=>$this->fileUrl('public/assets/fonts/nunito-sans-pdf-bold.ttf'),
        ];
    }

    public function pdf(string $path, ?DateTimeImmutable $date = null): void
    {
        $this->render($path, $this->data($date));
    }

    /** The same approved layout renders issued documents from their frozen snapshot.
     * @param array<string,mixed> $data
     */
    public function render(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) { throw new \RuntimeException('Nu se poate crea directorul PDF.'); }
        $cache = $this->root . '/storage/cache/invoice-pdf';
        if (!is_dir($cache) && !mkdir($cache, 0770, true) && !is_dir($cache)) { throw new \RuntimeException('Nu se poate crea cache-ul PDF.'); }
        $options = new Options();
        $options->set('isRemoteEnabled', false); $options->set('isPhpEnabled', false); $options->set('isJavascriptEnabled', false);
        $options->setChroot([$this->root . '/public', $cache]);
        $options->setFontDir($cache); $options->setFontCache($cache); $options->setTempDir($cache);
        $options->set('isFontSubsettingEnabled', true);
        $options->setFontHeightRatio(0.8);
        $pdf = new Dompdf($options);
        $twig = new Environment(new FilesystemLoader($this->root . '/resources/views'), ['autoescape'=>'html','strict_variables'=>true]);
        $pdf->loadHtml($twig->render('admin/invoices/pdf-preview.twig', $data), 'UTF-8');
        $pdf->setPaper('A4'); $pdf->render();
        $font = $pdf->getFontMetrics()->getFont('Nunito PDF', 'normal') ?? 'Helvetica';
        $pdf->getCanvas()->page_text(506, 816, 'Pagina {PAGE_NUM} / {PAGE_COUNT}', $font, 7, [0.5,0.44,0.49]);
        if (file_put_contents($path, $pdf->output()) === false) { throw new \RuntimeException('PDF-ul nu a putut fi salvat.'); }
        unset($pdf); gc_collect_cycles();
    }

    private function money(int $bani): string
    {
        return number_format(intdiv($bani, 100), 0, ',', '.') . ',' . str_pad((string) ($bani % 100), 2, '0', STR_PAD_LEFT) . ' Lei';
    }

    private function fileUrl(string $relative): string
    {
        // Dompdf validates file:// paths with realpath(), including Windows drive letters.
        return 'file://' . str_replace('\\', '/', $this->root . '/' . $relative);
    }

    private function image(string $relative): string
    {
        $path = $this->root . '/public/' . $relative;
        if (!is_file($path)) { throw new \RuntimeException('Lipsește imaginea previzualizării: ' . $relative); }
        $mime = str_ends_with($relative, '.webp') ? 'image/webp' : 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
    }
}
