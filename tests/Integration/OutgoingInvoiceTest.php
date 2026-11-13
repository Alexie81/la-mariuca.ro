<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Companies\{CompanyAdminService,CompanyRepository,CompanyStampStorage};
use App\Modules\Inventory\{NirService,InvoiceStockService};
use App\Modules\Invoicing\{InvoiceService,InvoiceRepository,InvoiceSnapshot,InvoiceCalculator,InvoiceException};
use App\Modules\Suppliers\SupplierAdminService;
use App\Shared\Config\Config;
use Tests\DatabaseTestCase;

final class OutgoingInvoiceTest extends DatabaseTestCase
{
    private InvoiceService $service;
    private array $actor;
    private array $variant;
    private int $supplier;
    protected function setUp(): void
    {
        parent::setUp(); $this->seed();
        $this->pdo->exec("INSERT INTO users(email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES('invoice.qa@example.test','disabled','Paula','QA','administrator','active','2026-01-01','2026-01-01')");
        $this->actor=['id'=>(int)$this->pdo->lastInsertId(),'role'=>'administrator'];
        $companies=new CompanyAdminService(new CompanyRepository($this->pdo),new CompanyStampStorage($this->root));
        $company=$companies->saveCompany(['legal_name'=>'Firma QA','trade_name'=>'La Măriuca QA','tax_id'=>'12345678','registered_address'=>'Strada Test 1','city'=>'București','country'=>'România','is_vat_payer'=>'1','vat_rate'=>'21'],null,null);
        $companies->saveWarehouse($company,['name'=>'Gestiune QA'],null);
        $this->supplier=(new SupplierAdminService($this->pdo))->save(['alias'=>'Furnizor QA','company_name'=>'Firma Furnizor QA']);
        $this->variant=$this->pdo->query('SELECT v.* FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.track_in_conta=1 AND i.physical_quantity>=6 ORDER BY v.id LIMIT 1')->fetch();
        $this->pdo->exec('UPDATE product_variants SET track_in_conta=0 WHERE product_id='.(int)$this->variant['product_id']);
        $this->service=new InvoiceService(new InvoiceRepository($this->pdo),new InvoiceSnapshot($this->pdo,$this->root),new InvoiceStockService($this->pdo));
    }
    protected function tearDown(): void { unset($this->service); parent::tearDown(); }
    public function testIssueFreezesSnapshotAndDeduplicatesWithoutOnlineStockChanges(): void
    {
        $order=$this->order(2); $online=$this->pdo->query('SELECT * FROM inventory ORDER BY variant_id')->fetchAll();
        $invoice=$this->service->issue($order,['request_key'=>'issue-basic-0001'],$this->actor);
        self::assertSame('LM 1',$invoice['display_number']); self::assertSame('unpaid',$invoice['payment_state']);
        self::assertSame(-20000,$this->balance()); self::assertSame($online,$this->pdo->query('SELECT * FROM inventory ORDER BY variant_id')->fetchAll());
        self::assertSame($invoice['id'],$this->service->issue($order,['request_key'=>'issue-basic-0001'],$this->actor)['id']);
        self::assertSame($invoice['id'],$this->service->issue($order,['request_key'=>'other-client-key'],$this->actor)['id']);
        self::assertSame(-20000,$this->balance());
        $this->pdo->exec("UPDATE companies SET legal_name='Altă firmă'; UPDATE products SET name='Alt nume'");
        $reload=$this->service->document($invoice['id'],$this->actor);
        self::assertSame('Firma QA',$reload['snapshot']['company']['legal_name']); self::assertSame($invoice['lines'][0]['name'],$reload['lines'][0]['name']);
        self::assertSame((int)$this->pdo->query('SELECT total_bani FROM orders WHERE id='.$order)->fetchColumn(),$invoice['gross_bani']);
    }
    public function testLatePurchaseReconcilesAndDeletionRestoresExactBalance(): void
    {
        $invoice=$this->service->issue($this->order(6),['request_key'=>'issue-before-nir'],$this->actor);
        $this->purchase(5,'40','P-1'); $this->purchase(5,'45','P-2');
        $loaded=$this->service->document($invoice['id'],$this->actor); $line=$loaded['lines'][0];
        self::assertSame(0,$line['pending_units']); self::assertCount(2,$line['trace']);
        self::assertSame(50000,(int)$line['trace'][0]['quantity_units']); self::assertSame(10000,(int)$line['trace'][1]['quantity_units']);
        self::assertSame(24500,array_sum(array_column($line['trace'],'value_bani'))); self::assertSame(40000,$this->balance());
        $this->service->delete($invoice['id'],['request_key'=>'delete-last-sale','version'=>$invoice['row_version'],'reason'=>'Corectare test'],$this->actor);
        self::assertSame(100000,$this->balance());
        $again=$this->service->issue((int)$invoice['order_id'],['request_key'=>'reissue-same-order'],$this->actor); self::assertSame(1,$again['number']); self::assertSame(40000,$this->balance());
    }
    public function testCreditDoesNotRestockUntilReceiptAndDeletionUndoesIt(): void
    {
        $this->purchase(5,'40','P-3'); $invoice=$this->service->issue($this->order(2),['request_key'=>'issue-return-test'],$this->actor); $line=$invoice['lines'][0];
        $credit=$this->service->credit($invoice['id'],['request_key'=>'partial-credit-01','version'=>$invoice['row_version'],'reason'=>'Mărime nepotrivită','quantities'=>[$line['id']=>'1']],$this->actor);
        self::assertSame(30000,$this->balance()); self::assertSame(2,$credit['number']); self::assertLessThan(0,$credit['gross_bani']);
        $received=$this->service->receive($credit['id'],['request_key'=>'receive-credit-01','version'=>$credit['row_version'],'reason'=>'Produs verificat','received'=>[$credit['lines'][0]['id']=>'1'],'restock'=>[$credit['lines'][0]['id']=>'1']],$this->actor);
        self::assertSame(40000,$this->balance());
        $this->service->delete($credit['id'],['request_key'=>'delete-credit-01','version'=>$received['row_version'],'reason'=>'Corectare test'],$this->actor);
        self::assertSame(30000,$this->balance());
    }
    public function testPartialCreditsKeepAllHistoricalCentsAndRejectExcess(): void
    {
        $invoice=$this->service->issue($this->order(3),['request_key'=>'issue-cents-test'],$this->actor); $line=$invoice['lines'][0]; $sum=0;
        for($i=0;$i<3;$i++) { $current=$this->service->document($invoice['id'],$this->actor); $credit=$this->service->credit($invoice['id'],['request_key'=>'partial-cents-'.$i,'version'=>$current['row_version'],'reason'=>'Retur parțial','quantities'=>[$line['id']=>'1']],$this->actor); $sum+=$credit['gross_bani']; }
        self::assertSame(-$line['gross_bani'],$sum);
        $this->expectException(InvoiceException::class); $current=$this->service->document($invoice['id'],$this->actor);
        $this->service->credit($invoice['id'],['request_key'=>'partial-excess','version'=>$current['row_version'],'reason'=>'Cantitate în plus','quantities'=>[$line['id']=>'1']],$this->actor);
    }
    public function testFiscalAttemptLocksEditsAndDeletionAndUnauthorizedActorIsRejected(): void
    {
        $invoice=$this->service->issue($this->order(1),['request_key'=>'issue-fiscal-test'],$this->actor);
        $this->pdo->exec("UPDATE invoices SET fiscal_attempted_at='2026-11-13T00:00:00Z',fiscal_state='transient_error'");
        foreach(['delete','edit'] as $method) {
            try { $this->service->$method($invoice['id'],['request_key'=>'locked-'.$method,'version'=>1,'reason'=>'Nu trebuie acceptat'],$this->actor); self::fail('Fiscal lock bypassed'); }
            catch(InvoiceException $e) { self::assertSame(409,$e->getCode()); }
        }
        $this->expectException(InvoiceException::class); $this->service->document($invoice['id'],['id'=>0,'role'=>'administrator']);
    }
    public function testNoNumberOrMovementOnInvalidOrderOrUnpaidCard(): void
    {
        $order=$this->order(1); $this->pdo->exec("UPDATE orders SET payment_method='stripe',payment_status='pending' WHERE id=".$order);
        try { $this->service->issue($order,['request_key'=>'unpaid-stripe-test'],$this->actor); self::fail('Unpaid card invoiced'); } catch(InvoiceException) {}
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn()); self::assertSame(0,$this->balance());
        $this->pdo->exec("UPDATE orders SET payment_status='paid',status='confirmed' WHERE id=".$order);
        $invoice=$this->service->issue($order,['request_key'=>'paid-stripe-test'],$this->actor); self::assertSame(1,$invoice['number']); self::assertSame('paid',$invoice['payment_state']);
    }
    public function testExactDecimalRoundingAndOptionalDecimals(): void
    {
        self::assertSame('3',InvoiceCalculator::quantity(30000)); self::assertSame('3.125',InvoiceCalculator::quantity(31250));
        $line=InvoiceCalculator::line([],30000,10001,333,2100); self::assertSame(9668,$line['gross_bani']); self::assertSame(9668,$line['net_bani']+$line['tax_bani']);
        $line['id']=1; $credit=[]; for($i=0;$i<3;$i++) { $credit[]=InvoiceCalculator::credit($line,10000,$i*10000); }
        self::assertSame(-9668,array_sum(array_column($credit,'gross_bani'))); self::assertSame(-$line['net_bani'],array_sum(array_column($credit,'net_bani')));
    }
    public function testEditingRebuildsStockWithoutChangingAgreedPricesOrIdentity(): void
    {
        $invoice=$this->service->issue($this->order(2),['request_key'=>'issue-edit-case'],$this->actor);
        $item=$this->pdo->query('SELECT * FROM order_items WHERE order_id='.$invoice['order_id'].' ORDER BY id')->fetch();
        $this->pdo->exec('UPDATE product_variants SET price_bani=99999 WHERE id='.(int)$item['variant_id']);
        $updated=$this->service->edit($invoice['id'],['request_key'=>'edit-items-case','version'=>$invoice['row_version'],'reason'=>'Actualizare cantitate','items'=>[['id'=>$item['id'],'quantity'=>'3']]],$this->actor);
        self::assertSame($invoice['display_number'],$updated['display_number']); self::assertSame(-30000,$this->balance());
        self::assertSame(InvoiceCalculator::ratio($invoice['lines'][0]['gross_bani'],3,2),$updated['lines'][0]['gross_bani']);
        self::assertSame('not_sent',$updated['email_state']);
        $this->service->queueEmail($updated['id'],'email-before-another-edit',$this->actor);
        $updated=$this->service->edit($invoice['id'],['request_key'=>'edit-after-email-queued','version'=>$updated['row_version'],'reason'=>'Corectare note','notes'=>'Date actualizate'],$this->actor);
        self::assertSame('needs_resend',$updated['email_state']);
        self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='invoice' AND status='pending'")->fetchColumn());
    }
    public function testPartialCorrectionPreservesOriginalAndCreatesLinkedPair(): void
    {
        $invoice=$this->service->issue($this->order(3),['request_key'=>'issue-correction-case'],$this->actor);
        $this->pdo->exec("UPDATE invoices SET fiscal_attempted_at='2026-11-13T00:00:00Z',fiscal_state='accepted' WHERE id=".$invoice['id']);
        $line=$invoice['lines'][0];
        $corrected=$this->service->correct($invoice['id'],['request_key'=>'correct-partial-case','version'=>$invoice['row_version'],'reason'=>'Corectare date client','quantities'=>[$line['id']=>'1'],'billing'=>['name'=>'Ana Pop corectat']],$this->actor);
        self::assertSame(3,$corrected['number']); self::assertSame(10000,$corrected['lines'][0]['quantity_units']);
        self::assertSame('Firma QA',$corrected['snapshot']['company']['legal_name']);
        self::assertSame('Ana Pop',$this->service->load($invoice['id'])['snapshot']['customer']['name']);
        self::assertSame(-40000,$this->balance()); // Physical receipt remains an explicit separate action.
        self::assertCount(2,$this->service->document($invoice['id'],$this->actor)['related']);
    }
    public function testReturnRequestRequiresVerifiedOrderAndDecisionAccountsForEveryLine(): void
    {
        $invoice=$this->service->issue($this->order(2),['request_key'=>'issue-request-case'],$this->actor); $line=$invoice['lines'][0];
        $this->pdo->exec("UPDATE orders SET status='delivered' WHERE id=".$invoice['order_id']);
        $q=$this->pdo->prepare("INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES(:actor,'order.deliver','order',:id,'{}',:now)"); $q->execute(['actor'=>$this->actor['id'],'id'=>$invoice['order_id'],'now'=>gmdate('c')]);
        $input=['request_key'=>'return-request-case','reason'=>'Mărime nepotrivită','quantities'=>[$line['id']=>'2'],'iban'=>'RO49AAAA1B31007593840000','holder'=>'Ana Pop'];
        try { $this->service->requestReturn($invoice['id'],$input,null,$invoice['order_id']+1); self::fail('Wrong customer accepted'); } catch(InvoiceException $e) { self::assertSame(403,$e->getCode()); }
        $request=$this->service->requestReturn($invoice['id'],$input,null,$invoice['order_id']);
        self::assertSame($request['id'],$this->service->requestReturn($invoice['id'],$input,null,$invoice['order_id'])['id']);
        $decision=['request_key'=>'return-decision-case','return_request_id'=>$request['id'],'version'=>$invoice['row_version'],'reason'=>'Verificare retur','decisions'=>[$line['id']=>['quantity'=>'1','reason'=>'A doua bucată este utilizată']]];
        $credit=$this->service->decideReturn($invoice['id'],$decision,$this->actor); self::assertSame(-10000,$credit['lines'][0]['quantity_units']); self::assertSame(-20000,$this->balance());
        self::assertSame($credit['id'],$this->service->decideReturn($invoice['id'],$decision,$this->actor)['id']);
        self::assertSame(2,(int)$this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='invoice_return_notice'")->fetchColumn());
        $notifications=(new \App\Modules\Admin\AdminNotificationService($this->pdo))->feed($this->actor['id']); self::assertIsArray($notifications['items']);
    }
    public function testCancellationDeletesOnlyLatestOtherwiseCreditsWithoutInventingRefund(): void
    {
        $first=$this->service->issue($this->order(1),['request_key'=>'cancel-first-case'],$this->actor);
        $second=$this->service->issue($this->order(1),['request_key'=>'cancel-second-case'],$this->actor);
        $this->service->cancelOrder($second['order_id'],'Greșeală de comandă',$this->actor); self::assertSame(-10000,$this->balance());
        $replacement=$this->service->issue($this->order(1),['request_key'=>'cancel-third-case'],$this->actor); self::assertSame(2,$replacement['number']);
        $this->service->cancelOrder($first['order_id'],'Solicitare client',$this->actor);
        self::assertCount(1,$this->service->document($first['id'],$this->actor)['related']); self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM invoice_refunds')->fetchColumn());
    }
    public function testExportsAndEmailUseHistoricalSnapshotsAndRevocableLinks(): void
    {
        $invoice=$this->service->issue($this->order(2),['request_key'=>'issue-export-case'],$this->actor);
        $exports=new \App\Modules\Invoicing\InvoiceExportService(new InvoiceRepository($this->pdo),$this->root);
        $data=$exports->pdfData($invoice); self::assertSame('156,1983 Lei',$data['lines'][0]['unit_net']);
        self::assertSame('156.19834711',$invoice['lines'][0]['unit_net']);
        $pdf=$exports->pdf($invoice); self::assertSame('%PDF',substr((string)file_get_contents($pdf),0,4));
        $xlsx=$exports->xlsx($invoice); $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($xlsx); $sheet=$book->getActiveSheet();
        self::assertEquals(2,$sheet->getCell('E14')->getValue()); self::assertSame('0',$sheet->getStyle('E14')->getNumberFormat()->getFormatCode());
        self::assertEquals($invoice['lines'][0]['gross_bani']/100,$sheet->getCell('K14')->getCalculatedValue()); $book->disconnectWorksheets();
        $zip=$exports->zip([$invoice]); $archive=new \ZipArchive(); self::assertTrue($archive->open($zip)); self::assertGreaterThanOrEqual(3,$archive->numFiles); $archive->close();
        $token=$this->service->publicLink($invoice['id'],$this->actor); self::assertSame($invoice['id'],$this->service->publicDocument($token)['id']);
        $this->service->publicLink($invoice['id'],$this->actor,true);
        try { $this->service->publicDocument($token); self::fail('Revoked link accessible'); } catch(InvoiceException $e) { self::assertSame(404,$e->getCode()); }
        $this->service->queueEmail($invoice['id'],'email-export-case',$this->actor);
        $payload=json_decode($this->pdo->query("SELECT payload_json FROM outbox_messages WHERE message_type='invoice'")->fetchColumn(),true);
        $mail=(new \App\Modules\Invoicing\InvoiceEmail(new InvoiceRepository($this->pdo),Config::load($this->root),$this->root))->render($payload,'invoice.customer@example.test');
        self::assertStringContainsString('Descarcă factura',$mail['html']); self::assertSame($pdf,$mail['attachment']);
        // Persist only synthetic artifacts for visual inspection, never live customer data.
        $target=$this->root.'/storage/qa/outgoing-invoices'; if(!is_dir($target)) { mkdir($target,0770,true); }
        copy($pdf,$target.'/factura-test.pdf'); copy($xlsx,$target.'/factura-test.xlsx'); copy($zip,$target.'/registru-test.zip'); file_put_contents($target.'/email-test.html',$mail['html']);
        $many=$invoice; $many['document_key']='qa-many-lines'; $many['lines']=array_merge(...array_fill(0,15,$invoice['lines'])); $many=array_replace($many,InvoiceCalculator::totals($many['lines'])); copy($exports->pdf($many),$target.'/factura-multipagina.pdf');
    }
    public function testLegacyPostingCannotDecrementAnAlreadyIssuedInvoiceAgain(): void
    {
        $invoice=$this->service->issue($this->order(1),['request_key'=>'issue-legacy-guard'],$this->actor);
        (new \App\Modules\Inventory\AccountingStockService($this->pdo))->recordInvoice($invoice['order_id'],'LM 1',$this->actor['id']);
        self::assertSame(-10000,$this->balance());
    }
    public function testCancellationNeutralizesTheEntireCorrectionChainExactlyOnce(): void
    {
        $invoice=$this->service->issue($this->order(2),['request_key'=>'cancel-correction-root'],$this->actor);
        $this->service->correct($invoice['id'],['request_key'=>'cancel-correction-pair','version'=>1,'reason'=>'Date corecte','billing'=>['name'=>'Ana Corectat']],$this->actor);
        $this->service->cancelOrder($invoice['order_id'],'Anulare solicitată',$this->actor);
        $count=(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
        self::assertSame(0,(new InvoiceRepository($this->pdo))->orderNet($invoice['order_id']));
        $this->service->cancelOrder($invoice['order_id'],'Anulare solicitată',$this->actor);
        self::assertSame($count,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
    }
    public function testRefundRequiresActualCashAndPaymentCannotExceedNetAfterCredit(): void
    {
        $invoice=$this->service->issue($this->order(2),['request_key'=>'cash-limit-root'],$this->actor);
        $credit=$this->service->credit($invoice['id'],['request_key'=>'cash-limit-credit','version'=>1,'reason'=>'Retur parțial','quantities'=>[$invoice['lines'][0]['id']=>'1']],$this->actor);
        try { $this->service->payment($credit['id'],['request_key'=>'cash-before-received','version'=>1,'reason'=>'Restituire test','amount'=>'1'],$this->actor); self::fail('Refund without cash'); } catch(InvoiceException $e) { self::assertStringContainsString('încasați',$e->getMessage()); }
        $net=(new InvoiceRepository($this->pdo))->orderNet($invoice['order_id']);
        $invoice=$this->service->load($invoice['id']);
        try { $this->service->payment($invoice['id'],['request_key'=>'cash-overpaid-case','version'=>$invoice['row_version'],'reason'=>'Încasare test','amount'=>(string)(($net+1)/100)],$this->actor); self::fail('Overpayment accepted'); } catch(InvoiceException $e) { self::assertStringContainsString('restul de plată',$e->getMessage()); }
        $this->service->payment($invoice['id'],['request_key'=>'cash-net-received','version'=>$invoice['row_version'],'reason'=>'Încasare ramburs','amount'=>(string)($net/100)],$this->actor);
        self::assertSame(0,(new InvoiceRepository($this->pdo))->summary()['outstanding']);
    }
    public function testAutomaticIssueQueuesOneInvoiceEmailAndRetriesDoNotDuplicate(): void
    {
        $automation=new \App\Modules\Invoicing\InvoiceAutomationService(new \App\Modules\Invoicing\InvoiceAutomationRepository($this->pdo));
        $automation->save(['revision'=>$automation->settings()['revision'],'cod_issue'=>1,'cod_email'=>1],$this->actor['id']);
        $order=$this->order(1);
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
        \App\Modules\Invoicing\InvoiceModule::process($this->pdo,$this->root);
        self::assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='invoice'")->fetchColumn());
        self::assertSame('new',$this->pdo->query('SELECT status FROM orders WHERE id='.$order)->fetchColumn());
        $this->pdo->exec("UPDATE companies SET tax_id=''"); $second=$this->order(1);
        self::assertSame('new',$this->pdo->query('SELECT status FROM orders WHERE id='.$second)->fetchColumn());
        self::assertSame('failed',$this->pdo->query('SELECT state FROM invoice_automation_intents WHERE order_id='.$second)->fetchColumn());
        $this->pdo->exec("UPDATE companies SET tax_id='12345678'"); \App\Modules\Invoicing\InvoiceModule::process($this->pdo,$this->root);
        self::assertSame(2,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
    }
    public function testOnlineStockReturnsOnlyAfterPhysicalReceiptOfShippedPieces(): void
    {
        $this->pdo->exec('UPDATE product_variants SET stock_unlimited=0 WHERE id='.$this->variant['id']);
        $order=$this->order(2); $invoice=$this->service->issue($order,['request_key'=>'online-receipt-root'],$this->actor);
        $orders=new \App\Modules\Orders\OrderService($this->pdo);
        foreach(['confirm','prepare','ship'] as $action) { $orders->transition($order,$action,null,$this->actor['id']); }
        $before=(int)$this->pdo->query('SELECT physical_quantity FROM inventory WHERE variant_id='.$this->variant['id'])->fetchColumn();
        $credit=$this->service->credit($invoice['id'],['request_key'=>'online-receipt-credit','version'=>1,'reason'=>'Mărime nepotrivită','quantities'=>[$invoice['lines'][0]['id']=>'1']],$this->actor);
        self::assertSame($before,(int)$this->pdo->query('SELECT physical_quantity FROM inventory WHERE variant_id='.$this->variant['id'])->fetchColumn());
        $received=$this->service->receive($credit['id'],['request_key'=>'online-receipt-confirm','version'=>1,'reason'=>'Produs verificat','received'=>[$credit['lines'][0]['id']=>'1'],'restock'=>[$credit['lines'][0]['id']=>'1']],$this->actor);
        self::assertSame($before+1,(int)$this->pdo->query('SELECT physical_quantity FROM inventory WHERE variant_id='.$this->variant['id'])->fetchColumn());
        $this->service->delete($credit['id'],['request_key'=>'online-receipt-delete','version'=>$received['row_version'],'reason'=>'Corectare primire'],$this->actor);
        self::assertSame($before,(int)$this->pdo->query('SELECT physical_quantity FROM inventory WHERE variant_id='.$this->variant['id'])->fetchColumn());
    }
    public function testHttpRequiresAdminAndCsrfAndSupportsPreviewWithoutJavaScript(): void
    {
        $order=$this->order(1); $factory=new \Slim\Psr7\Factory\ServerRequestFactory();
        $send=function(string $method,string $path,array $input=[])use($factory) { $app=\App\AppFactory::create($this->root); $request=$factory->createServerRequest($method,'http://localhost'.$path,['REMOTE_ADDR'=>'127.0.0.71'])->withParsedBody($input)->withHeader('Accept','application/json'); $response=$app->handle($request); unset($app); gc_collect_cycles(); return $response; };
        self::assertContains($send('GET','/admin/facturi/')->getStatusCode(),[302,303,401,403]);
        $_SESSION['user_id']=$this->actor['id'];
        self::assertSame(200,$send('GET','/admin/facturi/')->getStatusCode());
        self::assertSame(419,$send('POST','/admin/facturi/emite/',['order_id'=>$order,'request_key'=>'http-issue-test'])->getStatusCode());
        $response=$send('POST','/admin/facturi/emite/',['_csrf'=>$_SESSION['_csrf'],'order_id'=>$order,'request_key'=>'http-issue-test']);
        self::assertSame(200,$response->getStatusCode(),(string)$response->getBody());
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
    }
    public function testFiscalAdapterLocksAtFirstAttemptAndRetriesSameDocumentIdentity(): void
    {
        $invoice=$this->service->issue($this->order(1),['request_key'=>'fiscal-adapter-test'],$this->actor);
        $gateway=new class($this->pdo) implements \App\Modules\Invoicing\FiscalGateway {
            public array $keys=[];
            public function __construct(private \PDO $pdo) {}
            public function build(array $invoice): string { return (string)$invoice['number']; }
            public function validate(string $document): array { return []; }
            public function send(string $document,string $documentKey): array { if($this->pdo->inTransaction()) { throw new \LogicException('Network inside transaction'); } $this->keys[]=$documentKey; if(count($this->keys)===1) { throw new \RuntimeException('Transient fake error'); } return ['reference'=>'SIMULATED-ONLY','state'=>'submitted','response'=>[]]; }
            public function status(string $reference): array { return ['state'=>'accepted','response'=>['test'=>true]]; }
        };
        $fiscal=new \App\Modules\Invoicing\InvoiceFiscalService(new InvoiceRepository($this->pdo),$this->service,$gateway);
        $fiscal->queue($invoice['id'],$this->actor); $fiscal->process($invoice['id']);
        self::assertSame('transient_error',$this->service->load($invoice['id'])['fiscal_state']);
        self::assertFalse($this->service->document($invoice['id'],$this->actor)['editable']);
        $fiscal->process($invoice['id']); $fiscal->poll($invoice['id']);
        self::assertSame('accepted',$this->service->load($invoice['id'])['fiscal_state']);
        self::assertSame(['fiscal-adapter-test','fiscal-adapter-test'],$gateway->keys);
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
    }
    public function testNewSkuUsesCatalogPriceAndKeepsExistingAgreedPrice(): void
    {
        $order=$this->order(1); $invoice=$this->service->issue($order,['request_key'=>'sku-edit-root'],$this->actor);
        $other=$this->pdo->query("SELECT v.* FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.id<>".$this->variant['id']." AND v.status='active' AND p.status='published' ORDER BY v.id LIMIT 1")->fetch();
        $this->pdo->exec('UPDATE product_variants SET stock_unlimited=1 WHERE id='.$other['id']);
        $edited=$this->service->edit($invoice['id'],['request_key'=>'sku-edit-new-product','version'=>1,'reason'=>'Adăugare articol','items'=>[['id'=>$invoice['lines'][0]['order_item_id'],'quantity'=>'1'],['sku'=>$other['sku'],'quantity'=>'2','unit_price_bani'=>1]]],$this->actor);
        $found=array_values(array_filter($edited['lines'],fn(array $line):bool=>$line['sku']===$other['sku']));
        self::assertSame(2*(int)$other['price_bani'],$found[0]['gross_bani']);
    }
    public function testRegistryFiltersAndCreditDeletionKeepCashAndLaterOrderEvents(): void
    {
        $invoice=$this->service->issue($this->order(2),['request_key'=>'registry-filters-root'],$this->actor);
        $credit=$this->service->credit($invoice['id'],['request_key'=>'registry-filters-credit','version'=>1,'reason'=>'Retur parțial','quantities'=>[$invoice['lines'][0]['id']=>'1']],$this->actor);
        $repo=new InvoiceRepository($this->pdo);
        self::assertSame(1,$repo->registry(['receipt'=>'pending','customer_type'=>'person'])['total']);
        self::assertSame(0,$repo->registry(['customer_type'=>'company'])['total']);
        self::assertSame(0,$repo->registry(['receipt'=>'received'])['total']);
        $net=$repo->orderNet($invoice['order_id']); $current=$this->service->load($invoice['id']);
        $this->service->payment($invoice['id'],['request_key'=>'registry-filters-cash','version'=>$current['row_version'],'reason'=>'Ramburs încasat','amount'=>(string)($net/100)],$this->actor);
        self::assertSame('paid',$repo->one('orders',['id'=>$invoice['order_id']])['payment_status']);
        $repo->update('orders',['status'=>'delivered'],['id'=>$invoice['order_id']]);
        $this->service->delete($credit['id'],['request_key'=>'registry-filters-delete','version'=>1,'reason'=>'Corectare retur'],$this->actor);
        $order=$repo->one('orders',['id'=>$invoice['order_id']]);
        self::assertSame('delivered',$order['status']);
        self::assertSame('pending',$order['payment_status']);
        self::assertSame($net,$repo->orderCash($invoice['order_id'])['received']);
        self::assertSame(1,$repo->registry(['fiscal_state'=>'unconfigured'])['total']);
    }

    public function testAllAdminFormsAndPrivateExportsHaveWorkingHttpResponses(): void
    {
        $invoice=$this->service->issue($this->order(2),['request_key'=>'http-forms-document'],$this->actor);
        $unbilled=$this->order(1); $_SESSION['user_id']=$this->actor['id'];
        $factory=new \Slim\Psr7\Factory\ServerRequestFactory();
        $get=function(string $path,array $query=[])use($factory) {
            $app=\App\AppFactory::create($this->root);
            $request=$factory->createServerRequest('GET','http://localhost'.$path,['REMOTE_ADDR'=>'127.0.0.71'])->withQueryParams($query)->withHeader('X-Invoice-Modal','1');
            $response=$app->handle($request); unset($app); gc_collect_cycles(); return $response;
        };
        foreach(['edit','return','correct','payment','delete','email','link','request'] as $action) {
            $response=$get('/admin/facturi/'.$invoice['id'].'/'.$action.'/');
            self::assertSame(200,$response->getStatusCode(),$action);
            self::assertStringContainsString('name="_csrf"',(string)$response->getBody());
            self::assertStringContainsString('data-iv-form',(string)$response->getBody());
        }
        foreach(['/admin/facturi/emite/','/admin/facturi/0/order-edit/','/admin/facturi/0/policy/'] as $path) {
            $response=$get($path,['order'=>$unbilled]); self::assertSame(200,$response->getStatusCode(),$path);
            self::assertStringContainsString('data-iv-form',(string)$response->getBody());
        }
        $credit=$this->service->credit($invoice['id'],['request_key'=>'http-forms-credit','version'=>1,'reason'=>'Retur test','quantities'=>[$invoice['lines'][0]['id']=>'1']],$this->actor);
        self::assertSame(200,$get('/admin/facturi/'.$credit['id'].'/receive/')->getStatusCode());
        foreach(['pdf'=>'%PDF','xlsx'=>'PK','zip'=>'PK'] as $format=>$magic) {
            $response=$get('/admin/facturi/'.$invoice['id'].'/'.$format.'/');
            self::assertSame(200,$response->getStatusCode()); self::assertStringStartsWith($magic,(string)$response->getBody());
            self::assertStringStartsWith('attachment;',$response->getHeaderLine('Content-Disposition'));
            self::assertStringContainsString('no-store',$response->getHeaderLine('Cache-Control'));
        }
        $inline=$get('/admin/facturi/'.$invoice['id'].'/pdf/',['view'=>'1']);
        self::assertStringStartsWith('inline;',$inline->getHeaderLine('Content-Disposition'));
        foreach(['xlsx','zip'] as $format) {
            $response=$get('/admin/facturi/export/'.$format.'/');
            self::assertSame(200,$response->getStatusCode()); self::assertStringStartsWith('PK',(string)$response->getBody());
        }
        unset($_SESSION['user_id']);
        self::assertContains($get('/admin/facturi/'.$invoice['id'].'/pdf/')->getStatusCode(),[302,303,401,403]);
        unset($get); gc_collect_cycles();
    }
    public function testStaleVersionAndNonLatestDeletionLeaveDocumentsAndStockUntouched(): void
    {
        $first=$this->service->issue($this->order(1),['request_key'=>'stale-first-document'],$this->actor);
        $second=$this->service->issue($this->order(1),['request_key'=>'stale-second-document'],$this->actor);
        $balance=$this->balance();
        try { $this->service->delete($first['id'],['request_key'=>'not-last-deletion','version'=>1,'reason'=>'Test blocaj'],$this->actor); self::fail('Non-latest deleted'); }
        catch(InvoiceException) { self::assertSame($balance,$this->balance()); }
        $updated=$this->service->edit($second['id'],['request_key'=>'current-version-edit','version'=>1,'reason'=>'Test actualizare','notes'=>'Nota noua'],$this->actor);
        try { $this->service->edit($second['id'],['request_key'=>'stale-version-edit','version'=>1,'reason'=>'Formular vechi','notes'=>'Nota veche'],$this->actor); self::fail('Stale edit accepted'); }
        catch(InvoiceException $e) { self::assertSame(409,$e->getCode()); }
        self::assertSame($updated['notes'],$this->service->load($second['id'])['notes']);
        self::assertSame($balance,$this->balance());
        self::assertSame(2,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices WHERE deleted_at IS NULL')->fetchColumn());
    }
    public function testRejectedRequestAndInvalidPhysicalReceiptDoNotInventCreditsOrStock(): void
    {
        $invoice=$this->service->issue($this->order(2),['request_key'=>'reject-request-document'],$this->actor); $line=$invoice['lines'][0];
        $request=$this->service->requestReturn($invoice['id'],['request_key'=>'reject-request-input','reason'=>'Cerere test','quantities'=>[$line['id']=>'1'],'iban'=>'RO49AAAA1B31007593840000','holder'=>'Ana Pop'],$this->actor);
        $this->service->decideReturn($invoice['id'],['request_key'=>'reject-request-decision','return_request_id'=>$request['id'],'version'=>1,'reason'=>'Verificare cerere','decisions'=>[$line['id']=>['quantity'=>'0','reason'=>'Produs utilizat']]],$this->actor);
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
        self::assertSame(-20000,$this->balance());
        $invoice=$this->service->load($invoice['id']);
        $credit=$this->service->credit($invoice['id'],['request_key'=>'receipt-invalid-credit','version'=>$invoice['row_version'],'reason'=>'Retur test','quantities'=>[$line['id']=>'1']],$this->actor);
        $creditLine=$credit['lines'][0]['id'];
        try { $this->service->receive($credit['id'],['request_key'=>'receipt-invalid-input','version'=>1,'reason'=>'Control test','received'=>[$creditLine=>'0'],'restock'=>[$creditLine=>'1']],$this->actor); self::fail('Unreceived stock restored'); }
        catch(InvoiceException) { self::assertSame(-20000,$this->balance()); }
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM invoice_receipts')->fetchColumn());
    }

    private function order(int $quantity): int
    {
        $_SESSION['cart_token']=bin2hex(random_bytes(32)); $cart=new CartService($this->pdo); $cart->add($this->variant['id'],$quantity);
        $shipping=(int)$this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        $order=(new CheckoutService($this->pdo,$cart,Config::load($this->root)))->place(['email'=>'invoice.customer@example.test','first_name'=>'Ana','last_name'=>'Pop','phone'=>'0700000000','address'=>'Strada Test 1','city'=>'București — Sector 1','county'=>'București','postal_code'=>'010101','shipping_method_id'=>(string)$shipping,'payment_method'=>'cod','idempotency_key'=>bin2hex(random_bytes(16)),'terms'=>'1']); return (int)$order['id'];
    }
    private function purchase(int $quantity,string $price,string $number): void
    {
        $nir=new NirService($this->pdo); $values=$nir->defaults($this->actor)['values'];
        $values=array_replace($values,['supplier_id'=>$this->supplier,'invoice_number'=>$number]);
        $values['lines']=[array_replace($values['lines'][0],['product_id'=>$this->variant['product_id'],'variant_id'=>$this->variant['id'],'supplier_name'=>'Produs QA','supplier_code'=>'SUP-QA','invoice_quantity'=>(string)$quantity,'received_quantity'=>(string)$quantity,'accepted_quantity'=>(string)$quantity,'unit_price'=>$price])];
        $draft=$nir->save(null,$values,$this->actor); $nir->confirm($draft['id'],$draft['row_version'],'confirm-'.$number,true,$this->actor);
    }
    private function balance(): int { return (int)$this->pdo->query('SELECT COALESCE(SUM(quantity_units),0) FROM nir_stock_balances')->fetchColumn(); }
}
