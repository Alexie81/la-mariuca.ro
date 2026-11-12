<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\NirDocumentStorage;
use App\Modules\Inventory\NirExportService;
use App\Modules\Inventory\NirImportService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\UploadedFile;

final class NirDocumentsTest extends TestCase
{
    private string $temporary;
    private array $exports = [];

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir() . '/lamariuca-nir-documents-' . bin2hex(random_bytes(8));
        mkdir($this->temporary, 0770, true);
    }

    protected function tearDown(): void
    {
        $exporter = new NirExportService(dirname(__DIR__, 2));
        foreach ($this->exports as $path) {
            $exporter->cleanup($path);
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->temporary, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
        }
        rmdir($this->temporary);
    }

    public function testPrivateUploadPreservesOriginalHashAndRejectsTraversalAccess(): void
    {
        $path = $this->temporary . '/invoice.xml';
        file_put_contents($path, self::ubl());
        $storage = new NirDocumentStorage($this->temporary);
        $data = $storage->store(new UploadedFile($path, '../../Factură.xml', 'application/xml', filesize($path)));
        self::assertSame('Factură.xml', $data['original_name']);
        self::assertSame(hash_file('sha256', $path), $data['sha256']);
        self::assertSame('application/xml', $data['mime_type']);
        self::assertSame('pending', $data['extraction_status']);
        self::assertFileExists($storage->path($data['stored_name']));
        self::assertNull($storage->path('../invoice.xml'));
        self::assertNull($storage->path('../../public/index.php'));
        $storage->remove($data['stored_name']);
        self::assertNull($storage->path($data['stored_name']));
    }

    public function testUploadRejectsSpoofedExtension(): void
    {
        $path=$this->temporary.'/bad.png';file_put_contents($path,'<?php echo "no";');
        $this->expectException(\InvalidArgumentException::class);
        (new NirDocumentStorage($this->temporary))->store(new UploadedFile($path,'image.png','image/png',filesize($path)));
    }

    public function testUploadRejectsOversizeBeforeReading(): void
    {
        $path=$this->temporary.'/big.pdf';file_put_contents($path,'%PDF-1.7');
        $this->expectException(\InvalidArgumentException::class);
        (new NirDocumentStorage($this->temporary))->store(new UploadedFile($path,'big.pdf','application/pdf',NirDocumentStorage::MAX_BYTES+1));
    }

    public function testXmlRejectsExternalEntities(): void
    {
        $path=$this->temporary.'/bad.xml';file_put_contents($path,'<!DOCTYPE Invoice [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><Invoice>&xxe;</Invoice>');
        $this->expectException(\InvalidArgumentException::class);
        (new NirImportService())->parse($path,'xml');
    }

    public function testUblImportExtractsHeaderAndExactInvoiceValuesWithoutAssociation(): void
    {
        $path=$this->temporary.'/invoice.xml';file_put_contents($path,self::ubl());
        $result=(new NirImportService())->parse($path,'xml');
        self::assertSame('F-1001',$result['header']['invoice_number']);
        self::assertSame('RO12345678',$result['header']['supplier_tax_id']);
        self::assertSame('RON',$result['header']['currency']);
        self::assertSame('0000123',$result['lines'][0]['supplier_code']);
        self::assertSame('6.000000',$result['lines'][0]['unit_price']);
        self::assertSame('18.00',$result['lines'][0]['net_override']);
        self::assertSame('3.78',$result['lines'][0]['tax_override']);
        self::assertNull($result['lines'][0]['product_id']);
        self::assertNotEmpty($result['warnings']);
    }

    public function testXlsxImportRecognizesRomanianAliasesAndPreservesLeadingZeros(): void
    {
        $book=new Spreadsheet();$sheet=$book->getActiveSheet();
        $sheet->fromArray(['Denumire produs','Cod furnizor','Cantitate','Preț unitar','TVA','UM'],null,'A1');
        $sheet->fromArray(['Rochiță Ana','0000123','2,5','1.234,50','21','buc'],null,'A2');
        $sheet->setCellValueExplicit('B2','0000123',DataType::TYPE_STRING);
        $path=$this->temporary.'/import.xlsx';(new Xlsx($book))->save($path);$book->disconnectWorksheets();
        $result=(new NirImportService())->parse($path,'xlsx');
        self::assertSame('0000123',$result['lines'][0]['supplier_code']);
        self::assertSame('2.5',$result['lines'][0]['accepted_quantity']);
        self::assertSame('1234.50',$result['lines'][0]['unit_price']);
    }

    public function testXlsxFormulaIsNeverExecutedDuringImport(): void
    {
        $book=new Spreadsheet();$book->getActiveSheet()->fromArray([['Denumire','Cantitate','Preț'],['Rochiță',1,'=1+1']]);
        $path=$this->temporary.'/formula.xlsx';$writer=new Xlsx($book);$writer->setPreCalculateFormulas(false);$writer->save($path);$book->disconnectWorksheets();
        $this->expectException(\InvalidArgumentException::class);
        (new NirImportService())->parse($path,'xlsx');
    }

    public function testXlsxExportsAllSheetsColumnsDifferencesAndSafeText(): void
    {
        $document=self::document();$document['supplier']['company_name']='=HYPERLINK("https://example.test","supplier")';
        $file=(new NirExportService(dirname(__DIR__,2)))->individual($document,'xlsx');$this->exports[]=$file['path'];
        $book=IOFactory::load($file['path']);
        self::assertSame(['Rezumat NIR','Poziții NIR','Diferențe','Documente & Audit','Dicționar câmpuri'],$book->getSheetNames());
        $sheet=$book->getSheetByName('Poziții NIR');
        self::assertSame('EK',$sheet->getHighestColumn());
        self::assertSame('Observații audit',$sheet->getCell('EK1')->getValue());
        self::assertSame(DataType::TYPE_STRING,$sheet->getCell('AH2')->getDataType());
        self::assertSame($document['supplier']['company_name'],$sheet->getCell('AH2')->getValue());
        self::assertEquals(99,$sheet->getCell('CG2')->getValue());
        self::assertSame('=BQ2-BP2',$sheet->getCell('BV2')->getValue());
        self::assertEquals(-1,$sheet->getCell('BV2')->getCalculatedValue());
        self::assertEquals(-1,$book->getSheetByName('Diferențe')->getCell('I2')->getCalculatedValue());
        self::assertSame('C2',$sheet->getFreezePane());
        self::assertTrue(is_numeric($sheet->getCell('E2')->getValue()));
        self::assertSame('Nestocat',$book->getSheetByName('Dicționar câmpuri')->getCell('E13')->getValue());
        $book->disconnectWorksheets();
    }

    public function testDesignedXlsxSummaryPreservesCompanyAndSupplierSnapshotsAsSafeText(): void
    {
        $document = self::document();
        $document['company'] = array_replace($document['company'], [
            'legal_name' => '=HYPERLINK("https://example.test/company","Firma arhivată")',
            'county' => 'Iași', 'postal_code' => '700001', 'country' => 'România',
            'email' => 'firma-snapshot@example.test', 'phone' => '0730123456',
            'bank_name' => 'BCR', 'iban' => 'RO49AAAA1B31007593840000',
            'website' => 'https://firma-snapshot.example.test', 'share_capital_bani' => 20000,
        ]);
        $document['supplier'] = array_replace($document['supplier'], [
            'company_name' => '=HYPERLINK("https://example.test/supplier","Furnizor arhivat")',
            'trade_register' => 'J22/456/2021', 'city' => 'Pașcani', 'county' => 'Iași',
            'country' => 'România', 'phone' => '0740654321',
            'bank_name' => 'Banca Transilvania', 'iban' => 'RO09BBBB1B31007593840000',
        ]);
        $file = (new NirExportService(dirname(__DIR__, 2)))->individual($document, 'xlsx');
        $this->exports[] = $file['path'];
        $book = IOFactory::load($file['path']);
        self::assertSame(['Rezumat NIR', 'Poziții NIR', 'Diferențe', 'Documente & Audit', 'Dicționar câmpuri'], $book->getSheetNames());
        self::assertSame('EK', $book->getSheetByName('Poziții NIR')->getHighestColumn());
        $summary = $book->getSheetByName('Rezumat NIR');
        self::assertSame('L', $summary->getHighestColumn());
        self::assertSame('A8', $summary->getFreezePane());
        foreach (['A9' => $document['company']['legal_name'], 'G9' => $document['supplier']['company_name']] as $cell => $value) {
            self::assertSame($value, $summary->getCell($cell)->getValue());
            self::assertSame(DataType::TYPE_STRING, $summary->getCell($cell)->getDataType());
        }
        $summaryText = json_encode($summary->toArray(null, false, false), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach (['tax_id', 'trade_register', 'registered_address', 'city', 'county', 'postal_code', 'country', 'email', 'phone', 'bank_name', 'iban', 'website'] as $key) {
            self::assertStringContainsString($document['company'][$key], $summaryText, 'Company snapshot: ' . $key);
        }
        foreach (['tax_id', 'trade_register', 'address', 'city', 'county', 'country', 'email', 'phone', 'bank_name', 'iban'] as $key) {
            self::assertStringContainsString($document['supplier'][$key], $summaryText, 'Supplier snapshot: ' . $key);
        }
        self::assertStringContainsString('Capital social 200,00 RON', $summaryText);
        self::assertStringContainsString('Factură AT 1001', $summaryText);
        self::assertStringContainsString('2026-11-12', $summaryText);
        self::assertStringContainsString('Rochiță de zi „Ana”', $summary->getCell('B23')->getValue());
        self::assertStringContainsString('LM-G-001', $summary->getCell('B23')->getValue());
        foreach ($book->getAllSheets() as $sheet) {
            self::assertStringNotContainsString('FIFO', strtoupper(json_encode($sheet->toArray(null, false, false), JSON_THROW_ON_ERROR)), $sheet->getTitle());
        }
        $book->disconnectWorksheets();
    }

    public function testXlsxSummaryUsesAuthoritativeBaniForEachLineAndDocumentTotals(): void
    {
        $document = self::document();
        $document['lines'][] = array_replace($document['lines'][0], [
            'id' => 2, 'position' => 2, 'product_name' => 'Poziție cu valori exacte', 'sku' => 'EXACT-002',
            'invoice_quantity' => '3.0000', 'received_quantity' => '3.0000', 'accepted_quantity' => '3.0000',
            'stock_quantity' => '3.0000', 'unit_price' => '0.014000', 'price_mode' => 'net_exact',
            'net_override' => '0.03', 'tax_override' => '0.01', 'net_bani' => 3, 'tax_bani' => 1,
            'total_bani' => 4, 'inventory_bani' => 3, 'difference_reason' => '', 'difference_notes' => '',
        ]);
        $document = array_replace($document, ['net_bani' => 9903, 'tax_bani' => 2080, 'total_bani' => 11983, 'inventory_bani' => 9903]);
        $file = (new NirExportService(dirname(__DIR__, 2)))->individual($document, 'xlsx');
        $this->exports[] = $file['path'];
        $book = IOFactory::load($file['path']);
        $summary = $book->getSheetByName('Rezumat NIR');
        foreach (['J23' => 99.00, 'K23' => 20.79, 'L23' => 119.79, 'J24' => 0.03, 'K24' => 0.01, 'L24' => 0.04] as $cell => $expected) {
            self::assertSame(DataType::TYPE_NUMERIC, $summary->getCell($cell)->getDataType());
            self::assertEqualsWithDelta($expected, $summary->getCell($cell)->getValue(), 0.000001, $cell);
        }
        $totals = ['Subtotal' => 99.03, 'TVA' => 20.80, 'TOTAL DOCUMENT' => 119.83, 'Intrare în stoc · RON' => 99.03, 'TVA 21%' => 20.80];
        foreach ($summary->getRowIterator() as $row) {
            $index = $row->getRowIndex();
            $label = $summary->getCell('G' . $index)->getValue();
            if (is_string($label) && array_key_exists($label, $totals)) {
                self::assertEqualsWithDelta($totals[$label], $summary->getCell('J' . $index)->getValue(), 0.000001, $label);
                unset($totals[$label]);
            }
        }
        self::assertSame([], $totals, 'Every document total and VAT subtotal must remain in the designed summary.');
        $book->disconnectWorksheets();
    }

    public function testXlsxLogoIsEmbeddedAsPngWithoutBlackTransparencyBackground(): void
    {
        $file = (new NirExportService(dirname(__DIR__, 2)))->individual(self::document(), 'xlsx');
        $this->exports[] = $file['path'];
        $book = IOFactory::load($file['path']);
        $logo = null;
        foreach ($book->getSheetByName('Rezumat NIR')->getDrawingCollection() as $drawing) {
            if ($drawing->getCoordinates() === 'A2') { $logo = $drawing; break; }
        }
        self::assertNotNull($logo, 'The summary must retain the actual La Măriuca logo.');
        $bytes = file_get_contents($logo->getPath());
        self::assertNotFalse($bytes);
        self::assertSame(IMAGETYPE_PNG, getimagesizefromstring($bytes)[2]);
        $image = imagecreatefromstring($bytes);
        self::assertNotFalse($image);
        foreach ([[0, 0], [imagesx($image) - 1, 0], [0, imagesy($image) - 1], [imagesx($image) - 1, imagesy($image) - 1]] as [$x, $y]) {
            $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            self::assertTrue($color['alpha'] >= 120 || min($color['red'], $color['green'], $color['blue']) >= 240, 'Logo corner must be transparent or white, never black.');
        }
        imagedestroy($image);
        $book->disconnectWorksheets();
    }

    public function testCostsAndAuditPayloadsAreOmittedFromRestrictedExports(): void
    {
        $document=self::document();
        $document=array_replace($document,['type'=>'opening_balance','net_bani'=>387654,'tax_bani'=>81407,'total_bani'=>469061,'net_ron_bani'=>387654,'tax_ron_bani'=>81407,'total_ron_bani'=>469061,'inventory_bani'=>387654]);
        $document['lines'][0]=array_replace($document['lines'][0],['unit_price'=>'1938.270000','unit_cost_ron'=>'1938.270000','net_bani'=>387654,'tax_bani'=>81407,'total_bani'=>469061,'inventory_bani'=>387654]);
        $document['audit']=[['action'=>'confirmed','before_json'=>'{"secret_cost":987.654}','after_json'=>'{"secret_cost":456.789}','context_json'=>'{"cost_context":654.987}']];
        $exporter=new NirExportService(dirname(__DIR__,2));$file=$exporter->individual($document,'xlsx',false);$this->exports[]=$file['path'];
        $book=IOFactory::load($file['path']);$sheet=$book->getSheetByName('Poziții NIR');
        self::assertNull($sheet->getCell('BY2')->getValue());self::assertNull($sheet->getCell('CG2')->getValue());self::assertNull($sheet->getCell('DP2')->getValue());
        foreach (array_merge(range(77,94),range(118,125)) as $column) { self::assertNull($sheet->getCell([$column,2])->getValue(), 'Protected cost column '.$column); }
        self::assertCount(5,$book->getAllSheets());
        foreach($book->getAllSheets() as $tab){
            $values=json_encode($tab->toArray(null,false,false),JSON_THROW_ON_ERROR);
            foreach(['987.654','456.789','654.987','1938.27','3876.54','814.07','4690.61'] as $secret){self::assertStringNotContainsString($secret,$values,$tab->getTitle());}
            self::assertStringNotContainsString('FIFO',strtoupper($values),$tab->getTitle());
        }
        self::assertSame('Observații recepție',$book->getSheetByName('Rezumat NIR')->getCell('J22')->getValue());
        self::assertNull($book->getSheetByName('Rezumat NIR')->getCell('K23')->getValue());
        self::assertNull($book->getSheetByName('Rezumat NIR')->getCell('L23')->getValue());
        $book->disconnectWorksheets();
        $registry=$exporter->registry([$document],'xlsx',['from'=>'2026-11-01','to'=>'2026-11-30'],false);$this->exports[]=$registry['path'];
        $book=IOFactory::load($registry['path']);$sheet=$book->getActiveSheet();self::assertSame('C7',$sheet->getFreezePane());self::assertNull($sheet->getCell('X7')->getValue());self::assertSame('AL',$sheet->getHighestColumn());$book->disconnectWorksheets();
    }

    public function testPdfAndZipContainRealDocumentsAndOriginalFiles(): void
    {
        $root=dirname(__DIR__,2);$exporter=new NirExportService($root);$document=self::document();
        $source=$this->temporary.'/invoice.xml';file_put_contents($source,self::ubl());$storage=new NirDocumentStorage($root);$attachment=$storage->store(new UploadedFile($source,'original.xml','application/xml',filesize($source)));$document['attachments']=[$attachment];
        try{
            $pdf=$exporter->individual($document,'pdf');$this->exports[]=$pdf['path'];
            self::assertStringStartsWith('%PDF-',(string)file_get_contents($pdf['path'],false,null,0,8));self::assertGreaterThan(10000,filesize($pdf['path']));
            $file=$exporter->registry([$document],'zip',['from'=>'2026-11-01','to'=>'2026-11-30']);$this->exports[]=$file['path'];
            $zip=new \ZipArchive();self::assertTrue($zip->open($file['path']));self::assertNotFalse($zip->locateName('Registru_NIR.xlsx'));self::assertNotFalse($zip->locateName('NIR-2026-000001/NIR-2026-000001.pdf'));self::assertSame(self::ubl(),$zip->getFromName('NIR-2026-000001/documente/001_original.xml'));self::assertSame(4,$zip->numFiles);$zip->close();
            $restricted=$exporter->individual($document,'zip',false);$this->exports[]=$restricted['path'];
            self::assertTrue($zip->open($restricted['path']));self::assertFalse($zip->locateName('NIR-2026-000001/documente/001_original.xml'));self::assertNotFalse($zip->locateName('NIR-2026-000001/documente/ACCES_RESTRICTIONAT.txt'));$zip->close();
        }finally{$storage->remove($attachment['stored_name']);}
    }

    public static function document(): array
    {
        return ['id'=>1,'number'=>'NIR-2026-000001','type'=>'supplier_receipt','status'=>'confirmed','row_version'=>2,'nir_at'=>'2026-11-12 09:00:00','received_at'=>'2026-11-12 09:00:00','created_at'=>'2026-11-12 08:00:00','confirmed_at'=>'2026-11-12 09:05:00','created_by'=>1,'confirmed_by'=>1,'created_by_name'=>'Paula','confirmed_by_name'=>'Paula','supplier_id'=>1,'supplier'=>['company_name'=>'Atelier pentru copii SRL','tax_id'=>'RO12345678','address'=>'Strada Florilor nr. 4, Iași','email'=>'atelier@example.test'],'company'=>['legal_name'=>'La Măriuca Boutique','trade_name'=>'La Măriuca','tax_id'=>'12345678','trade_register'=>'J22/123/2020','registered_address'=>'Strada Grădinii nr. 8','city'=>'Iași'],'warehouse'=>['name'=>'Gestiune principală'],'location'=>['name'=>'Magazin La Măriuca','address'=>'Strada Grădinii nr. 8, Iași'],'invoice_series'=>'AT','invoice_number'=>'1001','invoice_date'=>'2026-11-12','currency'=>'RON','exchange_rate'=>'1.00000000','exchange_rate_date'=>'2026-11-12','notes'=>'O rochiță lipsește din colet. Furnizorul a fost contactat.','net_bani'=>9900,'tax_bani'=>2079,'total_bani'=>11979,'net_ron_bani'=>9900,'tax_ron_bani'=>2079,'total_ron_bani'=>11979,'inventory_bani'=>9900,'discount_bani'=>0,'attachments'=>[],'audit'=>[],'lines'=>[['id'=>1,'position'=>1,'product_id'=>1,'supplier_name'=>'Rochiță de zi „Ana”','supplier_code'=>'0000123','supplier_ean'=>'0123456789012','product_name'=>'Rochiță de zi „Ana”','sku'=>'LM-G-001','image_path'=>'assets/images/logo-la-mariuca-transparent.png','purchase_unit'=>'buc','stock_unit'=>'buc','invoice_quantity'=>'3.0000','received_quantity'=>'2.0000','accepted_quantity'=>'2.0000','rejected_quantity'=>'0.0000','stock_quantity'=>'2.0000','conversion_factor'=>'1.0000','unit_price'=>'49.500000','discount_percent'=>'0','vat_percent'=>'21','extra_cost_ron'=>'0','unit_cost_ron'=>'49.500000','net_bani'=>9900,'tax_bani'=>2079,'total_bani'=>11979,'inventory_bani'=>9900,'discount_bani'=>0,'difference_reason'=>'missing','difference_notes'=>'O bucată lipsă din colet.']]];
    }

    private static function ubl(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"><cbc:ID>F-1001</cbc:ID><cbc:IssueDate>2026-11-12</cbc:IssueDate><cbc:DocumentCurrencyCode>RON</cbc:DocumentCurrencyCode><cac:AccountingSupplierParty><cac:Party><cac:PartyTaxScheme><cbc:CompanyID>RO12345678</cbc:CompanyID></cac:PartyTaxScheme><cac:PartyLegalEntity><cbc:RegistrationName>Atelier SRL</cbc:RegistrationName></cac:PartyLegalEntity></cac:Party></cac:AccountingSupplierParty><cac:InvoiceLine><cbc:InvoicedQuantity unitCode="H87">3</cbc:InvoicedQuantity><cbc:LineExtensionAmount>18.00</cbc:LineExtensionAmount><cac:TaxTotal><cbc:TaxAmount>3.78</cbc:TaxAmount></cac:TaxTotal><cac:Item><cbc:Name>Rochiță Ana</cbc:Name><cac:SellersItemIdentification><cbc:ID>0000123</cbc:ID></cac:SellersItemIdentification><cac:ClassifiedTaxCategory><cbc:Percent>21</cbc:Percent></cac:ClassifiedTaxCategory></cac:Item><cac:Price><cbc:PriceAmount>12</cbc:PriceAmount><cbc:BaseQuantity>2</cbc:BaseQuantity></cac:Price></cac:InvoiceLine></Invoice>';
    }
}
