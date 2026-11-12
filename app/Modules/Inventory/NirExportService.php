<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/** Exports immutable document snapshots supplied by the authorized application service. */
final class NirExportService
{
    /** @var array<string,string> */
    private array $thumbnailCache = [];
    private const REGISTRY_COLUMNS = ['Nr. crt.', 'Număr NIR', 'Tip document', 'Status', 'Data NIR', 'Ora NIR', 'Data recepției', 'Ora recepției', 'Furnizor', 'CUI furnizor', 'Serie factură', 'Număr factură', 'Data facturii', 'Gestiune', 'Monedă', 'Curs valutar', 'Data cursului', 'Poziții', 'Cantitate facturată', 'Cantitate recepționată', 'Cantitate acceptată', 'Cantitate respinsă', 'Diferență cantitativă', 'Subtotal în moneda documentului', 'TVA în moneda documentului', 'Total în moneda documentului', 'Subtotal RON', 'TVA RON', 'Total RON', 'Cost contabil RON', 'Diferență valoare RON', 'Documente atașate', 'NIR inițial', 'Observații/motiv', 'Confirmat la', 'Confirmat de', 'Creat la', 'Creat de'];
    public function __construct(private readonly string $root)
    {
    }

    /** @param array<string,mixed> $document @return array{path:string,filename:string,mime:string} */
    public function individual(array $document, string $format, bool $viewCosts = true): array
    {
        if (!in_array($format, ['pdf', 'xlsx', 'zip'], true)) {
            throw new \InvalidArgumentException('Alege PDF, Excel sau pachetul ZIP.');
        }
        $path = $this->temporary($format);
        $number = self::number($document);
        try {
            if ($format === 'pdf') {
                $this->pdf($document, $path, $viewCosts);
            } elseif ($format === 'xlsx') {
                $this->saveWorkbook($this->documentWorkbook($document, $viewCosts), $path);
            } else {
                $this->package([$document], $path, $viewCosts);
            }
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
        $company = $document['company']['trade_name'] ?? $document['company']['legal_name'] ?? 'La_Mariuca';
        $filename = $format === 'xlsx' ? 'NIR_' . $company . '_' . $number . '_' . substr((string) ($document['nir_at'] ?? ''), 0, 10) : $number;
        return ['path' => $path, 'filename' => NirDocumentStorage::safeName($filename . '.' . $format), 'mime' => self::mime($format)];
    }

    /** @param list<array<string,mixed>> $documents @param array<string,mixed> $period @return array{path:string,filename:string,mime:string} */
    public function registry(array $documents, string $format, array $period = [], bool $viewCosts = true): array
    {
        if (!in_array($format, ['xlsx', 'zip'], true)) {
            throw new \InvalidArgumentException('Registrul se exportă în Excel sau ZIP.');
        }
        $path = $this->temporary($format);
        try {
            if ($format === 'zip') {
                $this->package($documents, $path, $viewCosts, $period);
            } else {
                $this->saveWorkbook($this->registryWorkbook($documents, $period, $viewCosts), $path);
            }
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
        $filename = 'Registru_NIR_' . ($period['from'] ?? 'toate') . '_' . ($period['to'] ?? 'datele') . '.' . $format;
        return ['path' => $path, 'filename' => NirDocumentStorage::safeName($filename), 'mime' => self::mime($format)];
    }

    /** The controller calls this after streaming; arbitrary filesystem paths are never accepted. */
    public function cleanup(string $path): void
    {
        $directory = realpath($this->root . '/storage/private/nir-exports');
        $resolved = realpath($path);
        if ($directory !== false && $resolved !== false && dirname($resolved) === $directory && !is_link($path)) {
            @unlink($resolved);
        }
    }

    /** @param array<string,mixed> $document */
    private function pdf(array $document, string $path, bool $viewCosts): void
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->setChroot([$this->root . '/public', $this->root . '/storage/private/nir-exports']);
        $options->setTempDir(dirname($path));
        $dompdf = new Dompdf($options);
        try {
            $twig = new Environment(new FilesystemLoader($this->root . '/resources/views'), ['autoescape' => 'html']);
            $lines = [];
            foreach ($document['lines'] ?? [] as $line) {
                $line['image_data'] = $this->imageData($line['image_path'] ?? null);
                $line['difference'] = (string) BigDecimal::of($line['received_quantity'] ?? '0')->minus($line['invoice_quantity'] ?? '0');
                $line['difference_label'] = self::differenceLabel($line['difference_reason'] ?? '');
                $lines[] = $line;
            }
            $stamp = new \App\Modules\Companies\CompanyStampStorage($this->root);
            $stampPath = $stamp->path($document['company']['stamp_path'] ?? null);
            $dompdf->loadHtml($twig->render('admin/nir/pdf.twig', [
                'nir' => $document, 'number' => self::number($document), 'type_label' => self::type($document),
                'status_label' => self::status($document), 'lines' => $lines, 'view_costs' => $viewCosts,
                'logo' => $this->imageData('/assets/images/logo-la-mariuca-transparent.png'),
                'stamp' => $stampPath === null ? null : 'data:image/png;base64,' . base64_encode((string) file_get_contents($stampPath)),
                'nir_date' => self::displayDate($document['nir_at'] ?? ''), 'received_date' => self::displayDate($document['received_at'] ?? ''),
                'generated_at' => self::displayDate(gmdate('Y-m-d H:i:s')),
            ]), 'UTF-8');
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();
            $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
            $dompdf->getCanvas()->page_text(680, 578, 'Pagina {PAGE_NUM} / {PAGE_COUNT}', $font ?? 'DejaVu Sans', 7, [0.48, 0.43, 0.44]);
            if (file_put_contents($path, $dompdf->output()) === false) {
                throw new \RuntimeException('PDF-ul nu a putut fi generat.');
            }
        } finally {
            // The PDF canvas holds circular references. Release its temporary alpha-mask
            // images after each document, including failed ZIP exports and large batches.
            unset($dompdf);
            gc_collect_cycles();
        }
    }

    /** @param array<string,mixed> $document */
    private function documentWorkbook(array $document, bool $viewCosts): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(9)->getColor()->setRGB('49383E');
        $book->getProperties()->setCreator('La Măriuca Boutique')->setTitle('NIR ' . self::number($document));
        $sheet = $book->getActiveSheet()->setTitle('NIR');
        $sheet->setShowGridlines(false)->setPrintGridlines(false);
        $sheet->getTabColor()->setRGB('855260');
        $widths = [6,13,11,23,8,13,14,13,13,14,9,13,16];
        foreach ($widths as $i => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($width);
        }
        foreach ([32,24,20,8,24,24,26,26,26,26,8,48] as $i => $height) {
            $sheet->getRowDimension($i + 1)->setRowHeight($height);
        }
        $merge = function (string $range, mixed $value = '') use ($sheet): void {
            $sheet->mergeCells($range);
            $first = explode(':', $range)[0];
            [$column, $row] = Coordinate::coordinateFromString($first);
            $this->cell($sheet, Coordinate::columnIndexFromString($column), (int)$row, $value);
            $sheet->getStyle($range)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        };
        $fill = static function (string $range, string $color) use ($sheet): void {
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
        };
        $border = ['borderStyle'=>'thin','color'=>['rgb'=>'E7D9DD']];
        $company = $document['company'] ?? [];
        $merge('A1:B3');
        $this->draw($sheet, $this->publicImage('/assets/images/logo-la-mariuca-transparent.png'), 'A1', 72, 122);
        $merge('C1:F1', ($company['trade_name'] ?? '') ?: ($company['legal_name'] ?? 'La Măriuca Boutique'));
        $merge('C2:F2', $company['legal_name'] ?? '');
        $identity = [];
        foreach (['CUI'=>'tax_id', 'Reg. Com.'=>'trade_register'] as $label=>$key) {
            if (!empty($company[$key])) { $identity[] = $label . ' ' . $company[$key]; }
        }
        $merge('C3:F3', implode(' | ', $identity));
        $sheet->getStyle('C1:F1')->getFont()->setSize(18)->setBold(true)->getColor()->setRGB('714652');
        $sheet->getStyle('C2:F2')->getFont()->setBold(true);
        $sheet->getStyle('C3:F3')->getFont()->setSize(8)->getColor()->setRGB('8A747C');
        $merge('G1:L2', 'NOTĂ DE RECEPȚIE ȘI CONSTATARE DE DIFERENȚE');
        $sheet->getStyle('G1:L2')->getFont()->setSize(18)->setBold(true);
        $merge('G3:L3', '(NIR)');
        $sheet->getStyle('G3:L3')->getFont()->setSize(14)->setBold(true)->getColor()->setRGB('B05D73');
        $merge('M1:M3', "Cod formular:\nNIR");
        $fill('M1:M3', 'FBF5F4');
        $sheet->getStyle('G1:M3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('M1:M3')->getFont()->setBold(true);
        $number = self::number($document);
        if (($document['status'] ?? 'draft') === 'draft') { $number .= ' · Ciornă'; }
        $merge('A5:F6', "Nr. NIR\n" . $number);
        $merge('G5:M6', "din data de\n" . self::dateOnly($document['nir_at'] ?? ''));
        $fill('A5:M6', '855260');
        $sheet->getStyle('A5:M6')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A5:M6')->getAlignment()->setIndent(1);
        $sheet->getStyle('G5:M6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $supplier = $document['supplier'] ?? [];
        $left = [
            ['Furnizor:', ($supplier['company_name'] ?? '') ?: ($supplier['alias'] ?? '')],
            ['CUI furnizor:', $supplier['tax_id'] ?? ''],
            ['Gestiune:', $document['warehouse']['name'] ?? ''],
            ['Loc recepție:', $document['location']['name'] ?? ''],
        ];
        $right = [
            ['Factura furnizor nr. / data:', self::invoiceLabel($document)],
            ['Factura inițială stornată nr. / data:', self::invoiceLabel($document, 'original_')],
            ['Tip operațiune:', self::type($document)],
            ['Motiv storno / retur / diferență:', $document['reason'] ?? ''],
        ];
        foreach ($left as $i => [$label, $value]) {
            $row = $i + 7;
            $merge('A'.$row.':B'.$row, $label);
            $merge('C'.$row.':F'.$row, $value);
            $merge('G'.$row.':I'.$row, $right[$i][0]);
            $merge('J'.$row.':M'.$row, $right[$i][1]);
            foreach (['A'.$row.':B'.$row, 'G'.$row.':I'.$row] as $range) {
                $fill($range, 'FBF5F4');
                $sheet->getStyle($range)->getFont()->setBold(true);
            }
            $longest = max(mb_strlen((string)$value), mb_strlen((string)$right[$i][1]));
            $sheet->getRowDimension($row)->setRowHeight(max(26, (int)ceil($longest / 52) * 12 + 8));
        }
        $sheet->getStyle('A7:M10')->getBorders()->getAllBorders()->applyFromArray($border);
        $sheet->getStyle('A7:M10')->getAlignment()->setIndent(1);
        $headers = ['Nr. crt.','Cod / SKU','Imagine','Denumirea bunurilor recepționate','U.M.','Cantitate document','Cantitate recepționată','Diferență cantitativă','Preț unitar fără TVA','Valoare fără TVA','TVA %','Valoare TVA','Valoare totală'];
        $this->putRow($sheet, 12, $headers);
        $fill('A12:M12', '855260');
        $sheet->getStyle('A12:M12')->getFont()->setSize(8)->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A12:M12')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $currency = preg_match('/^[A-Z]{3}$/D', (string)($document['currency'] ?? 'RON')) ? $document['currency'] : 'RON';
        $moneyFormat = '#,##0.00 "' . $currency . '";[Red]-#,##0.00 "' . $currency . '"';
        $lines = $document['lines'] ?? [];
        $rates = [];
        $observations = empty($document['notes']) ? [] : [(string)$document['notes']];
        foreach ($lines as $i => $line) {
            $row = 13 + $i;
            $name = (string)(($line['supplier_name'] ?? '') ?: ($line['product_name'] ?? ''));
            $sku = (string)(($line['sku'] ?? '') ?: ($line['supplier_code'] ?? ''));
            $values = [$i+1,$sku,null,$name,$line['purchase_unit'] ?? 'buc',self::numeric($line['invoice_quantity'] ?? 0),self::numeric($line['received_quantity'] ?? 0),null];
            array_push($values, $viewCosts ? self::unitPrice($line) : null, $viewCosts ? self::money($line,'net_bani') : null, $viewCosts ? self::numeric($line['vat_percent'] ?? null) : null, $viewCosts ? self::money($line,'tax_bani') : null, $viewCosts ? self::money($line,'total_bani') : null);
            $this->putRow($sheet, $row, $values);
            $sheet->setCellValue('H'.$row, '=ROUND(G'.$row.'-F'.$row.',4)');
            $difference = BigDecimal::of($line['received_quantity'] ?? '0')->minus($line['invoice_quantity'] ?? '0');
            $sheet->getStyle('H'.$row)->getFont()->getColor()->setRGB($difference->isZero() ? '39735C' : ($difference->isNegative() ? 'AB4056' : '986B2B'));
            $sheet->getRowDimension($row)->setRowHeight(max(50, (int)ceil(mb_strlen($name) / 23) * 12 + 12, (int)ceil(mb_strlen($sku) / 13) * 12 + 12));
            $this->draw($sheet, $this->publicImage($line['image_path'] ?? null), 'C'.$row, 46, 64);
            if ($viewCosts && isset($line['vat_percent'])) { $rates[(string)BigDecimal::of($line['vat_percent'])->strippedOfTrailingZeros()] = true; }
            $acceptedDiffers = !BigDecimal::of($line['accepted_quantity'] ?? $line['received_quantity'] ?? '0')->isEqualTo($line['received_quantity'] ?? '0');
            if ($acceptedDiffers || !empty($line['difference_reason']) || !empty($line['difference_notes'])) {
                $accepted = $acceptedDiffers ? 'Acceptat '.BigDecimal::of($line['accepted_quantity'] ?? '0')->strippedOfTrailingZeros().' '.($line['purchase_unit'] ?? 'buc').'. ' : '';
                $observations[] = 'Poziția '.($i+1).': '.$accepted.trim(self::differenceLabel($line['difference_reason'] ?? '').'. '.($line['difference_notes'] ?? ''), '. ');
            }
        }
        $last = 12 + max(1, count($lines));
        $total = $last + 1;
        $sheet->getStyle('A13:M'.$last)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A13:A'.$last)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('E13:H'.$last)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('I13:M'.$total)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('K13:K'.$total)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F13:H'.$last)->getNumberFormat()->setFormatCode('General');
        $sheet->getStyle('K13:K'.$last)->getNumberFormat()->setFormatCode('General');
        foreach (['I','J','L','M'] as $column) { $sheet->getStyle($column.'13:'.$column.$total)->getNumberFormat()->setFormatCode($moneyFormat); }
        $merge('A'.$total.':I'.$total, $viewCosts ? 'TOTAL' : 'VALORI PROTEJATE');
        $fill('A'.$total.':M'.$total, 'F8EBF0');
        $sheet->getStyle('A'.$total.':M'.$total)->getFont()->setBold(true);
        $sheet->getStyle('A'.$total.':I'.$total)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setIndent(1);
        if ($viewCosts) {
            foreach (['J','L','M'] as $column) { $sheet->setCellValue($column.$total, '=ROUND(SUM('.$column.'13:'.$column.$last.'),2)'); }
            $this->cell($sheet, 11, $total, count($rates) === 1 ? array_key_first($rates).'%' : (count($rates) > 1 ? 'Mixte' : ''));
            $fill('M'.$total, 'B05D73');
            $sheet->getStyle('M'.$total)->getFont()->getColor()->setRGB('FFFFFF');
        }
        $sheet->getRowDimension($total)->setRowHeight(30);
        $sheet->getStyle('A'.$total.':M'.$total)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A12:M'.$total)->getBorders()->getAllBorders()->applyFromArray($border);
        $noteTitle = $total + 2;
        $noteRow = $total + 3;
        $stampRow = $total + 5;
        $merge('A'.$noteTitle.':M'.$noteTitle, 'Constatări privind recepția / diferențe calitative sau cantitative:');
        $fill('A'.$noteTitle.':M'.$noteTitle, 'FBF5F4');
        $sheet->getStyle('A'.$noteTitle)->getFont()->setBold(true);
        $sheet->getRowDimension($noteTitle)->setRowHeight(24);
        $notes = implode("\n", $observations);
        $merge('A'.$noteRow.':M'.$noteRow, $notes);
        $sheet->getRowDimension($noteRow)->setRowHeight(max(48, (int)ceil(mb_strlen($notes) / 155) * 13 + count($observations)*13));
        $sheet->getStyle('A'.$noteTitle.':M'.$noteRow)->getBorders()->getAllBorders()->applyFromArray($border);
        $sheet->getStyle('A'.$noteTitle.':M'.$noteRow)->getAlignment()->setIndent(1);
        $merge('A'.$stampRow.':M'.$stampRow);
        $sheet->getRowDimension($stampRow)->setRowHeight(88);
        $sheet->getStyle('A'.$stampRow.':M'.$stampRow)->getBorders()->getOutline()->applyFromArray($border);
        $stamp = new \App\Modules\Companies\CompanyStampStorage($this->root);
        $this->draw($sheet, $stamp->path($company['stamp_path'] ?? null), 'K'.$stampRow, 78, 100);
        $this->page($sheet, 'A1:M'.$stampRow);
        // Fit the width only: long receipts must paginate rather than shrink to unreadable text.
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(12,12)->setHorizontalCentered(true);
        $sheet->getPageMargins()->setTop(0.45)->setBottom(0.45)->setLeft(0.25)->setRight(0.25)->setHeader(0.2)->setFooter(0.2);
        $sheet->getHeaderFooter()->setOddFooter('&RPagina &P / &N');
        $sheet->setSelectedCell('A1');
        return $book;
    }

    /** @param array<string,mixed> $document */
    private static function invoiceLabel(array $document, string $prefix = ''): string
    {
        $number = trim(($document[$prefix.'invoice_series'] ?? '').' '.($document[$prefix.'invoice_number'] ?? ''));
        $date = $document[$prefix.'invoice_date'] ?? '';
        return $number . ($date === '' ? '' : ($number === '' ? '' : ' / ') . self::dateOnly($date));
    }

    private static function dateOnly(mixed $date): string
    {
        return substr(self::displayDate($date), 0, 10);
    }

    /** @param array<string,mixed> $line */
    private static function unitPrice(array $line): ?float
    {
        if (($line['price_mode'] ?? 'unit') === 'unit') { return self::numeric($line['unit_price'] ?? null); }
        $quantity = BigDecimal::of($line['accepted_quantity'] ?? '0');
        return $quantity->isZero() || !isset($line['net_bani']) ? null : (float)(string)BigDecimal::of($line['net_bani'])->dividedBy(100,2)->dividedBy($quantity,6,RoundingMode::HalfUp);
    }

    /** @param list<array<string,mixed>> $documents @param array<string,mixed> $period */
    private function registryWorkbook(array $documents,array $period,bool $costs): Spreadsheet
    {
        $book=new Spreadsheet(); $sheet=$book->getActiveSheet()->setTitle('Registru NIR');
        $book->getProperties()->setCreator('La Măriuca Boutique')->setTitle('Registru detaliat NIR')->setDescription('Recepții și stornări · documente, cantități și valori');
        $book->getDefaultStyle()->getFont()->setName('Aptos')->setSize(11);
        $sheet->setShowGridlines(false)->setSelectedCell('A1');
        $sheet->getSheetView()->setZoomScale(90);
        $sheet->getTabColor()->setRGB('75485F');
        $this->heading($sheet,self::REGISTRY_COLUMNS,6);
        foreach($documents as $index=>$d){
            $row=$index+7;
            $values=[$index+1,self::number($d),self::type($d),self::status($d),self::excelDate($d['nir_at']??''),self::excelDate($d['nir_at']??''),self::excelDate($d['received_at']??''),self::excelDate($d['received_at']??''),$d['supplier']['company_name']??$d['supplier']['alias']??'',$d['supplier']['tax_id']??'',$d['invoice_series']??'',$d['invoice_number']??'',self::excelDate($d['invoice_date']??''),$d['warehouse']['name']??'',$d['currency']??'RON',self::numeric($d['exchange_rate']??1),self::excelDate($d['exchange_rate_date']??''),count($d['lines']??[]),$this->quantity($d,'invoice_quantity'),$this->quantity($d,'received_quantity'),$this->quantity($d,'accepted_quantity'),$this->quantity($d,'rejected_quantity'),$this->quantity($d,'received_quantity')-$this->quantity($d,'invoice_quantity')];
            foreach(['net_bani','tax_bani','total_bani','net_ron_bani','tax_ron_bani','total_ron_bani','inventory_bani'] as $key){$values[]=$costs?self::money($d,$key):null;}
            $values[]=null;
            array_push($values,count($d['attachments']??[]),$d['original_number']??$d['original_id']??'',trim(($d['notes']??'').' '.($d['reason']??'')),self::excelDate($d['confirmed_at']??''),$d['confirmed_by_name']??$d['confirmed_by']??'',self::excelDate($d['created_at']??''),$d['created_by_name']??$d['created_by']??'');
            $this->putRow($sheet,$row,$values);
            $sheet->setCellValue('W'.$row,'=ROUND(T'.$row.'-S'.$row.',4)');
            foreach(['E','G','M','Q'] as $col){$sheet->getStyle($col.$row)->getNumberFormat()->setFormatCode('dd.mm.yyyy');}
            foreach(['F','H'] as $col){$sheet->getStyle($col.$row)->getNumberFormat()->setFormatCode('hh:mm');}
            foreach(['AI','AK'] as $col){$sheet->getStyle($col.$row)->getNumberFormat()->setFormatCode('dd.mm.yyyy hh:mm');}
            $sheet->getStyle('P'.$row)->getNumberFormat()->setFormatCode('General');
            $sheet->getStyle('S'.$row.':W'.$row)->getNumberFormat()->setFormatCode('General');
            $sheet->getStyle('X'.$row.':AE'.$row)->getNumberFormat()->setFormatCode('#,##0.00');
        }
        $last=max(7,count($documents)+6);
        $sheet->freezePane('C7');
        $this->table($sheet,38,$last,6);
        $this->styleRegistry($sheet,$documents,$period,$costs,$last);
        return $book;
    }

    /** @param list<array<string,mixed>> $documents @param array<string,mixed> $period */
    private function styleRegistry(Worksheet $sheet,array $documents,array $period,bool $costs,int $last): void
    {
        $widths=['A'=>8,'B'=>28,'C'=>26,'D'=>23,'E'=>15,'F'=>11,'G'=>17,'H'=>11,'I'=>34,'J'=>20,'K'=>16,'L'=>27,'M'=>17,'N'=>35,'O'=>12,'P'=>16,'Q'=>17,'R'=>11,'S'=>18,'T'=>20,'U'=>18,'V'=>18,'W'=>19,'X'=>22,'Y'=>22,'Z'=>22,'AA'=>20,'AB'=>18,'AC'=>22,'AD'=>23,'AE'=>23,'AF'=>18,'AG'=>28,'AH'=>58,'AI'=>24,'AJ'=>26,'AK'=>24,'AL'=>26];
        foreach($widths as $col=>$width){$sheet->getColumnDimension($col)->setWidth($width);}
        $sheet->getRowDimension(1)->setRowHeight(34);
        $sheet->getRowDimension(2)->setRowHeight(25);
        $sheet->getRowDimension(3)->setRowHeight(25);
        $sheet->getRowDimension(4)->setRowHeight(48);
        $sheet->getRowDimension(5)->setRowHeight(12);
        $sheet->getRowDimension(6)->setRowHeight(48);
        $sheet->mergeCells('A1:B3');
        $this->registryLogo($sheet);
        $company=$documents[0]['company']??[];
        foreach(['C1:H1','C2:H2','C3:H3','I1:N1','I2:N2','I3:N3'] as $range){$sheet->mergeCells($range);}
        $this->cell($sheet,3,1,'Registru detaliat NIR');
        $this->cell($sheet,3,2,'LA MĂRIUCA BOUTIQUE · Recepții și stornări');
        $identity=implode(' · ',array_filter([$company['legal_name']??'',empty($company['tax_id'])?'':'CUI '.$company['tax_id']]));
        $this->cell($sheet,3,3,$identity);
        $this->cell($sheet,9,1,'PERIOADA SELECTATĂ');
        $from=empty($period['from'])?'Toate datele':substr(self::displayDate($period['from']),0,10);
        $to=empty($period['to'])?'prezent':substr(self::displayDate($period['to']),0,10);
        $this->cell($sheet,9,2,$from.' — '.$to);
        $this->cell($sheet,9,3,'Generat la '.self::displayDate(gmdate('Y-m-d H:i:s')));
        $sheet->getStyle('C1:N3')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
        $sheet->getStyle('C1')->getFont()->setSize(24)->setBold(true)->getColor()->setRGB('513447');
        $sheet->getStyle('C2')->getFont()->setSize(11)->setBold(true)->getColor()->setRGB('A34E72');
        $sheet->getStyle('C3')->getFont()->setSize(11)->getColor()->setRGB('766976');
        $sheet->getStyle('I1')->getFont()->setSize(10)->setBold(true)->getColor()->setRGB('A34E72');
        $sheet->getStyle('I2')->getFont()->setSize(15)->getColor()->setRGB('513447');
        $sheet->getStyle('I3')->getFont()->setSize(11)->getColor()->setRGB('766976');
        // Native numeric/formula cells keep the overview useful when Excel filters change.
        $cards=[
            ['A4:B4','=SUBTOTAL(103,B7:B'.$last.')','0"  DOCUMENTE"','F4EDF2','75485F'],
            ['C4:D4','=SUBTOTAL(109,R7:R'.$last.')','0"  POZIȚII"','F5F2FA','76618A'],
            ['E4:H4',$costs?'=ROUND(SUBTOTAL(109,AC7:AC'.$last.'),2)':'Valori restricționate','#,##0.00" RON  ·  TOTAL CU TVA"','75485F','FFFFFF'],
            ['I4:K4','=SUBTOTAL(109,AF7:AF'.$last.')','0"  ATAȘAMENTE"','EAF5F0','347C68'],
        ];
        foreach($cards as [$range,$value,$format,$background,$foreground]){
            $sheet->mergeCells($range);$cell=explode(':',$range)[0];
            $sheet->setCellValue($cell,$value);
            $sheet->getStyle($range)->applyFromArray(['fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$background]],'font'=>['size'=>13,'bold'=>true,'color'=>['rgb'=>$foreground]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],'borders'=>['outline'=>['borderStyle'=>Border::BORDER_MEDIUM,'color'=>['rgb'=>'FFFFFF']]]]);
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($format);
        }
        $sheet->mergeCells('L4:N4');
        $filter=match($period['status']??''){'draft'=>'Drafturi','confirmed'=>'Confirmate','reversed'=>'Stornate',default=>'Toate stările'};
        $this->cell($sheet,12,4,'FILTRU · '.$filter.(empty($period['search'])?'':' · '.$period['search']));
        $sheet->getStyle('L4:N4')->applyFromArray(['font'=>['size'=>11,'color'=>['rgb'=>'766976']],'alignment'=>['vertical'=>Alignment::VERTICAL_CENTER,'wrapText'=>true,'indent'=>1]]);
        foreach(['A6:H6'=>'75485F','I6:N6'=>'8C6277','O6:R6'=>'81718D','S6:W6'=>'507C75','X6:AE6'=>'75485F','AF6:AL6'=>'737686'] as $range=>$color){
            $sheet->getStyle($range)->getFill()->getStartColor()->setRGB($color);
        }
        $sheet->getStyle('A6:AL6')->getFont()->setSize(11);
        $sheet->getStyle('A6:AL6')->getAlignment()->setIndent(1);
        $sheet->getStyle('A7:AL'.$last)->getFont()->setSize(11)->getColor()->setRGB('4E4852');
        $sheet->getStyle('A7:AL'.$last)->getAlignment()->setIndent(1);
        foreach(['A','D','E','F','G','H','M','O','Q','R','AF','AI','AK'] as $col){$sheet->getStyle($col.'7:'.$col.$last)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setIndent(0);}
        $sheet->getStyle('S7:AE'.$last)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('B7:B'.$last)->getFont()->setBold(true)->getColor()->setRGB('75485F');
        for($row=7;$row<=$last;++$row){
            $sheet->getRowDimension($row)->setRowHeight(54);
            $style=$sheet->getStyle('A'.$row.':AL'.$row);
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($row%2===0?'FAF7FA':'FFFFFF');
            $style->getBorders()->getBottom()->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB('E8DFE6');
            $document=$documents[$row-7]??null;
            if($document===null){continue;}
            $status=$document['status']??'draft';
            [$fill,$text]=match($status){'confirmed'=>['EAF5F0','347C68'],'reversed'=>['F3EEF8','775D93'],default=>!empty($document['draft_incomplete'])?['FDEDED','B84A58']:['FFF5E5','91682F']};
            $sheet->getStyle('D'.$row)->getFill()->getStartColor()->setRGB($fill);
            $sheet->getStyle('D'.$row)->getFont()->setBold(true)->getColor()->setRGB($text);
            $sheet->getStyle('AC'.$row)->getFill()->getStartColor()->setRGB('F4EDF2');
            $sheet->getStyle('AC'.$row)->getFont()->setBold(true)->getColor()->setRGB('75485F');
        }
        // This field has no persisted counterpart; a blank is not a measured zero.
        $sheet->getComment('AE6')->getText()->createTextRun('Diferența valorică nu este stocată separat în La Măriuca. Celulele rămân necompletate.');
        $sheet->getComment('E4')->getText()->createTextRun('Totalul în RON al documentelor vizibile, inclusiv drafturi dacă sunt selectate. Nu adună monede diferite; folosește valorile convertite ale fiecărui NIR.');
        if($documents===[]){$sheet->getComment('A6')->getText()->createTextRun('Nu există NIR-uri pentru filtrele selectate.');}
        // Avoid compressing 38 columns onto one illegible printed page.
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A3)->setFitToWidth(3)->setColumnsToRepeatAtLeftByStartAndEnd('A','B');
    }

    private function registryLogo(Worksheet $sheet): void
    {
        $path=$this->publicImage('/assets/images/logo-la-mariuca-transparent.png');
        if($path===null){return;}
        $bytes=$this->thumbnail($path);if($bytes===null){return;}
        $logo=@imagecreatefromstring($bytes);if($logo===false){return;}
        // A softly rounded identity plaque; all report text remains native Excel text.
        $image=imagecreatetruecolor(500,196);
        $white=imagecolorallocate($image,255,255,255);$border=imagecolorallocate($image,235,220,227);
        imagefill($image,0,0,$white);
        imagefilledrectangle($image,30,3,470,192,$border);imagefilledrectangle($image,3,30,497,165,$border);
        foreach([[30,30],[470,30],[30,165],[470,165]] as [$x,$y]){imagefilledellipse($image,$x,$y,54,54,$border);}
        imagefilledrectangle($image,30,5,470,190,$white);imagefilledrectangle($image,5,30,495,165,$white);
        foreach([[30,30],[470,30],[30,165],[470,165]] as [$x,$y]){imagefilledellipse($image,$x,$y,50,50,$white);}
        $scale=min(430/imagesx($logo),150/imagesy($logo));$width=(int)round(imagesx($logo)*$scale);$height=(int)round(imagesy($logo)*$scale);
        imagecopyresampled($image,$logo,(int)((500-$width)/2),(int)((196-$height)/2),0,0,$width,$height,imagesx($logo),imagesy($logo));imagedestroy($logo);
        $drawing=new MemoryDrawing();$drawing->setName('La Măriuca Boutique — logo original');$drawing->setImageResource($image);$drawing->setRenderingFunction(MemoryDrawing::RENDERING_PNG);$drawing->setMimeType(MemoryDrawing::MIMETYPE_PNG);$drawing->setCoordinates('A1')->setWidth(240)->setOffsetX(7)->setOffsetY(8)->setWorksheet($sheet);
    }

    /** @param list<array<string,mixed>> $documents @param array<string,mixed>|null $period */
    private function package(array $documents,string $path,bool $costs,?array $period=null): void
    {
        $zip=new \ZipArchive();$temporary=[];
        if($zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true){throw new \RuntimeException('Arhiva nu poate fi creată.');}
        try{
            if($period!==null){$registry=$this->temporary('xlsx');$temporary[]=$registry;$this->saveWorkbook($this->registryWorkbook($documents,$period,$costs),$registry);$zip->addFile($registry,'Registru_NIR.xlsx');$zip->setCompressionName('Registru_NIR.xlsx',\ZipArchive::CM_STORE);}
            $storage=new NirDocumentStorage($this->root);
            foreach($documents as $document){
                $prefix=$period===null?'':NirDocumentStorage::safeName(self::number($document)).'/';
                foreach(['xlsx','pdf'] as $format){$file=$this->individual($document,$format,$costs);$temporary[]=$file['path'];$entry=$prefix.$file['filename'];$zip->addFile($file['path'],$entry);$zip->setCompressionName($entry,\ZipArchive::CM_STORE);}
                $zip->addEmptyDir($prefix.'documente');
                if(!$costs){$zip->addFromString($prefix.'documente/ACCES_RESTRICTIONAT.txt','Documentele originale pot conține prețuri și nu sunt incluse fără dreptul NIR_VIEW_COSTS.');continue;}
                foreach($document['attachments']??[] as $i=>$file){$original=$storage->path($file['stored_name']??null);if($original===null){throw new \RuntimeException('Lipsește un document original din arhivă: '.($file['original_name']??'document').'.');}if(!hash_equals((string)($file['sha256']??''),(string)hash_file('sha256',$original))){throw new \RuntimeException('Integritatea documentului original nu poate fi confirmată: '.($file['original_name']??'document').'.');}$entry=$prefix.'documente/'.sprintf('%03d_',$i+1).NirDocumentStorage::safeName($file['original_name']??'document');$zip->addFile($original,$entry);$zip->setCompressionName($entry,\ZipArchive::CM_STORE);}
            }
            if(!$zip->close()){throw new \RuntimeException('Arhiva nu a putut fi finalizată.');}
        }catch(\Throwable $exception){@$zip->close();throw $exception;}finally{foreach($temporary as $file){$this->cleanup($file);}}
    }

    private function temporary(string $extension): string
    {
        $directory=$this->root.'/storage/private/nir-exports';
        if(!is_dir($directory)&&!mkdir($directory,0770,true)&&!is_dir($directory)){throw new \RuntimeException('Exportul nu poate fi pregătit.');}
        $path=$directory.'/'.bin2hex(random_bytes(20)).'.'.$extension;
        if(file_put_contents($path,'')===false){throw new \RuntimeException('Exportul nu poate fi creat.');}
        @chmod($path,0660);return $path;
    }

    private function saveWorkbook(Spreadsheet $book,string $path): void
    {
        try{$writer=new Xlsx($book);$writer->setPreCalculateFormulas(true);$writer->save($path);}finally{$book->disconnectWorksheets();}
    }

    /** @param list<string> $labels */
    private function heading(Worksheet $sheet,array $labels,int $row): void
    {
        $this->putRow($sheet,$row,$labels);$last=Coordinate::stringFromColumnIndex(count($labels));
        $style=$sheet->getStyle('A'.$row.':'.$last.$row);$style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF80505C');$style->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FFFFFFFF');$style->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);$sheet->getRowDimension($row)->setRowHeight(42);
    }

