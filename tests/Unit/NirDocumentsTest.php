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

    public function testCostsAndAuditPayloadsAreOmittedFromRestrictedExports(): void
    {
        $document=self::document();$document['audit']=[['action'=>'confirmed','before_json'=>'{"secret_cost":987.654}','after_json'=>'{"secret_cost":456.789}']];
        $exporter=new NirExportService(dirname(__DIR__,2));$file=$exporter->individual($document,'xlsx',false);$this->exports[]=$file['path'];
        $book=IOFactory::load($file['path']);$sheet=$book->getSheetByName('Poziții NIR');
        self::assertNull($sheet->getCell('BY2')->getValue());self::assertNull($sheet->getCell('CG2')->getValue());self::assertNull($sheet->getCell('DP2')->getValue());
        $all='';foreach($book->getAllSheets() as $tab){$all.=json_encode($tab->toArray());}
        self::assertStringNotContainsString('987.654',$all);self::assertStringNotContainsString('456.789',$all);$book->disconnectWorksheets();
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
