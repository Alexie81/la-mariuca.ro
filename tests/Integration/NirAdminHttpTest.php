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