    private function table(Worksheet $sheet,int $columns,int $rows,int $header,bool $filter=true): void
    {
        $last=Coordinate::stringFromColumnIndex($columns);
        for($column=1;$column<=$columns;++$column){$sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth(21);}
        $sheet->getStyle('A'.($header+1).':'.$last.$rows)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A'.($header+1).':'.$last.$rows)->getFont()->setSize(10)->getColor()->setARGB('FF49383E');
        for($row=$header+1;$row<=$rows;++$row){if($row%2===0){$sheet->getStyle('A'.$row.':'.$last.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFCF5F4');}$sheet->getRowDimension($row)->setRowHeight(max(32,$sheet->getRowDimension($row)->getRowHeight()));}
        if($filter){$sheet->setAutoFilter('A'.$header.':'.$last.$rows);}
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($header,$header);$this->page($sheet,'A1:'.$last.$rows);
    }

    private function page(Worksheet $sheet,string $area): void
    {
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0)->setPrintArea($area);
        $sheet->getPageMargins()->setTop(0.2)->setBottom(0.3)->setLeft(0.2)->setRight(0.2);
        $sheet->getHeaderFooter()->setOddFooter('&LLa Măriuca Boutique&RPagina &P / &N');
    }

    /** @param list<mixed> $values */
    private function putRow(Worksheet $sheet,int $row,array $values): void
    {
        foreach($values as $index=>$value){$this->cell($sheet,$index+1,$row,$value);}
    }

    private function cell(Worksheet $sheet,int $column,int $row,mixed $value): void
    {
        if($value===null||$value===''){return;}
        // Text such as supplier names beginning with =, +, -, @ is never an executable formula.
        $sheet->setCellValueExplicit([$column,$row],is_int($value)||is_float($value)?$value:mb_substr((string)$value,0,32767),is_int($value)||is_float($value)?DataType::TYPE_NUMERIC:DataType::TYPE_STRING);
    }

    private function draw(Worksheet $sheet,?string $path,string $coordinate,int $height,?int $maxWidth=null): void
    {
        if($path===null){return;}
        $bytes=$this->thumbnail($path);if($bytes===null){return;}$source=@imagecreatefromstring($bytes);if($source===false){return;}
        // The workbook uses white paper. Flatten transparency for spreadsheet viewers
        // that otherwise render the transparent brand image against a black canvas.
        $image=imagecreatetruecolor(imagesx($source),imagesy($source));
        imagefill($image,0,0,imagecolorallocate($image,255,255,255));
        imagecopy($image,$source,0,0,0,0,imagesx($source),imagesy($source));imagedestroy($source);
        $drawing=new MemoryDrawing();$drawing->setName('Imagine produs / identitate');$drawing->setImageResource($image);$drawing->setRenderingFunction(MemoryDrawing::RENDERING_PNG);$drawing->setMimeType(MemoryDrawing::MIMETYPE_PNG);$drawing->setCoordinates($coordinate)->setHeight($height)->setOffsetX(5)->setOffsetY(5)->setWorksheet($sheet);
        if($maxWidth!==null && $drawing->getWidth()>$maxWidth){$drawing->setWidth($maxWidth);}
    }

    private function publicImage(?string $image): ?string
    {
        if($image===null||$image===''||str_contains($image,'://')||str_contains($image,"\0")){return null;}
        $base=realpath($this->root.'/public');$path=realpath($this->root.'/public/'.ltrim(str_replace('\\','/',$image),'/'));
        if($base===false||$path===false||!str_starts_with(str_replace('\\','/',$path),str_replace('\\','/',$base).'/')||!is_file($path)||filesize($path)>15*1024*1024){return null;}
        $info=@getimagesize($path);
        return $info!==false&&in_array($info['mime'],['image/jpeg','image/png','image/webp'],true)&&$info[0]*$info[1]<=40_000_000?$path:null;
    }

    private function imageData(?string $image): ?string
    {
        $path=$this->publicImage($image);if($path===null){return null;}
        $bytes=$this->thumbnail($path);return $bytes===null?null:'data:image/png;base64,'.base64_encode($bytes);
    }

    private function thumbnail(string $path): ?string
    {
        if(isset($this->thumbnailCache[$path])){return $this->thumbnailCache[$path];}
        $source=@imagecreatefromstring((string)file_get_contents($path));if($source===false){return null;}
        $scale=min(1,360/max(imagesx($source),imagesy($source)));$image=imagecreatetruecolor(max(1,(int)round(imagesx($source)*$scale)),max(1,(int)round(imagesy($source)*$scale)));
        imagealphablending($image,false);imagesavealpha($image,true);imagefill($image,0,0,imagecolorallocatealpha($image,255,255,255,127));
        imagecopyresampled($image,$source,0,0,0,0,imagesx($image),imagesy($image),imagesx($source),imagesy($source));
        ob_start();imagepng($image,null,6);$bytes=ob_get_clean();imagedestroy($image);imagedestroy($source);
        if(!is_string($bytes)){return null;}$this->thumbnailCache[$path]=$bytes;return $bytes;
    }

    /** @param array<string,mixed> $document */
    private function quantity(array $document,string $field): float
    {
        $total=BigDecimal::zero();foreach($document['lines']??[] as $line){$total=$total->plus($line[$field]??'0');}return (float)(string)$total;
    }

    private static function numeric(mixed $value): ?float { return $value===null||$value===''?null:(is_numeric($value)?(float)$value:null); }
    /** @param array<string,mixed> $data */
    private static function money(array $data,string $key): ?float { return array_key_exists($key,$data)?((int)$data[$key])/100:null; }
    /** @param array<string,mixed> $document */
    private static function number(array $document): string { return (string)(($document['number']??'')?:($document['display_number']??(!empty($document['draft_sequence'])?'Draft #'.(int)$document['draft_sequence']:($document['temp_number']??'NIR')))); }
    /** @param array<string,mixed> $document */
    private static function status(array $document): string { return match($document['status']??'draft'){'confirmed'=>'Confirmat','reversed'=>'Stornat',default=>!empty($document['draft_incomplete'])?'Draft - incomplet':'Draft'}; }
    /** @param array<string,mixed> $document */
    private static function type(array $document): string { return match($document['type']??'supplier_receipt'){'supplier_return'=>'Retur furnizor / storno','customer_return'=>'Recepție retur client','opening_balance'=>'Sold inițial',default=>'Recepție furnizor'}; }
    private static function differenceLabel(string $reason): string { return match($reason){'shortage','missing'=>'Lipsă','excess'=>'Plus','damaged'=>'Deteriorat','wrong_product'=>'Produs greșit','price_difference'=>'Diferență de preț','vat_difference'=>'Diferență TVA','other'=>'Alt motiv',default=>$reason}; }
    private static function mime(string $format): string { return match($format){'pdf'=>'application/pdf','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',default=>'application/zip'}; }
    private static function excelDate(mixed $value): ?float { if(!is_string($value)||$value===''){return null;}try{return Date::PHPToExcel((new \DateTimeImmutable($value,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Bucharest')));}catch(\Exception){return null;} }
    private static function displayDate(mixed $value): string { if(!is_string($value)||$value===''){return '—';}try{return(new \DateTimeImmutable($value,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y, H:i');}catch(\Exception){return '—';} }
}
