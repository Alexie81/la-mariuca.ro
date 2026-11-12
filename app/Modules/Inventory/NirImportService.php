<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

/** Imports suggestions only; never matches products or changes a saved document/stock. */
final class NirImportService
{
    private const ALIASES = [
        'supplier_name' => ['denumire', 'denumire produs', 'denumire produs furnizor', 'produs', 'descriere', 'description', 'product name', 'name'],
        'supplier_code' => ['cod', 'cod produs', 'cod furnizor', 'cod produs furnizor', 'sku', 'sku furnizor', 'supplier sku', 'supplier code', 'product code'],
        'invoice_quantity' => ['cantitate', 'cantitate facturata', 'cant', 'qty', 'quantity'],
        'unit_price' => ['pret', 'pret unitar', 'pret fara tva', 'pret unitar fara tva', 'unit price', 'price'],
        'vat_percent' => ['tva', 'cota tva', 'tva %', 'vat', 'vat rate', 'tax percent', 'tax'],
        'supplier_ean' => ['ean', 'gtin', 'ean gtin', 'cod de bare', 'barcode'],
        'purchase_unit' => ['um', 'u m', 'unitate de masura', 'unit', 'unit of measure'],
        'discount_percent' => ['discount', 'discount %', 'discount procent', 'reducere %'],
    ];

    /** @return array{header: array<string,mixed>, lines: list<array<string,mixed>>, warnings: list<string>} */
    public function parse(string $absolutePath, string $extension): array
    {
        if (!is_file($absolutePath) || is_link($absolutePath) || !is_readable($absolutePath)) {
            throw new \InvalidArgumentException('Documentul nu poate fi citit.');
        }
        return match (strtolower($extension)) {
            'xlsx' => $this->xlsx($absolutePath),
            'xml' => $this->xml($absolutePath),
            default => ['header' => [], 'lines' => [], 'warnings' => ['Acest document rămâne atașat. Completează pozițiile manual; extragerea OCR nu este activată.']],
        };
    }

