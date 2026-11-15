<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Companies\{CompanyAdminService,CompanyRepository,CompanyStampStorage};
use App\Modules\Inventory\InvoiceStockService;
use App\Modules\Invoicing\{DispatchNoteRepository,DispatchNoteService,InvoiceException,InvoiceRepository,InvoiceService,InvoiceSnapshot};
use App\Modules\Orders\{OrderConfirmationEmail,OrderEmailImages,OrderService,OrderTrackingRepository,OrderTrackingService};
use App\Shared\Config\Config;
use App\Shared\Mail\OutboxProcessor;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class OrderEditWorkspaceTest extends DatabaseTestCase
{
    private InvoiceRepository $repo;
    private InvoiceService $service;
    private DispatchNoteService $dispatch;
    private array $actor;
    private array $variant;

    protected function setUp(): void
    {
        parent::setUp(); $this->seed();
        $this->pdo->exec("INSERT INTO users(email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES('edit.qa@example.test','disabled','Admin','QA','administrator','active','2026-01-01','2026-01-01')");
        $this->actor=['id'=>(int)$this->pdo->lastInsertId(),'role'=>'administrator'];
        $companies=new CompanyAdminService(new CompanyRepository($this->pdo),new CompanyStampStorage($this->root));
        $company=$companies->saveCompany(['legal_name'=>'Firma QA','trade_name'=>'La Măriuca QA','tax_id'=>'12345678','registered_address'=>'Strada Test 1','city'=>'București','country'=>'România','is_vat_payer'=>'1','vat_rate'=>'21'],null,null);
        $companies->saveWarehouse($company,['name'=>'Gestiune QA'],null);
        $this->variant=$this->pdo->query("SELECT v.* FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.track_in_conta=1 AND p.status='published' AND v.status='active' AND i.physical_quantity-i.reserved_quantity>=6 ORDER BY v.id LIMIT 1")->fetch();
        $this->pdo->exec('UPDATE product_variants SET track_in_conta=0 WHERE product_id='.(int)$this->variant['product_id']);
        $this->repo=new InvoiceRepository($this->pdo);
        $this->service=new InvoiceService($this->repo,new InvoiceSnapshot($this->pdo,$this->root),new InvoiceStockService($this->pdo));
        $this->dispatch=new DispatchNoteService(new DispatchNoteRepository($this->pdo),$this->root,new CompanyStampStorage($this->root));
    }

    protected function tearDown(): void {unset($this->service,$this->dispatch,$this->repo);parent::tearDown();}

    public function testEditRepricesReissuesSameNumberAndDispatchWithOneSnapshotEmail(): void
    {
        $id=$this->order(); $invoice=$this->service->issue($id,['request_key'=>'edit-issued-invoice'],$this->actor);
        $note=$this->dispatch->issue($id,str_repeat('a',32),$this->actor); $item=$this->repo->all('order_items',['order_id'=>$id])[0];
        $beforeStock=$this->pdo->query('SELECT * FROM inventory WHERE variant_id='.(int)$this->variant['id'])->fetch();
        $input=$this->input($id)+['version'=>$invoice['row_version'],'items'=>[['id'=>$item['id'],'quantity'=>'3','unit_price'=>'123.45']],'billing'=>['name'=>'Maria QA','phone'=>'0700111222'],'notify_customer'=>'1'];
        $this->service->editOrder($id,$input,$this->actor); $this->service->editOrder($id,$input,$this->actor);
        $updated=$this->service->document((int)$invoice['id'],$this->actor); $order=$this->repo->one('orders',['id'=>$id]);
        self::assertSame($invoice['number'],$updated['number']); self::assertSame($invoice['series_code'],$updated['series_code']);
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
        self::assertSame(37035,(int)$order['subtotal_bani']); self::assertSame((int)$order['total_bani'],(int)$updated['gross_bani']);
        self::assertSame('Maria QA',$updated['snapshot']['customer']['name']); self::assertSame(2,(int)$updated['row_version']);
        $stock=$this->pdo->query('SELECT * FROM inventory WHERE variant_id='.(int)$this->variant['id'])->fetch();
        self::assertSame((int)$beforeStock['physical_quantity'],(int)$stock['physical_quantity']);
        self::assertSame((int)$beforeStock['reserved_quantity']+1,(int)$stock['reserved_quantity']);
        $updatedNote=$this->dispatch->forOrder($id,$this->actor); self::assertSame($note['number'],$updatedNote['number']);
        self::assertSame(3,$updatedNote['snapshot']['quantity']); self::assertSame(37035,$updatedNote['snapshot']['total_bani']);
        $messages=$this->repo->all('outbox_messages',['message_type'=>'order_details_changed']); self::assertCount(1,$messages);
        $payload=json_decode($messages[0]['payload_json'],true,512,JSON_THROW_ON_ERROR);
        self::assertSame(3,$payload['order']['quantity']); self::assertSame(12345,$payload['order']['items'][0]['unit_price_bani']);
        self::assertArrayNotHasKey('internal_note',$payload['order']); self::assertArrayNotHasKey('reason',$payload);
        self::assertContains('Telefon',array_column($payload['changes'],'label'));
        self::assertCount(1,$this->repo->all('audit_log',['action'=>'order.details_email_queued']));
        $audit=$this->repo->all('audit_log',['action'=>'order.invoice_edit']); $context=json_decode($audit[0]['context_json'],true);
        self::assertSame(2,(int)$context['before'][0]['quantity']); self::assertSame(3,(int)$context['after'][0]['quantity']);
        self::assertSame($this->actor['id'],(int)$audit[0]['actor_user_id']);
    }

    public function testReplacementRemovalAndAddingUseServerValidationAndReleaseReservations(): void
    {
        $id=$this->order(); $item=$this->repo->all('order_items',['order_id'=>$id])[0]; $other=$this->otherVariant();
        $input=$this->input($id)+['items'=>[['id'=>$item['id'],'sku'=>$other['sku'],'quantity'=>'1','unit_price'=>'75.50']]];
        $this->service->editOrder($id,$input,$this->actor); $items=$this->repo->all('order_items',['order_id'=>$id]);
        self::assertCount(1,$items); self::assertSame($other['sku'],$items[0]['sku']); self::assertSame(7550,(int)$items[0]['unit_price_bani']);
        self::assertSame('released',$this->repo->one('inventory_reservations',['order_id'=>$id,'variant_id'=>$this->variant['id']])['status']);
        $this->service->editOrder($id,$this->input($id)+['items'=>[['id'=>$items[0]['id'],'quantity'=>'1'],['sku'=>$this->variant['sku'],'quantity'=>'2']]],$this->actor);
        self::assertCount(2,$this->repo->all('order_items',['order_id'=>$id]));
        $all=$this->repo->all('order_items',['order_id'=>$id]);
        $this->service->editOrder($id,$this->input($id)+['items'=>[['id'=>$all[0]['id'],'quantity'=>'1','remove'=>'1'],['id'=>$all[1]['id'],'quantity'=>'2']]],$this->actor);
        self::assertCount(1,$this->repo->all('order_items',['order_id'=>$id]));
        self::assertCount(0,$this->repo->all('outbox_messages',['message_type'=>'order_details_changed']));
    }

    public function testInvalidAndUnauthorizedEditsRollbackDocumentsStockAndEmail(): void
    {
        $id=$this->order(); $invoice=$this->service->issue($id,['request_key'=>'invalid-edit-invoice'],$this->actor); $item=$this->repo->all('order_items',['order_id'=>$id])[0];
        $before=$this->state();
        foreach(['0','-1','1000000','abc'] as $price) {
            $this->reject($id,$this->input($id)+['version'=>1,'notify_customer'=>'1','items'=>[['id'=>$item['id'],'quantity'=>'1','unit_price'=>$price]]]);
            self::assertSame($before,$this->state());
        }
        $this->reject($id,$this->input($id)+['version'=>1,'items_present'=>'1']); self::assertSame($before,$this->state());
        $this->reject($id,$this->input($id)+['version'=>1,'items'=>[['id'=>$item['id'],'quantity'=>'100']]]);self::assertSame($before,$this->state());
        $this->pdo->exec("UPDATE users SET role='operator' WHERE id=".$this->actor['id']);
        $this->reject($id,$this->input($id)+['version'=>1,'items'=>[['id'=>$item['id'],'quantity'=>'2','unit_price'=>'1.00']]],403);
        $this->reject($id,$this->input($id)+['version'=>1,'items'=>[['sku'=>$item['sku'],'quantity'=>'2','unit_price'=>'1.00']]],403);
        self::assertSame($before,$this->state());
        $this->pdo->exec("UPDATE users SET role='administrator' WHERE id=".$this->actor['id']);
        $this->pdo->exec('UPDATE inventory SET physical_quantity=reserved_quantity WHERE variant_id='.(int)$item['variant_id']);$before=$this->state();
        $this->reject($id,$this->input($id)+['version'=>1,'notify_customer'=>'1','items'=>[['id'=>$item['id'],'quantity'=>'3']]]);self::assertSame($before,$this->state());
        self::assertSame($invoice['number'],$this->service->load((int)$invoice['id'])['number']);
    }

    public function testDeliveredAndFiscalStatesRejectProductEditsIncludingForgedRequests(): void
    {
        $id=$this->order();$item=$this->repo->all('order_items',['order_id'=>$id])[0];
        $this->repo->update('orders',['status'=>'delivered'],['id'=>$id]);
        $this->reject($id,$this->input($id)+['items'=>[['id'=>$item['id'],'quantity'=>'1','unit_price'=>'45']]],409);
        $this->service->editOrder($id,$this->input($id)+['billing'=>['phone'=>'0700111333']],$this->actor);
        self::assertSame('0700111333',$this->repo->one('orders',['id'=>$id])['phone']);
        $this->repo->update('orders',['status'=>'new'],['id'=>$id]);$invoice=$this->service->issue($id,['request_key'=>'spv-edit-invoice'],$this->actor);
        foreach([['fiscal_state'=>'processing'],['fiscal_state'=>'sent'],['fiscal_state'=>'error','fiscal_reference'=>'upload-123'],['fiscal_state'=>'not_sent','fiscal_attempted_at'=>'2026-11-15']] as $fiscal) {
            $this->repo->update('invoices',array_replace(['fiscal_attempted_at'=>null,'fiscal_reference'=>null],$fiscal),['id'=>$invoice['id']]);
            $before=$this->state();$this->reject($id,$this->input($id)+['version'=>1,'notify_customer'=>'1','items'=>[['id'=>$item['id'],'quantity'=>'1']]],409);self::assertSame($before,$this->state());
        }
    }

    public function testStaleVersionIsRejectedEvenWhenTimestampsHaveTheSameSecond(): void
    {
        $id=$this->order();$old=$this->input($id);$stamp=$this->repo->one('orders',['id'=>$id])['updated_at'];
        $this->service->editOrder($id,$old+['billing'=>['phone'=>'0700999888']],$this->actor);
        $this->repo->update('orders',['updated_at'=>$stamp],['id'=>$id]);
        $before=$this->state();$old['request_key']='second-editor';
        $this->reject($id,$old+['billing'=>['name'=>'Stale Client'],'notify_customer'=>'1'],409);self::assertSame($before,$this->state());
    }

    public function testEmailKeepsSavedSnapshotReusesBrandAndProcessesOnlyAsPreview(): void
    {
        $id=$this->order();$item=$this->repo->all('order_items',['order_id'=>$id])[0];
        $this->service->editOrder($id,$this->input($id)+['notify_customer'=>'1','items'=>[['id'=>$item['id'],'quantity'=>'1','unit_price'=>'55.50']],'billing'=>['name'=>'Maria <script>QA</script>']],$this->actor);
        $message=$this->repo->all('outbox_messages',['message_type'=>'order_details_changed'])[0];$payload=json_decode($message['payload_json'],true);
        $this->service->editOrder($id,$this->input($id)+['billing'=>['name'=>'Nume Ulterior']],$this->actor);
        $config=Config::load($this->root);$tracking=new OrderTrackingService(new OrderTrackingRepository($this->pdo),new OrderService($this->pdo),$config,$this->root);
        $email=new OrderConfirmationEmail($tracking,$config,new Environment(new FilesystemLoader($this->root.'/resources/views'),['autoescape'=>'html','strict_variables'=>true]),new OrderEmailImages($config,$this->root));
        $rendered=$email->render($id,$message['recipient'],$payload);
        foreach(['Ce s-a schimbat','Produsele comenzii actualizate','logo-la-mariuca-transparent.png','Cu tine, și după comandă.','CAB IT','55,50','&lt;script&gt;','/urmarire-comanda/acces/'] as $fragment) {self::assertStringContainsString($fragment,$rendered['html']);}
        self::assertStringNotContainsString('Nume Ulterior',$rendered['html']);self::assertStringNotContainsString('motiv intern confidențial',$rendered['html']);self::assertStringNotContainsString('<script>',$rendered['html']);
        $this->repo->update('outbox_messages',['available_at'=>'2026-01-01'],['id'=>$message['id']]);
        $_ENV['MAIL_SEND_AFTER_ID']='0';$_ENV['MAIL_DELIVERY_MODE']='preview';$_ENV['MAIL_MESSAGE_TYPES']='order_details_changed';
        try{$result=(new OutboxProcessor($this->pdo,Config::load($this->root),$this->root))->process();self::assertSame(['processed'=>1,'sent'=>0,'previewed'=>1,'failed'=>0],$result);}
        finally{unset($_ENV['MAIL_MESSAGE_TYPES'],$_ENV['MAIL_SEND_AFTER_ID']);}
        self::assertSame('sent',$this->repo->one('outbox_messages',['id'=>$message['id']])['status']);
        $bad=$message['recipient'];$this->repo->update('orders',['email'=>'new.customer@example.test'],['id'=>$id]);
        $this->expectException(\RuntimeException::class);$email->render($id,$bad,$payload);
    }

    public function testHttpPencilsConfirmationCsrfAndDispatchJson(): void
    {
        $id=$this->order();$_SESSION['user_id']=$this->actor['id'];$app=AppFactory::create($this->root);$factory=new ServerRequestFactory();
        $html=(string)$app->handle($factory->createServerRequest('GET','http://localhost/admin/comenzi/'.$id.'/'))->getBody();
        foreach(['Facturare și aviz','Editează produsele','Editează detaliile','Salvăm modificările?','data-dispatch-issue'] as $fragment){self::assertStringContainsString($fragment,$html);}
        self::assertStringNotContainsString('Corectează prin documente noi',$html);
        $form=$app->handle($factory->createServerRequest('GET','http://localhost/admin/facturi/0/order-edit/')->withQueryParams(['order'=>$id,'focus'=>'items'])->withHeader('X-Invoice-Modal','1'));
        self::assertSame(200,$form->getStatusCode());self::assertStringContainsString('name="items[0][unit_price]"',(string)$form->getBody());self::assertStringContainsString($this->repo->editVersion($id),(string)$form->getBody());
        $post=$factory->createServerRequest('POST','http://localhost/admin/facturi/0/order-edit/')->withHeader('Accept','application/json');
        self::assertSame(419,$app->handle($post->withParsedBody($this->input($id)+['order_id'=>$id]))->getStatusCode());
        $saved=$app->handle($post->withParsedBody($this->input($id)+['_csrf'=>$_SESSION['_csrf'],'order_id'=>$id,'billing'=>['phone'=>'0700555444'],'notify_customer'=>'1']));
        self::assertSame(200,$saved->getStatusCode(),(string)$saved->getBody());self::assertCount(1,$this->repo->all('outbox_messages',['message_type'=>'order_details_changed']));
        $issue=$factory->createServerRequest('POST','http://localhost/admin/comenzi/'.$id.'/aviz/')->withHeader('Accept','application/json')->withParsedBody(['_csrf'=>$_SESSION['_csrf'],'request_key'=>str_repeat('b',32)]);
        $response=$app->handle($issue);self::assertSame(200,$response->getStatusCode());self::assertTrue(json_decode((string)$response->getBody(),true)['ok']);self::assertCount(1,(new DispatchNoteRepository($this->pdo))->activeForOrder($id)?[1]:[]);
        $again=$app->handle($issue);self::assertSame(200,$again->getStatusCode());self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM dispatch_notes')->fetchColumn());
        $this->repo->update('orders',['status'=>'delivered'],['id'=>$id]);$html=(string)$app->handle($factory->createServerRequest('GET','http://localhost/admin/comenzi/'.$id.'/'))->getBody();
        self::assertStringNotContainsString('Editează produsele',$html);self::assertStringContainsString('Comanda este livrată.',$html);self::assertStringContainsString('Editează detaliile',$html);
        unset($_SESSION['user_id']);self::assertSame(303,$app->handle($factory->createServerRequest('GET','http://localhost/admin/facturi/0/order-edit/')->withQueryParams(['order'=>$id]))->getStatusCode());
        unset($app);gc_collect_cycles();
    }

    public function testEveryNormalStatusCanBeSelectedWithoutDuplicatingStockOrNotifications(): void
    {
        $id=$this->order();$service=new OrderService($this->pdo);
        $invoice=$this->service->issue($id,['request_key'=>'free-status-invoice'],$this->actor);
        $base=$this->pdo->query('SELECT * FROM inventory WHERE variant_id='.(int)$this->variant['id'])->fetch();
        $accounting=$this->pdo->query('SELECT * FROM accounting_stock_movements ORDER BY id')->fetchAll();
        $actions=['new'=>'reset','confirmed'=>'confirm','preparing'=>'prepare','shipped'=>'ship','delivered'=>'deliver'];
        foreach($actions as $from=>$fromAction) {
            $service->transition($id,$fromAction,null,$this->actor['id']);
            foreach($actions as $to=>$action) {
                $version=$service->statusVersion($id);
                $service->transition($id,$action,null,$this->actor['id'],'Actualizare pas',true,$version);
                $stock=$this->pdo->query('SELECT * FROM inventory WHERE variant_id='.(int)$this->variant['id'])->fetch();
                $out=in_array($to,['shipped','delivered'],true)?2:0;
                self::assertSame((int)$base['physical_quantity']-$out,(int)$stock['physical_quantity']);
                self::assertSame((int)$base['reserved_quantity']-$out,(int)$stock['reserved_quantity']);
                self::assertSame($to,$this->repo->one('orders',['id'=>$id])['status']);
                $state=$this->state();$service->transition($id,$action,null,$this->actor['id'],'Reîncercare',true,$version);self::assertSame($state,$this->state());
                $service->transition($id,$fromAction,null,$this->actor['id']);
            }
        }
        self::assertSame($accounting,$this->pdo->query('SELECT * FROM accounting_stock_movements ORDER BY id')->fetchAll());
        self::assertSame(1,(int)$this->service->load((int)$invoice['id'])['row_version']);
        // Returning to a preparation state must not bypass the delivered-product lock.
        $service->transition($id,'reset',null,$this->actor['id']);$item=$this->repo->all('order_items',['order_id'=>$id])[0];
        $this->reject($id,$this->input($id)+['version'=>1,'items'=>[['id'=>$item['id'],'quantity'=>'1']]],409);
        $old=$service->statusVersion($id);$service->transition($id,'confirm',null,$this->actor['id']);$before=$this->state();
        try{$service->transition($id,'ship',null,$this->actor['id'],'',false,$old);self::fail('Stale status accepted');}catch(\RuntimeException $e){self::assertStringContainsString('între timp',$e->getMessage());}
        self::assertSame($before,$this->state());
        $this->repo->update('orders',['status'=>'cancelled'],['id'=>$id]);
        try{$service->transition($id,'reset',null,$this->actor['id']);self::fail('Cancellation bypassed');}catch(\RuntimeException $e){self::assertStringContainsString('nu este permisă',$e->getMessage());}
    }

    public function testXmlDownloadAndZipKeepFiscalStateUntouched(): void
    {
        $id=$this->order();$invoice=$this->service->issue($id,['request_key'=>'xml-export-invoice'],$this->actor);$_SESSION['user_id']=$this->actor['id'];
        $app=AppFactory::create($this->root);$factory=new ServerRequestFactory();$before=$this->state();
        $response=$app->handle($factory->createServerRequest('GET','http://localhost/admin/facturi/'.$invoice['id'].'/xml/'));
        self::assertSame(200,$response->getStatusCode());self::assertSame('application/xml; charset=utf-8',$response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment;',$response->getHeaderLine('Content-Disposition'));self::assertStringContainsString('.xml',$response->getHeaderLine('Content-Disposition'));self::assertSame('private, no-store',$response->getHeaderLine('Cache-Control'));
        $doc=new \DOMDocument();self::assertTrue($doc->loadXML((string)$response->getBody(),LIBXML_NONET));$xpath=new \DOMXPath($doc);$xpath->registerNamespace('cbc','urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        self::assertSame($invoice['series_code'].' '.$invoice['number'],$xpath->evaluate('string(/*/cbc:ID)'));
        self::assertSame($before,$this->state());
        $exports=new \App\Modules\Invoicing\InvoiceExportService($this->repo,$this->root);$path=$exports->zip([$invoice],false);$zip=new \ZipArchive();self::assertTrue($zip->open($path));
        self::assertSame(3,$zip->numFiles);$xml=$zip->getFromName($exports->filename($invoice).'.xml');self::assertIsString($xml);self::assertSame((string)$response->getBody(),$xml);$zip->close();
        unset($_SESSION['user_id']);self::assertSame(303,$app->handle($factory->createServerRequest('GET','http://localhost/admin/facturi/'.$invoice['id'].'/xml/'))->getStatusCode());
        unset($app,$exports,$response);gc_collect_cycles();
    }

    private function input(int $id): array {return ['request_key'=>bin2hex(random_bytes(16)),'order_version'=>$this->repo->editVersion($id),'reason'=>'motiv intern confidențial'];}
    private function reject(int $id,array $input,?int $code=null): void {try{$this->service->editOrder($id,$input,$this->actor);self::fail('Edit should be rejected');}catch(InvoiceException $e){if($code!==null)self::assertSame($code,$e->getCode());else self::assertNotSame('',$e->getMessage());}}
    private function state(): array {$result=[];foreach(['orders','order_items','invoices','invoice_lines','inventory','inventory_reservations','accounting_stock_movements','outbox_messages','audit_log'] as $table){$result[$table]=$this->pdo->query('SELECT * FROM '.$table.' ORDER BY '.($table==='inventory'?'variant_id':'id'))->fetchAll();}return $result;}
    private function otherVariant(): array {return $this->pdo->query("SELECT v.* FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE v.id<>".(int)$this->variant['id']." AND p.status='published' AND v.status='active' AND i.physical_quantity-i.reserved_quantity>=3 ORDER BY v.id LIMIT 1")->fetch();}
    private function order(): int
    {
        $_SESSION['cart_token']=bin2hex(random_bytes(32));$cart=new CartService($this->pdo);$cart->add((int)$this->variant['id'],2);
        $shipping=(int)$this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        return (int)(new CheckoutService($this->pdo,$cart,Config::load($this->root)))->place(['email'=>'order.change@example.test','first_name'=>'Ana','last_name'=>'QA','phone'=>'0700000000','address'=>'Strada Test 1','city'=>'București — Sector 1','county'=>'București','postal_code'=>'010101','shipping_method_id'=>(string)$shipping,'payment_method'=>'cod','idempotency_key'=>bin2hex(random_bytes(16)),'terms'=>'1'])['id'];
    }
}
