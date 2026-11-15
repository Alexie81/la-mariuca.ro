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

    public function testDeletionIsAuditedIdempotentAndBlockedByLaterFlow(): void
    {
        $first=$this->order(1); $note=$this->service->issue($first,str_repeat('d',32),$this->actor);
        $this->service->delete($first,str_repeat('e',32),$this->actor); $this->service->delete($first,str_repeat('e',32),$this->actor);
        self::assertNull($this->service->forOrder($first,$this->actor)); self::assertNotNull((new DispatchNoteRepository($this->pdo))->find((int)$note['id'])['deleted_at']);
        $replacement=$this->service->issue($first,str_repeat('f',32),$this->actor); self::assertSame(2,(int)$replacement['number']);
        $this->pdo->exec("UPDATE orders SET status='shipped' WHERE id=".$first);
        try{$this->service->delete($first,str_repeat('1',32),$this->actor);self::fail('Shipped dispatch note was deleted.');}catch(InvoiceException $error){self::assertSame(409,$error->getCode());}
        self::assertNotNull($this->service->forOrder($first,$this->actor));
        self::assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='dispatch_note.deleted'")->fetchColumn());
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