    /** @return array{header: array<string,mixed>, lines: list<array<string,mixed>>, warnings: list<string>} */
    private function xlsx(string $path): array
    {
        NirDocumentStorage::validateWorkbook($path);
        $reader = new Xlsx();
        $reader->setAllowExternalImages(false);
        $info = $reader->listWorksheetInfo($path);
        if ($info === []) {
            throw new \InvalidArgumentException('Registrul XLSX nu conține foi.');
        }
        if ($info[0]['totalRows'] > 1021 || $info[0]['totalColumns'] > 80) {
            throw new \InvalidArgumentException('Importă o foaie cu maximum 1.000 de produse și 80 de coloane.');
        }
        $reader->setLoadSheetsOnly($info[0]['worksheetName']);
        $reader->setReadFilter(new class implements IReadFilter {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= 1021 && Coordinate::columnIndexFromString($columnAddress) <= 80;
            }
        });
        $book = $reader->load($path);
        try {
            $sheet = $book->getSheet(0);
            $map = [];
            $headerRow = 0;
            for ($row = 1; $row <= min(20, $info[0]['totalRows']); ++$row) {
                $candidate = [];
                for ($col = 1; $col <= $info[0]['totalColumns']; ++$col) {
                    $label = self::normalize((string) $sheet->getCell([$col, $row])->getValue());
                    foreach (self::ALIASES as $field => $aliases) {
                        if (in_array($label, $aliases, true) && !isset($candidate[$field])) {
                            $candidate[$field] = $col;
                        }
                    }
                }
                if (isset($candidate['supplier_name'], $candidate['invoice_quantity'], $candidate['unit_price'])) {
                    $map = $candidate;
                    $headerRow = $row;
                    break;
                }
            }
            if ($headerRow === 0) {
                throw new \InvalidArgumentException('Nu am găsit coloanele obligatorii: denumire produs, cantitate și preț unitar.');
            }
            $lines = [];
            $warnings = ['Verifică datele importate și asociază produsele înainte de confirmare.'];
            for ($row = $headerRow + 1; $row <= $info[0]['totalRows']; ++$row) {
                $values = [];
                foreach ($map as $field => $col) {
                    $cell = $sheet->getCell([$col, $row]);
                    if ($cell->isFormula()) {
                        throw new \InvalidArgumentException('Rândul ' . $row . ': formulele nu se importă. Copiază în XLSX valorile calculate, fără formule.');
                    }
                    $values[$field] = trim((string) $cell->getValue());
                    if (in_array($field, ['vat_percent', 'discount_percent'], true) && $cell->getDataType() === \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC && self::hasPercentageFormat($cell->getStyle()->getNumberFormat()->getFormatCode()) && $values[$field] !== '') {
                        $values[$field] = (string) BigDecimal::of(self::decimal($values[$field]))->multipliedBy(100);
                    }
                    // Preserve formatted supplier codes and leading zeros without executing formulas.
                    if (in_array($field, ['supplier_code', 'supplier_ean'], true) && preg_match('/^0{2,}$/D', $cell->getStyle()->getNumberFormat()->getFormatCode()) === 1) {
                        $values[$field] = str_pad($values[$field], strlen($cell->getStyle()->getNumberFormat()->getFormatCode()), '0', STR_PAD_LEFT);
                    }
                }
                if (implode('', $values) === '') {
                    continue;
                }
                if (($values['supplier_name'] ?? '') === '') {
                    throw new \InvalidArgumentException('Rândul ' . $row . ': completează denumirea produsului.');
                }
                try {
                    $line = $this->line($values);
                } catch (\InvalidArgumentException $exception) {
                    throw new \InvalidArgumentException('Rândul ' . $row . ': ' . $exception->getMessage(), 0, $exception);
                }
                $lines[] = $line;
                if (count($lines) > 1000) {
                    throw new \InvalidArgumentException('Un NIR poate avea maximum 1.000 de poziții.');
                }
            }
            if ($lines === []) {
                throw new \InvalidArgumentException('Nu există poziții completate în prima foaie XLSX.');
            }
            if (count($info) > 1) {
                $warnings[] = 'Au fost citite numai pozițiile din prima foaie: ' . $info[0]['worksheetName'] . '.';
            }
            return ['header' => [], 'lines' => $lines, 'warnings' => $warnings];
        } finally {
            $book->disconnectWorksheets();
        }
    }

    /** @return array{header: array<string,mixed>, lines: list<array<string,mixed>>, warnings: list<string>} */
    private function xml(string $path): array
    {
        if (filesize($path) > 80 * 1024 * 1024) {
            throw new \InvalidArgumentException('Acest XML este prea mare pentru extragerea automată. Îl poți atașa integral și completa produsele manual.');
        }
        $document = NirDocumentStorage::validateXml((string) file_get_contents($path));
        if ($document->documentElement?->localName !== 'Invoice' || $document->documentElement->namespaceURI !== 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2') {
            throw new \InvalidArgumentException('Importă o factură XML UBL Invoice 2. Documentele de storno se înregistrează prin retur furnizor.');
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $root = $document->documentElement;
        $text = static function (string $query, \DOMNode $context) use ($xpath): string {
            return trim((string) $xpath->evaluate('string(' . $query . ')', $context));
        };
        $supplierCui = $text('cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID', $root);
        if ($supplierCui === '') {
            $supplierCui = $text('cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:CompanyID', $root);
        }
        $header = [
            'invoice_number' => $text('cbc:ID', $root), 'invoice_date' => $text('cbc:IssueDate', $root),
            'currency' => strtoupper($text('cbc:DocumentCurrencyCode', $root)), 'supplier_tax_id' => $supplierCui,
            'supplier_name' => $text('cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName', $root),
        ];
        $nodes = $xpath->query('cac:InvoiceLine', $root);
        if ($nodes === false || count($nodes) < 1 || count($nodes) > 1000) {
            throw new \InvalidArgumentException('Factura trebuie să conțină între 1 și 1.000 de poziții.');
        }
        $lines = [];
        $warnings = ['Datele XML sunt propuneri. Verifică furnizorul, asocierile, reducerile și TVA-ul înainte de confirmare.'];
        foreach ($nodes as $node) {
            $price = self::decimal($text('cac:Price/cbc:PriceAmount', $node));
            $base = $text('cac:Price/cbc:BaseQuantity', $node);
            if ($base !== '') {
                $baseValue = BigDecimal::of(self::decimal($base));
                if ($baseValue->isLessThanOrEqualTo(0)) {
                    throw new \InvalidArgumentException('Cantitatea de bază a prețului UBL trebuie să fie pozitivă.');
                }
                $price = (string) BigDecimal::of($price)->dividedBy($baseValue, 6, RoundingMode::HalfUp);
            }
            $unitCode = strtoupper($text('cbc:InvoicedQuantity/@unitCode', $node));
            $values = [
                'supplier_name' => $text('cac:Item/cbc:Name', $node) ?: $text('cac:Item/cbc:Description', $node),
                'supplier_code' => $text('cac:Item/cac:SellersItemIdentification/cbc:ID', $node),
                'supplier_ean' => $text('cac:Item/cac:StandardItemIdentification/cbc:ID', $node),
                'invoice_quantity' => $text('cbc:InvoicedQuantity', $node), 'unit_price' => $price,
                'vat_percent' => $text('cac:Item/cac:ClassifiedTaxCategory/cbc:Percent', $node) ?: '0',
                'purchase_unit' => in_array($unitCode, ['H87', 'C62', 'EA', 'XPP'], true) ? 'buc' : strtolower($unitCode ?: 'buc'),
            ];
            $line = $this->line($values);
            $net = $text('cbc:LineExtensionAmount', $node);
            $tax = $text('cac:TaxTotal/cbc:TaxAmount', $node);
            if ($net === '') {
                throw new \InvalidArgumentException('O poziție UBL nu conține valoarea netă a liniei. Exportă factura completă pentru a păstra reducerile și taxele.');
            }
            $line['price_mode'] = $tax !== '' ? 'exact_vat' : 'line_net';
            $line['net_override'] = self::decimal($net);
            if (BigDecimal::of($line['net_override'])->isNegative()) {
                throw new \InvalidArgumentException('Valoarea netă UBL trebuie să fie nenegativă. Documentele de storno se înregistrează prin retur furnizor.');
            }
            if ($tax !== '') {
                $line['tax_override'] = self::decimal($tax);
                if (BigDecimal::of($line['tax_override'])->isNegative()) {
                    throw new \InvalidArgumentException('Valoarea TVA a poziției UBL trebuie să fie nenegativă.');
                }
            }
            $lines[] = $line;
        }
        if ((int) $xpath->evaluate('count(cac:AllowanceCharge)', $root) > 0) {
            $warnings[] = 'Factura conține reduceri sau taxe la nivel de document. Repartizează-le manual pe poziții pentru a egala totalul facturii.';
        }
        if ($header['currency'] !== 'RON') {
            $warnings[] = 'Completează cursul valutar și data cursului înainte de confirmare.';
        }
        return ['header' => $header, 'lines' => $lines, 'warnings' => $warnings];
    }

    /** @param array<string,string> $values @return array<string,mixed> */
    private function line(array $values): array
    {
        $quantity = self::decimal($values['invoice_quantity'] ?? '');
        $price = self::decimal($values['unit_price'] ?? '');
        $vat = self::decimal(($values['vat_percent'] ?? '') !== '' ? $values['vat_percent'] : '21');
        $discount = self::decimal(($values['discount_percent'] ?? '') !== '' ? $values['discount_percent'] : '0');
        if (($values['supplier_name'] ?? '') === '' || BigDecimal::of($quantity)->isLessThanOrEqualTo(0) || BigDecimal::of($price)->isNegative()) {
            throw new \InvalidArgumentException('Completează denumirea, o cantitate pozitivă și un preț nenegativ.');
        }
        foreach ([$vat, $discount] as $percent) {
            if (BigDecimal::of($percent)->isNegative() || BigDecimal::of($percent)->isGreaterThan(100)) {
                throw new \InvalidArgumentException('TVA și discountul trebuie să fie între 0 și 100.');
            }
        }
        return [
            'supplier_name' => mb_substr($values['supplier_name'], 0, 500),
            'supplier_code' => mb_substr($values['supplier_code'] ?? '', 0, 160),
            'supplier_ean' => mb_substr($values['supplier_ean'] ?? '', 0, 64),
            'invoice_quantity' => $quantity, 'received_quantity' => $quantity, 'accepted_quantity' => $quantity,
            'rejected_quantity' => '0', 'unit_price' => $price, 'vat_percent' => $vat, 'discount_percent' => $discount,
            'purchase_unit' => ($values['purchase_unit'] ?? '') ?: 'buc', 'stock_unit' => ($values['purchase_unit'] ?? '') ?: 'buc',
            'conversion_factor' => '1', 'extra_cost_ron' => '0', 'price_mode' => 'unit',
            'product_id' => null, 'variant_id' => null, 'tracked' => true,
        ];
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
        return trim((string) preg_replace('/[\s.\/_-]+/u', ' ', $value));
    }

    private static function hasPercentageFormat(string $format): bool
    {
        // Quoted, escaped and padding characters are literal decorations in Excel,
        // not the percentage operator which scales a numeric cell by 100.
        $operators = preg_replace('/"(?:""|[^"])*"|\\\\.|[_*]./u', '', $format);
        return str_contains($operators ?? '', '%');
    }

    private static function decimal(string $value): string
    {
        $value = trim(str_replace(["\xc2\xa0", ' ', '%'], '', $value));
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = strrpos($value, ',') > strrpos($value, '.') ? str_replace(',', '.', str_replace('.', '', $value)) : str_replace(',', '', $value);
        } else {
            $value = str_replace(',', '.', $value);
        }
        if (preg_match('/^[+-]?\d+(?:\.\d+)?$/D', $value) !== 1 || strlen($value) > 40) {
            throw new \InvalidArgumentException('O valoare numerică este invalidă. Folosește cifre și separator zecimal.');
        }
        return (string) BigDecimal::of($value);
    }
}
