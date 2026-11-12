<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\NirDocumentStorage;
use App\Modules\Inventory\NirExchangeRateService;
use App\Modules\Inventory\NirExportService;
use App\Modules\Inventory\NirImportService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\UploadedFile;

/** Additional end-to-end file fixtures; no application database or external services. */
final class NirIoAuditTest extends TestCase
{
    private string $temporary;
    /** @var list<string> */
    private array $exports = [];
    /** @var list<string> */
    private array $originals = [];

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir() . '/lamariuca-nir-io-audit-' . bin2hex(random_bytes(8));
        mkdir($this->temporary, 0770, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->exports as $file) { (new NirExportService(dirname(__DIR__, 2)))->cleanup($file); }
        foreach ($this->originals as $file) { (new NirDocumentStorage(dirname(__DIR__, 2)))->remove($file); }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->temporary, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
        }
        rmdir($this->temporary);
    }

    public function testExcelNumericAndLiteralPercentagesHaveTheSameMeaning(): void
    {
        $book = $this->book();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(['Numeric', 2, 10, 0.21, 0.10, 'buc'], null, 'A2');
        $sheet->fromArray(['Text', 2, 10, '21%', '10%', ''], null, 'A3');
        $sheet->getStyle('D2:E3')->getNumberFormat()->setFormatCode('0.00%');
        $sheet->fromArray(['Literal suffix', 2, 10, 21, 10, 'buc'], null, 'A4');
        $sheet->getStyle('D4')->getNumberFormat()->setFormatCode('0\\%');
        $sheet->getStyle('E4')->getNumberFormat()->setFormatCode('0"%"');
        $result = (new NirImportService())->parse($this->save($book), 'xlsx');
        self::assertSame('21.00', $result['lines'][0]['vat_percent']);
        self::assertSame('21', $result['lines'][1]['vat_percent']);
        self::assertSame('10.0', $result['lines'][0]['discount_percent']);
        self::assertSame('10', $result['lines'][1]['discount_percent']);
        self::assertSame('buc', $result['lines'][1]['purchase_unit']);
        self::assertSame('buc', $result['lines'][1]['stock_unit']);
        self::assertSame('21', $result['lines'][2]['vat_percent']);
        self::assertSame('10', $result['lines'][2]['discount_percent']);
    }

    public function testExcelUsesFirstSheetSkipsEmptyRowsAndPreservesFormattedCodes(): void
    {
        $book = $this->book();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(['A', '2,5', '1.234,56', '21', '5', 'buc', 1234, 999], null, 'A3');
        $sheet->setCellValue('G1', 'Cod furnizor');
        $sheet->setCellValue('H1', 'EAN');
        $sheet->getStyle('G3')->getNumberFormat()->setFormatCode('000000');
        $sheet->getStyle('H3')->getNumberFormat()->setFormatCode('0000000000000');
        $book->createSheet()->setCellValue('A1', '=WEBSERVICE("https://example.invalid")');
        $result = (new NirImportService())->parse($this->save($book), 'xlsx');
        self::assertCount(1, $result['lines']);
        self::assertSame('2.5', $result['lines'][0]['invoice_quantity']);
        self::assertSame('1234.56', $result['lines'][0]['unit_price']);
        self::assertSame('001234', $result['lines'][0]['supplier_code']);
        self::assertSame('0000000000999', $result['lines'][0]['supplier_ean']);
        self::assertStringContainsString('prima foaie', implode(' ', $result['warnings']));
    }

    #[DataProvider('invalidSpreadsheetRows')]
    public function testExcelRejectsInvalidValuesWithTheExactRow(array $values): void
    {
        $book = $this->book();
        $book->getActiveSheet()->fromArray($values, null, 'A2');
        $path = $this->save($book);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Rândul 2');
        (new NirImportService())->parse($path, 'xlsx');
    }

    public static function invalidSpreadsheetRows(): array
    {
        return [
            'missing name' => [['', 1, 10]],
            'zero quantity' => [['Produs', 0, 10]],
            'negative quantity' => [['Produs', -1, 10]],
            'negative price' => [['Produs', 1, -1]],
            'tax over 100' => [['Produs', 1, 10, 101]],
            'negative discount' => [['Produs', 1, 10, 21, -1]],
            'formula' => [['Produs', 1, '=5+5']],
        ];
    }

    public function testExcelRejectsMoreThanOneThousandActualLines(): void
    {
        $book = $this->book();
        for ($row = 2; $row <= 1002; ++$row) { $book->getActiveSheet()->fromArray(['Produs', 1, 10], null, 'A' . $row); }
        $path = $this->save($book);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('1.000');
        (new NirImportService())->parse($path, 'xlsx');
    }

    public function testUblPreservesDiscountedNetAndExactTaxAndWarnsAboutDocumentAdjustments(): void
    {
        $xml = self::ubl('<cbc:LineExtensionAmount currencyID="EUR">17.00</cbc:LineExtensionAmount>', '<cac:TaxTotal><cbc:TaxAmount currencyID="EUR">3.57</cbc:TaxAmount></cac:TaxTotal>');
        $xml = str_replace('<cac:InvoiceLine>', '<cac:AllowanceCharge><cbc:ChargeIndicator>false</cbc:ChargeIndicator><cbc:Amount>2</cbc:Amount></cac:AllowanceCharge><cac:InvoiceLine>', $xml);
        $result = $this->importXml($xml);
        self::assertSame('6.000000', $result['lines'][0]['unit_price']);
        self::assertSame('exact_vat', $result['lines'][0]['price_mode']);
        self::assertSame('17.00', $result['lines'][0]['net_override']);
        self::assertSame('3.57', $result['lines'][0]['tax_override']);
        self::assertStringContainsString('nivel de document', implode(' ', $result['warnings']));
        self::assertStringContainsString('cursul valutar', implode(' ', $result['warnings']));
        self::assertNull($result['lines'][0]['product_id']);
    }

    public function testUblWithoutExplicitTaxPreservesLineNetForCalculation(): void
    {
        $result = $this->importXml(self::ubl('<cbc:LineExtensionAmount>17</cbc:LineExtensionAmount>'));
        self::assertSame('line_net', $result['lines'][0]['price_mode']);
        self::assertSame('17', $result['lines'][0]['net_override']);
        self::assertSame('21', $result['lines'][0]['vat_percent']);
        self::assertSame('buc', $result['lines'][0]['purchase_unit']);
    }

    #[DataProvider('invalidUblValues')]
    public function testUblCannotSilentlyLoseAdjustmentsOrImportNegativeReceiptValues(string $net, string $tax): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->importXml(self::ubl($net, $tax));
    }

    public static function invalidUblValues(): array
    {
        return [
            'missing mandatory net' => ['', ''],
            'negative net' => ['<cbc:LineExtensionAmount>-17</cbc:LineExtensionAmount>', ''],
            'negative exact tax' => ['<cbc:LineExtensionAmount>17</cbc:LineExtensionAmount>', '<cac:TaxTotal><cbc:TaxAmount>-1</cbc:TaxAmount></cac:TaxTotal>'],
        ];
    }

    public function testUblCreditNoteUsesTheSupplierReturnFlow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retur furnizor');
        $this->importXml(str_replace(['Invoice-2', '<Invoice ', '</Invoice>'], ['CreditNote-2', '<CreditNote ', '</CreditNote>'], self::ubl('<cbc:LineExtensionAmount>18</cbc:LineExtensionAmount>')));
    }

    public function testUblRejectsZeroBaseQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('bază');
        $this->importXml(str_replace('<cbc:BaseQuantity>2</cbc:BaseQuantity>', '<cbc:BaseQuantity>0</cbc:BaseQuantity>', self::ubl('<cbc:LineExtensionAmount>18</cbc:LineExtensionAmount>')));
    }

    public function testLongOriginalFilenamePreservesItsExtensionAndContentHash(): void
    {
        $path = $this->temporary . '/source.xml';
        file_put_contents($path, self::ubl('<cbc:LineExtensionAmount>18</cbc:LineExtensionAmount>'));
        $stored = (new NirDocumentStorage($this->temporary))->store(new UploadedFile($path, str_repeat('Factura', 50) . '.xml', 'application/xml', filesize($path)));
        self::assertSame(220, mb_strlen($stored['original_name']));
        self::assertStringEndsWith('.xml', $stored['original_name']);
        self::assertSame(hash_file('sha256', $path), $stored['sha256']);
    }

    #[DataProvider('unsafeWorkbookEntries')]
    public function testWorkbookRejectsMacrosAndArchiveTraversal(string $entry): void
    {
        $path = $this->save($this->book());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $zip->addFromString($entry, 'not allowed');
        $zip->close();
        $this->expectException(\InvalidArgumentException::class);
        NirDocumentStorage::validateWorkbook($path);
    }

    public static function unsafeWorkbookEntries(): array
    {
        return [['../outside.txt'], ['xl/../../outside.txt'], ['..\\outside.txt'], ['/absolute.txt'], ['C:/absolute.txt'], ['xl/vbaProject.bin']];
    }

    public function testWorkbookRejectsDecompressionBombBeforeLoadingCells(): void
    {
        $path = $this->save($this->book());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        // ZipArchive reads the fixture from disk so the full application suite
        // does not allocate another 30 MB string merely to test the size guard.
        $oversized = $this->temporary . '/oversized.xml';
        $stream = fopen($oversized, 'wb');
        self::assertNotFalse($stream);
        self::assertTrue(ftruncate($stream, 30 * 1024 * 1024 + 1));
        fclose($stream);
        $zip->addFile($oversized, 'xl/oversized.xml');
        $zip->close();
        self::assertLessThan(1024 * 1024, filesize($path));
        $this->expectException(\InvalidArgumentException::class);
        NirDocumentStorage::validateWorkbook($path);
    }

    public function testSupportedImageAndPdfUploadsUseContentMimeAndPrivateRandomPaths(): void
    {
        $storage = new NirDocumentStorage($this->temporary);
        $image = imagecreatetruecolor(12, 12);
        foreach (['png', 'jpg', 'webp'] as $extension) {
            $path = $this->temporary . '/image.' . $extension;
            match ($extension) { 'png' => imagepng($image, $path), 'jpg' => imagejpeg($image, $path), default => imagewebp($image, $path) };
            $file = $storage->store(new UploadedFile($path, 'Fotografie.' . $extension, 'application/octet-stream', filesize($path)));
            self::assertMatchesRegularExpression('/^[a-f0-9]{40}\.' . $extension . '$/', $file['stored_name']);
            self::assertStringStartsWith('image/', $file['mime_type']);
            self::assertSame('manual', $file['extraction_status']);
        }
        imagedestroy($image);
        $export = (new NirExportService(dirname(__DIR__, 2)))->individual(NirDocumentsTest::document(), 'pdf');
        $this->exports[] = $export['path'];
        $file = $storage->store(new UploadedFile($export['path'], 'Factură.pdf', 'application/octet-stream', filesize($export['path'])));
        self::assertSame('application/pdf', $file['mime_type']);
        self::assertSame('manual', $file['extraction_status']);
    }

    public function testExportKeepsAmountsUnitsAndFormulaReferencesAcrossDifferentLines(): void
    {
        $document = NirDocumentsTest::document();
        $document['type'] = 'opening_balance';
        $document['lines'][0]['difference_reason'] = '';
        $document['lines'][0]['invoice_quantity'] = '2.0000';
        $line = $document['lines'][0];
        $line['id'] = 2;
        $line['invoice_quantity'] = '4.0000';
        $line['supplier_name'] = '=HYPERLINK("https://example.invalid","not a formula")';
        $line['difference_reason'] = 'shortage';
        $line['difference_notes'] = '+SUM(A1:A2)';
        $document['lines'][] = $line;
        $document=array_replace($document,['net_bani'=>19800,'tax_bani'=>4158,'total_bani'=>23958,'inventory_bani'=>19800]);
        $book = $this->exportBook($document);
        self::assertCount(1, $book->getAllSheets());
        $positions = $book->getSheetByName('NIR');
        self::assertSame('M', $positions->getHighestColumn());
        self::assertSame(DataType::TYPE_STRING, $positions->getCell('D14')->getDataType());
        self::assertSame($line['supplier_name'],$positions->getCell('D14')->getValue());
        self::assertSame('=ROUND(G14-F14,4)',$positions->getCell('H14')->getValue());
        self::assertEquals(-2, $positions->getCell('H14')->getCalculatedValue());
        self::assertEquals(49.5, $positions->getCell('I14')->getValue());
        self::assertEquals(99, $positions->getCell('J14')->getValue());
        foreach ($book->getAllSheets() as $sheet) {
            self::assertStringNotContainsString('FIFO', json_encode($sheet->toArray(), JSON_THROW_ON_ERROR));
        }
        $book->disconnectWorksheets();
    }

    public function testRestrictedExportOmitsAllCostColumnsAndAuditPayloads(): void
    {
        $document = NirDocumentsTest::document();
        $document['audit'] = [['id'=>1, 'before_json'=>'SECRET-PRICE-BEFORE', 'after_json'=>'SECRET-PRICE-AFTER', 'context_json'=>'SECRET-PRICE-CONTEXT']];
        $book = $this->exportBook($document, false);
        $sheet = $book->getSheetByName('NIR');
        foreach (range('I','M') as $column) {
            self::assertNull($sheet->getCell($column.'13')->getValue());
            self::assertNull($sheet->getCell($column.'14')->getValue());
        }
        foreach ($book->getAllSheets() as $sheet) {
            self::assertStringNotContainsString('SECRET-PRICE', json_encode($sheet->toArray(), JSON_THROW_ON_ERROR));
        }
        $book->disconnectWorksheets();
    }

    public function testExportDerivesNetUnitPriceForAnExactLineWithoutReapplyingStaleDiscount(): void
    {
        $document = NirDocumentsTest::document();
        $document['type'] = 'opening_balance';
        $document['lines'][0]['price_mode'] = 'line_net';
        $document['lines'][0]['discount_percent'] = '15';
        $document['lines'][0]['unit_price'] = '1.234567';
        $book = $this->exportBook($document);
        $sheet = $book->getSheetByName('NIR');
        self::assertSame('Sold inițial',$sheet->getCell('J9')->getValue());
        self::assertEquals(49.5,$sheet->getCell('I13')->getValue());
        self::assertEquals(99, $sheet->getCell('J13')->getValue());
        self::assertEquals(20.79, $sheet->getCell('L13')->getValue());
        self::assertEquals(119.79,$sheet->getCell('M13')->getValue());
        $book->disconnectWorksheets();
    }

    public function testZipFailsClosedOnTamperedOriginalAndCleansIntermediateFiles(): void
    {
        $root = dirname(__DIR__, 2);
        $storage = new NirDocumentStorage($root);
        $path = $this->temporary . '/source.xml';
        file_put_contents($path, self::ubl('<cbc:LineExtensionAmount>18</cbc:LineExtensionAmount>'));
        $original = $storage->store(new UploadedFile($path, 'Factură.xml', 'application/xml', filesize($path)));
        $this->originals[] = $original['stored_name'];
        file_put_contents($storage->path($original['stored_name']), '<tampered/>');
        $document = NirDocumentsTest::document();
        $document['attachments'] = [$original];
        $before = glob($root . '/storage/private/nir-exports/*') ?: [];
        try {
            (new NirExportService($root))->individual($document, 'zip');
            self::fail('Tampered original must not be exported.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Integritatea', $exception->getMessage());
        }
        self::assertSame($before, glob($root . '/storage/private/nir-exports/*') ?: []);
    }

    public function testCleanupDoesNotDeleteArbitraryFiles(): void
    {
        $path = $this->temporary . '/keep.txt';
        file_put_contents($path, 'keep');
        (new NirExportService(dirname(__DIR__, 2)))->cleanup($path);
        self::assertFileExists($path);
    }

    public function testBnrUsesCachedLastPreviousQuoteAndIgnoresInvalidCalendarDates(): void
    {
        $xml = '<DataSet xmlns="http://www.bnr.ro/xsd"><Body><Cube date="2025-02-28"><Rate currency="EUR">4.9756</Rate></Cube><Cube date="2025-02-31"><Rate currency="EUR">9.9</Rate></Cube><Cube date="2025-03-03"><Rate currency="EUR">5.1</Rate></Cube></Body></DataSet>';
        mkdir($this->temporary . '/storage/cache', 0770, true);
        file_put_contents($this->temporary . '/storage/cache/bnr-2025.xml', $xml);
        $quote = (new NirExchangeRateService($this->temporary))->lookup('eur', '2025-03-02');
        self::assertSame(['success'=>true, 'source'=>'BNR', 'currency'=>'EUR', 'rate'=>'4.97560000', 'date'=>'2025-02-28'], $quote);
        self::assertSame('1.00000000', (new NirExchangeRateService($this->temporary))->lookup('RON', '2025-03-02')['rate']);
    }

    #[DataProvider('invalidBnrRequests')]
    public function testBnrRejectsInvalidRequestsBeforeNetworkAccess(string $currency, string $date): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new NirExchangeRateService($this->temporary))->lookup($currency, $date);
    }

    public static function invalidBnrRequests(): array
    {
        return [['../EUR', '2025-03-02'], ['EUR', '2025-02-31'], ['EUR', '2999-01-01'], ['EUR', '2000-01-01']];
    }

    private function book(): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray(['Denumire produs', 'Cantitate', 'Preț unitar', 'TVA', 'Discount %', 'UM']);
        return $book;
    }

    private function save(Spreadsheet $book): string
    {
        $path = $this->temporary . '/' . bin2hex(random_bytes(5)) . '.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        return $path;
    }

    private function importXml(string $xml): array
    {
        $path = $this->temporary . '/invoice.xml';
        file_put_contents($path, $xml);
        return (new NirImportService())->parse($path, 'xml');
    }

    private function exportBook(array $document, bool $costs = true): Spreadsheet
    {
        $export = (new NirExportService(dirname(__DIR__, 2)))->individual($document, 'xlsx', $costs);
        $this->exports[] = $export['path'];
        return IOFactory::load($export['path']);
    }

    private static function ubl(string $net, string $tax = ''): string
    {
        return '<?xml version="1.0"?><Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"><cbc:ID>INV-100</cbc:ID><cbc:IssueDate>2026-11-12</cbc:IssueDate><cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode><cac:InvoiceLine><cbc:ID>1</cbc:ID><cbc:InvoicedQuantity unitCode="H87">3</cbc:InvoicedQuantity>' . $net . $tax . '<cac:Item><cbc:Name>Rochiță</cbc:Name><cac:ClassifiedTaxCategory><cbc:Percent>21</cbc:Percent></cac:ClassifiedTaxCategory></cac:Item><cac:Price><cbc:PriceAmount>12</cbc:PriceAmount><cbc:BaseQuantity>2</cbc:BaseQuantity></cac:Price></cac:InvoiceLine></Invoice>';
    }
}
