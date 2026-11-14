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

/** Design model only: no numbering, order, invoice or inventory writes. */
final class DispatchNotePdfPreview
{
    public function __construct(private readonly CompanyRepository $companies, private readonly string $root) {}

    /** @return array<string,mixed> */
    public function data(?DateTimeImmutable $date = null, int $lineCount = 3): array
    {
        if ($lineCount < 1 || $lineCount > 100) { throw new \InvalidArgumentException('Modelul acceptă între 1 și 100 de poziții.'); }
        $company = $this->companies->allCompanies()[0] ?? throw new \RuntimeException('Completează mai întâi datele firmei.');
        $date = ($date ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Europe/Bucharest'));
        $samples = [
            ['name'=>'Cardigan „Norișor”','sku'=>'LM-26-009','quantity'=>1,'unit_bani'=>18900,'image'=>'cardigan-norisor.webp'],
            ['name'=>'Balerini „Rosa”','sku'=>'LM-26-022','quantity'=>2,'unit_bani'=>14900,'image'=>'balerini-rosa.webp'],
            ['name'=>'Bonetă cu dantelă „Bun venit”','sku'=>'LM-26-004','quantity'=>1,'unit_bani'=>5900,'image'=>'boneta-bun-venit.webp'],
        ];
        $lines = []; $total = 0; $quantity = 0;
        for ($index = 0; $index < $lineCount; ++$index) {
            $item = $samples[$index % count($samples)];
            $value = $item['unit_bani'] * $item['quantity'];
            $lines[] = $item + ['position'=>$index + 1, 'unit'=>'buc','unit_price'=>$this->money($item['unit_bani']), 'value'=>$this->money($value),
                'image_data'=>$this->image('assets/images/products/collection-2026/' . $item['image'])];
            $total += $value; $quantity += $item['quantity'];
        }
        $county = str_contains((string) $company['city'], (string) $company['county']) ? '' : $company['county'];
        $company['address_display'] = implode(', ', array_filter([$company['registered_address'], $company['postal_code'], $company['city'], $county, $company['country']], static fn($v): bool => trim((string) $v) !== ''));
        $company['website_display'] = preg_replace('~^https?://~i', '', rtrim((string) $company['website'], '/'));
        $lineGroups = $lineCount > 20 ? [array_slice($lines, 0, 11), ...array_chunk(array_slice($lines, 11), 20)] : [$lines];
        return [
            'company'=>$company, 'lines'=>$lines, 'line_groups'=>$lineGroups, 'series'=>'LM-AVZ', 'number'=>'1', 'reference'=>'LM-AVZ 1',
            'date'=>$date->format('d.m.Y'), 'order_reference'=>'LM-DEMO-2026-1', 'total'=>$this->money($total), 'quantity'=>$quantity,
            'customer'=>['name'=>'Ana Popescu', 'type'=>'Persoană fizică · Date demonstrative',
                'address'=>'Strada Exemplului nr. 12, 010101, București, România', 'phone'=>'07xx xxx xxx', 'email'=>'client@example.test'],
            'dispatch'=>['delegate'=>'', 'identity'=>'', 'vehicle'=>'', 'time'=>'', 'loading_place'=>$company['physical_address'] ?: $company['address_display']],
            'logo'=>$this->image('assets/images/logo-la-mariuca-transparent.png'),
            'monogram'=>$this->image('assets/images/apple-touch-icon-lm.png'),
            'font_regular'=>'file://' . str_replace('\\', '/', $this->root . '/public/assets/fonts/nunito-sans-pdf-regular.ttf'),
            'font_bold'=>'file://' . str_replace('\\', '/', $this->root . '/public/assets/fonts/nunito-sans-pdf-bold.ttf'),
        ];
    }

    public function pdf(string $path, ?DateTimeImmutable $date = null, int $lineCount = 3): void
    {
        $data = $this->data($date, $lineCount);
        $cache = $this->root . '/storage/cache/dispatch-note-pdf';
        foreach ([dirname($path), $cache] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new \RuntimeException('Directorul pentru PDF nu poate fi creat.');
            }
        }
        $options = new Options();
        $options->set('isRemoteEnabled', false); $options->set('isPhpEnabled', false); $options->set('isJavascriptEnabled', false);
        $options->setChroot([$this->root . '/public', $cache]);
        $options->setFontDir($cache); $options->setFontCache($cache); $options->setTempDir($cache);
        $options->set('isFontSubsettingEnabled', true); $options->setFontHeightRatio(0.8);
        $pdf = new Dompdf($options);
        $twig = new Environment(new FilesystemLoader($this->root . '/resources/views'), ['autoescape'=>'html', 'strict_variables'=>true]);
        $pdf->loadHtml($twig->render('admin/invoices/dispatch-note-preview.twig', $data), 'UTF-8');
        $pdf->setPaper('A4'); $pdf->render();
        $font = $pdf->getFontMetrics()->getFont('Nunito PDF', 'normal') ?? 'Helvetica';
        $pdf->getCanvas()->page_text(500, 813, 'Pagina {PAGE_NUM} / {PAGE_COUNT}', $font, 7, [.5,.44,.49]);
        if (file_put_contents($path, $pdf->output()) === false) { throw new \RuntimeException('Modelul de aviz nu a putut fi salvat.'); }
        unset($pdf); gc_collect_cycles();
    }

    private function money(int $bani): string
    {
        return number_format(intdiv($bani, 100), 0, ',', '.') . ',' . str_pad((string) ($bani % 100), 2, '0', STR_PAD_LEFT) . ' Lei';
    }

    private function image(string $relative): string
    {
        $path = $this->root . '/public/' . $relative;
        if (!is_file($path)) { throw new \RuntimeException('Lipsește imaginea modelului: ' . $relative); }
        return 'data:' . (str_ends_with($relative, '.webp') ? 'image/webp' : 'image/png') . ';base64,' . base64_encode((string) file_get_contents($path));
    }
}
