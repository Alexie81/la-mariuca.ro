<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Companies\CompanyAdminService;
use App\Modules\Companies\CompanyRepository;
use App\Modules\Companies\CompanyStampStorage;
use App\Modules\Invoicing\DispatchNoteRepository;
use App\Modules\Invoicing\DispatchNoteService;
use App\Modules\Invoicing\InvoiceException;
use App\Shared\Config\Config;
use Tests\DatabaseTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class DispatchNoteWorkflowTest extends DatabaseTestCase
{
    private DispatchNoteService $service;
    /** @var array{id:int,role:string} */
    private array $actor;
    /** @var array<string,mixed> */
    private array $variant;

    protected function setUp(): void
    {
        parent::setUp(); $this->seed();
        $this->pdo->exec("INSERT INTO users(email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES('dispatch.qa@example.test','disabled','Paula','QA','administrator','active','2026-01-01','2026-01-01')");
        $this->actor=['id'=>(int)$this->pdo->lastInsertId(),'role'=>'administrator'];
        $companies=new CompanyAdminService(new CompanyRepository($this->pdo),new CompanyStampStorage($this->root));
        $company=$companies->saveCompany(['legal_name'=>'Firma QA SRL','trade_name'=>'La Măriuca QA','tax_id'=>'12345678','registered_address'=>'Strada Test 1','city'=>'București','country'=>'România','email'=>'firma@example.test','is_vat_payer'=>'1','vat_rate'=>'21'],null,null);
        $companies->saveWarehouse($company,['name'=>'Gestiune QA'],null);
        $this->variant=$this->pdo->query("SELECT v.* FROM product_variants v JOIN products p ON p.id=v.product_id WHERE p.status='published' AND v.status='active' AND v.price_bani>0 ORDER BY v.id LIMIT 1")->fetch();
        $this->service=new DispatchNoteService(new DispatchNoteRepository($this->pdo),$this->root,new CompanyStampStorage($this->root));
    }

    protected function tearDown(): void {unset($this->service); parent::tearDown();}

    public function testIssueIsIdempotentKeepsSnapshotAndNeverChangesStock(): void
    {
        $order=$this->order(2); $before=$this->stockState();
        $note=$this->service->issue($order,str_repeat('a',32),$this->actor);
        self::assertSame('LM-AVZ-'.date('Y'),$note['series_code']); self::assertSame(1,(int)$note['number']);
        self::assertSame('Ana Pop',$note['snapshot']['customer']['name']); self::assertSame(2,$note['snapshot']['quantity']);
        self::assertSame($note['id'],$this->service->issue($order,str_repeat('a',32),$this->actor)['id']);
        self::assertSame($note['id'],$this->service->issue($order,str_repeat('b',32),$this->actor)['id']);
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM dispatch_notes')->fetchColumn());
        self::assertSame($before,$this->stockState());
        $this->pdo->exec("UPDATE orders SET first_name='Schimbat' WHERE id=".$order);
        self::assertSame('Ana Pop',$this->service->forOrder($order,$this->actor)['snapshot']['customer']['name']);
        $path=$this->service->pdf($order,$this->actor); self::assertStringStartsWith('%PDF-', (string)file_get_contents($path));
    }

    public function testRegenerationKeepsNumberAndRefreshesOnlyMutableSnapshot(): void
    {
        $order=$this->order(1); $note=$this->service->issue($order,str_repeat('c',32),$this->actor);
        $this->pdo->exec("UPDATE orders SET first_name='Maria',shipping_address_json='{\"address\":\"Strada Nouă 2\",\"city\":\"Iași\",\"county\":\"Iași\",\"country\":\"România\"}' WHERE id=".$order);
        $this->service->regenerate($order,$this->actor); $updated=$this->service->forOrder($order,$this->actor);
        self::assertSame($note['series_code'],$updated['series_code']); self::assertSame($note['number'],$updated['number']); self::assertSame(2,(int)$updated['row_version']);
        self::assertSame('Maria Pop',$updated['snapshot']['customer']['name']); self::assertStringContainsString('Strada Nouă 2',$updated['snapshot']['customer']['address']);
        self::assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='dispatch_note.regenerated'")->fetchColumn());
    }

    public function testOnlyLatestActiveNoteCanBeDeletedEvenAfterDelivery(): void
    {
        $first=$this->order(1); $note=$this->service->issue($first,str_repeat('d',32),$this->actor);
        $second=$this->order(1);$latest=$this->service->issue($second,str_repeat('f',32),$this->actor);
        self::assertFalse($this->service->forOrder($first,$this->actor)['can_delete']);
        self::assertFalse((new \App\Modules\Orders\OrderService($this->pdo))->findForAdmin($first)['dispatch_note']['can_delete']);
        try{$this->service->delete($first,str_repeat('1',32),$this->actor);self::fail('Older dispatch note was deleted.');}catch(InvoiceException $error){self::assertSame(409,$error->getCode());self::assertStringContainsString('ultimul aviz',$error->getMessage());}
        $this->pdo->exec("UPDATE orders SET status='delivered' WHERE id=".$second);
        self::assertTrue($this->service->forOrder($second,$this->actor)['can_delete']);
        self::assertTrue((new \App\Modules\Orders\OrderService($this->pdo))->findForAdmin($second)['dispatch_note']['can_delete']);
        $before=$this->stockState();$orders=$this->pdo->query('SELECT * FROM orders ORDER BY id')->fetchAll();
        $this->service->delete($second,str_repeat('e',32),$this->actor,$latest['id'],$latest['row_version']);$this->service->delete($second,str_repeat('e',32),$this->actor,$latest['id'],$latest['row_version']);
        self::assertNull($this->service->forOrder($second,$this->actor));self::assertNotNull((new DispatchNoteRepository($this->pdo))->find((int)$latest['id'])['deleted_at']);
        self::assertTrue($this->service->forOrder($first,$this->actor)['can_delete']);
        $this->service->delete($first,str_repeat('2',32),$this->actor,$note['id'],$note['row_version']);
        self::assertSame($before,$this->stockState());self::assertSame($orders,$this->pdo->query('SELECT * FROM orders ORDER BY id')->fetchAll());
        self::assertSame(2,(int)$this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='dispatch_note.deleted'")->fetchColumn());
    }

    public function testLatestNoteDeletionWorksForShippedReturnedAndCancelledOrders(): void
    {
        $order=$this->order(1);
        foreach(['shipped','returned','cancelled'] as $index=>$status) {
            $this->pdo->exec("UPDATE orders SET status='new' WHERE id=".$order);
            $note=$this->service->issue($order,hash('sha256','issue-'.$status),$this->actor);
            $this->pdo->prepare('UPDATE orders SET status=:status WHERE id=:id')->execute(['status'=>$status,'id'=>$order]);
            self::assertTrue($this->service->forOrder($order,$this->actor)['can_delete']);self::assertSame($index+1,(int)$note['number']);
            $this->service->delete($order,hash('sha256','delete-'.$status),$this->actor,$note['id'],$note['row_version']);
            self::assertNull($this->service->forOrder($order,$this->actor));
        }
    }

    public function testStaleDeleteCannotRemoveRegeneratedOrReplacementNote(): void
    {
        $order=$this->order(1);$note=$this->service->issue($order,str_repeat('a',32),$this->actor);
        $this->service->regenerate($order,$this->actor);
        try{$this->service->delete($order,str_repeat('b',32),$this->actor,$note['id'],$note['row_version']);self::fail('Stale version deleted');}catch(InvoiceException $error){self::assertSame(409,$error->getCode());}
        $current=$this->service->forOrder($order,$this->actor);$this->service->delete($order,str_repeat('b',32),$this->actor,$current['id'],$current['row_version']);
        $replacement=$this->service->issue($order,str_repeat('c',32),$this->actor);
        $this->service->delete($order,str_repeat('b',32),$this->actor,$current['id'],$current['row_version']);
        try{$this->service->delete($order,str_repeat('d',32),$this->actor,$current['id'],$current['row_version']);self::fail('Stale form deleted replacement');}catch(InvoiceException $error){self::assertSame(409,$error->getCode());}
        self::assertSame($replacement['id'],$this->service->forOrder($order,$this->actor)['id']);
    }

    public function testInvoiceFiscalLockStillPreventsDeletingLatestNote(): void
    {
        $order=$this->order(1);$note=$this->service->issue($order,str_repeat('a',32),$this->actor);
        $invoice=\App\Modules\Invoicing\InvoiceModule::service($this->pdo,$this->root)->issue($order,['request_key'=>'dispatch-fiscal-lock'],$this->actor);
        $this->pdo->prepare("UPDATE invoices SET fiscal_attempted_at='2026-11-15T12:00:00Z' WHERE id=:id")->execute(['id'=>$invoice['id']]);
        self::assertFalse($this->service->forOrder($order,$this->actor)['can_delete']);
        self::assertFalse((new \App\Modules\Orders\OrderService($this->pdo))->findForAdmin($order)['dispatch_note']['can_delete']);
        $before=$this->stockState();$invoices=$this->pdo->query('SELECT * FROM invoices')->fetchAll();
        try{$this->service->delete($order,str_repeat('b',32),$this->actor,$note['id'],$note['row_version']);self::fail('Fiscal lock bypassed');}catch(InvoiceException $error){self::assertSame(409,$error->getCode());self::assertStringContainsString('SPV',$error->getMessage());}
        self::assertSame($before,$this->stockState());self::assertSame($invoices,$this->pdo->query('SELECT * FROM invoices')->fetchAll());
    }

    public function testAdminRoutesRequireCsrfAndExposeTheGeneratedPdf(): void
    {
        $order=$this->order(1); $_SESSION['user_id']=$this->actor['id']; $app=AppFactory::create($this->root); $factory=new ServerRequestFactory();
        $page=$app->handle($factory->createServerRequest('GET','http://localhost/admin/comenzi/'.$order.'/'));
        self::assertSame(200,$page->getStatusCode()); $html=(string)$page->getBody();
        self::assertStringContainsString('/admin/comenzi/'.$order.'/aviz/', $html); self::assertStringContainsString('Generează avizul',$html);
        $rejected=$app->handle($factory->createServerRequest('POST','http://localhost/admin/comenzi/'.$order.'/aviz/')->withParsedBody(['request_key'=>str_repeat('2',32)]));
        self::assertSame(419,$rejected->getStatusCode());
        $issued=$app->handle($factory->createServerRequest('POST','http://localhost/admin/comenzi/'.$order.'/aviz/')->withParsedBody(['_csrf'=>$_SESSION['_csrf'],'request_key'=>str_repeat('3',32)]));
        self::assertSame(302,$issued->getStatusCode()); self::assertSame('/admin/comenzi/'.$order.'/#aviz',$issued->getHeaderLine('Location'));
        $updated=$app->handle($factory->createServerRequest('GET','http://localhost/admin/comenzi/'.$order.'/'));
        self::assertStringContainsString('LM-AVZ-'.date('Y').' 1',(string)$updated->getBody()); self::assertStringContainsString('Descarcă PDF',(string)$updated->getBody());
        $pdf=$app->handle($factory->createServerRequest('GET','http://localhost/admin/comenzi/'.$order.'/aviz/pdf/'));
        self::assertSame(200,$pdf->getStatusCode()); self::assertSame('application/pdf',$pdf->getHeaderLine('Content-Type')); self::assertStringStartsWith('%PDF-',(string)$pdf->getBody());
        $this->pdo->exec("UPDATE orders SET status='delivered' WHERE id=".$order);
        $delivered=$app->handle($factory->createServerRequest('GET','http://localhost/admin/comenzi/'.$order.'/'));
        self::assertStringContainsString('Șterge avizul',(string)$delivered->getBody());
        $confirmation=$factory->createServerRequest('GET','http://localhost/admin/comenzi/'.$order.'/aviz/sterge/confirmare/');
        $form=$app->handle($confirmation->withHeader('X-Invoice-Modal','1'));self::assertSame(200,$form->getStatusCode());self::assertStringContainsString('Da, șterge avizul',(string)$form->getBody());self::assertStringNotContainsString('name="reason"',(string)$form->getBody());
        $standalone=$app->handle($confirmation);self::assertSame(200,$standalone->getStatusCode());self::assertStringContainsString('Înapoi la comandă',(string)$standalone->getBody());
        $note=$this->service->forOrder($order,$this->actor);$input=['request_key'=>str_repeat('4',32),'dispatch_note_id'=>$note['id'],'version'=>$note['row_version']];
        $delete=$factory->createServerRequest('POST','http://localhost/admin/comenzi/'.$order.'/aviz/sterge/');
        self::assertSame(419,$app->handle($delete->withParsedBody($input))->getStatusCode());
        $noIdentity=$app->handle($delete->withParsedBody(['_csrf'=>$_SESSION['_csrf'],'request_key'=>str_repeat('4',32)]));self::assertSame(302,$noIdentity->getStatusCode());self::assertNotNull($this->service->forOrder($order,$this->actor));
        $stale=$app->handle($delete->withHeader('Accept','application/json')->withParsedBody(array_replace($input,['_csrf'=>$_SESSION['_csrf'],'version'=>99])));self::assertSame(409,$stale->getStatusCode());self::assertFalse(json_decode((string)$stale->getBody(),true)['ok']);
        self::assertSame(302,$app->handle($delete->withParsedBody($input+['_csrf'=>$_SESSION['_csrf']]))->getStatusCode());self::assertNull($this->service->forOrder($order,$this->actor));
        $repeat=$app->handle($delete->withHeader('Accept','application/json')->withParsedBody($input+['_csrf'=>$_SESSION['_csrf']]));self::assertSame(200,$repeat->getStatusCode());self::assertTrue(json_decode((string)$repeat->getBody(),true)['ok']);
        self::assertSame(404,$app->handle($factory->createServerRequest('GET','http://localhost/admin/comenzi/'.$order.'/aviz/pdf/'))->getStatusCode());
        unset($delivered,$noIdentity,$form,$standalone,$stale,$repeat);
        unset($pdf,$updated,$issued,$rejected,$page,$app); gc_collect_cycles();
    }

    private function order(int $quantity): int
    {
        $_SESSION['cart_token']=bin2hex(random_bytes(32)); $cart=new CartService($this->pdo); $cart->add((int)$this->variant['id'],$quantity);
        $shipping=(int)$this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        $order=(new CheckoutService($this->pdo,$cart,Config::load($this->root)))->place(['email'=>'dispatch.customer@example.test','first_name'=>'Ana','last_name'=>'Pop','phone'=>'0700000000','address'=>'Strada Test 1','city'=>'București — Sector 1','county'=>'București','postal_code'=>'010101','shipping_method_id'=>(string)$shipping,'payment_method'=>'cod','idempotency_key'=>bin2hex(random_bytes(16)),'terms'=>'1']);
        return (int)$order['id'];
    }

    /** @return array{accounting:int,commercial:string} */
    private function stockState(): array
    {
        return ['accounting'=>(int)$this->pdo->query('SELECT COUNT(*) FROM accounting_stock_movements')->fetchColumn(),'commercial'=>json_encode($this->pdo->query('SELECT * FROM inventory ORDER BY variant_id')->fetchAll(),JSON_THROW_ON_ERROR)];
    }
}
