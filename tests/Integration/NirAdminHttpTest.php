<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Companies\CompanyAdminService;
use App\Modules\Companies\CompanyRepository;
use App\Modules\Companies\CompanyStampStorage;
use App\Modules\Inventory\NirService;
use App\Modules\Suppliers\SupplierAdminService;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class NirAdminHttpTest extends DatabaseTestCase
{
    private int $adminId;
    private int $operatorId;
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $now=gmdate('c');
        $statement=$this->pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES (:email,'disabled','Paula','NIR',:role,'active',:now,:now)");
        foreach (['administrator','operator'] as $role) {
            $statement->execute(['email'=>'nir-'.$role.'@example.test','role'=>$role,'now'=>$now]);
            if ($role==='administrator') { $this->adminId=(int) $this->pdo->lastInsertId(); } else { $this->operatorId=(int) $this->pdo->lastInsertId(); }
        }
        $_SESSION['user_id']=$this->adminId;
        $_SESSION['_csrf']=bin2hex(random_bytes(32));
        $company=new CompanyAdminService(new CompanyRepository($this->pdo),new CompanyStampStorage($this->root));
        $companyId=$company->saveCompany(['legal_name'=>'Firma NIR QA','registered_address'=>'Strada Exemplu 1','physical_address'=>'Depozit QA'],null,null);
        $company->saveWarehouse($companyId,['name'=>'Gestiune QA'],null);
    }

    public function testAdminRegistryFormsAndCsrfProtection(): void
    {
        $index=$this->request('GET','/admin/nir-uri/');
        self::assertSame(200,$index->getStatusCode(),(string) $index->getBody());
        self::assertStringContainsString('Adaugă NIR',(string) $index->getBody());
        $form=$this->request('GET','/admin/nir-uri/nou/modal/');
        self::assertSame(200,$form->getStatusCode(),(string) $form->getBody());
        self::assertStringContainsString('name="supplier_id"',(string) $form->getBody());
        self::assertStringContainsString('name="lines[0][accepted_quantity]"',(string) $form->getBody());
        $blocked=$this->request('POST','/admin/nir-uri/nou/',[],true);
        self::assertSame(419,$blocked->getStatusCode());
        self::assertSame(0,(int) $this->pdo->query('SELECT COUNT(*) FROM nir_documents')->fetchColumn());
    }

    public function testOperatorCannotMutateOrSeeCostsAndAnonymousCannotRead(): void
    {
        $_SESSION['user_id']=$this->operatorId;
        $registry=$this->request('GET','/admin/nir-uri/');
        self::assertSame(200,$registry->getStatusCode());
        self::assertStringNotContainsString('Adaugă NIR',(string) $registry->getBody());
        self::assertSame(403,$this->request('GET','/admin/nir-uri/nou/modal/',[],true)->getStatusCode());
        self::assertSame(403,$this->request('POST','/admin/nir-uri/nou/',['_csrf'=>$_SESSION['_csrf']],true)->getStatusCode());
        unset($_SESSION['user_id']);
        self::assertSame(303,$this->request('GET','/admin/nir-uri/')->getStatusCode());
    }

    public function testAddingRowWithoutJavascriptDoesNotPersistOrLoseValues(): void
    {
        $response=$this->request('POST','/admin/nir-uri/nou/',['_csrf'=>$_SESSION['_csrf'],'intent'=>'add_line','invoice_number'=>'Factura păstrată','lines'=>[['supplier_name'=>'Produs păstrat','unit_price'=>'12.34']]]);
        self::assertSame(200,$response->getStatusCode(),(string) $response->getBody());
        $html=(string) $response->getBody();
        self::assertStringContainsString('Factura păstrată',$html);
        self::assertStringContainsString('Produs păstrat',$html);
        self::assertStringContainsString('name="lines[1][accepted_quantity]"',$html);
        self::assertSame(0,(int) $this->pdo->query('SELECT COUNT(*) FROM nir_documents')->fetchColumn());
    }

    public function testSavingDraftAndConfirmingThroughHttpKeepsCommercialStock(): void
    {
        $service=new NirService($this->pdo);
        $defaults=$service->defaults(['id'=>$this->adminId,'role'=>'administrator']);
        $supplier=(new SupplierAdminService($this->pdo))->save(['alias'=>'Furnizor HTTP','company_name'=>'Furnizor HTTP SRL']);
        $variant=$this->pdo->query('SELECT v.id,v.product_id,COALESCE(i.physical_quantity,0) AS physical_quantity FROM product_variants v JOIN products p ON p.id=v.product_id LEFT JOIN inventory i ON i.variant_id=v.id WHERE p.track_in_conta=1 LIMIT 1')->fetch();
        $values=$defaults['values'];
        $values['supplier_id']=$supplier;
        $values['invoice_number']='HTTP-100';
        $values['lines']=[['product_id'=>$variant['product_id'],'variant_id'=>$variant['id'],'supplier_name'=>'Produs pentru recepție','supplier_code'=>'HTTP-01','invoice_quantity'=>'2','received_quantity'=>'2','accepted_quantity'=>'2','rejected_quantity'=>'0','unit_price'=>'10','vat_percent'=>'21','conversion_factor'=>'1','purchase_unit'=>'buc','stock_unit'=>'buc','price_mode'=>'unit']];
        $response=$this->request('POST','/admin/nir-uri/nou/',['_csrf'=>$_SESSION['_csrf']]+$values,true);
        self::assertSame(200,$response->getStatusCode(),(string) $response->getBody());
        $json=json_decode((string) $response->getBody(),true,512,JSON_THROW_ON_ERROR);
        $document=$json['document'];
        self::assertSame('draft',$document['status']);
        $confirm=$this->request('POST','/admin/nir-uri/'.$document['id'].'/confirma/',['_csrf'=>$_SESSION['_csrf'],'row_version'=>$document['row_version'],'idempotency_key'=>'http-confirm-key-001','accept_warnings'=>'1'],true);
        self::assertSame(200,$confirm->getStatusCode(),(string) $confirm->getBody());
        self::assertSame('confirmed',json_decode((string) $confirm->getBody(),true)['document']['status']);
        self::assertSame((int) $variant['physical_quantity'],(int) $this->pdo->query('SELECT physical_quantity FROM inventory WHERE variant_id='.(int) $variant['id'])->fetchColumn());
        $detail=$this->request('GET','/admin/nir-uri/'.$document['id'].'/');
        self::assertSame(200,$detail->getStatusCode(),(string) $detail->getBody());
    }

    public function testEditingCompactsStoredDecimalsAndPreservesManuallySubmittedPrecision(): void
    {
        $service = new NirService($this->pdo);
        $values = $service->defaults(['id'=>$this->adminId,'role'=>'administrator'])['values'];
        $values['supplier_id'] = (new SupplierAdminService($this->pdo))->save(['alias'=>'Decimal QA','company_name'=>'Decimal QA SRL']);
        $values['invoice_number'] = 'DECIMAL-01';
        $variant = $this->pdo->query('SELECT v.id,v.product_id FROM product_variants v JOIN products p ON p.id=v.product_id WHERE p.track_in_conta=1 LIMIT 1')->fetch();
        $values['lines'] = [['product_id'=>$variant['product_id'],'variant_id'=>$variant['id'],'supplier_name'=>'Precizie păstrată','invoice_quantity'=>'2','received_quantity'=>'2','accepted_quantity'=>'2','rejected_quantity'=>'0','unit_price'=>'85.125600','vat_percent'=>'21','discount_percent'=>'0','conversion_factor'=>'1','purchase_unit'=>'buc','stock_unit'=>'buc','price_mode'=>'unit']];
        $saved = $this->request('POST','/admin/nir-uri/nou/',['_csrf'=>$_SESSION['_csrf']]+$values,true);
        self::assertSame(200,$saved->getStatusCode(),(string) $saved->getBody());
        $document = json_decode((string) $saved->getBody(),true,512,JSON_THROW_ON_ERROR)['document'];
        foreach (['editare/','editare/modal/'] as $suffix) {
            $response = $this->request('GET','/admin/nir-uri/'.$document['id'].'/'.$suffix);
            self::assertSame(200,$response->getStatusCode());
            $html = (string) $response->getBody();
            foreach (['unit_price'=>'85.1256','vat_percent'=>'21','discount_percent'=>'0','accepted_quantity'=>'2','conversion_factor'=>'1'] as $field=>$expected) {
                self::assertStringContainsString('name="lines[0]['.$field.']" type="text" value="'.$expected.'"',$html);
            }
        }
        $submitted = $this->request('POST','/admin/nir-uri/nou/',['_csrf'=>$_SESSION['_csrf'],'intent'=>'add_line','lines'=>[['supplier_name'=>'Valori introduse manual','unit_price'=>'85,1200','vat_percent'=>'21.00','accepted_quantity'=>'2.500']]]);
        self::assertSame(200,$submitted->getStatusCode());
        foreach (['unit_price'=>'85,1200','vat_percent'=>'21.00','accepted_quantity'=>'2.500'] as $field=>$expected) {
            self::assertStringContainsString('name="lines[0]['.$field.']" type="text" value="'.$expected.'"',(string) $submitted->getBody());
        }
    }

    public function testSaveConfirmEndpointRequiresPermissionAndRejectsIncompleteWithoutPersistence(): void
    {
        $form=$this->request('GET','/admin/nir-uri/nou/modal/');
        self::assertStringContainsString('Salvează și confirmă',(string)$form->getBody());
        self::assertStringContainsString('Salvează draft',(string)$form->getBody());
        self::assertStringNotContainsString('Salvează în lucru',(string)$form->getBody());
        $response=$this->request('POST','/admin/nir-uri/nou/',['_csrf'=>$_SESSION['_csrf'],'intent'=>'save_confirm','idempotency_key'=>'incomplete-http'],true);
        self::assertSame(422,$response->getStatusCode(),(string)$response->getBody());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM nir_documents')->fetchColumn());
        $_SESSION['user_id']=$this->operatorId;
        self::assertSame(403,$this->request('POST','/admin/nir-uri/nou/',['_csrf'=>$_SESSION['_csrf'],'intent'=>'save_confirm','idempotency_key'=>'operator-http'],true)->getStatusCode());
    }

    public function testRegistryShowsIncompleteDraftWithResumeActionAndShortNumber(): void
    {
        $service=new NirService($this->pdo); $actor=['id'=>$this->adminId,'role'=>'administrator'];
        $draft=$service->save(null,$service->defaults($actor)['values'],$actor);
        $response=$this->request('GET','/admin/nir-uri/'); $html=(string)$response->getBody();
        self::assertSame(200,$response->getStatusCode());
        self::assertStringContainsString('Draft · incomplet',$html); self::assertStringContainsString('Reia completarea',$html);
        self::assertStringContainsString('Draft #1',$html); self::assertStringContainsString('/admin/nir-uri/'.$draft['id'].'/editare/',$html);
        self::assertStringNotContainsString($draft['temp_number'],$html);
        $found=$service->registry(['search'=>'Draft #1'],$actor);
        self::assertSame([$draft['id']],array_column($found['items'],'id'));
        self::assertSame(0,$service->registry(['search'=>'Draft #2'],$actor)['total']);
    }

    public function testDirectConfirmationIsIdempotentAndProductLinksStayPublic(): void
    {
        $service=new NirService($this->pdo); $actor=['id'=>$this->adminId,'role'=>'administrator'];
        $values=$service->defaults($actor)['values'];
        $values['supplier_id']=(new SupplierAdminService($this->pdo))->save(['alias'=>'Direct HTTP','company_name'=>'Direct HTTP SRL']);
        $values['invoice_number']='DIRECT-HTTP';
        $variant=$this->pdo->query("SELECT v.id,v.product_id,p.slug FROM product_variants v JOIN products p ON p.id=v.product_id WHERE p.track_in_conta=1 AND p.status='published' LIMIT 1")->fetch();
        $values['lines']=[['product_id'=>$variant['product_id'],'variant_id'=>$variant['id'],'supplier_name'=>'Produs HTTP','invoice_quantity'=>'2','received_quantity'=>'2','accepted_quantity'=>'2','rejected_quantity'=>'0','unit_price'=>'10','vat_percent'=>'21','conversion_factor'=>'1','purchase_unit'=>'buc','stock_unit'=>'buc','price_mode'=>'unit']];
        $body=['_csrf'=>$_SESSION['_csrf'],'intent'=>'save_confirm','idempotency_key'=>'direct-http-confirm','accept_warnings'=>'1']+$values;
        $response=$this->request('POST','/admin/nir-uri/nou/',$body,true);
        self::assertSame(200,$response->getStatusCode(),(string)$response->getBody());
        $doc=json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR)['document'];
        self::assertSame('confirmed',$doc['status']);
        self::assertStringEndsWith('-1',$doc['number']);
        self::assertSame(1,(int)$this->pdo->query('SELECT next_number FROM nir_draft_sequence WHERE id=1')->fetchColumn());
        $retry=$this->request('POST','/admin/nir-uri/nou/',$body,true);
        self::assertSame(200,$retry->getStatusCode(),(string)$retry->getBody());
        self::assertSame($doc['id'],json_decode((string)$retry->getBody(),true,512,JSON_THROW_ON_ERROR)['document']['id']);
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM nir_documents')->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM nir_stock_movements')->fetchColumn());
        $detail=$this->request('GET','/admin/nir-uri/'.$doc['id'].'/');
        self::assertSame(200,$detail->getStatusCode(),(string)$detail->getBody());
        self::assertStringContainsString('href="/produs/'.$variant['slug'].'/" target="_blank" rel="noopener"',(string)$detail->getBody());
        self::assertStringContainsString('Vezi produsul',(string)$detail->getBody());
        self::assertStringContainsString('data-nir-lightbox',(string)$detail->getBody());
        $this->pdo->prepare("UPDATE products SET status='draft' WHERE id=?")->execute([$variant['product_id']]);
        self::assertStringNotContainsString('Vezi produsul',(string)$this->request('GET','/admin/nir-uri/'.$doc['id'].'/')->getBody());
    }

    /** @param array<string,mixed> $body */
    private function request(string $method,string $path,array $body=[],bool $json=false): ResponseInterface
    {
        $request=(new ServerRequestFactory())->createServerRequest($method,'http://localhost'.$path,['REMOTE_ADDR'=>'127.0.0.1']);
        if ($body!==[]) { $request=$request->withParsedBody($body); }
        if ($json) { $request=$request->withHeader('Accept','application/json'); }
        $app=AppFactory::create($this->root);
        $response=$app->handle($request);
        unset($app);
        gc_collect_cycles();
        return $response;
    }
}
