<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

final class CatalogExportService
{
    public function __construct(private readonly string $root)
    {
    }

    /** @param list<array<string,mixed>> $products @return array{path:string,name:string} */
    public function export(array $products): array
    {
        $book = new Spreadsheet();
        $book->getProperties()->setCreator('La Măriuca Boutique')->setTitle('Catalog produse');
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(10)->getColor()->setRGB('49383E');
        $sheet = $book->getActiveSheet()->setTitle('Catalog produse');
        $sheet->setShowGridlines(false);
        $sheet->getTabColor()->setRGB('855260');
        $sheet->mergeCells('A1:C3')->mergeCells('D1:Q2')->mergeCells('D3:Q3');
        $this->text($sheet, 'D1', 'Catalog produse · La Măriuca Boutique');
        $this->text($sheet, 'D3', 'Generat la ' . (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Bucharest')))->format('d.m.Y H:i') . ' · ' . count($products) . ' produse filtrate');
        $sheet->getStyle('D1:Q2')->getFont()->setSize(20)->setBold(true)->getColor()->setRGB('60445C');
        $sheet->getStyle('D3:Q3')->getFont()->setSize(10)->getColor()->setRGB('947587');
        $sheet->getStyle('D1:Q3')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $logo = $this->root . '/public/assets/images/logo-la-mariuca-transparent.png';
        if (is_file($logo)) {
            $drawing = new Drawing();
            $drawing->setName('La Măriuca Boutique');
            $drawing->setPath($logo);
            $drawing->setCoordinates('A1')->setHeight(62)->setOffsetX(8)->setOffsetY(4)->setWorksheet($sheet);
        }

        $headers = ['Imagine','Nume produs','Preț sursă','Preț public','Marjă','SKU','Cod sursă','Sursă','Stoc online','Stoc contabil','Stoc extern','Cantitate vândută','Vizualizări','Cod furnizor','Nume furnizor','Rating mediu','Număr recenzii'];
        foreach ($headers as $index => $header) {
            $this->text($sheet, $this->column($index + 1) . '6', $header);
        }
        $sheet->getStyle('A6:Q6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('60445C');
        $sheet->getStyle('A6:Q6')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A6:Q6')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension(6)->setRowHeight(38);

        foreach ($products as $index => $product) {
            $row = 7 + $index;
            $sourcePrice = $this->decimal($product['source_price'] ?? null);
            $publicPrice = (int) ($product['price_bani'] ?? 0) / 100;
            $margin = $sourcePrice !== null && $publicPrice > 0 ? (($publicPrice - $sourcePrice) / $publicPrice) : null;
            $values = [
                null,
                (string) ($product['name'] ?? ''),
                $sourcePrice,
                $publicPrice,
                $margin,
                (string) ($product['sku'] ?? ''),
                (string) ($product['supplier_code'] ?? ''),
                (string) ($product['supplier_alias'] ?? ''),
                (int) ($product['has_unlimited_stock'] ?? 0) === 1 ? 'Nelimitat' : (float) ($product['online_quantity'] ?? 0),
                (float) ($product['physical_quantity'] ?? 0),
                null,
                (float) ($product['sold_quantity'] ?? 0),
                0,
                (string) ($product['supplier_code'] ?? ''),
                trim((string) ($product['supplier_alias'] ?? '') . ((string) ($product['supplier_name'] ?? '') !== '' ? ' · ' . (string) $product['supplier_name'] : '')),
                (float) ($product['rating_average'] ?? 0),
                (int) ($product['review_count'] ?? 0),
            ];
            foreach ($values as $columnIndex => $value) {
                if ($value === null) {
                    continue;
                }
                $cell = $sheet->getCell([$columnIndex + 1, $row]);
                if (is_int($value) || is_float($value)) {
                    $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);
                } else {
                    $cell->setValueExplicit($value, DataType::TYPE_STRING);
                }
            }
            $sheet->getStyle('C' . $row . ':D' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('0.00%');
            $sheet->getStyle('I' . $row . ':L' . $row)->getNumberFormat()->setFormatCode('#,##0.####');
            $sheet->getStyle('P' . $row)->getNumberFormat()->setFormatCode('0.00');
            $sheet->getStyle('A' . $row . ':Q' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($index % 2 === 0 ? 'FFFFFF' : 'FBF6F8');
            $sheet->getStyle('A' . $row . ':Q' . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
            $sheet->getRowDimension($row)->setRowHeight(58);
            $this->productImage($sheet, $product, $row);
        }

        $lastRow = max(6, 6 + count($products));
        $sheet->freezePane('B7')->setAutoFilter('A6:Q' . $lastRow);
        foreach (['A'=>13,'B'=>38,'C'=>14,'D'=>14,'E'=>12,'F'=>20,'G'=>20,'H'=>22,'I'=>14,'J'=>14,'K'=>14,'L'=>17,'M'=>13,'N'=>20,'O'=>28,'P'=>14,'Q'=>16] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(6, 6)->setPrintArea('A1:Q' . $lastRow);
        $sheet->getHeaderFooter()->setOddFooter('&LLa Măriuca Boutique · Catalog produse&RPagina &P / &N');

        $directory = $this->directory();
        $path = $directory . '/' . bin2hex(random_bytes(12)) . '.xlsx';
        $temporary = $path . '.tmp';
        try {
            (new Xlsx($book))->save($temporary);
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Exportul catalogului nu a putut fi pregătit.');
            }
        } finally {
            $book->disconnectWorksheets();
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
        return ['path' => $path, 'name' => 'Catalog-produse-' . date('Y-m-d') . '.xlsx'];
    }

    public function cleanup(string $path): void
    {
        $directory = realpath($this->directory());
        $resolved = realpath($path);
        if ($directory !== false && $resolved !== false && dirname($resolved) === $directory && !is_link($path)) {
            @unlink($resolved);
        }
    }

    /** @param array<string,mixed> $product */
    private function productImage(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $product, int $row): void
    {
        $publicPath = ltrim((string) ($product['image_path'] ?? ''), '/');
        if ($publicPath === '') {
            return;
        }
        $path = $this->root . '/public/' . $publicPath;
        if (!is_file($path) || @getimagesize($path) === false) {
            throw new RuntimeException('Imaginea produsului „' . (string) ($product['name'] ?? '') . '” nu poate fi inclusă. Exportul a fost oprit pentru a nu livra un fișier incomplet.');
        }
        $drawing = new Drawing();
        $drawing->setName((string) ($product['name'] ?? 'Produs'))->setDescription((string) ($product['image_alt'] ?? ''));
        $drawing->setPath($path);
        $drawing->setCoordinates('A' . $row)->setHeight(48)->setOffsetX(7)->setOffsetY(5)->setWorksheet($sheet);
    }

    private function decimal(mixed $value): ?float
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        return $normalized !== '' && is_numeric($normalized) ? (float) $normalized : null;
    }

    private function text(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    private function column(int $index): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
    }

    private function directory(): string
    {
        $directory = $this->root . '/storage/private/catalog-exports';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Directorul privat pentru exportul catalogului nu este disponibil.');
        }
        return $directory;
    }
}
