<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Companies\{CompanyAdminService,CompanyRepository,CompanyStampStorage};
use App\Modules\Inventory\InvoiceStockService;
use App\Modules\Invoicing\{InvoiceService,InvoiceRepository,InvoiceSnapshot,SpvConnection,SpvController,SpvHttpClient,SpvSettingsService,SpvTokenVault,SpvTransportException,SpvWorker};
use App\Shared\Config\Config;
use App\Shared\Http\View;
use App\Shared\Security\Auth;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\DatabaseTestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class SpvTransmissionTest extends DatabaseTestCase
{
    private InvoiceService $invoices;
    private InvoiceRepository $repository;
    private array $actor;
    private array $invoice;
    private array $lastNotice;

    protected function setUp(): void
    {
        parent::setUp(); $this->seed();
        $_ENV['APP_KEY']='spv-workflow-key-at-least-32-bytes-long';
        $_ENV['ANAF_CLIENT_ID']='local-test-client'; $_ENV['ANAF_CLIENT_SECRET']='local-test-secret'; $_ENV['ANAF_ALLOW_PRODUCTION']='false';
        $this->pdo->exec("INSERT INTO users(email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES('spv.workflow@example.test','disabled','Ana','QA','administrator','active','2026-01-01','2026-01-01')");
        $this->actor=['id'=>(int)$this->pdo->lastInsertId(),'role'=>'administrator']; $_SESSION['user_id']=$this->actor['id'];
        $companies=new CompanyAdminService(new CompanyRepository($this->pdo),new CompanyStampStorage($this->root));
        $company=$companies->saveCompany(['legal_name'=>'Firma QA','trade_name'=>'La Măriuca QA','tax_id'=>'12345678','registered_address'=>'Strada Test 1','city'=>'București','county'=>'București','country'=>'România','is_vat_payer'=>'1','vat_rate'=>'21'],null,null);
        $companies->saveWarehouse($company,['name'=>'Gestiune QA'],null);
        $variant=(int)$this->pdo->query('SELECT v.id FROM product_variants v JOIN inventory i ON i.variant_id=v.id WHERE i.physical_quantity>=6 ORDER BY v.id LIMIT 1')->fetchColumn();
        $cart=new CartService($this->pdo); $cart->add($variant,1);
        $shipping=(int)$this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        $order=(new CheckoutService($this->pdo,$cart,Config::load($this->root)))->place(['email'=>'spv.customer@example.test','first_name'=>'Ana','last_name'=>'Pop','phone'=>'0700000000','address'=>'Strada Test 1','city'=>'București — Sector 1','county'=>'București','postal_code'=>'010101','shipping_method_id'=>(string)$shipping,'payment_method'=>'cod','idempotency_key'=>bin2hex(random_bytes(16)),'terms'=>'1']);
        $this->repository=new InvoiceRepository($this->pdo);
        $this->invoices=new InvoiceService($this->repository,new InvoiceSnapshot($this->pdo,$this->root),new InvoiceStockService($this->pdo));
        $this->invoice=$this->invoices->issue((int)$order['id'],['request_key'=>'spv-workflow-sale'],$this->actor);
    }

    protected function tearDown(): void
    {
        unset($this->invoices,$this->repository); gc_collect_cycles();
        unset($_ENV['ANAF_CLIENT_ID'],$_ENV['ANAF_CLIENT_SECRET'],$_ENV['ANAF_ALLOW_PRODUCTION']);
        parent::tearDown();
    }

    public function testSpvSettingsExcludeDocumentListAndMissingAuthorizationDoesNotQueueOrFreezeInvoices(): void
    {
        $_ENV['ANAF_CLIENT_ID']=''; $_ENV['ANAF_CLIENT_SECRET']='';
        $id=$this->invoice['id'];
        $page=$this->page('/admin/spv-efactura/');
        self::assertStringNotContainsString('id="document-'.$id.'"',$page);
        self::assertStringNotContainsString('Facturi și răspunsuri ANAF',$page);
        self::assertStringNotContainsString('Calendar și alerte',$page);
        self::assertStringContainsString('Aplicația ANAF nu este configurată',$page);
        self::assertStringContainsString('Configurează conexiunea ANAF',$this->page('/admin/facturi/'.$id.'/'));
        $http=new TransmissionHttpStub([]); $controller=$this->controller($http);
        $result=$this->act($controller,'trimite',['return_to'=>'invoice']);
        self::assertSame('/admin/facturi/'.$id.'/',$result->getHeaderLine('Location'));
        self::assertSame('error',$_SESSION['_flash'][0]['type']);
        self::assertCount(0,$http->requests);
        self::assertNull($this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$id]));
        self::assertNull($this->invoices->load($id)['fiscal_attempted_at']);
        self::assertTrue($this->invoices->document($id,$this->actor)['editable']);
    }

    public function testManualConsumerTransmissionPollsWithoutDuplicateUploadsAndLabelsTestMode(): void
    {
        $this->authorize();
        $id=$this->invoice['id'];
        self::assertStringContainsString('/admin/spv-efactura/facturi/'.$id.'/trimite/',$this->page('/admin/facturi/'.$id.'/'));
        $http=new TransmissionHttpStub([
            ['status'=>200,'body'=>'<header index_incarcare="4711"/>'],
            ['status'=>200,'body'=>'<header stare="ok" id_descarcare="8128"/>'],
        ]);
        $controller=$this->controller($http);
        $this->act($controller,'trimite');
        self::assertTrue($this->lastNotice['ok'],$this->lastNotice['text']);
        self::assertStringContainsString('Test ANAF:',$this->lastNotice['text']);
        self::assertSame('submitted',$this->invoices->load($id)['fiscal_state']);
        self::assertStringStartsWith('https://api.anaf.ro/test/FCTEL/rest/uploadb2c?',$http->requests[0]['url']);
        self::assertStringContainsString('0000000000000',$http->requests[0]['body']);
        $this->act($controller,'trimite');
        self::assertFalse($this->lastNotice['ok']); self::assertCount(1,$http->requests);
        self::assertStringContainsString('/verifica/',$this->page('/admin/facturi/'.$id.'/'));
        $this->act($controller,'verifica');
        self::assertTrue($this->lastNotice['ok'],$this->lastNotice['text']);
        self::assertSame('accepted',$this->invoices->load($id)['fiscal_state']);
        self::assertFalse($this->invoices->document($id,$this->actor)['editable']);
        self::assertSame('8128',$this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$id])['download_reference']);
        self::assertStringContainsString('/arhiva/',$this->page('/admin/facturi/'.$id.'/'));
        self::assertCount(2,$http->requests);
    }

    /** @return array<string,array{int,string,string}> */
    public static function refusedUploads(): array
    {
        return ['validation'=>[200,'<header><Errors errorMessage="CUI fără drept de transmitere"/></header>','rejected'],
            'authorization'=>[401,'Unauthorized','transient_error'],
            'server'=>[503,'Service unavailable','uncertain']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedUploads')]
    public function testFailedUploadsNeverShowSuccess(int $status,string $body,string $state): void
    {
        $this->authorize(); $http=new TransmissionHttpStub([['status'=>$status,'body'=>$body]]);
        $controller=$this->controller($http); $this->act($controller,'trimite');
        self::assertFalse($this->lastNotice['ok']);
        self::assertSame($state,$this->invoices->load($this->invoice['id'])['fiscal_state']);
        self::assertNotEmpty($this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$this->invoice['id']])['last_error']);
        if($state==='uncertain') {
            $this->act($controller,'reia'); self::assertFalse($this->lastNotice['ok']); self::assertCount(1,$http->requests);
            $worker=new SpvWorker($this->repository,$this->invoices,new SpvConnection($this->pdo,Config::load($this->root),$http),$http,Config::load($this->root),$this->root);
            self::assertSame(['processed'=>0,'errors'=>0],$worker->run());
        }
    }

    public function testRejectionDetailsAreStoredAndExplicitRetryUsesIdenticalPayload(): void
    {
        $this->authorize(); $http=new TransmissionHttpStub([
            ['status'=>200,'body'=>'<header index_incarcare="4711"/>'],
            ['status'=>200,'body'=>'<header stare="nok" id_descarcare="8128"><Errors errorMessage="Regula BR-TEST nu este îndeplinită"/></header>'],
            ['status'=>200,'body'=>'<header index_incarcare="4712"/>'],
        ]);
        $controller=$this->controller($http); $this->act($controller,'trimite'); $this->act($controller,'verifica');
        self::assertFalse($this->lastNotice['ok']);
        $before=$this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$this->invoice['id']]);
        self::assertStringContainsString('BR-TEST',$before['last_error']);
        self::assertStringContainsString('BR-TEST',$this->page('/admin/facturi/'.$this->invoice['id'].'/'));
        $this->pdo->exec('UPDATE spv_credentials SET expires_at=0,refresh_expires_at=0');
        $this->act($controller,'reia'); self::assertFalse($this->lastNotice['ok']);
        self::assertSame($before,$this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$this->invoice['id']]));
        self::assertCount(2,$http->requests);
        $this->authorize(); $this->act($controller,'reia');
        self::assertTrue($this->lastNotice['ok'],$this->lastNotice['text']);
        self::assertSame($http->requests[0]['body'],$http->requests[2]['body']);
        self::assertSame('4712',$this->invoices->load($this->invoice['id'])['fiscal_reference']);
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
    }

    public function testExpiredOrUnreadableAuthorizationOffersReconnectionWithoutExposingTokens(): void
    {
        $this->authorize(); $this->pdo->exec('UPDATE spv_credentials SET expires_at=0,refresh_expires_at=0');
        $connection=new SpvConnection($this->pdo,Config::load($this->root),new TransmissionHttpStub([]));
        self::assertTrue($connection->state()['expired']); self::assertFalse($connection->state()['ready']);
        $page=$this->page('/admin/spv-efactura/');
        self::assertStringContainsString('Reconectează certificatul',$page);
        self::assertStringContainsString('action="/admin/spv-efactura/conecteaza/"',$page);
        self::assertStringNotContainsString('workflow-access-token',$page);
        $this->pdo->exec("UPDATE spv_credentials SET encrypted_tokens='broken'");
        self::assertFalse($connection->state()['ready']);
        self::assertStringContainsString('nu poate fi citită',$connection->state()['problem']);
    }

    public function testPostRoutesEnforceCsrfAndAdministratorRoleBeforeTransport(): void
    {
        $this->authorize(); $id=$this->invoice['id'];
        $this->page('/admin/facturi/'.$id.'/');
        $app=AppFactory::create($this->root); $factory=new ServerRequestFactory();
        $request=$factory->createServerRequest('POST','http://localhost/admin/spv-efactura/facturi/'.$id.'/trimite/');
        self::assertSame(419,$app->handle($request)->getStatusCode());
        $this->pdo->exec("UPDATE users SET role='operator' WHERE id=".$this->actor['id']);
        self::assertSame(403,$app->handle($request->withParsedBody(['_csrf'=>$_SESSION['_csrf']]))->getStatusCode());
        self::assertStringNotContainsString('/spv-efactura/facturi/'.$id.'/trimite/',$this->page('/admin/facturi/'.$id.'/'));
        self::assertNull($this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$id]));
        unset($app); gc_collect_cycles();
    }

    public function testAutomaticQueueUsesSameConsumerRouteAndCannotSendTwice(): void
    {
        $this->authorize();
        $this->pdo->exec("UPDATE spv_settings SET sale_mode='instant'");
        (new \App\Modules\Invoicing\SpvSchedule($this->pdo))->rebuild();
        $http=new TransmissionHttpStub([['status'=>200,'body'=>'<header index_incarcare="5001"/>']]);
        $config=Config::load($this->root);
        $worker=new SpvWorker($this->repository,$this->invoices,new SpvConnection($this->pdo,$config,$http),$http,$config,$this->root);
        self::assertSame(['processed'=>1,'errors'=>0],$worker->run());
        self::assertSame(['processed'=>0,'errors'=>0],$worker->run());
        self::assertCount(1,$http->requests);
        self::assertStringContainsString('/uploadb2c?',$http->requests[0]['url']);
    }

    public function testEditingBeforeFirstAttemptRebuildsQueuedXmlFromUpdatedInvoice(): void
    {
        $this->authorize();
        $this->pdo->exec("UPDATE spv_settings SET sale_mode='instant'");
        $http=new TransmissionHttpStub([['status'=>200,'body'=>'<header index_incarcare="6001"/>']]);
        $config=Config::load($this->root);
        $gateway=new \App\Modules\Invoicing\AnafFiscalGateway(new SpvConnection($this->pdo,$config,$http),$http,$config);
        $service=new \App\Modules\Invoicing\InvoiceFiscalService($this->repository,$this->invoices,$gateway);
        $id=$this->invoice['id']; $service->queue($id,$this->actor);
        self::assertNotEmpty($this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$id])['payload_xml']);
        $this->invoices->edit($id,['request_key'=>'spv-edit-before-upload','version'=>$this->invoice['row_version'],'reason'=>'Corectare înainte de transmitere','billing'=>['name'=>'Ana Actualizat']],$this->actor);
        self::assertNull($this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$id])['payload_xml']);
        $service->process($id);
        self::assertSame('submitted',$this->invoices->load($id)['fiscal_state']);
        self::assertCount(1,$http->requests);
        self::assertStringContainsString('Actualizat',$http->requests[0]['body']);
    }

    public function testJsonActionsRefreshStatusReferenceAndControlsInsideInvoicePage(): void
    {
        $this->authorize(); $http=new TransmissionHttpStub([
            ['status'=>200,'body'=>'<header index_incarcare="7001"/>'],
            ['status'=>200,'body'=>'<header stare="in prelucrare"/>'],
            ['status'=>200,'body'=>'<header stare="ok" id_descarcare="8001"/>'],
        ]);
        $controller=$this->controller($http);
        $request=(new ServerRequestFactory())->createServerRequest('POST','http://localhost/')->withHeader('Accept','application/json');
        $send=function(string $operation)use($controller,$request):array {
            $response=$controller->invoiceAction($request,new Response(),['id'=>(string)$this->invoice['id'],'operation'=>$operation]);
            self::assertSame(200,$response->getStatusCode());
            self::assertSame('private, no-store',$response->getHeaderLine('Cache-Control'));
            self::assertStringContainsString('application/json',$response->getHeaderLine('Content-Type'));
            self::assertArrayNotHasKey('spv_notice',$_SESSION);
            return json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR);
        };
        $submitted=$send('trimite');
        self::assertTrue($submitted['ok']); self::assertSame('submitted',$submitted['state']);
        self::assertStringContainsString('Referință ANAF <b>7001</b>',$submitted['html']);
        self::assertStringContainsString('data-spv-poll',$submitted['html']);
        self::assertStringNotContainsString('/trimite/',$submitted['html']);
        self::assertSame('submitted',$send('verifica')['state']);
        $accepted=$send('verifica');
        self::assertTrue($accepted['ok']); self::assertSame('accepted',$accepted['state']);
        self::assertStringContainsString('Acceptată de ANAF',$accepted['html']);
        self::assertStringContainsString('7001',$accepted['html']);
        self::assertStringContainsString('/arhiva/',$accepted['html']);
        self::assertStringNotContainsString('data-spv-poll',$accepted['html']);
        self::assertCount(3,$http->requests);
        self::assertArrayNotHasKey('_flash',$_SESSION);
    }

    public function testSavingSimplifiedSpvSettingsPreservesExistingCalendarValues(): void
    {
        $this->pdo->exec("UPDATE spv_settings SET deadline_days=4,holidays_json='[\"2026-12-25\"]'");
        $settings=new SpvSettingsService($this->pdo,Config::load($this->root));
        $settings->save(['revision'=>1,'environment'=>'test','sale_mode'=>'scheduled','sale_days'=>'2','return_mode'=>'manual','return_days'=>'1','alerts'=>'1'],$this->actor);
        self::assertSame(4,(int)$settings->state()['deadline_days']);
        self::assertSame('2026-12-25',$settings->state()['holidays']);
    }

    public function testLatePollingResponseCannotOverwriteAcceptanceFromAnotherWorker(): void
    {
        $this->authorize(); $http=new TransmissionHttpStub([
            ['status'=>200,'body'=>'<header index_incarcare="7002"/>'],
            ['status'=>200,'body'=>'<header stare="in prelucrare"/>'],
        ]);
        $controller=$this->controller($http); $this->act($controller,'trimite');
        $http->beforeResponse=function():void {
            self::assertFalse($this->pdo->inTransaction());
            $this->repository->update('invoices',['fiscal_state'=>'accepted'],['id'=>$this->invoice['id']]);
            $this->repository->update('invoice_fiscal_jobs',['state'=>'accepted','download_reference'=>'8002'],['invoice_id'=>$this->invoice['id']]);
        };
        $this->act($controller,'verifica');
        self::assertSame('accepted',$this->invoices->load($this->invoice['id'])['fiscal_state']);
        self::assertSame('8002',$this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$this->invoice['id']])['download_reference']);
    }

    private function authorize(): void
    {
        $cipher=(new SpvTokenVault($_ENV['APP_KEY']))->seal(['access_token'=>'workflow-access-token','refresh_token'=>'workflow-refresh-token']);
        $this->pdo->prepare('UPDATE spv_credentials SET encrypted_tokens=?,expires_at=?,refresh_expires_at=?,connected_at=?')->execute([$cipher,time()+3600,time()+7200,gmdate('c')]);
    }

    private function controller(TransmissionHttpStub $http): SpvController
    {
        $config=Config::load($this->root);
        $twig=new Environment(new FilesystemLoader($this->root.'/resources/views'),['autoescape'=>'html']);
        $twig->addFunction(new TwigFunction('url',fn(string $path):string=>$config->path($path)));
        $twig->addFunction(new TwigFunction('csrf_token',static fn():string=>'test-csrf'));
        return new SpvController(new SpvSettingsService($this->pdo,$config),new SpvConnection($this->pdo,$config,$http),$http,$this->repository,$this->invoices,$config,new Auth($this->pdo),new View($twig),$this->root);
    }

    private function act(SpvController $controller,string $operation,array $input=[]): \Psr\Http\Message\ResponseInterface
    {
        $request=(new ServerRequestFactory())->createServerRequest('POST','http://localhost/')->withParsedBody($input);
        $response=$controller->invoiceAction($request,new Response(),['id'=>(string)$this->invoice['id'],'operation'=>$operation]);
        $flash=$_SESSION['_flash'][array_key_last($_SESSION['_flash'])];
        $this->lastNotice=['ok'=>$flash['type']==='success','text'=>$flash['message']];
        self::assertSame('/admin/facturi/'.$this->invoice['id'].'/',$response->getHeaderLine('Location'));
        return $response;
    }

    private function page(string $path): string
    {
        $app=AppFactory::create($this->root);
        $response=$app->handle((new ServerRequestFactory())->createServerRequest('GET','http://localhost'.$path));
        self::assertSame(200,$response->getStatusCode(),(string)$response->getBody());
        unset($app); gc_collect_cycles(); return (string)$response->getBody();
    }
}

final class TransmissionHttpStub implements SpvHttpClient
{
    public array $requests=[];
    public ?\Closure $beforeResponse=null;
    public function __construct(private array $responses) {}
    public function request(string $method,string $url,array $headers=[],string $body=''): array
    {
        $this->requests[]=compact('method','url','headers','body');
        if($this->beforeResponse) { ($this->beforeResponse)(); }
        if(!$this->responses) { throw new SpvTransportException('Unexpected simulated HTTP request.'); }
        return array_shift($this->responses);
    }
}
