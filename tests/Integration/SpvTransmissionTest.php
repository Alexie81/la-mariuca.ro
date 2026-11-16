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

    public function testInvoiceAndRegisterShowTheSameScheduleInRomanianTimeWithoutSchedulingOnRead(): void
    {
        $id=$this->invoice['id']; $paths=['/admin/facturi/','/admin/facturi/'.$id.'/'];
        foreach($paths as $path) { self::assertStringContainsString('Trimitere neprogramată',$this->page($path)); }
        self::assertNull($this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$id]));
        $this->pdo->exec("UPDATE spv_settings SET sale_mode='scheduled',sale_days=1,return_mode='scheduled',return_days=1,holidays_json='[]' WHERE id=1");
        $this->repository->update('invoices',['issued_at'=>'2026-03-27T09:00:00Z'],['id'=>$id]);
        $scheduler=new \App\Modules\Invoicing\SpvSchedule($this->pdo); $scheduler->sync($this->invoices->load($id));
        $job=$this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$id]);
        self::assertSame('awaiting_configuration',$job['state']);
        self::assertSame('2026-03-29T21:00:00Z',$job['available_at']);
        foreach($paths as $path) {
            $html=$this->page($path);
            self::assertStringContainsString('Trimitere programată',$html);
            self::assertStringContainsString('30.03.2026, 00:00',$html);
            self::assertStringContainsString('ora României',$html);
            self::assertStringContainsString('Suspendată până la conectarea ANAF.',$html);
        }
        self::assertSame($job,$this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$id]));
        self::assertNull($this->invoices->load($id)['fiscal_attempted_at']);
        self::assertStringContainsString('Trimite manual în SPV',$this->page($paths[1]));

        $this->pdo->exec("UPDATE spv_settings SET sale_mode='manual' WHERE id=1");
        $scheduler->sync($this->invoices->load($id));
        $credit=$this->invoices->credit($id,['request_key'=>'schedule-return','version'=>$this->invoice['row_version'],'reason'=>'Retur test','quantities'=>[$this->invoice['lines'][0]['id']=>'1']],$this->actor);
        $this->repository->update('invoices',['issued_at'=>'2026-10-23T08:00:00Z'],['id'=>$credit['id']]);
        $scheduler->sync($this->invoices->load($credit['id']));
        $register=$this->invoices->registry([],$this->actor); $items=array_column($register['items'],null,'id');
        self::assertSame('Trimitere neprogramată',$items[$id]['spv_schedule']['label']);
        self::assertSame('2026-10-25T22:00:00Z',$items[$credit['id']]['spv_schedule']['at']);
        foreach(['/admin/facturi/','/admin/facturi/'.$credit['id'].'/'] as $path) {
            self::assertStringContainsString('26.10.2026, 00:00',$this->page($path));
        }
        $html=$this->page('/admin/facturi/');
        self::assertSame(1,substr_count($html,'<strong>Trimitere neprogramată</strong>'));
        self::assertSame(1,substr_count($html,'<strong>Trimitere programată</strong>'));
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
        self::assertStringContainsString('data-spv-can-delete="0"',$submitted['html']);
        self::assertStringContainsString('data-spv-locked="1"',$submitted['html']);
        self::assertStringNotContainsString('/trimite/',$submitted['html']);
        self::assertStringContainsString('Trimitere efectuată',$submitted['html']);
        self::assertStringNotContainsString('Trimitere programată',$submitted['html']);
        $auditCount=(int)$this->pdo->query("SELECT COUNT(*) FROM invoice_audit WHERE action='fiscal_result'")->fetchColumn();
        self::assertSame('submitted',$send('verifica')['state']);
        self::assertSame($auditCount,(int)$this->pdo->query("SELECT COUNT(*) FROM invoice_audit WHERE action='fiscal_result'")->fetchColumn());
        $accepted=$send('verifica');
        self::assertTrue($accepted['ok']); self::assertSame('accepted',$accepted['state']);
        self::assertStringContainsString('Acceptată de ANAF',$accepted['html']);
        self::assertStringContainsString('7001',$accepted['html']);
        self::assertStringContainsString('/arhiva/',$accepted['html']);
        self::assertStringNotContainsString('data-spv-poll',$accepted['html']);
        self::assertSame($auditCount+1,(int)$this->pdo->query("SELECT COUNT(*) FROM invoice_audit WHERE action='fiscal_result'")->fetchColumn());
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

    public function testTrafficSendsOverdueInvoiceAndConcurrentVisitCannotUploadItAgain(): void
    {
        $this->authorize();
        $this->pdo->exec("UPDATE spv_settings SET sale_mode='instant'");
        (new \App\Modules\Invoicing\SpvSchedule($this->pdo))->sync($this->invoice);
        $this->pdo->exec("UPDATE invoice_fiscal_jobs SET available_at='2020-01-01T00:00:00Z'");
        $http=new TransmissionHttpStub([['status'=>200,'body'=>'<header index_incarcare="9011"/>'],['status'=>200,'body'=>'<header stare="ok"/>']]);
        $runtime=$this->traffic($http);
        $http->beforeResponse=function()use($runtime):void {
            self::assertFalse($this->pdo->inTransaction());
            self::assertFalse($runtime->run()['ran'],'A simultaneous visit must not acquire the active lease.');
        };
        self::assertSame(1,$runtime->run()['spv']);
        self::assertSame('submitted',$this->invoices->load($this->invoice['id'])['fiscal_state']);
        self::assertSame('9011',$this->invoices->load($this->invoice['id'])['fiscal_reference']);
        self::assertCount(1,$http->requests);
        self::assertFalse($runtime->run()['ran']);
        $http->beforeResponse=null;
        $this->pdo->exec('UPDATE request_automation_state SET next_run_at=0');
        $this->pdo->exec('UPDATE invoice_fiscal_jobs SET polled_at=NULL');
        self::assertSame(1,$runtime->run()['spv']);
        self::assertSame('accepted',$this->invoices->load($this->invoice['id'])['fiscal_state']);
        self::assertCount(2,$http->requests);
        self::assertSame('GET',$http->requests[1]['method']);
    }

    public function testTrafficPreservesFutureScheduleAndManualImmediateOverride(): void
    {
        $this->authorize();
        $this->pdo->exec("UPDATE spv_settings SET sale_mode='scheduled',sale_days=5");
        (new \App\Modules\Invoicing\SpvSchedule($this->pdo))->sync($this->invoice);
        $http=new TransmissionHttpStub([]);
        self::assertSame(0,$this->traffic($http)->run()['spv']); self::assertCount(0,$http->requests);
        $job=$this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$this->invoice['id']]);
        self::assertSame('00:00',(new \DateTimeImmutable($job['available_at']))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('H:i'));
        $service=new \App\Modules\Invoicing\InvoiceFiscalService($this->repository,$this->invoices,new \App\Modules\Invoicing\AnafFiscalGateway(new SpvConnection($this->pdo,Config::load($this->root),$http),$http,Config::load($this->root)));
        $service->queue($this->invoice['id'],$this->actor);
        $manual=$this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$this->invoice['id']]);
        $this->pdo->exec('UPDATE request_automation_state SET next_run_at=0,schedule_version=0');
        $send=new TransmissionHttpStub([['status'=>200,'body'=>'<header index_incarcare="9012"/>']]);
        self::assertSame(1,$this->traffic($send)->run()['spv']);
        self::assertSame($manual['payload_xml'],$send->requests[0]['body']);
    }

    public function testMissingAnafAccessNotifiesConfiguredOrderRecipientsOnceAndKeepsInvoiceEditable(): void
    {
        $this->pdo->exec("UPDATE spv_settings SET sale_mode='instant'");
        $this->pdo->exec("UPDATE order_notification_settings SET recipients_json='[\"operations@example.test\",\"owner@example.test\"]'");
        (new \App\Modules\Invoicing\SpvSchedule($this->pdo))->sync($this->invoice);
        $http=new TransmissionHttpStub([]); $runtime=$this->traffic($http); $runtime->run();
        $this->pdo->exec('UPDATE request_automation_state SET next_run_at=0'); $runtime->run();
        self::assertCount(0,$http->requests);
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM automation_alerts WHERE resolved_at IS NULL')->fetchColumn());
        self::assertSame(['operations@example.test','owner@example.test'],$this->pdo->query("SELECT recipient FROM outbox_messages WHERE message_type='automation_alert' ORDER BY recipient")->fetchAll(\PDO::FETCH_COLUMN));
        self::assertNull($this->invoices->load($this->invoice['id'])['fiscal_attempted_at']);
        $feed=(new \App\Modules\Admin\AdminNotificationService($this->pdo))->feed($this->actor['id']);
        self::assertContains('Trimiterea SPV așteaptă conectarea ANAF',array_column($feed['items'],'title'));
    }

    public function testUncertainUploadNotifiesOnceAndNeverAutomaticallyResends(): void
    {
        $this->authorize(); $this->pdo->exec("UPDATE spv_settings SET sale_mode='instant'");
        (new \App\Modules\Invoicing\SpvSchedule($this->pdo))->sync($this->invoice);
        $http=new TransmissionHttpStub([]); $runtime=$this->traffic($http); $runtime->run();
        self::assertSame('uncertain',$this->invoices->load($this->invoice['id'])['fiscal_state']);
        $this->pdo->exec('UPDATE request_automation_state SET next_run_at=0'); $runtime->run();
        self::assertCount(1,$http->requests);
        self::assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM automation_alerts WHERE source_key='spv:".$this->invoice['id']."'")->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query('SELECT revision FROM automation_alerts')->fetchColumn());
    }

    public function testScheduledMidnightRespectsBusinessDaysHolidaysAndClockChanges(): void
    {
        self::assertSame('2026-03-29T21:00:00Z',\App\Modules\Invoicing\SpvSchedule::afterBusinessDays('2026-03-27T09:32:15Z',1,[],true));
        self::assertSame('2026-10-25T22:00:00Z',\App\Modules\Invoicing\SpvSchedule::afterBusinessDays('2026-10-23T08:32:15Z',1,[],true));
        self::assertSame('2026-12-28T22:00:00Z',\App\Modules\Invoicing\SpvSchedule::afterBusinessDays('2026-12-24T15:32:15Z',1,['2026-12-25','2026-12-28'],true));
        self::assertSame('2026-12-24T15:32:15Z',\App\Modules\Invoicing\SpvSchedule::afterBusinessDays('2026-12-24T15:32:15Z',0));
    }

    public function testTrafficRetriesArchiveDownloadWithoutUploadingAcceptedInvoiceAgain(): void
    {
        $this->authorize(); $this->pdo->exec("UPDATE spv_settings SET sale_mode='instant'");
        (new \App\Modules\Invoicing\SpvSchedule($this->pdo))->sync($this->invoice);
        $download=(string)random_int(100000000,999999999);
        $http=new TransmissionHttpStub([
            ['status'=>200,'body'=>'<header index_incarcare="9014"/>'],
            ['status'=>200,'body'=>'<header stare="ok" id_descarcare="'.$download.'"/>'],
            ['status'=>503,'body'=>'Unavailable'],
            ['status'=>200,'body'=>"PK\x03\x04traffic-archive"],
        ]);
        $runtime=$this->traffic($http); $runtime->run();
        $this->pdo->exec('UPDATE request_automation_state SET next_run_at=0');
        $this->pdo->exec('UPDATE invoice_fiscal_jobs SET polled_at=NULL'); $runtime->run();
        self::assertSame('accepted',$this->invoices->load($this->invoice['id'])['fiscal_state']);
        self::assertCount(3,$http->requests);
        $this->pdo->exec('UPDATE request_automation_state SET next_run_at=0');
        $this->pdo->exec("UPDATE invoice_fiscal_jobs SET available_at='2020-01-01T00:00:00Z'"); $runtime->run();
        self::assertCount(4,$http->requests); self::assertSame('GET',$http->requests[3]['method']);
        self::assertStringContainsString('/descarcare?',$http->requests[3]['url']);
        $job=$this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$this->invoice['id']]);
        try {
            self::assertSame("PK\x03\x04traffic-archive",file_get_contents($job['archive_path']));
            self::assertNull($job['last_error']);
            self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM automation_alerts WHERE resolved_at IS NULL')->fetchColumn());
        } finally { if(is_file($job['archive_path'])) { unlink($job['archive_path']); } }
    }

    public function testDeadlineAlertIsOptionalDeduplicatedAndResolvedAfterSubmission(): void
    {
        $this->repository->update('invoices',['issued_at'=>'2020-01-01T10:20:00Z'],['id'=>$this->invoice['id']]);
        $alerts=new \App\Shared\Automation\AutomationAlerts($this->pdo);
        $this->pdo->exec('UPDATE spv_settings SET alerts=0'); $alerts->synchronize();
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM automation_alerts')->fetchColumn());
        $this->pdo->exec('UPDATE spv_settings SET alerts=1'); $alerts->synchronize(); $alerts->synchronize();
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM automation_alerts')->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query('SELECT revision FROM automation_alerts')->fetchColumn());
        $this->repository->update('invoices',['fiscal_reference'=>'9015','fiscal_state'=>'submitted'],['id'=>$this->invoice['id']]); $alerts->synchronize();
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM automation_alerts WHERE resolved_at IS NULL')->fetchColumn());
    }

    public function testDeadlineNotificationsCountDownAndExplainUnscheduledInvoicesWithoutRepeatingVisits(): void
    {
        $this->repository->update('invoices',['issued_at'=>'2026-11-10T10:20:00Z'],['id'=>$this->invoice['id']]);
        $this->pdo->exec("UPDATE spv_settings SET alerts=1,deadline_days=5,holidays_json='[]'");
        $alerts=new \App\Shared\Automation\AutomationAlerts($this->pdo);
        foreach([
            ['2026-11-13T09:00:00Z','Mai sunt 2 zile lucrătoare',1],
            ['2026-11-14T09:00:00Z','Mai sunt 2 zile lucrătoare',1],
            ['2026-11-16T09:00:00Z','Mai ai o zi lucrătoare',2],
            ['2026-11-16T19:00:00Z','Mai ai o zi lucrătoare',2],
            ['2026-11-17T21:59:00Z','Astăzi este ultima zi',3],
            ['2026-11-17T22:00:00Z','Termenul de trimitere SPV a fost depășit',4],
        ] as [$at,$title,$revision]) {
            $alerts->synchronize(new \DateTimeImmutable($at));
            $alert=$this->pdo->query('SELECT * FROM automation_alerts')->fetch();
            self::assertStringStartsWith($title,$alert['title']);
            self::assertStringContainsString('Factura nu este programată',$alert['message']);
            self::assertStringContainsString('Trimite-o acum',$alert['message']);
            self::assertStringContainsString('17.11.2026',$alert['message']);
            self::assertSame($revision,(int)$alert['revision']);
        }
        self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='automation_alert'")->fetchColumn(),'An empty recipient list must stay empty and send nothing.');
    }

    private function traffic(TransmissionHttpStub $http): \App\Shared\Automation\RequestAutomation
    {
        $env=$_ENV;
        try {
            $_ENV['REQUEST_AUTOMATIONS_ENABLED']='true'; $_ENV['MAIL_MESSAGE_TYPES']='verify_email'; $_ENV['MAIL_SEND_AFTER_ID']='0'; $_ENV['MAIL_DELIVERY_MODE']='preview';
            return new \App\Shared\Automation\RequestAutomation($this->pdo,Config::load($this->root),$this->root,$http);
        } finally { $_ENV=$env; }
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
