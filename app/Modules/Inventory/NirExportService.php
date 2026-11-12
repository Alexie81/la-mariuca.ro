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
    private const LINE_COLUMNS = [
        'Miniatură', 'ID NIR intern', 'Serie NIR', 'Număr NIR', 'Data NIR', 'Data recepției fizice', 'Ora recepției', 'Data creării', 'Data finalizării', 'Status NIR', 'Gestiune', 'Punct de lucru', 'Locație recepție', 'Gestionar', 'Recepționat de', 'Verificat de', 'Aprobat de', 'Observații generale NIR', 'Tip document sursă', 'Serie factură', 'Număr factură', 'Data facturii', 'Serie aviz', 'Număr aviz', 'Data avizului', 'Număr comandă achiziție', 'AWB', 'Transportator', 'Monedă', 'Curs valutar', 'Data cursului', 'ID/index extern RO e-Factura',
        'ID furnizor intern', 'Denumire furnizor', 'CUI/CIF', 'Cod TVA', 'Nr. Registrul Comerțului', 'Țară furnizor', 'Județ furnizor', 'Localitate furnizor', 'Adresă furnizor', 'Telefon furnizor', 'Email furnizor',
        'Nr. crt.', 'ID poziție NIR', 'Denumire produs furnizor', 'Cod produs furnizor', 'SKU furnizor distinct', 'EAN furnizor', 'UM furnizor', 'Marcă furnizor', 'Model furnizor', 'Variantă furnizor', 'Lot furnizor', 'Serie furnizor',
        'ID produs intern', 'Denumire internă', 'Cod/SKU intern', 'EAN intern', 'Categorie', 'Subcategorie', 'Marcă internă', 'Model intern', 'Variantă internă', 'UM intern', 'Status asociere', 'Observații asociere',
        'Cantitate facturată', 'Cantitate recepționată fizic', 'Cantitate acceptată în gestiune', 'Cantitate carantină/blocată', 'Lipsă', 'Plus', 'Diferență recepționat − facturat', 'Diferență acceptat − facturat', 'Factor conversie',
        'Preț unitar brut inițial fără TVA', 'Discount unitar', 'Discount %', 'Discount total linie', 'Preț net unitar după discount', 'Cotă TVA', 'TVA unitar', 'Preț unitar cu TVA', 'Valoare netă linie', 'Valoare TVA linie', 'Valoare totală linie',
        'Transport alocat', 'Taxe vamale linie', 'Alte taxe nerecuperabile', 'Costuri directe', 'Cost suplimentar total alocat', 'Cost unitar de inventar fără TVA', 'Valoare totală intrare în inventar', 'TVA deductibilă', 'TVA nedeductibilă', 'Moneda costului', 'Observații cost',
        'Starea produsului', 'Conform da/nu', 'Tip diferență', 'Motiv carantină', 'Descriere neconformitate', 'Măsură luată', 'Retur furnizor da/nu', 'Notă de credit solicitată', 'Document/PV diferență', 'Observații recepție',
        'Serie individuală', 'Lot/batch', 'Data fabricației', 'Data expirării', 'Durată garanție furnizor', 'Început garanție', 'Sfârșit garanție', 'Locație raft', 'QR/cod de bare intern',
        'Total net document', 'Total TVA document', 'Total general document', 'Discount document', 'Transport total', 'Taxe vamale document', 'Alte costuri', 'Valoare totală inventar', 'Total cantitate facturată', 'Total cantitate recepționată', 'Total cantitate acceptată',
        'Creat de', 'Creat la', 'Ultima modificare de', 'Ultima modificare la', 'Validat de', 'Validat la', 'Stornat de', 'Stornat la', 'Motiv storno', 'Versiune document', 'ID/nume atașament factură', 'ID PDF NIR', 'Observații audit',
    ];
    private const REGISTRY_COLUMNS = ['Nr. crt.', 'Număr NIR', 'Tip document', 'Status', 'Data NIR', 'Ora NIR', 'Data recepției', 'Ora recepției', 'Furnizor', 'CUI furnizor', 'Serie factură', 'Număr factură', 'Data facturii', 'Gestiune', 'Monedă', 'Curs valutar', 'Data cursului', 'Poziții', 'Cantitate facturată', 'Cantitate recepționată', 'Cantitate acceptată', 'Cantitate respinsă', 'Diferență cantitativă', 'Subtotal în moneda documentului', 'TVA în moneda documentului', 'Total în moneda documentului', 'Subtotal RON', 'TVA RON', 'Total RON', 'Cost contabil RON', 'Diferență valoare RON', 'Documente atașate', 'NIR inițial', 'Observații/motiv', 'Confirmat la', 'Confirmat de', 'Creat la', 'Creat de'];
    private const DIFFERENCE_COLUMNS = ['Poziția NIR', 'Produs furnizor', 'Produs intern', 'SKU', 'Tip diferență', 'Cantitate document', 'Cantitate primită', 'Cantitate acceptată', 'Diferență primit − document', 'Diferență acceptat − document', 'Observații', 'Cauză probabilă', 'Măsură luată', 'Document asociat', 'Dovezi foto', 'Constatare de', 'Data constatării', 'Furnizor notificat', 'Data notificării'];
    private const STORED_LINE_COLUMNS = [2,4,5,6,7,8,9,10,11,13,15,17,18,19,20,21,22,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,49,50,56,57,58,59,60,62,64,65,66,68,69,70,71,76,77,79,80,82,85,86,87,92,93,94,97,101,103,105,108,118,119,120,121,125,126,127,128,129,130,131,132,133,134,137,138,139];

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
        $book->getProperties()->setCreator('La Măriuca Boutique')->setTitle('NIR ' . self::number($document))->setSubject('Recepție și trasabilitate stoc');
        $summary = $book->getActiveSheet()->setTitle('Rezumat NIR');
        $this->summary($summary, $document, $viewCosts);
        $sheet = $book->createSheet()->setTitle('Poziții NIR');
        $this->heading($sheet, self::LINE_COLUMNS, 1);
        $sheet->freezePane('C2');
        $lines = $document['lines'] ?? [];
        foreach ($lines as $index => $line) {
            $row = $index + 2;
            $values = $this->lineValues($document, $line, $index, $viewCosts);
            $this->putRow($sheet, $row, $values);
            $sheet->setCellValue('BT' . $row, '=MAX(0,BP' . $row . '-BQ' . $row . ')');
            $sheet->setCellValue('BU' . $row, '=MAX(0,BQ' . $row . '-BP' . $row . ')');
            $sheet->setCellValue('BV' . $row, '=BQ' . $row . '-BP' . $row);
            $sheet->setCellValue('BW' . $row, '=BR' . $row . '-BP' . $row);
            $sheet->getStyle('BP' . $row . ':BX' . $row)->getNumberFormat()->setFormatCode('0.0000');
            $sheet->getStyle('BY' . $row . ':CT' . $row)->getNumberFormat()->setFormatCode('#,##0.000000');
            $sheet->getStyle('CG' . $row . ':CI' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            foreach ([5,6,8,9,22,31,130,132,134] as $dateColumn) {
                $sheet->getStyle(Coordinate::stringFromColumnIndex($dateColumn) . $row)->getNumberFormat()->setFormatCode(in_array($dateColumn, [8,9,130,132,134], true) ? 'dd.mm.yyyy hh:mm' : 'dd.mm.yyyy');
            }
            $sheet->getStyle('G' . $row)->getNumberFormat()->setFormatCode('hh:mm');
            $this->draw($sheet, $this->publicImage($line['image_path'] ?? null), 'A' . $row, 52);
            $sheet->getRowDimension($row)->setRowHeight(48);
        }
        $this->table($sheet, count(self::LINE_COLUMNS), max(2, count($lines) + 1), 1);
        $sheet->getColumnDimension('A')->setWidth(12);
        foreach (['AT','BE'] as $column) {
            $sheet->getColumnDimension($column)->setWidth(38);
        }
        $this->differences($book->createSheet()->setTitle('Diferențe'), $document);
        $this->audit($book->createSheet()->setTitle('Documente & Audit'), $document, $viewCosts);
        $this->dictionary($book->createSheet()->setTitle('Dicționar câmpuri'), $viewCosts);
        $book->setActiveSheetIndex(0);
        return $book;
    }

    /** @param array<string,mixed> $d @param array<string,mixed> $l @return list<mixed> */
    private function lineValues(array $d, array $l, int $index, bool $costs): array
    {
        $v = array_fill(0, count(self::LINE_COLUMNS), null);
        $supplier = $d['supplier'] ?? [];
        $set = static function (int $column, mixed $value) use (&$v): void { $v[$column - 1] = $value; };
        $fields = [
            2=>$d['id'] ?? null, 3=>null, 4=>self::number($d), 5=>self::excelDate($d['nir_at'] ?? ''), 6=>self::excelDate($d['received_at'] ?? ''), 7=>self::excelDate($d['received_at'] ?? ''), 8=>self::excelDate($d['created_at'] ?? ''), 9=>self::excelDate($d['confirmed_at'] ?? ''), 10=>self::status($d), 11=>$d['warehouse']['name'] ?? '', 13=>$d['location']['name'] ?? '', 15=>$d['received_by_name'] ?? $d['created_by'] ?? null, 17=>$d['confirmed_by_name'] ?? $d['confirmed_by'] ?? null, 18=>$d['notes'] ?? '', 19=>($d['type'] ?? '') === 'opening_balance' ? 'Sold inițial' : 'Factură', 20=>$d['invoice_series'] ?? '', 21=>$d['invoice_number'] ?? '', 22=>self::excelDate($d['invoice_date'] ?? ''), 29=>$d['currency'] ?? 'RON', 30=>self::numeric($d['exchange_rate'] ?? null), 31=>self::excelDate($d['exchange_rate_date'] ?? ''), 32=>$d['external_id'] ?? '',
            33=>$d['supplier_id'] ?? null,34=>$supplier['company_name'] ?? $supplier['alias'] ?? '',35=>$supplier['tax_id'] ?? '',36=>$supplier['vat_number'] ?? '',37=>$supplier['trade_register'] ?? '',38=>$supplier['country'] ?? '',39=>$supplier['county'] ?? '',40=>$supplier['city'] ?? '',41=>$supplier['address'] ?? '',42=>$supplier['phone'] ?? '',43=>$supplier['email'] ?? '',
            44=>$index+1,45=>$l['id'] ?? null,46=>$l['supplier_name'] ?? '',47=>$l['supplier_code'] ?? '',49=>$l['supplier_ean'] ?? '',50=>$l['purchase_unit'] ?? '',56=>$l['product_id'] ?? null,57=>$l['product_name'] ?? '',58=>$l['sku'] ?? '',59=>$l['ean'] ?? '',60=>$l['category_name'] ?? '',62=>$l['brand'] ?? '',64=>$l['variant_name'] ?? '',65=>$l['stock_unit'] ?? '',66=>empty($l['product_id']) ? 'Neasociat' : 'Asociat',68=>self::numeric($l['invoice_quantity'] ?? null),69=>self::numeric($l['received_quantity'] ?? null),70=>self::numeric($l['accepted_quantity'] ?? null),71=>self::numeric($l['rejected_quantity'] ?? null),76=>self::numeric($l['conversion_factor'] ?? null),
            101=>$l['difference_reason'] ?? '',103=>$l['difference_notes'] ?? '',105=>($d['type'] ?? '') === 'supplier_return' ? 'Da' : 'Nu',108=>$l['difference_notes'] ?? '',126=>$this->quantity($d,'invoice_quantity'),127=>$this->quantity($d,'received_quantity'),128=>$this->quantity($d,'accepted_quantity'),129=>$d['created_by_name'] ?? $d['created_by'] ?? null,130=>self::excelDate($d['created_at'] ?? ''),131=>$d['updated_by_name'] ?? $d['updated_by'] ?? null,132=>self::excelDate($d['updated_at'] ?? ''),133=>$d['confirmed_by_name'] ?? $d['confirmed_by'] ?? null,134=>self::excelDate($d['confirmed_at'] ?? ''),137=>$d['reason'] ?? '',138=>$d['row_version'] ?? null,139=>implode('; ', array_map(static fn(array $a): string => '#' . ($a['id'] ?? '') . ' ' . ($a['original_name'] ?? ''), $d['attachments'] ?? [])),
        ];
        foreach ($fields as $column => $value) { $set($column, $value); }
        if ($costs) {
            $quant = BigDecimal::of($l['accepted_quantity'] ?? '0');
            $unitValue=static function(string $key) use($l,$quant):?float{return $quant->isZero()||!isset($l[$key])?null:(float)(string)BigDecimal::of($l[$key])->dividedBy(100,2)->dividedBy($quant,6,RoundingMode::HalfUp);};
            foreach ([77=>self::numeric($l['unit_price'] ?? null),78=>$unitValue('discount_bani'),79=>self::numeric($l['discount_percent'] ?? null),80=>self::money($l,'discount_bani'),81=>$unitValue('net_bani'),82=>self::numeric($l['vat_percent'] ?? null),83=>$unitValue('tax_bani'),84=>$unitValue('total_bani'),85=>self::money($l,'net_bani'),86=>self::money($l,'tax_bani'),87=>self::money($l,'total_bani'),92=>self::numeric($l['extra_cost_ron'] ?? null),93=>self::numeric($l['unit_cost_ron'] ?? null),94=>self::money($l,'inventory_bani'),97=>'RON',118=>self::money($d,'net_bani'),119=>self::money($d,'tax_bani'),120=>self::money($d,'total_bani'),121=>self::money($d,'discount_bani'),125=>self::money($d,'inventory_bani')] as $column=>$value) { $set($column,$value); }
        }
        if (($l['price_mode'] ?? 'unit') !== 'unit') {
            // Net/exact/gross entry stores the authoritative line value, not the
            // supplier's original pre-discount price or discount breakdown.
            foreach ([77,78,79,80] as $column) { $set($column, null); }
        }
        return $v;
    }

    /** @param array<string,mixed> $d */
    private function summary(Worksheet $sheet, array $d, bool $costs): void
    {
        $sheet->mergeCells('A1:F2');
        $sheet->setCellValue('A1', 'NOTĂ DE RECEPȚIE · ' . self::number($d));
        $sheet->getStyle('A1:F2')->getFont()->setSize(19)->setBold(true)->getColor()->setARGB('FF643C46');
        $sheet->getRowDimension(1)->setRowHeight(28);
        $this->draw($sheet, $this->publicImage('/assets/images/logo-la-mariuca-transparent.png'), 'G1', 58);
        $pairs = [
            ['Stare', self::status($d), 'Tip operațiune', self::type($d)],
            ['Firmă', $d['company']['legal_name'] ?? '', 'CUI firmă', $d['company']['tax_id'] ?? ''],
            ['Furnizor', $d['supplier']['company_name'] ?? $d['supplier']['alias'] ?? '', 'CUI furnizor', $d['supplier']['tax_id'] ?? ''],
            ['Factură', trim(($d['invoice_series'] ?? '') . ' ' . ($d['invoice_number'] ?? '')), 'Data facturii', $d['invoice_date'] ?? ''],
            ['Data NIR', self::displayDate($d['nir_at'] ?? ''), 'Recepție fizică', self::displayDate($d['received_at'] ?? '')],
            ['Gestiune', $d['warehouse']['name'] ?? '', 'Locație', $d['location']['name'] ?? ''],
            ['Adresă recepție', $d['location']['address'] ?? '', 'Monedă', $d['currency'] ?? 'RON'],
            ['Curs RON', self::numeric($d['exchange_rate'] ?? 1), 'Data cursului', $d['exchange_rate_date'] ?? ''],
            ['Poziții', count($d['lines'] ?? []), 'Atașamente', count($d['attachments'] ?? [])],
            ['Poziții cu diferențe', count(array_filter($d['lines'] ?? [], fn(array $l): bool => $this->hasDifference($l))), 'Neasociate', count(array_filter($d['lines'] ?? [], static fn(array $l): bool => empty($l['product_id'])))],
        ];
        $row = 4;
        foreach ($pairs as $pair) {
            foreach ([1=>$pair[0],2=>$pair[1],5=>$pair[2],6=>$pair[3]] as $col=>$value) { $this->cell($sheet,$col,$row,$value); }
            $sheet->mergeCells('B'.$row.':D'.$row)->mergeCells('F'.$row.':H'.$row);
            $sheet->getRowDimension($row)->setRowHeight(29);
            ++$row;
        }
        if ($costs) {
            ++$row;
            foreach (['net_bani'=>'Subtotal', 'tax_bani'=>'TVA', 'total_bani'=>'Total document', 'inventory_bani'=>'Valoare de intrare în stoc (RON)'] as $key=>$label) {
                $this->cell($sheet,1,$row,$label); $sheet->mergeCells('A'.$row.':D'.$row);
                $this->cell($sheet,6,$row,self::money($d,$key)); $sheet->mergeCells('F'.$row.':H'.$row);
                $sheet->getStyle('F'.$row.':H'.$row)->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle('A'.$row.':H'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF9EEEE');
                $sheet->getRowDimension($row++)->setRowHeight(30);
            }
            $vat = [];
            foreach ($d['lines'] ?? [] as $line) {
                $rate=(string)($line['vat_percent']??'0');
                $vat[$rate]=($vat[$rate]??0)+(int)($line['tax_bani']??0);
            }
            foreach ($vat as $rate=>$bani) {
                $this->cell($sheet,1,++$row,'TVA ' . $rate . '%'); $this->cell($sheet,6,$row,$bani/100);
                $sheet->getStyle('F'.$row)->getNumberFormat()->setFormatCode('#,##0.00');
            }
        }
        foreach (['NIR original'=> $d['original_number'] ?? $d['original_id'] ?? '', 'Motiv'=> $d['reason'] ?? '', 'Observații'=> $d['notes'] ?? '', 'Creat de'=> $d['created_by_name'] ?? $d['created_by'] ?? '', 'Generat la'=>self::displayDate(gmdate('Y-m-d H:i:s')), 'Amprentă document'=> $d['fingerprint'] ?? '', 'Aplicație'=>'La Măriuca Boutique · Administrare stoc'] as $label=>$value) {
            $row+=2; $this->cell($sheet,1,$row,$label); $sheet->mergeCells('B'.$row.':H'.$row); $this->cell($sheet,2,$row,$value); $sheet->getRowDimension($row)->setRowHeight(34);
        }
        $sheet->getStyle('A1:H'.$row)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        foreach (range('A','H') as $col) { $sheet->getColumnDimension($col)->setWidth(17); }
        $this->page($sheet,'A1:H'.$row);
    }

    /** @param array<string,mixed> $document */
    private function differences(Worksheet $sheet, array $document): void
    {
        $this->heading($sheet,self::DIFFERENCE_COLUMNS,1);
        $row=2;
        foreach ($document['lines']??[] as $index=>$line) {
            if (!$this->hasDifference($line)) { continue; }
            $source=$index+2;
            $this->putRow($sheet,$row,[$index+1,$line['supplier_name']??'',$line['product_name']??'',$line['sku']??'',self::differenceLabel($line['difference_reason']??''),null,null,null,null,null,$line['difference_notes']??'']);
            foreach (['F'=>'BP','G'=>'BQ','H'=>'BR','I'=>'BV','J'=>'BW'] as $target=>$origin) {
                $sheet->setCellValue($target.$row,"='Poziții NIR'!".$origin.$source);
            }
            $sheet->getStyle('F'.$row.':J'.$row)->getNumberFormat()->setFormatCode('0.0000');
            ++$row;
        }
        $sheet->freezePane('C2');
        $this->table($sheet,count(self::DIFFERENCE_COLUMNS),max(2,$row-1),1);
    }

    /** @param array<string,mixed> $document */
    private function audit(Worksheet $sheet, array $document, bool $costs): void
    {
        $this->heading($sheet,['Relație','Număr document','Fișier','Tip MIME','Extensie','Dimensiune bytes','SHA-256','Status extragere','Creat de','Creat la'],1);
        $row=2;
        foreach ($document['attachments']??[] as $file) {
            $this->putRow($sheet,$row++,['Atașament',self::number($document),$file['original_name']??'',$file['mime_type']??'',$file['extension']??'',$file['size_bytes']??0,$file['sha256']??'',$file['extraction_status']??'',$file['created_by']??'',self::displayDate($file['created_at']??'')]);
        }
        $row+=2;
        $this->heading($sheet,['ID eveniment','Acțiune','ID entitate','ID actor','Actor','Moment','Valori anterioare','Valori noi','Context','Observație'],$row++);
        foreach ($document['audit']??[] as $event) {
            $json=static function(mixed $value): string { return is_string($value)?$value:(json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:''); };
            $this->putRow($sheet,$row++,[$event['id']??'',$event['action']??'',$event['nir_id']??$document['id']??'',$event['actor_id']??'',$event['actor_name']??'',self::displayDate($event['created_at']??''),$costs?$json($event['before']??$event['before_json']??''):'Date protejate',$costs?$json($event['after']??$event['after_json']??''):'Date protejate',$costs?$json($event['context']??$event['context_json']??''):'Date protejate','']);
        }
        foreach (['NIR original'=>$document['original_number']??$document['original_id']??'','Motiv'=>$document['reason']??'','Aplicație'=>'La Măriuca Boutique','Generat la'=>self::displayDate(gmdate('Y-m-d H:i:s')),'Amprentă document'=>$document['fingerprint']??''] as $label=>$value) {
            $this->putRow($sheet,++$row,[$label,$value]);
        }
        $this->table($sheet,10,max(2,$row),1,false);
        $sheet->freezePane('C2');
        $sheet->getColumnDimension('C')->setWidth(36); $sheet->getColumnDimension('G')->setWidth(55); $sheet->getColumnDimension('H')->setWidth(55); $sheet->getColumnDimension('I')->setWidth(40);
    }

    private function dictionary(Worksheet $sheet, bool $costs): void
    {
        $this->heading($sheet,['Foaie','Coloană','Descriere','Sursă / formulă','Disponibilitate','Observație de utilizare'],1);
        $row=2;
        foreach (self::LINE_COLUMNS as $index=>$label) {
            $column=$index+1;
            $derived=in_array($column,[72,73,74,75,78,81,83,84,126,127,128],true);
            $stored=in_array($column,self::STORED_LINE_COLUMNS,true)||$column===1;
            $sensitive=($column>=77&&$column<=98)||($column>=118&&$column<=125);
            $available=$derived?'Derivat':($stored?'Disponibil':'Nestocat');
            $source=$derived?'Calcul din cantitățile / valorile instantaneului NIR':($stored?'Instantaneu NIR / furnizor / produs, dacă este completat':'Câmpul nu este înregistrat de versiunea curentă');
            $note=$stored?'Necompletat în document → celulă goală.':'Nu se estimează și nu se completează cu date inventate.';
            if($sensitive&&!$costs){$available='Protejat';$source='NIR_VIEW_COSTS';$note='Valoare omisă deoarece operatorul nu are acces la costuri.';}
            if($column===93){$note='Cost contabil; poate include TVA nedeductibilă conform politicii firmei din instantaneu.';}
            if($column===71){$note='Cantitate respinsă; nu intră în stoc. Nu echivalează cu un lot de carantină disponibil.';}
            if(in_array($column,[77,78,79,80],true)&&$costs){$note='Disponibil la calculul din preț unitar. Pentru net/TVA exact/total cu TVA, prețul inițial și discountul original nu sunt păstrate separat și celula rămâne goală.';}
            $this->putRow($sheet,$row++,['Poziții NIR',Coordinate::stringFromColumnIndex($column),$label,$source,$available,$note]);
        }
        foreach(self::DIFFERENCE_COLUMNS as $index=>$label){$this->putRow($sheet,$row++,['Diferențe',Coordinate::stringFromColumnIndex($index+1),$label,$index>=5&&$index<=9?'Referință Excel la Poziții NIR':'Instantaneu / necompletat dacă nu este înregistrat',$index>=11?'Nestocat':($index>=5&&$index<=9?'Derivat':'Disponibil'),'Formulele fac referire numai la această carte.']);}
        $this->putRow($sheet,$row++,['Rezumat NIR','Toate','Date de identificare, totaluri și verificări','Instantaneu document; totalurile sunt bani întregi convertiți numai pentru afișare','Disponibil','Datele sunt afișate în Europe/Bucharest.']);
        $this->putRow($sheet,$row++,['Documente & Audit','Toate','Fișiere originale, hash și audit','Atașamente și evenimente persistate','Disponibil','Payload-urile audit sunt omise integral fără permisiunea de costuri.']);
        $this->table($sheet,6,$row-1,1); $sheet->freezePane('C2');
        foreach(['C'=>36,'D'=>64,'F'=>68] as $col=>$width){$sheet->getColumnDimension($col)->setWidth($width);}
    }

    /** @param list<array<string,mixed>> $documents @param array<string,mixed> $period */
    private function registryWorkbook(array $documents,array $period,bool $costs): Spreadsheet
    {
        $book=new Spreadsheet(); $sheet=$book->getActiveSheet()->setTitle('Registru NIR');
        $sheet->mergeCells('A1:B4'); $this->draw($sheet,$this->publicImage('/assets/images/logo-la-mariuca-transparent.png'),'A1',68);
        $sheet->mergeCells('C1:AL1')->setCellValue('C1','REGISTRU DETALIAT NIR');
        $sheet->mergeCells('C2:AL2')->setCellValue('C2','La Măriuca Boutique · Recepții, diferențe și trasabilitate');
        $this->cell($sheet,3,3,'Perioadă: '.($period['from']??'Toate datele').' — '.($period['to']??'prezent').' · Generat: '.self::displayDate(gmdate('Y-m-d H:i:s'))); $sheet->mergeCells('C3:AL3');
        $lineCount=array_sum(array_map(static fn(array $d):int=>count($d['lines']??[]),$documents));
        $fileCount=array_sum(array_map(static fn(array $d):int=>count($d['attachments']??[]),$documents));
        $totals=array_sum(array_column($documents,'total_ron_bani'));
        $sheet->mergeCells('C4:AL4'); $this->cell($sheet,3,4,count($documents).' documente · '.$lineCount.' poziții · '.$fileCount.' atașamente'.($costs?' · Total '.number_format($totals/100,2,',','.').' RON':'').' · Filtru: '.($period['status']??'toate'));
        $sheet->getStyle('C1:AL1')->getFont()->setSize(21)->setBold(true)->getColor()->setARGB('FF643C46');
        $this->heading($sheet,self::REGISTRY_COLUMNS,6);
        foreach($documents as $index=>$d){
            $row=$index+7;
            $values=[$index+1,self::number($d),self::type($d),self::status($d),self::excelDate($d['nir_at']??''),self::excelDate($d['nir_at']??''),self::excelDate($d['received_at']??''),self::excelDate($d['received_at']??''),$d['supplier']['company_name']??$d['supplier']['alias']??'',$d['supplier']['tax_id']??'',$d['invoice_series']??'',$d['invoice_number']??'',self::excelDate($d['invoice_date']??''),$d['warehouse']['name']??'',$d['currency']??'RON',self::numeric($d['exchange_rate']??1),self::excelDate($d['exchange_rate_date']??''),count($d['lines']??[]),$this->quantity($d,'invoice_quantity'),$this->quantity($d,'received_quantity'),$this->quantity($d,'accepted_quantity'),$this->quantity($d,'rejected_quantity'),$this->quantity($d,'received_quantity')-$this->quantity($d,'invoice_quantity')];
            foreach(['net_bani','tax_bani','total_bani','net_ron_bani','tax_ron_bani','total_ron_bani','inventory_bani'] as $key){$values[]=$costs?self::money($d,$key):null;}
            $values[]=null;
            array_push($values,count($d['attachments']??[]),$d['original_number']??$d['original_id']??'',trim(($d['notes']??'').' '.($d['reason']??'')),self::excelDate($d['confirmed_at']??''),$d['confirmed_by_name']??$d['confirmed_by']??'',self::excelDate($d['created_at']??''),$d['created_by_name']??$d['created_by']??'');
            $this->putRow($sheet,$row,$values);
            foreach(['E','G','M','Q'] as $col){$sheet->getStyle($col.$row)->getNumberFormat()->setFormatCode('dd.mm.yyyy');}
            foreach(['F','H'] as $col){$sheet->getStyle($col.$row)->getNumberFormat()->setFormatCode('hh:mm');}
            foreach(['AI','AK'] as $col){$sheet->getStyle($col.$row)->getNumberFormat()->setFormatCode('dd.mm.yyyy hh:mm');}
            $sheet->getStyle('S'.$row.':W'.$row)->getNumberFormat()->setFormatCode('0.0000');
            $sheet->getStyle('X'.$row.':AE'.$row)->getNumberFormat()->setFormatCode('#,##0.00');
        }
        $sheet->freezePane('C7');$this->table($sheet,38,max(7,count($documents)+6),6); $sheet->getColumnDimension('I')->setWidth(30);$sheet->getColumnDimension('AH')->setWidth(42);
        return $book;
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
                $folder=NirDocumentStorage::safeName(self::number($document));
                foreach(['pdf','xlsx'] as $format){$file=$this->individual($document,$format,$costs);$temporary[]=$file['path'];$entry=$folder.'/'.$file['filename'];$zip->addFile($file['path'],$entry);$zip->setCompressionName($entry,\ZipArchive::CM_STORE);}
                if(!$costs){$zip->addFromString($folder.'/documente/ACCES_RESTRICTIONAT.txt','Documentele originale pot conține prețuri și nu sunt incluse fără dreptul NIR_VIEW_COSTS.');continue;}
                foreach($document['attachments']??[] as $i=>$file){$original=$storage->path($file['stored_name']??null);if($original===null){throw new \RuntimeException('Lipsește un document original din arhivă: '.($file['original_name']??'document').'.');}if(!hash_equals((string)($file['sha256']??''),(string)hash_file('sha256',$original))){throw new \RuntimeException('Integritatea documentului original nu poate fi confirmată: '.($file['original_name']??'document').'.');}$entry=$folder.'/documente/'.sprintf('%03d_',$i+1).NirDocumentStorage::safeName($file['original_name']??'document');$zip->addFile($original,$entry);$zip->setCompressionName($entry,\ZipArchive::CM_STORE);}
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

    private function draw(Worksheet $sheet,?string $path,string $coordinate,int $height): void
    {
        if($path===null){return;}
        $bytes=$this->thumbnail($path);if($bytes===null){return;}$image=@imagecreatefromstring($bytes);if($image===false){return;}
        $drawing=new MemoryDrawing();$drawing->setName('Imagine produs / identitate');$drawing->setImageResource($image);$drawing->setRenderingFunction(MemoryDrawing::RENDERING_PNG);$drawing->setMimeType(MemoryDrawing::MIMETYPE_PNG);$drawing->setCoordinates($coordinate)->setHeight($height)->setOffsetX(5)->setOffsetY(5)->setWorksheet($sheet);
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

    /** @param array<string,mixed> $line */
    private function hasDifference(array $line): bool
    {
        return !BigDecimal::of($line['invoice_quantity']??'0')->isEqualTo($line['received_quantity']??'0')||!BigDecimal::of($line['invoice_quantity']??'0')->isEqualTo($line['accepted_quantity']??'0')||($line['difference_reason']??'')!=='';
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
    private static function number(array $document): string { return (string)(($document['number']??'')?:($document['temp_number']??'NIR')); }
    /** @param array<string,mixed> $document */
    private static function status(array $document): string { return match($document['status']??'draft'){'confirmed'=>'Confirmat','reversed'=>'Stornat',default=>'Ciornă'}; }
    /** @param array<string,mixed> $document */
    private static function type(array $document): string { return match($document['type']??'supplier_receipt'){'supplier_return'=>'Retur furnizor / storno','customer_return'=>'Recepție retur client','opening_balance'=>'Sold inițial',default=>'Recepție furnizor'}; }
    private static function differenceLabel(string $reason): string { return match($reason){'shortage','missing'=>'Lipsă','excess'=>'Plus','damaged'=>'Deteriorat','wrong_product'=>'Produs greșit','price_difference'=>'Diferență de preț','vat_difference'=>'Diferență TVA','other'=>'Alt motiv',default=>$reason}; }
    private static function mime(string $format): string { return match($format){'pdf'=>'application/pdf','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',default=>'application/zip'}; }
    private static function excelDate(mixed $value): ?float { if(!is_string($value)||$value===''){return null;}try{return Date::PHPToExcel((new \DateTimeImmutable($value,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Bucharest')));}catch(\Exception){return null;} }
    private static function displayDate(mixed $value): string { if(!is_string($value)||$value===''){return '—';}try{return(new \DateTimeImmutable($value,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y, H:i');}catch(\Exception){return '—';} }
}
