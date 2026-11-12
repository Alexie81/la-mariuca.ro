<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Companies\CompanyAdminService;
use App\Modules\Companies\CompanyRepository;
use App\Modules\Companies\CompanyStampStorage;
use App\Modules\Inventory\NirDocumentStorage;
use App\Modules\Inventory\NirService;
use App\Modules\Suppliers\SupplierAdminService;
use App\Shared\Config\Config;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;
use Tests\DatabaseTestCase;

/** Full HTTP workflow against an isolated SQLite database; no emails or external API calls. */
final class NirWorkflowHttpAuditTest extends DatabaseTestCase
{
    private int $adminId;
    private int $operatorId;
    private int $supplierId;
    /** @var array<string,mixed> */
    private array $variant;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        foreach (['administrator'=>'adminId','operator'=>'operatorId'] as $role=>$property) {
            $statement=$this->pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES (:email,'disabled','QA','HTTP',:role,'active',:now,:now)");
            $statement->execute(['email'=>'nir-workflow-'.$role.'@example.test','role'=>$role,'now'=>gmdate('c')]);
            $this->{$property}=(int) $this->pdo->lastInsertId();
        }
        $_SESSION['user_id']=$this->adminId;
        $_SESSION['_csrf']=bin2hex(random_bytes(32));
        $companies=new CompanyAdminService(new CompanyRepository($this->pdo),new CompanyStampStorage($this->root));
        $id=$companies->saveCompany(['legal_name'=>'Firma audit HTTP','registered_address'=>'Strada Audit 1','physical_address'=>'Depozit audit'],null,null);
        $companies->saveWarehouse($id,['name'=>'Depozit HTTP'],null);
        $this->supplierId=(new SupplierAdminService($this->pdo))->save(['alias'=>'Furnizor HTTP','company_name'=>'Furnizor HTTP SRL','tax_id'=>'12345678']);
        $this->variant=$this->pdo->query('SELECT v.id,v.product_id,v.sku FROM product_variants v JOIN products p ON p.id=v.product_id WHERE p.track_in_conta=1 ORDER BY v.id LIMIT 1')->fetch();
    }

    protected function tearDown(): void
    {
        $storage=new NirDocumentStorage($this->root);
        foreach ($this->pdo->query('SELECT stored_name FROM nir_attachments')->fetchAll() as $row) { $storage->remove((string) $row['stored_name']); }
        foreach ($this->files as $path) { if (is_file($path)) { unlink($path); } }
        parent::tearDown();
    }

    public function testReadOnlyPagesAndEveryActionFormHaveWorkingRoutesAndCurrentContext(): void
    {
        $draft=$this->service()->save(null,$this->input('ROUTES'),$this->actor());
        $base='/admin/nir-uri/'.$draft['id'].'/';
        foreach (['/admin/nir-uri/','/admin/nir-uri/stoc/',$base] as $path) {
            $response=$this->request('GET',$path);
            self::assertSame(200,$response->getStatusCode(),$path.' '.substr((string)$response->getBody(),0,300));
            self::assertSame('no-store',$response->getHeaderLine('Cache-Control'));
        }
        foreach (['nou','sold-initial','export',$draft['id'].'/editare',$draft['id'].'/confirma',$draft['id'].'/sterge',$draft['id'].'/documente'] as $suffix) {
            foreach (['/','/modal/'] as $ending) {
                $path='/admin/nir-uri/'.$suffix.$ending;
                $response=$this->request('GET',$path);
                self::assertSame(200,$response->getStatusCode(),$path.' '.substr((string)$response->getBody(),0,300));
                $xpath=$this->html((string)$response->getBody());
                self::assertGreaterThan(0,$xpath->query('//form')->length,$path);
                self::assertSame(0,$xpath->query('//form[contains(@action,"/modal/")]')->length,$path);
            }
        }
        self::assertSame(1,$this->rowCount('nir_documents'));
        self::assertSame(0,$this->rowCount('nir_stock_movements'));
        self::assertSame(0,$this->rowCount('nir_fifo_layers'));
    }

    public function testEveryMutationRejectsInvalidCsrfBeforeChangingAnything(): void
    {
        $draft=$this->service()->save(null,$this->input('CSRF'),$this->actor());
        $paths=['nou/','sold-initial/','retururi/99/receptie/','retururi/99/amanare/','referinte/99/reasociere/','asociere/','furnizor/','locatii/','import/'];
        foreach (['editare/','corectie/','confirma/','sterge/','storno/','documente/','documente/99/sterge/'] as $path) { $paths[]=$draft['id'].'/'.$path; }
        $before=$this->rowCount('nir_audit');
        foreach ($paths as $path) {
            $response=$this->request('POST','/admin/nir-uri/'.$path,['_csrf'=>'invalid'],true);
            self::assertSame(419,$response->getStatusCode(),$path.' '.(string)$response->getBody());
        }
        self::assertSame(1,$this->rowCount('nir_documents'));
        self::assertSame($before,$this->rowCount('nir_audit'));
        self::assertSame(0,$this->rowCount('nir_fifo_layers'));
    }

    public function testOperatorCannotUseAnyPrivilegedEndpoint(): void
    {
        $draft=$this->service()->save(null,$this->input('PERMISSIONS'),$this->actor());
        $_SESSION['user_id']=$this->operatorId;
        $paths=['nou/','sold-initial/','retururi/99/receptie/','referinte/99/reasociere/','furnizor/','locatii/','import/'];
        foreach (['editare/','corectie/','confirma/','sterge/','storno/','documente/','documente/99/sterge/'] as $path) { $paths[]=$draft['id'].'/'.$path; }
        foreach ($paths as $path) {
            $response=$this->request('POST','/admin/nir-uri/'.$path,[],true);
            self::assertSame(403,$response->getStatusCode(),$path.' '.(string)$response->getBody());
        }
        foreach (['stoc/','nou/','sold-initial/','export/','export/xlsx/',$draft['id'].'/export/pdf/'] as $path) {
            self::assertSame(403,$this->request('GET','/admin/nir-uri/'.$path,[],true)->getStatusCode(),$path);
        }
        self::assertSame(200,$this->request('GET','/admin/nir-uri/produse/?q='.urlencode($this->variant['sku']),[],true)->getStatusCode());
        self::assertSame(1,$this->rowCount('nir_documents'));
    }

    public function testDraftLifecycleWithoutJavascriptKeepsValuesAndNeverChangesStockUntilConfirmed(): void
    {
        $input=$this->input('NO-JS');
        $input['lines'][0]['variant_id_fallback']=$this->variant['id'];
        $input['lines'][0]['variant_id']='';
        $response=$this->request('POST','/admin/nir-uri/nou/',$input);
        self::assertSame(303,$response->getStatusCode(),(string)$response->getBody());
        $id=(int)$this->pdo->query('SELECT id FROM nir_documents')->fetchColumn();
        self::assertSame('/admin/nir-uri/'.$id.'/',$response->getHeaderLine('Location'));
        $document=$this->service()->document($id,$this->actor());
        self::assertSame((int)$this->variant['id'],(int)$document['lines'][0]['variant_id']);
        self::assertSame(0,$this->rowCount('nir_stock_movements'));
        $input['row_version']=$document['row_version'];
        $input['notes']='Verificat și păstrat';
        self::assertSame(303,$this->request('POST','/admin/nir-uri/'.$id.'/editare/',$input)->getStatusCode());
        $document=$this->service()->document($id,$this->actor());
        self::assertSame('Verificat și păstrat',$document['notes']);
        self::assertSame(303,$this->request('POST','/admin/nir-uri/'.$id.'/sterge/',['row_version'=>$document['row_version']])->getStatusCode());
        self::assertSame(0,$this->rowCount('nir_documents'));
        self::assertSame(0,$this->rowCount('nir_stock_movements'));
    }

    public function testAddingSupplierAndLocationWithoutJavascriptPreservesTheUnsavedReceipt(): void
    {
        $input=$this->input('UNSAVED-KEEP');
        $input['intent']='add_supplier';
        $input['new_supplier']=['alias'=>'Nou inline','company_name'=>'Nou inline SRL','email'=>'furnizor@example.test'];
        $response=$this->request('POST','/admin/nir-uri/nou/',$input);
        self::assertSame(200,$response->getStatusCode(),(string)$response->getBody());
        $xpath=$this->html((string)$response->getBody());
        self::assertSame('UNSAVED-KEEP',$xpath->evaluate('string(//input[@name="invoice_number"]/@value)'));
        $newSupplier=(int)$this->pdo->query("SELECT id FROM suppliers WHERE alias='Nou inline'")->fetchColumn();
        self::assertSame((string)$newSupplier,$xpath->evaluate('string(//select[@name="supplier_id"]/option[@selected]/@value)'));
        $input['intent']='add_location';
        $input['new_location']=['name'=>'Raft test','address'=>'Rândul doi'];
        $response=$this->request('POST','/admin/nir-uri/nou/',$input);
        self::assertSame(200,$response->getStatusCode(),(string)$response->getBody());
        $xpath=$this->html((string)$response->getBody());
        self::assertSame('UNSAVED-KEEP',$xpath->evaluate('string(//input[@name="invoice_number"]/@value)'));
        $location=(int)$this->pdo->query("SELECT id FROM nir_locations WHERE name='Raft test'")->fetchColumn();
        self::assertSame((string)$location,$xpath->evaluate('string(//select[@name="location_id"]/option[@selected]/@value)'));
        self::assertSame(0,$this->rowCount('nir_documents'));
    }

    public function testInlineValidationAndSuccessfulJsonSupplierAndLocationResponses(): void
    {
        self::assertSame(422,$this->request('POST','/admin/nir-uri/furnizor/',['alias'=>'Incomplete'],true)->getStatusCode());
        self::assertSame(422,$this->request('POST','/admin/nir-uri/furnizor/',['alias'=>'Bad email','company_name'=>'Bad SRL','email'=>'invalid'],true)->getStatusCode());
        $supplier=$this->json($this->request('POST','/admin/nir-uri/furnizor/',['alias'=>'JSON supplier','company_name'=>'JSON SRL'],true));
        self::assertTrue($supplier['success']);
        self::assertSame('JSON supplier',$supplier['supplier']['alias']);
        self::assertNotEmpty($supplier['suppliers']);
        $input=$this->input('INLINE');
        self::assertSame(422,$this->request('POST','/admin/nir-uri/locatii/',['warehouse_id'=>$input['warehouse_id'],'name'=>''],true)->getStatusCode());
        self::assertSame(404,$this->request('POST','/admin/nir-uri/locatii/',['warehouse_id'=>99999,'name'=>'Missing'],true)->getStatusCode());
        $location=$this->json($this->request('POST','/admin/nir-uri/locatii/',['warehouse_id'=>$input['warehouse_id'],'name'=>'Raft JSON'],true));
        self::assertTrue($location['success']);
        self::assertSame('Raft JSON',$location['location']['name']);
        self::assertNotEmpty($location['locations']);
    }

    public function testMalformedLineContainersReturnValidationInsteadOfServerErrors(): void
    {
        foreach (['wrong',['wrong'],[['variant_id_fallback'=>['wrong']]]] as $lines) {
            $input=$this->input('BAD-LINES');
            $input['lines']=$lines;
            $response=$this->request('POST','/admin/nir-uri/nou/',$input,true);
            self::assertSame(422,$response->getStatusCode(),(string)$response->getBody());
        }
        self::assertSame(0,$this->rowCount('nir_documents'));
    }

    public function testExactSkuLookupAndAssociationReturnTheSelectedProductWithoutMutation(): void
    {
        $found=$this->json($this->request('GET','/admin/nir-uri/produse/?q='.urlencode($this->variant['sku']),[],true));
        self::assertCount(1,$found['items']);
        self::assertSame((int)$this->variant['id'],(int)$found['items'][0]['variant_id']);
        self::assertArrayHasKey('image_url',$found['items'][0]);
        $match=$this->json($this->request('POST','/admin/nir-uri/asociere/',['supplier_id'=>$this->supplierId,'line'=>['internal_sku'=>$this->variant['sku'],'supplier_name'=>'Denumire din factură']],true));
        self::assertSame((int)$this->variant['id'],(int)$match['product']['variant_id']);
        self::assertSame(0,$this->rowCount('nir_documents'));
        self::assertSame(0,$this->rowCount('nir_supplier_references'));
    }

    public function testRonRateAndInvalidRateRequestsDoNotNeedAnExternalConnection(): void
    {
        $quote=$this->json($this->request('GET','/admin/nir-uri/curs/?currency=RON&date=2026-01-12',[],true));
        self::assertTrue($quote['success']);
        self::assertSame('1.00000000',$quote['rate']);
        foreach (['currency=RON&date=2026-02-30','currency=BAD!&date=2026-01-01','currency=EUR&date=2999-01-01'] as $query) {
            self::assertSame(422,$this->request('GET','/admin/nir-uri/curs/?'.$query,[],true)->getStatusCode(),$query);
        }
    }

    public function testOpeningStockValidationPreservesNoJavascriptInputAndCanBeRetried(): void
    {
        $defaults=$this->input('OPENING');
        $input=['warehouse_id'=>$defaults['warehouse_id'],'variant_id'=>$this->variant['id'],'quantity'=>'3.5','unit_cost_ron'=>'12.34','date'=>'2026-01-15','reason'=>'','idempotency_key'=>'http-opening-audit'];
        $response=$this->request('POST','/admin/nir-uri/sold-initial/',$input);
        self::assertSame(422,$response->getStatusCode(),(string)$response->getBody());
        $xpath=$this->html((string)$response->getBody());
        self::assertSame('3.5',$xpath->evaluate('string(//input[@name="quantity"]/@value)'));
        self::assertSame('12.34',$xpath->evaluate('string(//input[@name="unit_cost_ron"]/@value)'));
        self::assertSame((string)$this->variant['id'],$xpath->evaluate('string(//select[@name="variant_id"]/option[@selected]/@value)'));
        $input['reason']='Inventar fizic documentat';
        $saved=$this->json($this->request('POST','/admin/nir-uri/sold-initial/',$input,true));
        self::assertSame('opening_balance',$saved['document']['type']);
        self::assertSame('confirmed',$saved['document']['status']);
        $again=$this->json($this->request('POST','/admin/nir-uri/sold-initial/',$input,true));
        self::assertSame($saved['document']['id'],$again['document']['id']);
        self::assertSame(1,$this->rowCount('nir_fifo_layers'));
        self::assertSame(35000,(int)$this->pdo->query('SELECT quantity_units FROM nir_stock_balances')->fetchColumn());
    }

    public function testImportsOnlySuggestDataAndDoNotPersistProductsOrStock(): void
    {
        $path=$this->temporary('.xlsx');
        $book=new Spreadsheet();
        $book->getActiveSheet()->fromArray([['Denumire produs','Cod furnizor','Cantitate','Pret unitar','TVA'],['Din import',$this->variant['sku'],'2','12.34','21']]);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $products=$this->rowCount('products');
        $response=$this->request('POST','/admin/nir-uri/import/',['supplier_id'=>$this->supplierId],true,['file'=>new UploadedFile($path,'factura.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',filesize($path))]);
        $data=$this->json($response);
        self::assertTrue($data['success']);
        self::assertCount(1,$data['lines']);
        self::assertSame('Din import',$data['lines'][0]['supplier_name']);
        self::assertFalse($data['lines'][0]['match']['matched'],'An unfamiliar supplier SKU must not silently become an internal SKU association.');
        self::assertSame(0,$this->rowCount('nir_documents'));
        self::assertSame(0,$this->rowCount('nir_attachments'));
        self::assertSame(0,$this->rowCount('nir_fifo_layers'));
        self::assertSame($products,$this->rowCount('products'));
        self::assertSame(422,$this->request('POST','/admin/nir-uri/import/',[],true)->getStatusCode());
    }

    public function testAttachDownloadDetachAndDeleteDraftHaveTheExpectedFileLifecycle(): void
    {
        $draft=$this->service()->save(null,$this->input('FILES'),$this->actor());
        $content='<?xml version="1.0"?><audit>'.bin2hex(random_bytes(12)).'</audit>';
        $path=$this->temporary('.xml'); file_put_contents($path,$content);
        $saved=$this->json($this->request('POST','/admin/nir-uri/'.$draft['id'].'/documente/',['row_version'=>$draft['row_version']],true,['attachments'=>[new UploadedFile($path,'Dosar recepție.xml','application/xml',strlen($content))]]));
        $document=$saved['document']; $file=$document['attachments'][0];
        $detail=$this->request('GET','/admin/nir-uri/'.$document['id'].'/');
        self::assertStringNotContainsString('Istoricul recepției',(string)$detail->getBody());
        self::assertContains('attachment_added',array_column($this->service()->document((int)$document['id'],$this->actor())['audit'],'action'));
        $download=$this->request('GET','/admin/nir-uri/'.$document['id'].'/documente/'.$file['id'].'/');
        self::assertSame(200,$download->getStatusCode());
        self::assertSame($content,(string)$download->getBody());
        self::assertSame('private, no-store',$download->getHeaderLine('Cache-Control'));
        self::assertSame('nosniff',$download->getHeaderLine('X-Content-Type-Options'));
        foreach (['/','/modal/'] as $suffix) {
            self::assertSame(200,$this->request('GET','/admin/nir-uri/'.$document['id'].'/documente/'.$file['id'].'/sterge'.$suffix)->getStatusCode());
        }
        $storedPath=(new NirDocumentStorage($this->root))->path($file['stored_name']);
        self::assertNotNull($storedPath);
        self::assertSame(409,$this->request('POST','/admin/nir-uri/'.$document['id'].'/documente/'.$file['id'].'/sterge/',['row_version'=>0],true)->getStatusCode());
        self::assertFileExists($storedPath);
        $deleted=$this->json($this->request('POST','/admin/nir-uri/'.$document['id'].'/documente/'.$file['id'].'/sterge/',['row_version'=>$document['row_version']],true));
        self::assertSame([],$deleted['document']['attachments']);
        self::assertStringNotContainsString('Istoricul recepției',(string)$this->request('GET','/admin/nir-uri/'.$document['id'].'/')->getBody());
        self::assertContains('attachment_deleted',array_column($this->service()->document((int)$document['id'],$this->actor())['audit'],'action'));
        self::assertFileDoesNotExist($storedPath);
        self::assertSame(404,$this->request('GET','/admin/nir-uri/'.$document['id'].'/documente/'.$file['id'].'/',[],true)->getStatusCode());
    }

    public function testLargeWorkbookCanBeAttachedDownloadedAndImportedWithoutChangingStock(): void
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([['Denumire', 'Cantitate', 'Preț'], ['Produs de test', 2, 85]]);
        $path = $this->temporary('.xlsx');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $payload = $this->temporary('.bin');
        $handle = fopen($payload, 'wb');
        ftruncate($handle, 17 * 1024 * 1024);
        fclose($handle);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $zip->addFile($payload, 'xl/media/original.bin');
        $zip->setCompressionName('xl/media/original.bin', \ZipArchive::CM_STORE);
        $zip->close();
        clearstatcache(true, $path);
        $size = filesize($path);
        self::assertGreaterThan(15 * 1024 * 1024, $size);
        $draft = $this->service()->save(null, $this->input('LARGE-XLSX'), $this->actor());
        $saved = $this->json($this->request('POST', '/admin/nir-uri/' . $draft['id'] . '/documente/', ['row_version' => $draft['row_version']], true, ['attachments' => [new UploadedFile($path, 'catalog.xlsx', 'application/zip', $size)]]));
        $file = $saved['document']['attachments'][0];
        self::assertSame($size, (int) $file['size_bytes']);
        self::assertSame(hash_file('sha256', $path), $file['sha256']);
        $download = $this->request('GET', '/admin/nir-uri/' . $draft['id'] . '/documente/' . $file['id'] . '/');
        self::assertSame(200, $download->getStatusCode());
        $hash = hash_init('sha256');
        $body = $download->getBody();
        while (!$body->eof()) { hash_update($hash, $body->read(65536)); }
        self::assertSame($file['sha256'], hash_final($hash));
        $imported = $this->json($this->request('POST', '/admin/nir-uri/import/', [], true, ['file' => new UploadedFile($path, 'catalog.xlsx', 'application/zip', $size)]));
        self::assertSame('Produs de test', $imported['lines'][0]['supplier_name']);
        self::assertSame(1, $this->rowCount('nir_attachments'));
        self::assertSame(0, $this->rowCount('nir_fifo_layers'));
    }

    public function testConfirmedReceiptOffersCorrectionAndReturnButCannotBeDeletedOrEditedAsDraft(): void
    {
        $draft=$this->service()->save(null,$this->input('CONFIRMED'),$this->actor());
        $confirmed=$this->json($this->request('POST','/admin/nir-uri/'.$draft['id'].'/confirma/',['row_version'=>$draft['row_version'],'idempotency_key'=>'http-confirm-audit','accept_warnings'=>1],true))['document'];
        self::assertSame('confirmed',$confirmed['status']);
        foreach (['corectie','storno'] as $action) {
            foreach (['/','/modal/'] as $suffix) { self::assertSame(200,$this->request('GET','/admin/nir-uri/'.$draft['id'].'/'.$action.$suffix)->getStatusCode()); }
        }
        self::assertSame(303,$this->request('GET','/admin/nir-uri/'.$draft['id'].'/editare/')->getStatusCode());
        self::assertSame(409,$this->request('POST','/admin/nir-uri/'.$draft['id'].'/sterge/',['row_version'=>$confirmed['row_version']],true)->getStatusCode());
        self::assertSame(1,$this->rowCount('nir_documents'));
    }

    public function testIndividualAndRegistryExportsProduceRealFilesAndHonourSearch(): void
    {
        $draft=$this->service()->save(null,$this->input('EXPORT-INCLUDE'),$this->actor());
        $this->service()->save(null,$this->input('EXPORT-EXCLUDE'),$this->actor());
        foreach (['pdf'=>'%PDF-','xlsx'=>'PK','zip'=>'PK'] as $format=>$signature) {
            $response=$this->request('GET','/admin/nir-uri/'.$draft['id'].'/export/'.$format.'/');
            self::assertSame(200,$response->getStatusCode(),substr((string)$response->getBody(),0,300));
            self::assertStringStartsWith($signature,(string)$response->getBody());
            self::assertStringContainsString('attachment;',$response->getHeaderLine('Content-Disposition'));
        }
        $response=$this->request('GET','/admin/nir-uri/export/xlsx/?search=EXPORT-INCLUDE');
        $path=$this->temporary('.xlsx'); file_put_contents($path,(string)$response->getBody());
        $book=IOFactory::load($path); $text=json_encode($book->getActiveSheet()->toArray(),JSON_THROW_ON_ERROR); $book->disconnectWorksheets();
        self::assertStringContainsString('EXPORT-INCLUDE',$text);
        self::assertStringNotContainsString('EXPORT-EXCLUDE',$text);
        self::assertStringContainsString('QA HTTP',$text);
        self::assertSame(200,$this->request('GET','/admin/nir-uri/export/zip/?period=all')->getStatusCode());
    }

    public function testRegistryPaginationSearchAndStatusFiltersRemainStable(): void
    {
        for ($i=1;$i<=7;++$i) { $this->service()->save(null,$this->input('PAGE-'.sprintf('%02d',$i)),$this->actor()); }
        $response=$this->request('GET','/admin/nir-uri/?cautare=PAGE&status=draft&per_page=5&page=1');
        self::assertSame(200,$response->getStatusCode());
        $xpath=$this->html((string)$response->getBody());
        self::assertGreaterThan(0,$xpath->query('//a[contains(@href,"page=2") and contains(@href,"cautare=PAGE") and contains(@href,"status=draft")]')->length);
        $response=$this->request('GET','/admin/nir-uri/?cautare=PAGE-01');
        self::assertStringContainsString('PAGE-01',(string)$response->getBody());
        self::assertStringNotContainsString('PAGE-02',(string)$response->getBody());
        self::assertSame(200,$this->request('GET','/admin/nir-uri/?page=999999')->getStatusCode());
        $response=$this->request('GET','/admin/nir-uri/?status=confirmed');
        self::assertStringNotContainsString('PAGE-01',(string)$response->getBody());
    }

    public function testMissingDocumentsAndUnknownReturnActionsAreClear404Responses(): void
    {
        foreach (['99999/','99999/editare/','99999/confirma/modal/','99999/documente/99/','99999/documente/99/sterge/modal/','99999/export/xlsx/','retururi/99999/receptie/modal/'] as $suffix) {
            $response=$this->request('GET','/admin/nir-uri/'.$suffix,[],true);
            self::assertSame(404,$response->getStatusCode(),$suffix.' '.(string)$response->getBody());
        }
    }

    public function testStockScreenAndLegacyUrlHideTheInternalAllocationTerminology(): void
    {
        $response=$this->request('GET','/admin/nir-uri/fifo/?warehouse_id=1');
        self::assertSame(301,$response->getStatusCode());
        self::assertSame('/admin/nir-uri/stoc/?warehouse_id=1',$response->getHeaderLine('Location'));
        foreach (['/admin/nir-uri/','/admin/nir-uri/stoc/','/admin/nir-uri/sold-initial/modal/'] as $url) {
            $response=$this->request('GET',$url);
            self::assertSame(200,$response->getStatusCode());
            $xpath=$this->html((string)$response->getBody());
            self::assertStringNotContainsString('FIFO',$xpath->evaluate('string(//body)'),$url);
        }
    }

    public function testRejectedUploadPreservesTheDraftFormAndClearsAnyEarlierStoredFile(): void
    {
        $input=$this->input('UPLOAD-KEEP');
        $valid='<?xml version="1.0"?><audit>'.bin2hex(random_bytes(16)).'</audit>';
        $path=$this->temporary('.xml'); file_put_contents($path,$valid);
        $bad=$this->temporary('.exe'); file_put_contents($bad,'Not an accepted document');
        $response=$this->request('POST','/admin/nir-uri/nou/',$input,false,['attachments'=>[
            new UploadedFile($path,'valid.xml','application/xml',strlen($valid)),
            new UploadedFile($bad,'invalid.exe','application/octet-stream',filesize($bad)),
        ]]);
        self::assertSame(422,$response->getStatusCode(),(string)$response->getBody());
        $xpath=$this->html((string)$response->getBody());
        self::assertSame('UPLOAD-KEEP',$xpath->evaluate('string(//input[@name="invoice_number"]/@value)'));
        self::assertSame('Produs verificat HTTP',$xpath->evaluate('string(//input[@name="lines[0][supplier_name]"]/@value)'));
        self::assertSame(0,$this->rowCount('nir_documents'));
        self::assertSame(0,$this->rowCount('nir_attachments'));
        foreach (glob($this->root.'/storage/private/nir-documents/*') ?: [] as $file) {
            if (is_file($file)) { self::assertNotSame(hash('sha256',$valid),hash_file('sha256',$file),'A failed multipart submission must remove previously stored files.'); }
        }
    }

    public function testSupplierReferenceCanBeReassignedWithReasonWithoutChangingHistoricDocuments(): void
    {
        $input=$this->input('REFERENCE'); $input['lines'][0]['save_reference']=1;
        $draft=$this->service()->save(null,$input,$this->actor());
        $reference=(int)$this->pdo->query('SELECT id FROM nir_supplier_references')->fetchColumn();
        self::assertGreaterThan(0,$reference);
        $other=$this->pdo->query('SELECT id,product_id FROM product_variants WHERE id<>'.(int)$this->variant['id'].' ORDER BY id LIMIT 1')->fetch();
        self::assertSame(422,$this->request('POST','/admin/nir-uri/referinte/'.$reference.'/reasociere/',['variant_id'=>$other['id']],true)->getStatusCode());
        $response=$this->json($this->request('POST','/admin/nir-uri/referinte/'.$reference.'/reasociere/',['variant_id'=>$other['id'],'reason'=>'Furnizorul a refolosit codul'],true));
        self::assertSame('/admin/nir-uri/stoc/',$response['redirect']);
        $match=$this->json($this->request('POST','/admin/nir-uri/asociere/',['supplier_id'=>$this->supplierId,'line'=>['supplier_code'=>'AUDIT-01']],true));
        self::assertSame((int)$other['id'],(int)$match['product']['variant_id']);
        self::assertSame((int)$this->variant['id'],(int)$this->service()->document((int)$draft['id'],$this->actor())['lines'][0]['variant_id']);
    }

    public function testXmlImportAutoselectsOnlyTheUniqueSupplierAndRejectsUnsafeXml(): void
    {
        $xml=<<<'XML'
<?xml version="1.0"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:ID>XML-AUDIT-01</cbc:ID><cbc:IssueDate>2026-01-12</cbc:IssueDate><cbc:DocumentCurrencyCode>RON</cbc:DocumentCurrencyCode>
  <cac:AccountingSupplierParty><cac:Party><cac:PartyTaxScheme><cbc:CompanyID>RO12345678</cbc:CompanyID></cac:PartyTaxScheme></cac:Party></cac:AccountingSupplierParty>
  <cac:InvoiceLine><cbc:ID>1</cbc:ID><cbc:InvoicedQuantity unitCode="H87">2</cbc:InvoicedQuantity><cbc:LineExtensionAmount>20</cbc:LineExtensionAmount><cac:Item><cbc:Name>Produs XML</cbc:Name><cac:ClassifiedTaxCategory><cbc:Percent>21</cbc:Percent></cac:ClassifiedTaxCategory></cac:Item><cac:Price><cbc:PriceAmount>10</cbc:PriceAmount></cac:Price></cac:InvoiceLine>
</Invoice>
XML;
        $path=$this->temporary('.xml'); file_put_contents($path,$xml);
        $response=$this->json($this->request('POST','/admin/nir-uri/import/',[],true,['file'=>new UploadedFile($path,'factura.xml','application/xml',strlen($xml))]));
        self::assertSame($this->supplierId,$response['header']['supplier_id']);
        self::assertSame('XML-AUDIT-01',$response['header']['invoice_number']);
        self::assertCount(1,$response['lines']);
        $unsafe='<?xml version="1.0"?><!DOCTYPE invoice [<!ENTITY leak SYSTEM "file:///never-read">]><invoice>&leak;</invoice>';
        $path=$this->temporary('.xml'); file_put_contents($path,$unsafe);
        self::assertSame(422,$this->request('POST','/admin/nir-uri/import/',[],true,['file'=>new UploadedFile($path,'unsafe.xml','application/xml',strlen($unsafe))])->getStatusCode());
        self::assertSame(0,$this->rowCount('nir_documents'));
        self::assertSame(0,$this->rowCount('nir_attachments'));
    }

    public function testCustomerReturnHttpFlowRequiresPhysicalReceiptPreservesFieldsAndIsIdempotent(): void
    {
        [$returnId,$document]=$this->returnFixture();
        foreach (['/','/modal/'] as $suffix) {
            $response=$this->request('GET','/admin/nir-uri/retururi/'.$returnId.'/receptie'.$suffix);
            self::assertSame(200,$response->getStatusCode(),(string)$response->getBody());
            self::assertStringContainsString('physically_received',(string)$response->getBody());
        }
        $input=['warehouse_id'=>$document['warehouse_id'],'location_id'=>$document['location_id'],'invoice_number'=>'RETUR-CLIENT-HTTP','invoice_date'=>'2026-11-12','idempotency_key'=>'http-receive-client-return'];
        $response=$this->request('POST','/admin/nir-uri/retururi/'.$returnId.'/receptie/',$input);
        self::assertSame(422,$response->getStatusCode(),(string)$response->getBody());
        $xpath=$this->html((string)$response->getBody());
        self::assertSame('RETUR-CLIENT-HTTP',$xpath->evaluate('string(//input[@name="invoice_number"]/@value)'));
        self::assertSame('2026-11-12',$xpath->evaluate('string(//input[@name="invoice_date"]/@value)'));
        self::assertSame((string)$document['location_id'],$xpath->evaluate('string(//select[@name="location_id"]/option[@selected]/@value)'));
        self::assertSame(1,$this->rowCount('nir_documents'));
        $input['physically_received']='1';
        $result=$this->json($this->request('POST','/admin/nir-uri/retururi/'.$returnId.'/receptie/',$input,true));
        self::assertSame('customer_return',$result['document']['type']);
        self::assertSame('confirmed',$result['document']['status']);
        $retry=$this->json($this->request('POST','/admin/nir-uri/retururi/'.$returnId.'/receptie/',$input,true));
        self::assertSame($result['document']['id'],$retry['document']['id']);
        self::assertSame(2,$this->rowCount('nir_documents'));
        self::assertSame(20000,(int)$this->pdo->query('SELECT quantity_units FROM nir_stock_balances')->fetchColumn());
        self::assertSame(404,$this->request('GET','/admin/nir-uri/retururi/'.$returnId.'/receptie/modal/',[],true)->getStatusCode());
    }

    public function testDismissingAReturnReminderOnlyAffectsTheCurrentDayAndActor(): void
    {
        [$returnId]=$this->returnFixture();
        $result=$this->json($this->request('POST','/admin/nir-uri/retururi/'.$returnId.'/amanare/',[],true));
        self::assertSame('/admin/nir-uri/stoc/',$result['redirect']);
        $reminder=$this->pdo->query('SELECT * FROM nir_return_reminders')->fetch();
        self::assertSame($this->adminId,(int)$reminder['actor_id']);
        self::assertSame((new \DateTimeImmutable('now',new \DateTimeZone('Europe/Bucharest')))->format('Y-m-d'),$reminder['dismissed_on']);
        self::assertTrue($this->service()->pendingReturnReceipts($this->actor())[0]['dismissed']);
        self::assertSame(1,$this->rowCount('nir_documents'));
        self::assertSame(10000,(int)$this->pdo->query('SELECT quantity_units FROM nir_stock_balances')->fetchColumn());
    }

    public function testOperatorReadsQuantitiesButNeverPurchaseCostsReferencesOrAuditSnapshots(): void
    {
        $input=$this->input('COST-REDACTION');
        $input['lines'][0]['unit_price']='173.59';
        $input['lines'][0]['save_reference']=1;
        $document=$this->service()->save(null,$input,$this->actor());
        $document=$this->service()->confirm((int)$document['id'],(int)$document['row_version'],'redaction-confirm',true,$this->actor());
        $_SESSION['user_id']=$this->operatorId;
        foreach (['/admin/nir-uri/','/admin/nir-uri/'.$document['id'].'/'] as $url) {
            $response=$this->request('GET',$url);
            self::assertSame(200,$response->getStatusCode());
            $body=(string)$response->getBody();
            self::assertStringContainsString('COST-REDACTION',$body);
            self::assertStringNotContainsString('173,59',$body);
            self::assertStringNotContainsString('173.59',$body);
            self::assertStringNotContainsString('347,18',$body);
            self::assertStringNotContainsString('unit_price',$body);
            self::assertStringNotContainsString('before_json',$body);
            self::assertStringNotContainsString('/export/pdf/',$body);
            self::assertStringNotContainsString('/corectie/',$body);
        }
        $match=$this->json($this->request('POST','/admin/nir-uri/asociere/',['supplier_id'=>$this->supplierId,'line'=>['supplier_code'=>'AUDIT-01']],true));
        self::assertTrue($match['matched']);
        self::assertArrayNotHasKey('last_price',$match['reference']);
        self::assertStringNotContainsString('173.59',json_encode($match,JSON_THROW_ON_ERROR));
        self::assertSame(403,$this->request('GET','/admin/nir-uri/stoc/',[],true)->getStatusCode());
    }

    public function testInvalidNoJavascriptReturnShowsActualAvailableQuantityAfterPriorReturn(): void
    {
        $draft=$this->service()->save(null,$this->input('RETURN-REMAINING'),$this->actor());
        $document=$this->service()->confirm((int)$draft['id'],(int)$draft['row_version'],'return-remaining-confirm',true,$this->actor());
        $lineId=(int)$document['lines'][0]['id'];
        $input=['invoice_number'=>'RET-PARTIAL','invoice_date'=>$document['invoice_date'],'reason'=>'Ambalaj deteriorat','quantities'=>[$lineId=>'0.5']];
        $return=$this->service()->reverse((int)$document['id'],(int)$document['row_version'],$input,'first-partial-http',$this->actor());
        self::assertStringContainsString('Retur către furnizor',(string)$this->request('GET','/admin/nir-uri/'.$return['id'].'/')->getBody());
        self::assertContains('supplier_return',array_column($this->service()->document((int)$return['id'],$this->actor())['audit'],'action'));
        $document=$this->service()->document((int)$document['id'],$this->actor());
        $input['reason']=''; $input['row_version']=$document['row_version']; $input['idempotency_key']='second-partial-http';
        $response=$this->request('POST','/admin/nir-uri/'.$document['id'].'/storno/',$input);
        self::assertSame(422,$response->getStatusCode());
        $xpath=$this->html((string)$response->getBody());
        self::assertSame('1.5000',$xpath->evaluate('string(//input[@name="quantities['.$lineId.']"]/@max)'));
        self::assertSame('0.5',$xpath->evaluate('string(//input[@name="quantities['.$lineId.']"]/@value)'));
        self::assertSame(2,$this->rowCount('nir_documents'));
    }

    public function testEditingReceiptKeepsTheServerCalculatedPriceHistoryVisible(): void
    {
        $input=$this->input('HISTORY-ORIGINAL'); $input['lines'][0]['unit_price']='173.59';
        $first=$this->service()->save(null,$input,$this->actor());
        $this->service()->confirm((int)$first['id'],(int)$first['row_version'],'history-confirm',true,$this->actor());
        $second=$this->service()->save(null,$this->input('HISTORY-EDIT'),$this->actor());
        $response=$this->request('GET','/admin/nir-uri/'.$second['id'].'/editare/modal/');
        self::assertSame(200,$response->getStatusCode());
        $xpath=$this->html((string)$response->getBody());
        self::assertSame(1,$xpath->query('//*[@data-price-history and not(@hidden)]')->length);
        self::assertStringContainsString('173,59',$xpath->evaluate('string(//*[@data-price-history])'));
    }

    public function testInvalidRegistryDatesGiveReadableValidationForHtmlAndJson(): void
    {
        foreach (['from=wrong','to=2026-02-30','from=2026-11-12&to=2026-11-01'] as $query) {
            foreach ([false,true] as $json) {
                $response=$this->request('GET','/admin/nir-uri/?'.$query,[],$json);
                self::assertSame(422,$response->getStatusCode(),(string)$response->getBody());
                self::assertStringNotContainsString('Operațiunea nu a putut fi finalizată',(string)$response->getBody());
                if ($json) {
                    $data=json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR);
                    self::assertFalse($data['success']);
                    self::assertNotEmpty($data['errors']);
                } else {
                    self::assertStringContainsString('/admin/nir-uri/',(string)$response->getBody());
                }
            }
        }
    }

    public function testStockScreenUsesRealProductAndSupplierNamesIncludingHistoricSupplierSnapshot(): void
    {
        $input=$this->input('DISPLAY-NAMES'); $input['lines'][0]['save_reference']=1;
        $draft=$this->service()->save(null,$input,$this->actor());
        $document=$this->service()->confirm((int)$draft['id'],(int)$draft['row_version'],'display-names-confirm',true,$this->actor());
        $productName=$document['lines'][0]['product_name'];
        $this->pdo->prepare("UPDATE suppliers SET alias='Furnizor redenumit',status='inactive' WHERE id=:id")->execute(['id'=>$this->supplierId]);
        $response=$this->request('GET','/admin/nir-uri/stoc/');
        self::assertSame(200,$response->getStatusCode());
        $xpath=$this->html((string)$response->getBody());
        self::assertSame($productName,$xpath->evaluate('string(//section[contains(@class,"nir-fifo-panel")]//td[@data-label="Produs"]/strong)'));
        self::assertSame($this->variant['sku'],$xpath->evaluate('string(//section[contains(@class,"nir-fifo-panel")]//td[@data-label="Produs"]/small)'));
        self::assertStringContainsString('Furnizor redenumit · '.$productName,$xpath->evaluate('string(//details[.//form[contains(@class,"nir-reference-form")]]/summary)'));
        self::assertSame('Furnizor HTTP',$xpath->evaluate('string(//dl[contains(@class,"nir-info-grid")]/div[dt="Furnizor"]/dd)'),'Lot identity comes from the received document snapshot, not the current supplier alias or product invoice name.');
    }

    /** @return array{int,array<string,mixed>} */
    private function returnFixture(): array
    {
        $draft=$this->service()->save(null,$this->input('CUSTOMER-RETURN'),$this->actor());
        $confirmed=$this->service()->confirm((int)$draft['id'],(int)$draft['row_version'],'return-fixture-confirm',true,$this->actor());
        $_SESSION['cart_token']=bin2hex(random_bytes(32));
        $cart=new CartService($this->pdo); $cart->add((int)$this->variant['id'],1);
        $shipping=(int)$this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        $order=(new CheckoutService($this->pdo,$cart,Config::load($this->root)))->place(['email'=>'nir.audit@example.test','first_name'=>'Ana','last_name'=>'Audit','phone'=>'0700000000','address'=>'Strada Test 1','city'=>'București — Sector 1','county'=>'București','postal_code'=>'010101','shipping_method_id'=>(string)$shipping,'payment_method'=>'cod','idempotency_key'=>'http-return-order','terms'=>'1']);
        $itemId=(int)$this->pdo->query('SELECT id FROM order_items WHERE order_id='.(int)$order['id'].' LIMIT 1')->fetchColumn();
        $this->service()->consume((int)$confirmed['warehouse_id'],$confirmed['lines'][0],10000,'order_item',$itemId,'http-return-consume',$this->actor());
        $this->pdo->prepare("INSERT INTO order_returns(order_id,return_type,reason,notes,restock,refund_mode,refund_bani,actor_user_id,created_at) VALUES (:order,'partial','Mărime nepotrivită','',0,'none',0,:actor,:now)")->execute(['order'=>$order['id'],'actor'=>$this->adminId,'now'=>gmdate('c')]);
        $returnId=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO order_return_items(return_id,order_item_id,quantity,restocked_quantity,line_refund_bani) VALUES (:return,:item,1,0,0)')->execute(['return'=>$returnId,'item'=>$itemId]);
        return [$returnId,$confirmed];
    }

    /** @return array<string,mixed> */
    private function input(string $number): array
    {
        $input=$this->service()->defaults($this->actor())['values'];
        $input['supplier_id']=$this->supplierId; $input['invoice_number']=$number;
        $input['lines']=[['product_id'=>$this->variant['product_id'],'variant_id'=>$this->variant['id'],'supplier_name'=>'Produs verificat HTTP','supplier_code'=>'AUDIT-01','invoice_quantity'=>'2','received_quantity'=>'2','accepted_quantity'=>'2','rejected_quantity'=>'0','unit_price'=>'10','vat_percent'=>'21','conversion_factor'=>'1','purchase_unit'=>'buc','stock_unit'=>'buc','price_mode'=>'unit']];
        return $input;
    }

    private function service(): NirService { return new NirService($this->pdo); }
    /** @return array{id:int,role:string} */
    private function actor(): array { return ['id'=>$this->adminId,'role'=>'administrator']; }
    private function rowCount(string $table): int { return (int)$this->pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(); }
    private function temporary(string $extension): string { $path=sys_get_temp_dir().'/nir-workflow-'.bin2hex(random_bytes(8)).$extension; $this->files[]=$path; return $path; }

    /** @param array<string,mixed> $body @param array<string,mixed> $uploads */
    private function request(string $method,string $path,array $body=[],bool $json=false,array $uploads=[]): ResponseInterface
    {
        $request=(new ServerRequestFactory())->createServerRequest($method,'http://localhost'.$path,['REMOTE_ADDR'=>'127.0.0.1']);
        parse_str((string)parse_url($path,PHP_URL_QUERY),$query); $request=$request->withQueryParams($query);
        if ($method==='POST') { $request=$request->withParsedBody($body+['_csrf'=>$_SESSION['_csrf']]); }
        if ($json) { $request=$request->withHeader('Accept','application/json'); }
        if ($uploads!==[]) { $request=$request->withUploadedFiles($uploads); }
        $app=AppFactory::create($this->root); $response=$app->handle($request); unset($app); gc_collect_cycles(); return $response;
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        self::assertSame(200,$response->getStatusCode(),(string)$response->getBody());
        return json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR);
    }
    private function html(string $html): \DOMXPath
    {
        $previous=libxml_use_internal_errors(true); $document=new \DOMDocument(); $document->loadHTML('<?xml encoding="UTF-8">'.$html); libxml_clear_errors(); libxml_use_internal_errors($previous); return new \DOMXPath($document);
    }
}
