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
    protected function tearDown(): void { unset($this->service); gc_collect_cycles(); parent::tearDown(); }
    public function testStockJournalMatchesDocumentsAndSeparatesOnlineAvailability(): void
    {
        $repository=new \App\Modules\Inventory\StockLedgerRepository($this->pdo);
        $ledger=new \App\Modules\Inventory\StockLedgerService($repository);
        $id=(int)$this->variant['product_id'];
        $invoice=$this->service->issue($this->order(2),['request_key'=>'journal-sale'],$this->actor);
        $detail=$ledger->detail($id,[],$this->actor);
        self::assertSame(-20000,$detail['current_units']); self::assertTrue($detail['consistent']);
        self::assertSame(20000,$detail['outgoing_units']); self::assertSame(0,$detail['incoming_units']);
        self::assertSame('LM 1',$detail['movements'][0]['document']);
        self::assertNull($detail['movements'][0]['cost_bani']);
        $this->purchase(3,'40','JOURNAL-PURCHASE');
        $detail=$ledger->detail($id,[],$this->actor);
        self::assertSame(10000,$detail['current_units']); self::assertSame(10000,$detail['closing_units']); self::assertTrue($detail['consistent']);
        self::assertSame(8000,$detail['movements'][0]['cost_bani']);
        $online=$detail['online'];
        $this->service->delete($invoice['id'],['request_key'=>'journal-delete','version'=>$invoice['row_version'],'reason'=>'Verificare jurnal'],$this->actor);
        $detail=$ledger->detail($id,[],$this->actor); self::assertSame(30000,$detail['current_units']); self::assertTrue($detail['consistent']); self::assertSame($online,$detail['online']);
        $ledger->threshold($id,'4',$this->actor);
        $rows=$ledger->registry(['q'=>$this->variant['sku'],'alert'=>'low'],$this->actor);
        self::assertCount(1,$rows['items']); self::assertSame('3',$rows['items'][0]['quantity']); self::assertSame('low',$rows['items'][0]['alert']);
    }
    public function testStockExportsPreserveNumericCellsNoTrailingDotsAndProtectFormulaText(): void
    {
        $this->purchase(3,'40','EXPORT-PURCHASE');
        $repository=new \App\Modules\Inventory\StockLedgerRepository($this->pdo); $ledger=new \App\Modules\Inventory\StockLedgerService($repository);
        $exports=new \App\Modules\Inventory\StockLedgerExportService($this->root);
        $data=$ledger->registry(['q'=>$this->variant['sku']],$this->actor,true); $data['items'][0]['name']='=HYPERLINK("https://invalid.test")';
        $file=$exports->registry($data,$repository->companyProfile());
        try {
            $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($file['path']); $sheet=$book->getActiveSheet();
            self::assertSame('s',$sheet->getCell('A7')->getDataType()); self::assertSame('n',$sheet->getCell('E7')->getDataType());
            self::assertSame('3',$sheet->getCell('E7')->getFormattedValue()); self::assertSame(120,$sheet->getCell('I7')->getValue());
            $book->disconnectWorksheets();
        } finally { $exports->cleanup($file['path']); }
        $detail=$ledger->detail((int)$this->variant['product_id'],[],$this->actor); $detail['product']['name']='=1+1';
        $file=$exports->product($detail,$repository->companyProfile());
        try { $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($file['path']); $sheet=$book->getActiveSheet(); self::assertSame('s',$sheet->getCell('D3')->getDataType()); self::assertSame('3',$sheet->getCell('F11')->getFormattedValue()); $book->disconnectWorksheets(); }
        finally { $exports->cleanup($file['path']); }
    }
    public function testStockLedgerRejectsInvalidPeriodAndUnauthorizedThresholdChanges(): void
    {
        $ledger=new \App\Modules\Inventory\StockLedgerService(new \App\Modules\Inventory\StockLedgerRepository($this->pdo)); $id=(int)$this->variant['product_id'];
        foreach([['from'=>'2026-02-30'],['from'=>'2026-12-01','to'=>'2026-01-01']] as $filters) {
            try { $ledger->detail($id,$filters,$this->actor); self::fail('Invalid interval accepted'); } catch(InvoiceException $e) { self::assertNotSame('',$e->getMessage()); }
        }
        $this->expectException(InvoiceException::class); $ledger->threshold($id,'4',['id'=>0,'role'=>'administrator']);
    }
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
    public function testManualIssueEmailChoiceIsAtomicAndIdempotent(): void
    {
        $order=$this->order(1);
        $input=['request_key'=>'manual-issue-with-email','send_email'=>'1'];
        $invoice=$this->service->issue($order,$input,$this->actor);
        self::assertSame('queued',$invoice['email_state']);
        $this->service->issue($order,$input,$this->actor);
        $this->service->issue($order,['request_key'=>'another-issue-with-email','send_email'=>'1'],$this->actor);
        self::assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='invoice'")->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM invoice_public_links')->fetchColumn());
        $off=$this->service->issue($this->order(1),['request_key'=>'manual-issue-no-email','send_email'=>'0'],$this->actor);
        self::assertSame('not_sent',$off['email_state']);
        self::assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='invoice'")->fetchColumn());
        try { $this->service->issue($order,array_replace($input,['send_email'=>'0']),$this->actor); self::fail('Changed retry must be rejected'); }
        catch(InvoiceException $e) { self::assertSame(409,$e->getCode()); }
        $next=$this->order(1); $balance=$this->balance();
        $this->pdo->exec("CREATE TRIGGER reject_invoice_email BEFORE INSERT ON outbox_messages WHEN NEW.message_type='invoice' BEGIN SELECT RAISE(ABORT,'QA queue unavailable'); END");
        try { $this->service->issue($next,['request_key'=>'manual-issue-email-rollback','send_email'=>'1'],$this->actor); self::fail('Queue failure must roll back'); }
        catch(\PDOException) { self::assertSame(2,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn()); self::assertSame($balance,$this->balance()); }
    }

    public function testEmailCtaOpensPrivatePdfWithoutLoginAndRevocationStillWorks(): void
    {
        $invoice=$this->service->issue($this->order(1),['request_key'=>'email-direct-link','send_email'=>'1'],$this->actor);
        $payload=json_decode($this->pdo->query("SELECT payload_json FROM outbox_messages WHERE message_type='invoice'")->fetchColumn(),true);
        $mail=(new \App\Modules\Invoicing\InvoiceEmail(new InvoiceRepository($this->pdo),Config::load($this->root),$this->root))->render($payload,'invoice.customer@example.test');
        $url='http://localhost/factura/'.$payload['public_token'].'/?view=1';
        self::assertStringContainsString('href="'.$url.'"',$mail['html']); self::assertStringContainsString('Deschide factura',$mail['html']);
        self::assertStringContainsString('Rezumatul facturii',$mail['html']); self::assertStringContainsString($url,$mail['text']);
        $_SESSION=[];
        $factory=new \Slim\Psr7\Factory\ServerRequestFactory();
        $app=\App\AppFactory::create($this->root);
        $response=$app->handle($factory->createServerRequest('GET',$url,['REMOTE_ADDR'=>'127.0.0.74']));
        self::assertSame(200,$response->getStatusCode()); self::assertStringStartsWith('inline;',$response->getHeaderLine('Content-Disposition'));
        self::assertSame('noindex, nofollow',$response->getHeaderLine('X-Robots-Tag')); self::assertStringStartsWith('%PDF',(string)$response->getBody());
        $this->service->publicLink($invoice['id'],$this->actor,true);
        self::assertSame(404,$app->handle($factory->createServerRequest('GET',$url))->getStatusCode());
        unset($app); gc_collect_cycles();
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
    public function testInvoiceBeforeAnyNirShowsNegativeBalanceUntilPurchaseIsEntered(): void
    {
        $order=$this->order(2); self::assertSame(0,$this->balance());
        $online=$this->pdo->query('SELECT * FROM inventory ORDER BY variant_id')->fetchAll();
        $invoice=$this->service->issue($order,['request_key'=>'negative-before-purchase'],$this->actor);
        self::assertSame(-20000,$this->balance());
        $this->purchase(2,'40','LATE-EXACT');
        self::assertSame(0,$this->balance());
        self::assertSame(0,$this->service->document($invoice['id'],$this->actor)['lines'][0]['pending_units']);
        self::assertSame($online,$this->pdo->query('SELECT * FROM inventory ORDER BY variant_id')->fetchAll());
    }
    public function testIssuanceConfirmationRejectsChangesAndNeverConsumesANumber(): void
    {
        $order=$this->order(1); $confirmation=$this->service->issuanceConfirmation($order,$this->actor);
        self::assertSame(1,$confirmation['number']);
        $this->pdo->exec("UPDATE orders SET first_name='Schimbat' WHERE id=".$order);
        try {
            $this->service->issue($order,['request_key'=>'stale-confirmation','confirmation_hash'=>$confirmation['confirmation_hash'],'expected_number'=>$confirmation['series'].' '.$confirmation['number']],$this->actor);
            self::fail('A stale confirmation was accepted');
        } catch(InvoiceException $e) { self::assertSame(409,$e->getCode()); }
        self::assertSame(0,$this->balance());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
    }
    public function testEditingFromOrderRevisesSameInvoiceAndShippingWithoutAdditionalStockExit(): void
    {
        $orderId=$this->order(2); $invoice=$this->service->issue($orderId,['request_key'=>'order-edit-existing'],$this->actor);
        $dispatch=new \App\Modules\Invoicing\DispatchNoteService(new \App\Modules\Invoicing\DispatchNoteRepository($this->pdo),$this->root,new CompanyStampStorage($this->root));
        $dispatch->issue($orderId,str_repeat('9',32),$this->actor);
        $order=(new InvoiceRepository($this->pdo))->one('orders',['id'=>$orderId]);
        $shipping=['address'=>'Strada nouă 2','city'=>'Iași','county'=>'Iași','country'=>'RO','postal_code'=>''];
        $this->service->editOrder($orderId,['request_key'=>'edit-order-existing','order_version'=>(new InvoiceRepository($this->pdo))->editVersion($orderId),'version'=>$invoice['row_version'],'reason'=>'Corectare date client','billing'=>['name'=>'Maria Pop','email'=>'maria@example.test'],'shipping_address'=>$shipping],$this->actor);
        $updated=$this->service->load($invoice['id']);
        self::assertSame($invoice['number'],$updated['number']); self::assertSame($invoice['series_code'],$updated['series_code']);
        self::assertSame('Maria Pop',$updated['snapshot']['customer']['name']); self::assertSame($shipping,$updated['snapshot']['shipping_address']);
        self::assertSame('Maria Pop',$dispatch->forOrder($orderId,$this->actor)['snapshot']['customer']['name']); self::assertStringContainsString('Strada nouă 2',$dispatch->forOrder($orderId,$this->actor)['snapshot']['customer']['address']);
        self::assertSame(-20000,$this->balance()); self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
        self::assertNotSame($invoice['content_hash'] ?? $invoice['snapshot_json'],$updated['content_hash'] ?? $updated['snapshot_json']);
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
        $invoice=$this->service->issue($order,['request_key'=>'paid-stripe-test'],$this->actor); self::assertSame(1,$invoice['number']); self::assertSame('paid',$invoice['payment_state']); self::assertTrue((new \App\Modules\Invoicing\InvoiceExportService(new InvoiceRepository($this->pdo),$this->root))->pdfData($invoice)['payment_paid']);
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
        $order=(new InvoiceRepository($this->pdo))->one('orders',['id'=>$invoice['order_id']]);
        self::assertSame((int)$updated['gross_bani'],(int)$order['total_bani']);
        self::assertSame(3,(int)$this->pdo->query('SELECT quantity FROM order_items WHERE id='.(int)$item['id'])->fetchColumn());
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
        $input=['request_key'=>'return-request-case','reason'=>'Mărime nepotrivită','quantities'=>[$line['id']=>'2'],'iban'=>'RO49AAAA1B31007593840000','holder'=>'Ana Pop','bank_name'=>'Banca QA'];
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
        $this->cancelWithDocuments($second['order_id'],'delete','cancel-second-delete'); self::assertSame(-10000,$this->balance());
        $replacement=$this->service->issue($this->order(1),['request_key'=>'cancel-third-case'],$this->actor); self::assertSame(2,$replacement['number']);
        $this->cancelWithDocuments($first['order_id'],'credit','cancel-first-credit');
        self::assertCount(1,$this->service->document($first['id'],$this->actor)['related']); self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM invoice_refunds')->fetchColumn());
    }
    public function testExportsAndEmailUseHistoricalSnapshotsAndRevocableLinks(): void
    {
        $invoice=$this->service->issue($this->order(2),['request_key'=>'issue-export-case'],$this->actor);
        $exports=new \App\Modules\Invoicing\InvoiceExportService(new InvoiceRepository($this->pdo),$this->root);
        $data=$exports->pdfData($invoice); self::assertFalse($data['payment_paid']); self::assertSame('156,1983 Lei',$data['lines'][0]['unit_net']);
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
        $workflow=new \App\Modules\Invoicing\CancellationInvoiceWorkflow(new InvoiceRepository($this->pdo),$this->service);
        $input=$this->cancelWithDocuments($invoice['order_id'],'credit','cancel-chain-explicit');
        $count=(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
        self::assertSame(0,(new InvoiceRepository($this->pdo))->orderNet($invoice['order_id']));
        $workflow->resolve($invoice['order_id'],$input,$this->actor);
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
    public function testAutomationSeparatesIssuanceAndEmailForCardAndCod(): void
    {
        $automation=new \App\Modules\Invoicing\InvoiceAutomationService(new \App\Modules\Invoicing\InvoiceAutomationRepository($this->pdo));
        $repo=new InvoiceRepository($this->pdo);
        foreach([['cod',false],['cod',true],['card',false],['card',true]] as [$method,$email]) {
            $automation->save(['revision'=>$automation->settings()['revision']],$this->actor['id']);
            $id=$this->order(1);
            if($method==='card') { $repo->update('orders',['payment_method'=>'stripe','payment_status'=>'paid','status'=>'confirmed'],['id'=>$id]); }
            $automation->save(['revision'=>$automation->settings()['revision'],$method.'_issue'=>1,$method.'_email'=>(int)$email],$this->actor['id']);
            $automation->prepareForOrder($id);
            $processed=\App\Modules\Invoicing\InvoiceModule::process($this->pdo,$this->root);
            self::assertSame(['issued'=>1,'failed'=>0,'emails'=>(int)$email],$processed);
            self::assertSame(['issued'=>0,'failed'=>0,'emails'=>0],\App\Modules\Invoicing\InvoiceModule::process($this->pdo,$this->root));
            $documents=$repo->currentOrderInvoices($id);self::assertCount(1,$documents);
            $invoice=$this->service->load((int)$documents[0]['id']);
            self::assertSame($email?'queued':'not_sent',$invoice['email_state']);
            $messages=array_values(array_filter($repo->all('outbox_messages',['message_type'=>'invoice']),static fn(array $row):bool=>(int)json_decode($row['payload_json'],true)['invoice_id']===(int)$invoice['id']));
            self::assertCount((int)$email,$messages);
            if($email) {
                $rendered=(new \App\Modules\Invoicing\InvoiceEmail($repo,Config::load($this->root),$this->root))->render(json_decode($messages[0]['payload_json'],true),$invoice['snapshot']['customer']['email']);
                self::assertStringContainsString('/factura/',$rendered['html']);
                self::assertSame('%PDF',substr((string)file_get_contents($rendered['attachment']),0,4));
            }
        }
    }

    public function testManualEmailIsNotQueuedAgainByPendingAutomation(): void
    {
        $order=$this->order(1);
        $automation=new \App\Modules\Invoicing\InvoiceAutomationService(new \App\Modules\Invoicing\InvoiceAutomationRepository($this->pdo));
        $automation->save(['revision'=>$automation->settings()['revision'],'cod_issue'=>1,'cod_email'=>1],$this->actor['id']);
        $automation->prepareForOrder($order);
        $this->service->issue($order,['request_key'=>'manual-before-automation','send_email'=>'1'],$this->actor);
        $result=\App\Modules\Invoicing\InvoiceModule::process($this->pdo,$this->root);
        self::assertSame(0,$result['emails']); self::assertSame(0,$result['failed']);
        self::assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='invoice'")->fetchColumn());
        self::assertSame('complete',$this->pdo->query('SELECT state FROM invoice_automation_intents WHERE order_id='.$order)->fetchColumn());
    }
    public function testPhysicalVerificationStartsWithReturnedQuantityWithoutNotesOrEmail(): void
    {
        $this->purchase(3,'40','SIMPLE-RECEIPT');
        $invoice=$this->service->issue($this->order(2),['request_key'=>'simple-receipt-sale'],$this->actor);
        $credit=$this->service->credit($invoice['id'],['request_key'=>'simple-receipt-credit','version'=>$invoice['row_version'],'reason'=>'Retur client','quantities'=>[$invoice['lines'][0]['id']=>1],'notify_customer'=>'0'],$this->actor);
        $line=$credit['lines'][0]['id'];$repo=new InvoiceRepository($this->pdo);
        $_SESSION['user_id']=$this->actor['id'];$factory=new \Slim\Psr7\Factory\ServerRequestFactory();
        $app=\App\AppFactory::create($this->root);$url='http://localhost/admin/facturi/'.$credit['id'].'/receive/';
        $form=$app->handle($factory->createServerRequest('GET',$url)->withHeader('X-Invoice-Modal','1'));
        self::assertSame(200,$form->getStatusCode());$html=(string)$form->getBody();
        self::assertStringContainsString('name="received['.$line.']" value="1"',$html);
        self::assertStringContainsString('name="restock['.$line.']" value="1"',$html);
        self::assertStringNotContainsString('name="reason"',$html);
        self::assertStringNotContainsString('name="notify_customer"',$html);
        self::assertMatchesRegularExpression('/name="received_at" value="[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}"/',$html);
        $messages=count($repo->all('outbox_messages'));$balance=$this->balance();
        $input=['_csrf'=>$_SESSION['_csrf'],'request_key'=>'simple-receipt-confirm','version'=>$credit['row_version'],'received'=>[$line=>'1'],'restock'=>[$line=>'0']];
        $request=$factory->createServerRequest('POST',$url,['REMOTE_ADDR'=>'127.0.0.85'])->withHeader('Accept','application/json')->withParsedBody($input);
        $response=$app->handle($request);self::assertSame(200,$response->getStatusCode(),(string)$response->getBody());
        self::assertSame(200,$app->handle($request)->getStatusCode());
        $receipts=$repo->all('invoice_receipts',['invoice_id'=>$credit['id']]);self::assertCount(1,$receipts);
        $stored=json_decode($receipts[0]['lines_json'],true)[0];
        self::assertSame(10000,$stored['received_units']);self::assertSame(0,$stored['restock_units']);self::assertSame(10000,$stored['quarantine_units']);
        self::assertSame($balance,$this->balance());self::assertSame($messages,count($repo->all('outbox_messages')));
        self::assertNotEmpty($receipts[0]['notes']);
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
    public static function returnCostCoverage(): array { return ['missing'=>[0,false],'partial'=>[1,false],'free acquisition'=>[2,true]]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('returnCostCoverage')]
    public function testReturnCostIsUnknownUntilCoveredByActualAcquisitionLots(int $purchased,bool $complete): void
    {
        if($purchased>0) { $this->purchase($purchased,'0','RETURN-COVERAGE'); }
        $sale=$this->service->issue($this->order(2),['request_key'=>'cost-coverage-sale'],$this->actor);
        $credit=$this->service->credit($sale['id'],['request_key'=>'cost-coverage-credit','version'=>1,'reason'=>'Retur','quantities'=>[$sale['lines'][0]['id']=>2],'notify_customer'=>'0'],$this->actor);
        $received=$this->service->receive($credit['id'],['request_key'=>'cost-coverage-receipt','version'=>1,'received'=>[$credit['lines'][0]['id']=>2],'restock'=>[$credit['lines'][0]['id']=>2]],$this->actor);
        $nir=(new NirService($this->pdo))->document($received['receipt_nir']['id'],$this->actor);
        self::assertSame($complete,$nir['cost_complete']); self::assertSame($complete,$nir['lines'][0]['cost_complete']);
        self::assertSame($purchased*10000,(int)$this->pdo->query('SELECT COALESCE(SUM(initial_units),0) FROM nir_fifo_layers WHERE nir_id='.(int)$nir['id'])->fetchColumn());
        $twig=new \Twig\Environment(new \Twig\Loader\FilesystemLoader($this->root.'/resources/views'),['autoescape'=>'html']);
        $html=$twig->render('admin/nir/pdf.twig',['nir'=>$nir,'lines'=>$nir['lines'],'view_costs'=>true,'logo'=>null,'stamp'=>null,'nir_date'=>'15.11.2026','type_label'=>'Recepție retur client','status_label'=>'Confirmat','number'=>$nir['number']]);
        if(!$complete) { self::assertStringContainsString('Cost de achiziție neînregistrat',$html); self::assertStringNotContainsString('0,00 lei',$html); }
        else { self::assertStringNotContainsString('Cost de achiziție neînregistrat',$html); self::assertStringContainsString('0,00 lei',$html); }
        $resale=$this->service->issue($this->order(2),['request_key'=>'cost-coverage-resale'],$this->actor);
        self::assertSame((2-$purchased)*10000,$resale['lines'][0]['pending_units']);
    }

    public function testReturnNirPreservesOriginalLotCostsOnResaleAndExportsOriginalInvoice(): void
    {
        $this->purchase(1,'40','RETURN-LOT-A'); $this->purchase(2,'53.27','RETURN-LOT-B');
        $sale=$this->service->issue($this->order(3),['request_key'=>'return-cost-sale'],$this->actor);
        $repo=new InvoiceRepository($this->pdo);
        $repo->update('invoices',['issued_at'=>'2026-11-09T23:30:00Z'],['id'=>$sale['id']]);
        $credit=$this->service->credit($sale['id'],['request_key'=>'return-cost-credit','version'=>1,'reason'=>'Retur','quantities'=>[$sale['lines'][0]['id']=>3],'notify_customer'=>'0'],$this->actor);
        $received=$this->service->receive($credit['id'],['request_key'=>'return-cost-receipt','version'=>1,'received'=>[$credit['lines'][0]['id']=>3],'restock'=>[$credit['lines'][0]['id']=>3]],$this->actor);
        $nirs=new NirService($this->pdo); $nir=$nirs->document($received['receipt_nir']['id'],$this->actor);
        self::assertTrue($nir['cost_complete']); self::assertSame(14654,$nir['inventory_bani']);
        self::assertSame($sale['id'],$nir['original_invoices'][0]['id']); self::assertSame('2026-11-10',$nir['original_invoices'][0]['invoice_date']);
        $layers=$this->pdo->query('SELECT initial_value_bani FROM nir_fifo_layers WHERE nir_id='.(int)$nir['id'].' ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([4000,10654],array_map('intval',$layers));
        $resale=$this->service->issue($this->order(3),['request_key'=>'return-cost-resale'],$this->actor);
        self::assertSame(14654,array_sum(array_column($resale['lines'][0]['trace'],'value_bani')));
        self::assertSame(0,$resale['lines'][0]['pending_units']); self::assertSame(0,$this->balance());
        $nir=$nirs->document($nir['id'],$this->actor); self::assertTrue($nir['cost_complete']);
        $exporter=new \App\Modules\Inventory\NirExportService($this->root); $file=$exporter->individual($nir,'xlsx');
        try {
            $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($file['path']); $sheet=$book->getActiveSheet();
            self::assertSame($sale['display_number'].' / 10.11.2026',$sheet->getCell('J8')->getValue());
            self::assertEquals(146.54,$sheet->getCell('J13')->getValue()); $book->disconnectWorksheets();
        } finally { $exporter->cleanup($file['path']); }
    }

    public function testVerificationConfirmsReturnNirWithReceptionDateAndInvoiceAttachment(): void
    {
        $this->purchase(5,'40','AUTOMATIC-RETURN-NIR'); $order=$this->order(3);
        $invoice=$this->service->issue($order,['request_key'=>'automatic-nir-sale'],$this->actor);
        $repo=new InvoiceRepository($this->pdo); $repo->update('orders',['status'=>'delivered'],['id'=>$order]);
        $returnId=$this->service->returnOrder($order,['request_key'=>'automatic-nir-return','quantities'=>[$invoice['lines'][0]['order_item_id']=>3],'reason'=>'Retur client','issue_invoice'=>'1','notify_customer'=>'0'],$this->actor);
        $creditId=(int)$repo->one('order_return_documents',['return_id'=>$returnId])['invoice_id'];
        $repo->update('invoices',['issued_at'=>'2026-11-10T23:30:00Z'],['id'=>$creditId]);
        $credit=$this->service->document($creditId,$this->actor); $line=$credit['lines'][0]['id'];
        self::assertSame(20000,$this->balance()); self::assertNull($credit['receipt_nir']);
        $input=['request_key'=>'automatic-nir-confirm','version'=>$credit['row_version'],'received_at'=>'2026-11-14T10:25','received'=>[$line=>'2'],'restock'=>[$line=>'1']];
        $result=$this->service->receive($creditId,$input,$this->actor);
        $nirs=new NirService($this->pdo); $nir=$nirs->document((int)$result['receipt_nir']['id'],$this->actor);
        self::assertSame('customer_return',$nir['type']); self::assertSame('confirmed',$nir['status']);
        self::assertSame('2026-11-14T08:25:00Z',$nir['received_at']); self::assertSame($nir['received_at'],$nir['nir_at']);
        self::assertSame('2026-11-11',$nir['invoice_date']); self::assertSame($credit['series_code'],$nir['invoice_series']); self::assertSame((string)$credit['number'],$nir['invoice_number']);
        self::assertSame('3.0000',$nir['lines'][0]['invoice_quantity']); self::assertSame('2.0000',$nir['lines'][0]['received_quantity']);
        self::assertSame('1.0000',$nir['lines'][0]['accepted_quantity']); self::assertSame('1.0000',$nir['lines'][0]['rejected_quantity']);
        self::assertSame(4000,$nir['inventory_bani']); self::assertSame(30000,$this->balance());
        $layer=$this->pdo->query('SELECT * FROM nir_fifo_layers WHERE nir_id='.(int)$nir['id'])->fetch();
        self::assertSame($nir['received_at'],$layer['received_at']); self::assertSame(10000,(int)$layer['initial_units']);
        self::assertSame('NIR_CUSTOMER_RETURN',$this->pdo->query('SELECT type FROM nir_stock_movements WHERE nir_id='.(int)$nir['id'])->fetchColumn());
        self::assertCount(1,$nir['attachments']); $file=$nir['attachments'][0];
        self::assertSame('Factura de retur '.$credit['display_number'].'.pdf',$file['original_name']);
        $path=(new \App\Modules\Inventory\NirDocumentStorage($this->root))->path($file['stored_name']);
        self::assertNotNull($path); self::assertSame($file['sha256'],hash_file('sha256',$path)); self::assertStringStartsWith('%PDF-',file_get_contents($path));
        self::assertSame($nir['id'],$this->service->receive($creditId,$input,$this->actor)['receipt_nir']['id']);
        self::assertSame(30000,$this->balance()); self::assertSame([],$nirs->pendingReturnReceipts($this->actor));
        $_SESSION['user_id']=$this->actor['id']; $factory=new \Slim\Psr7\Factory\ServerRequestFactory(); $app=\App\AppFactory::create($this->root);
        $page=$app->handle($factory->createServerRequest('GET','http://localhost/admin/comenzi/'.$order.'/'));
        self::assertSame(200,$page->getStatusCode()); self::assertStringNotContainsString('Au ajuns produsele?',(string)$page->getBody());
        self::assertStringContainsString('admin/nir-uri/'.$nir['id'].'/',(string)$page->getBody());
        $download=$app->handle($factory->createServerRequest('GET','http://localhost/admin/nir-uri/'.$nir['id'].'/documente/'.$file['id'].'/'));
        self::assertSame(200,$download->getStatusCode()); self::assertSame($file['sha256'],hash('sha256',(string)$download->getBody()));
        $delete=$app->handle($factory->createServerRequest('POST','http://localhost/admin/nir-uri/'.$nir['id'].'/sterge/')
            ->withHeader('Accept','application/json')->withParsedBody(['_csrf'=>$_SESSION['_csrf'],'row_version'=>$nir['row_version']]));
        self::assertSame(200,$delete->getStatusCode(),(string)$delete->getBody());
        self::assertSame('/admin/comenzi/'.$order.'/',json_decode((string)$delete->getBody(),true)['redirect']);
        self::assertSame(20000,$this->balance()); self::assertNull($this->service->document($creditId,$this->actor)['receipt_nir']);
        self::assertNull($repo->one('invoice_receipts',['invoice_id'=>$creditId]));
        self::assertFileDoesNotExist($path);
        $page=$app->handle($factory->createServerRequest('GET','http://localhost/admin/comenzi/'.$order.'/'));
        self::assertStringContainsString('Au ajuns produsele?',(string)$page->getBody());
        self::assertStringContainsString('Verifică produsele și creează NIR',(string)$page->getBody());
        self::assertNull($repo->one('invoices',['id'=>$creditId])['deleted_at']);
        try { $this->service->receive($creditId,$input,$this->actor); self::fail('Deleted confirmation replayed'); }
        catch(InvoiceException $error) { self::assertSame(409,$error->getCode()); }
        $input['request_key']='automatic-nir-recreated'; $input['version']=$this->service->document($creditId,$this->actor)['row_version'];
        $recreated=$this->service->receive($creditId,$input,$this->actor);
        self::assertNotSame($nir['id'],$recreated['receipt_nir']['id']); self::assertSame($nir['number'],$recreated['receipt_nir']['number']);
        self::assertSame(30000,$this->balance()); self::assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM nir_documents WHERE type='customer_return'")->fetchColumn());
        self::assertSame($recreated['receipt_nir']['id'],$this->service->receive($creditId,$input,$this->actor)['receipt_nir']['id']);
        self::assertSame(30000,$this->balance());
    }
    /** @return array<string,array{bool}> */
    public static function legacyReceiptScopes(): array { return ['tracked'=>[true],'untracked'=>[false]]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('legacyReceiptScopes')]
    public function testLegacyVerificationGetsMissingNirWithoutPostingStockAgain(bool $tracked): void
    {
        $this->purchase(5,'40','LEGACY-RETURN-NIR');
        if(!$tracked) { $this->pdo->exec('UPDATE products SET track_in_conta=0 WHERE id='.$this->variant['product_id']); }
        $this->pdo->exec('UPDATE product_variants SET stock_unlimited=0 WHERE id='.$this->variant['id']);
        $order=$this->order(2); $invoice=$this->service->issue($order,['request_key'=>'legacy-nir-sale'],$this->actor);
        $orders=new \App\Modules\Orders\OrderService($this->pdo); $orders->transition($order,'ship',null,$this->actor['id']);
        $credit=$this->service->credit($invoice['id'],['request_key'=>'legacy-nir-credit','version'=>1,'reason'=>'Retur client','quantities'=>[$invoice['lines'][0]['id']=>2],'notify_customer'=>'0'],$this->actor);
        $repo=new InvoiceRepository($this->pdo); $line=$credit['lines'][0];
        $plan=['line_id'=>$line['id'],'received_units'=>20000,'restock_units'=>10000,'quarantine_units'=>10000,'missing_units'=>0];
        $receipt=$repo->insert('invoice_receipts',['invoice_id'=>$credit['id'],'actor_id'=>$this->actor['id'],'received_at'=>'2026-11-15T12:00:00Z','lines_json'=>json_encode([$plan]),'notes'=>'Verificare veche','reversed_at'=>null]);
        $stock=new InvoiceStockService($this->pdo); $stock->receive($receipt,$line,$invoice['lines'][0],10000,$this->actor); $stock->receiveOnline($receipt,$line,$invoice['lines'][0],10000,$this->actor);
        $accounting=$this->balance(); $online=(int)$this->pdo->query('SELECT physical_quantity FROM inventory WHERE variant_id='.$this->variant['id'])->fetchColumn();
        $_SESSION['user_id']=$this->actor['id']; $factory=new \Slim\Psr7\Factory\ServerRequestFactory(); $app=\App\AppFactory::create($this->root);
        $page=$app->handle($factory->createServerRequest('GET','http://localhost/admin/comenzi/'.$order.'/'));
        self::assertStringContainsString('Recepție fără NIR',(string)$page->getBody()); self::assertStringContainsString('Generează NIR-ul recepției',(string)$page->getBody());
        $form=$app->handle($factory->createServerRequest('GET','http://localhost/admin/facturi/'.$credit['id'].'/receive/')->withHeader('X-Invoice-Modal','1'));
        self::assertSame(200,$form->getStatusCode()); self::assertStringContainsString('name="restock['.$line['id'].']" value="1" readonly',(string)$form->getBody());
        $input=['request_key'=>'legacy-nir-complete','version'=>1,'received'=>[$line['id']=>2],'restock'=>[$line['id']=>1]];
        try { $this->service->receive($credit['id'],array_replace($input,['restock'=>[$line['id']=>2]]),$this->actor); self::fail('Changed legacy quantities accepted'); }
        catch(InvoiceException $error) { self::assertSame(409,$error->getCode()); }
        $completed=$this->service->receive($credit['id'],$input,$this->actor); $nirs=new NirService($this->pdo);
        self::assertSame($accounting,$this->balance()); self::assertSame($online,(int)$this->pdo->query('SELECT physical_quantity FROM inventory WHERE variant_id='.$this->variant['id'])->fetchColumn());
        self::assertSame('2026-11-15T12:00:00Z',$completed['receipt_nir']['received_at']); self::assertSame($receipt,(int)$completed['receipt_nir']['invoice_receipt_id']);
        $nir=$nirs->document($completed['receipt_nir']['id'],$this->actor); self::assertCount(1,$nir['attachments']); self::assertSame('customer_return',$nir['type']);
        self::assertSame($tracked,$nir['cost_complete']); self::assertSame($tracked,$nir['lines'][0]['cost_complete']);
        self::assertSame($invoice['id'],$nir['original_invoices'][0]['id']);
        $nirPage=$app->handle($factory->createServerRequest('GET','http://localhost/admin/nir-uri/'.$nir['id'].'/'));
        self::assertSame(200,$nirPage->getStatusCode()); self::assertStringContainsString('Factura inițială:',(string)$nirPage->getBody());
        if(!$tracked) { self::assertStringContainsString('Cost de achiziție neînregistrat',(string)$nirPage->getBody()); }
        $exporter=new \App\Modules\Inventory\NirExportService($this->root); $file=$exporter->individual($nir,'xlsx');
        try {
            $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($file['path']);
            if(!$tracked) { self::assertNull($book->getActiveSheet()->getCell('J13')->getValue()); self::assertSame('—',$book->getActiveSheet()->getCell('M14')->getValue()); }
            $book->disconnectWorksheets();
        } finally { $exporter->cleanup($file['path']); }
        self::assertSame($nir['id'],$this->service->receive($credit['id'],$input,$this->actor)['receipt_nir']['id']);
        $nirs->delete($nir['id'],$nir['row_version'],$this->actor);
        self::assertSame($accounting-($tracked?10000:0),$this->balance()); self::assertSame($online-1,(int)$this->pdo->query('SELECT physical_quantity FROM inventory WHERE variant_id='.$this->variant['id'])->fetchColumn());
        $fresh=$this->service->document($credit['id'],$this->actor); self::assertNull($fresh['receipt']); self::assertNull($fresh['receipt_nir']);
        $input['request_key']='legacy-nir-new-reception'; $input['version']=$fresh['row_version'];
        $again=$this->service->receive($credit['id'],$input,$this->actor);
        self::assertSame($accounting,$this->balance()); self::assertSame($online,(int)$this->pdo->query('SELECT physical_quantity FROM inventory WHERE variant_id='.$this->variant['id'])->fetchColumn());
        self::assertSame($nir['number'],$again['receipt_nir']['number']);
    }
    public function testFailedNirGenerationRollsBackVerificationStockAndAttachment(): void
    {
        $this->purchase(3,'40','ROLLBACK-RETURN-NIR');
        $invoice=$this->service->issue($this->order(1),['request_key'=>'rollback-nir-sale'],$this->actor);
        $credit=$this->service->credit($invoice['id'],['request_key'=>'rollback-nir-credit','version'=>1,'reason'=>'Retur','quantities'=>[$invoice['lines'][0]['id']=>1],'notify_customer'=>'0'],$this->actor);
        $input=['request_key'=>'rollback-nir-confirm','version'=>1,'received_at'=>'2026-11-14T10:25','received'=>[$credit['lines'][0]['id']=>1],'restock'=>[$credit['lines'][0]['id']=>1]];
        $this->pdo->exec("CREATE TRIGGER qa_fail_return_nir BEFORE INSERT ON nir_documents WHEN NEW.type='customer_return' BEGIN SELECT RAISE(ABORT,'QA NIR unavailable'); END");
        $files=glob($this->root.'/storage/private/nir-documents/*.pdf');
        try { $this->service->receive($credit['id'],$input,$this->actor); self::fail('A failed NIR changed stock'); }
        catch(\PDOException $error) { self::assertStringContainsString('QA NIR unavailable',$error->getMessage()); }
        self::assertSame(20000,$this->balance()); self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM invoice_receipts')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT SUM(returned_units) FROM nir_fifo_consumptions')->fetchColumn());
        self::assertSame($files,glob($this->root.'/storage/private/nir-documents/*.pdf'));
        $this->pdo->exec('DROP TRIGGER qa_fail_return_nir');
        foreach(['2026-02-30T10:00','invalid',''] as $date) {
            try { $this->service->receive($credit['id'],array_replace($input,['received_at'=>$date]),$this->actor); self::fail('Invalid reception date accepted'); }
            catch(\App\Modules\Inventory\NirValidationException $error) { self::assertSame(422,$error->getCode()); }
        }
        self::assertSame(20000,$this->balance());
        self::assertNotNull($this->service->receive($credit['id'],$input,$this->actor)['receipt_nir']); self::assertSame(30000,$this->balance());
    }
    public function testHttpRequiresAdminAndCsrfAndSupportsPreviewWithoutJavaScript(): void
    {
        $order=$this->order(1); $factory=new \Slim\Psr7\Factory\ServerRequestFactory();
        $send=function(string $method,string $path,array $input=[])use($factory) { $app=\App\AppFactory::create($this->root); $request=$factory->createServerRequest($method,'http://localhost'.$path,['REMOTE_ADDR'=>'127.0.0.71'])->withParsedBody($input)->withHeader('Accept','application/json'); $response=$app->handle($request); unset($app); gc_collect_cycles(); return $response; };
        self::assertContains($send('GET','/admin/facturi/')->getStatusCode(),[302,303,401,403]);
        $_SESSION['user_id']=$this->actor['id'];
        self::assertSame(200,$send('GET','/admin/facturi/')->getStatusCode());
        self::assertSame(419,$send('POST','/admin/facturi/emite/',['order_id'=>$order,'request_key'=>'http-issue-test'])->getStatusCode());
        $confirmation=$this->service->issuanceConfirmation($order,$this->actor);
        $response=$send('POST','/admin/facturi/emite/',['_csrf'=>$_SESSION['_csrf'],'order_id'=>$order,'request_key'=>'http-issue-test','confirmation_hash'=>$confirmation['confirmation_hash'],'expected_number'=>$confirmation['series'].' '.$confirmation['number']]);
        self::assertSame(200,$response->getStatusCode(),(string)$response->getBody());
        $result=json_decode((string)$response->getBody(),true);
        self::assertSame('/admin/facturi/'.$this->pdo->query('SELECT id FROM invoices')->fetchColumn().'/',$result['redirect']);
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
        self::assertSame('partial',$order['payment_status']);
        self::assertSame($net,$repo->orderCash($invoice['order_id'])['received']);
        // Manual SPV mode leaves an issued document unsent, without a transmission job.
        self::assertSame(1,$repo->registry(['fiscal_state'=>'not_sent'])['total']);
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM invoice_fiscal_jobs')->fetchColumn());
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
        foreach(['edit','return','correct','payment','delete','email','link'] as $action) {
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
        $request=$this->service->requestReturn($invoice['id'],['request_key'=>'reject-request-input','reason'=>'Cerere test','quantities'=>[$line['id']=>'1'],'iban'=>'RO49AAAA1B31007593840000','holder'=>'Ana Pop','bank_name'=>'Banca QA'],$this->actor);
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

    public function testInvoiceBillingEditsAndCorrectionsSynchronizeOrderButPreserveShippingAndOriginal(): void
    {
        $repo=new InvoiceRepository($this->pdo);
        $invoice=$this->service->issue($this->order(2),['request_key'=>'sync-billing-issue'],$this->actor);
        $before=$repo->one('orders',['id'=>$invoice['order_id']]);
        $billing=['name'=>'Ioana Client Test','email'=>'ioana@example.test','phone'=>'0712345678','address'=>['address'=>'Strada Nouă 10','city'=>'Cluj-Napoca','county'=>'Cluj','country'=>'România','postal_code'=>'400001']];
        $updated=$this->service->edit($invoice['id'],['request_key'=>'sync-billing-edit','version'=>1,'reason'=>'Date corecte','billing'=>$billing],$this->actor);
        $order=$repo->one('orders',['id'=>$invoice['order_id']]);
        self::assertSame($billing['name'],$order['first_name'].' '.$order['last_name']);
        self::assertSame($billing['email'],$order['email']);self::assertSame($billing['phone'],$order['phone']);
        self::assertSame($billing['address'],json_decode($order['billing_address_json'],true));
        self::assertSame($before['shipping_address_json'],$order['shipping_address_json']);
        self::assertSame($billing['name'],$updated['snapshot']['customer']['name']);
        // Failure after the sync must roll back the order as well as the invoice.
        try { $this->service->edit($invoice['id'],['request_key'=>'sync-billing-rollback','version'=>$updated['row_version'],'reason'=>'Test validare','billing'=>['name'=>'Nu se salvează'],'notes'=>str_repeat('x',5001)],$this->actor); self::fail('Invalid notes accepted'); }
        catch(InvoiceException) { self::assertSame($order,$repo->one('orders',['id'=>$invoice['order_id']])); }
        $corrected=$this->service->correct($invoice['id'],['request_key'=>'sync-billing-correct','version'=>$updated['row_version'],'reason'=>'Corectare client','billing'=>['name'=>'Maria Client','email'=>'maria@example.test']],$this->actor);
        $order=$repo->one('orders',['id'=>$invoice['order_id']]);
        self::assertSame('Maria Client',$order['first_name'].' '.$order['last_name']);self::assertSame('maria@example.test',$order['email']);
        self::assertSame($before['shipping_address_json'],$order['shipping_address_json']);
        self::assertSame('Maria Client',$corrected['snapshot']['customer']['name']);
        self::assertSame($updated['snapshot_json'],$this->service->load($invoice['id'])['snapshot_json']);
        self::assertSame(2,(int)$this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='order.invoice_billing_edit'")->fetchColumn());
    }
    public function testOrderPreparationModalReturnsToTheOrderAfterCancelOrSave(): void
    {
        $orderId=$this->order(1);$repo=new InvoiceRepository($this->pdo);$order=$repo->one('orders',['id'=>$orderId]);$item=$repo->all('order_items',['order_id'=>$orderId])[0];
        $_SESSION['user_id']=$this->actor['id'];$factory=new \Slim\Psr7\Factory\ServerRequestFactory();
        $app=\App\AppFactory::create($this->root);
        $form=$app->handle($factory->createServerRequest('GET','http://localhost/admin/facturi/0/order-edit/')->withQueryParams(['order'=>$orderId])->withHeader('X-Invoice-Modal','1'));
        self::assertSame(200,$form->getStatusCode());$html=(string)$form->getBody();
        self::assertStringContainsString('href="/admin/comenzi/'.$orderId.'/" data-iv-close',$html);
        self::assertStringContainsString('data-iv-add-item',$html);
        $extra=$this->pdo->query("SELECT v.* FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE v.status='active' AND p.status='published' AND v.price_bani>0 AND i.physical_quantity-i.reserved_quantity>=1 AND v.id<>".(int)$item['variant_id']." ORDER BY v.id LIMIT 1")->fetch();
        $input=['_csrf'=>$_SESSION['_csrf'],'request_key'=>'http-order-preparation','order_id'=>$orderId,'order_version'=>(new InvoiceRepository($this->pdo))->editVersion($orderId),'reason'=>'Actualizare comandă','items'=>[['id'=>$item['id'],'quantity'=>'2'],['sku'=>$extra['sku'],'quantity'=>'1','price_bani'=>1]],'billing'=>['name'=>'Client Corectat']];
        $request=$factory->createServerRequest('POST','http://localhost/admin/facturi/0/order-edit/',['REMOTE_ADDR'=>'127.0.0.77'])->withParsedBody($input)->withHeader('Accept','application/json');
        $response=$app->handle($request);$result=json_decode((string)$response->getBody(),true);
        self::assertSame(200,$response->getStatusCode(),json_encode($result));self::assertSame('/admin/comenzi/'.$orderId.'/',$result['redirect']);
        self::assertSame(2,(int)$repo->all('order_items',['order_id'=>$orderId])[0]['quantity']);
        self::assertSame('Client',$repo->one('orders',['id'=>$orderId])['first_name']);
        $added=$repo->one('order_items',['order_id'=>$orderId,'variant_id'=>$extra['id']]);
        self::assertSame((int)$extra['price_bani'],(int)$added['unit_price_bani']);
        self::assertSame((int)$item['line_total_bani']*2+(int)$extra['price_bani']+(int)$order['shipping_bani'],(int)$repo->one('orders',['id'=>$orderId])['total_bani']);
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
        unset($app);gc_collect_cycles();
    }
    public function testIssuingFromAnOrderKeepsTheOrderContextAndDoesNotDuplicateOnRetry(): void
    {
        $orderId=$this->order(1);$_SESSION['user_id']=$this->actor['id'];
        $factory=new \Slim\Psr7\Factory\ServerRequestFactory();$app=\App\AppFactory::create($this->root);
        $page=$app->handle($factory->createServerRequest('GET','http://localhost/admin/comenzi/'.$orderId.'/'));
        self::assertStringContainsString('emite/?order='.$orderId.'&amp;return_to=order" data-iv-modal',(string)$page->getBody());
        $get=$factory->createServerRequest('GET','http://localhost/admin/facturi/emite/')->withQueryParams(['order'=>$orderId,'return_to'=>'order']);
        $form=$app->handle($get->withHeader('X-Invoice-Modal','1'));$html=(string)$form->getBody();
        self::assertSame(200,$form->getStatusCode());
        self::assertStringContainsString('href="/admin/comenzi/'.$orderId.'/" data-iv-close',$html);
        self::assertStringContainsString('name="return_to" value="order"',$html);
        self::assertStringContainsString('name="order_id" value="'.$orderId.'"',$html);
        self::assertStringNotContainsString('data-iv-order',$html);
        self::assertStringNotContainsString('name="billing[',$html);
        self::assertStringContainsString('Stoc contabil actualizat',$html);
        self::assertStringContainsString('name="send_email" value="1" checked',$html);
        $standalone=$app->handle($get);self::assertSame(200,$standalone->getStatusCode());
        self::assertStringContainsString('Înapoi la comandă',(string)$standalone->getBody());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
        $input=['_csrf'=>$_SESSION['_csrf'],'order_id'=>$orderId,'request_key'=>'issue-from-order','return_to'=>'order','send_email'=>'1'];
        $confirmation=$this->service->issuanceConfirmation($orderId,$this->actor);
        $input+=['confirmation_hash'=>$confirmation['confirmation_hash'],'expected_number'=>$confirmation['series'].' '.$confirmation['number']];
        $post=$factory->createServerRequest('POST','http://localhost/admin/facturi/emite/',['REMOTE_ADDR'=>'127.0.0.78'])->withParsedBody($input);
        $response=$app->handle($post->withHeader('Accept','application/json'));
        self::assertSame(200,$response->getStatusCode(),(string)$response->getBody());
        self::assertSame('/admin/comenzi/'.$orderId.'/',json_decode((string)$response->getBody(),true)['redirect']);
        $again=$app->handle($post);self::assertSame('/admin/comenzi/'.$orderId.'/',$again->getHeaderLine('Location'));
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
        self::assertSame(-10000,$this->balance());
        self::assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='invoice'")->fetchColumn());
        unset($app);gc_collect_cycles();
    }
    public function testOrderReturnCanRemainWithoutAnInvoiceAndNeverTouchesStock(): void
    {
        $order=$this->order(2); $this->pdo->exec("UPDATE orders SET status='delivered' WHERE id=".$order);
        $item=(int)$this->pdo->query('SELECT id FROM order_items WHERE order_id='.$order)->fetchColumn();
        $input=['request_key'=>'return-no-invoice','quantities'=>[$item=>1],'reason'=>'Mărime nepotrivită','issue_invoice'=>'0'];
        $id=$this->service->returnOrder($order,$input,$this->actor);
        self::assertSame($id,$this->service->returnOrder($order,$input,$this->actor));
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
        self::assertSame(0,$this->balance());
        $loaded=(new \App\Modules\Orders\OrderService($this->pdo))->findForAdmin($order);
        self::assertSame(1,$loaded['items'][0]['returnable_quantity']); self::assertTrue($loaded['can_return']);
    }
    public function testOrderReturnWithExistingInvoiceCreatesOneCreditAndCountsQuantityOnce(): void
    {
        $order=$this->order(2); $this->pdo->exec("UPDATE orders SET status='delivered' WHERE id=".$order);
        $this->service->issue($order,['request_key'=>'return-issue-original'],$this->actor);
        $item=(int)$this->pdo->query('SELECT id FROM order_items WHERE order_id='.$order)->fetchColumn();
        $input=['request_key'=>'return-with-invoice','quantities'=>[$item=>1],'reason'=>'Mărime nepotrivită','issue_invoice'=>'1'];
        $id=$this->service->returnOrder($order,$input,$this->actor);
        self::assertSame($id,$this->service->returnOrder($order,$input,$this->actor));
        self::assertSame(2,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
        self::assertSame(-20000,$this->balance(),'A credit does not receive physical goods');
        $loaded=(new \App\Modules\Orders\OrderService($this->pdo))->findForAdmin($order);
        self::assertSame(1,$loaded['items'][0]['returnable_quantity']);
        $this->service->returnOrder($order,array_replace($input,['request_key'=>'return-second-piece']),$this->actor);
        self::assertSame(0,(new \App\Modules\Orders\OrderService($this->pdo))->findForAdmin($order)['items'][0]['returnable_quantity']);
        $this->expectException(InvoiceException::class);
        $this->service->returnOrder($order,array_replace($input,['request_key'=>'return-too-many']),$this->actor);
    }
    public function testOrderReturnCanExplicitlyIssuePositiveAndNegativePair(): void
    {
        $order=$this->order(1); $this->pdo->exec("UPDATE orders SET status='delivered' WHERE id=".$order);
        $item=(int)$this->pdo->query('SELECT id FROM order_items WHERE order_id='.$order)->fetchColumn();
        $this->service->returnOrder($order,['request_key'=>'return-positive-pair','quantities'=>[$item=>1],'reason'=>'Produs nepotrivit','issue_invoice'=>'1'],$this->actor);
        self::assertSame(['sale','return'],$this->pdo->query('SELECT kind FROM invoices ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(-10000,$this->balance());
    }
    public function testZeroReturnCannotCreateInvoiceOrReturn(): void
    {
        $order=$this->order(1); $this->pdo->exec("UPDATE orders SET status='delivered' WHERE id=".$order);
        try { $this->service->returnOrder($order,['request_key'=>'zero-return-test','quantities'=>[],'reason'=>'Test retur','issue_invoice'=>'1'],$this->actor); self::fail('Empty return accepted'); }
        catch(InvoiceException $e) { self::assertStringContainsString('mai mare decât 0',$e->getMessage()); }
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM order_returns')->fetchColumn());
    }
    public function testOrderPaymentCanBeRecordedBeforeInvoiceWithoutReasonAndIsIdempotent(): void
    {
        $order=$this->order(2); $repo=new InvoiceRepository($this->pdo); $before=$repo->one('orders',['id'=>$order]);
        $input=['request_key'=>'order-payment-partial','order_version'=>$repo->paymentVersion($order),'payment_status'=>'partial','amount'=>'10'];
        $this->service->setOrderPayment($order,$input,$this->actor);
        $this->service->setOrderPayment($order,$input,$this->actor);
        self::assertSame('partial',$repo->one('orders',['id'=>$order])['payment_status']);
        self::assertSame(1000,$repo->orderCash($order)['received']);
        self::assertSame(0,$this->balance());
        self::assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='order.payment_updated'")->fetchColumn());
        $invoice=$this->service->issue($order,['request_key'=>'issue-after-payment'],$this->actor);
        self::assertSame(1000,(int)$invoice['paid_bani']); self::assertSame('partial',$invoice['payment_state']);
        $this->service->setOrderPayment($order,['request_key'=>'order-payment-full','order_version'=>$repo->paymentVersion($order),'payment_status'=>'paid'],$this->actor);
        $updated=$this->service->document($invoice['id'],$this->actor);
        self::assertSame('paid',$updated['payment_state']); self::assertSame((int)$before['total_bani'],(int)$updated['paid_bani']);
        self::assertSame($invoice['display_number'],$updated['display_number']); self::assertSame(-20000,$this->balance());
        $this->service->setOrderPayment($order,['request_key'=>'order-payment-correction','order_version'=>$repo->paymentVersion($order),'payment_status'=>'pending'],$this->actor);
        self::assertSame(0,$repo->orderCash($order)['received']); self::assertSame('unpaid',$this->service->document($invoice['id'],$this->actor)['payment_state']);
    }
    public function testCardStatusCannotBeForgedUsingOrderPaymentAction(): void
    {
        $order=$this->order(1); $this->pdo->exec("UPDATE orders SET payment_method='stripe' WHERE id=".$order);
        try { $this->service->setOrderPayment($order,['request_key'=>'forged-card-payment','payment_status'=>'paid'],$this->actor); self::fail('Card status accepted'); }
        catch(InvoiceException $e) { self::assertStringContainsString('procesator',$e->getMessage()); }
        self::assertSame('pending',$this->pdo->query('SELECT payment_status FROM orders WHERE id='.$order)->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM payments WHERE provider='manual_cod'")->fetchColumn());
    }
    public function testOrderPaymentPreservesTransmittedInvoiceAndRejectsStaleBalance(): void
    {
        $order=$this->order(1); $repo=new InvoiceRepository($this->pdo);
        $invoice=$this->service->issue($order,['request_key'=>'payment-fiscal-original'],$this->actor);
        $repo->update('invoices',['fiscal_state'=>'accepted','fiscal_reference'=>'TEST-ONLY','fiscal_attempted_at'=>$repo->now()],['id'=>$invoice['id']]);
        $before=$repo->one('invoices',['id'=>$invoice['id']]); $version=$repo->paymentVersion($order);
        $this->service->setOrderPayment($order,['request_key'=>'payment-fiscal-paid','order_version'=>$version,'payment_status'=>'paid'],$this->actor);
        self::assertSame('paid',$repo->one('orders',['id'=>$order])['payment_status']);
        self::assertSame($before,$repo->one('invoices',['id'=>$invoice['id']]));
        self::assertSame((int)$before['gross_bani'],$repo->orderCash($order)['received']);
        self::assertSame(0,$repo->summary()['outstanding']);
        try { $this->service->setOrderPayment($order,['request_key'=>'payment-fiscal-stale','order_version'=>$version,'payment_status'=>'pending'],$this->actor); self::fail('Stale payment form accepted'); }
        catch(InvoiceException $e) { self::assertSame(409,$e->getCode()); }
        self::assertSame('paid',$repo->one('orders',['id'=>$order])['payment_status']);
    }
    public function testPartialCollectionsDoNotRewriteTransmittedInvoice(): void
    {
        $order=$this->order(1); $repo=new InvoiceRepository($this->pdo);
        $invoice=$this->service->issue($order,['request_key'=>'collection-fiscal-original'],$this->actor);
        $repo->update('invoices',['fiscal_state'=>'accepted','fiscal_reference'=>'TEST-ONLY','fiscal_attempted_at'=>$repo->now()],['id'=>$invoice['id']]);
        $before=$repo->one('invoices',['id'=>$invoice['id']]);
        foreach(['collection-first','collection-second'] as $key) {
            $input=['request_key'=>$key,'version'=>$before['row_version'],'amount'=>'10','reason'=>'Încasare ramburs'];
            $this->service->payment($invoice['id'],$input,$this->actor);
            $this->service->payment($invoice['id'],$input,$this->actor);
        }
        self::assertSame($before,$repo->one('invoices',['id'=>$invoice['id']]));
        self::assertSame(2000,$repo->orderCash($order)['received']);
        self::assertSame((int)$before['gross_bani']-2000,$repo->summary()['outstanding']);
        self::assertSame('partial',$repo->one('orders',['id'=>$order])['payment_status']);
    }
    public function testOrderReturnNirUsesInvoiceAllocationsAndCannotBeReceivedTwice(): void
    {
        $this->purchase(5,'40','BRIDGE-PURCHASE'); $order=$this->order(2);
        $invoice=$this->service->issue($order,['request_key'=>'bridge-issue'],$this->actor);
        $this->pdo->exec("UPDATE orders SET status='delivered' WHERE id=".$order);
        $item=(int)$invoice['lines'][0]['order_item_id'];
        $returnId=$this->service->returnOrder($order,['request_key'=>'bridge-return','quantities'=>[$item=>1],'reason'=>'Mărime nepotrivită','issue_invoice'=>'1'],$this->actor);
        $credit=$this->service->document((int)$this->pdo->query("SELECT id FROM invoices WHERE kind='return'")->fetchColumn(),$this->actor);
        $nir=new NirService($this->pdo); $defaults=$nir->defaults($this->actor)['values'];
        self::assertTrue($nir->pendingReturnReceipts($this->actor)[0]['eligible']);
        $input=['physically_received'=>true,'warehouse_id'=>$defaults['warehouse_id'],'location_id'=>$defaults['location_id']];
        $receipt=$nir->receiveReturn($returnId,$input,'bridge-nir-receipt',$this->actor);
        self::assertSame(40000,$this->balance()); self::assertSame(4000,$receipt['inventory_bani']);
        self::assertSame($invoice['id'],$receipt['original_invoices'][0]['id']); self::assertTrue($receipt['cost_complete']);
        self::assertSame($credit['display_number'],$receipt['invoice_number']);
        self::assertSame($receipt['id'],$nir->receiveReturn($returnId,$input,'bridge-nir-receipt',$this->actor)['id']);
        self::assertSame([],$this->service->pendingReceipts($this->actor)); self::assertSame([],$nir->pendingReturnReceipts($this->actor));
        self::assertSame($receipt['id'],$this->service->document($credit['id'],$this->actor)['receipt_nir']['id']);
        try {
            $this->service->receive($credit['id'],['request_key'=>'bridge-double-receipt','version'=>$credit['row_version'],'reason'=>'Verificat','received'=>[$credit['lines'][0]['id']=>1],'restock'=>[$credit['lines'][0]['id']=>1]],$this->actor);
            self::fail('The same goods were received twice');
        } catch(InvoiceException $e) { self::assertSame(409,$e->getCode()); }
        self::assertSame(40000,$this->balance());
        $this->service->delete($credit['id'],['request_key'=>'bridge-delete-credit','version'=>$credit['row_version'],'reason'=>'Corectare document'],$this->actor);
        self::assertSame(30000,$this->balance());
        self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM nir_documents WHERE type='customer_return'")->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT SUM(returned_units) FROM nir_fifo_consumptions')->fetchColumn());
    }
    public function testInvoiceReceiptCannotBeRepeatedThroughNir(): void
    {
        $this->purchase(3,'25','BRIDGE-PURCHASE-2'); $order=$this->order(1);
        $invoice=$this->service->issue($order,['request_key'=>'bridge-issue-2'],$this->actor);
        $this->pdo->exec("UPDATE orders SET status='delivered' WHERE id=".$order);
        $returnId=$this->service->returnOrder($order,['request_key'=>'bridge-return-2','quantities'=>[$invoice['lines'][0]['order_item_id']=>1],'reason'=>'Retur','issue_invoice'=>'1'],$this->actor);
        $credit=$this->service->document((int)$this->pdo->query("SELECT id FROM invoices WHERE kind='return'")->fetchColumn(),$this->actor);
        $this->service->receive($credit['id'],['request_key'=>'bridge-invoice-receipt','version'=>$credit['row_version'],'reason'=>'Verificat','received'=>[$credit['lines'][0]['id']=>1],'restock'=>[$credit['lines'][0]['id']=>1]],$this->actor);
        $nir=new NirService($this->pdo); self::assertSame([],$nir->pendingReturnReceipts($this->actor));
        self::assertSame([],(new \App\Modules\Inventory\NirRepository($this->pdo))->pendingReturnNotifications($this->actor['id'],gmdate('Y-m-d')));
        try { $nir->receiveReturn($returnId,['physically_received'=>true],'bridge-duplicate-nir',$this->actor); self::fail('Duplicate receipt accepted'); }
        catch(\App\Modules\Inventory\NirConflictException) {}
        self::assertSame(30000,$this->balance());
    }
    public function testDeletingLastReturnRestoresOrderAndReturnableQuantities(): void
    {
        $order=$this->order(2);$repo=new InvoiceRepository($this->pdo);
        $repo->update('orders',['status'=>'delivered'],['id'=>$order]);
        $item=$repo->all('order_items',['order_id'=>$order])[0];
        $returnId=$this->service->returnOrder($order,['request_key'=>'lifecycle-full-return','quantities'=>[$item['id']=>2],'reason'=>'Mărime nepotrivită','issue_invoice'=>'1'],$this->actor);
        self::assertSame('returned',$repo->one('orders',['id'=>$order])['status']);
        $credit=$this->service->load((int)$repo->one('order_return_documents',['return_id'=>$returnId])['invoice_id']);
        $this->service->delete($credit['id'],['request_key'=>'lifecycle-delete-last','version'=>$credit['row_version'],'reason'=>'Anulăm returul'],$this->actor);
        $loaded=(new \App\Modules\Orders\OrderService($this->pdo))->findForAdmin($order);
        self::assertSame('delivered',$loaded['status']);self::assertSame(2,$loaded['items'][0]['returnable_quantity']);self::assertSame([],$loaded['returns']);
        self::assertNotNull($repo->one('order_returns',['id'=>$returnId])['voided_at']);
        self::assertSame([],(new \App\Modules\Inventory\NirRepository($this->pdo))->pendingReturnNotifications($this->actor['id'],gmdate('Y-m-d')));
        self::assertSame([],(new \App\Modules\Invoicing\OrderReturnWorkflow($repo))->missingDocuments($order));
        self::assertSame(-20000,$this->balance());
    }

    public function testExistingReturnWithoutInvoiceCanBeInvoicedOrReversedWithoutDuplicateQuantities(): void
    {
        $repo=new InvoiceRepository($this->pdo);$workflow=new \App\Modules\Invoicing\OrderReturnWorkflow($repo);
        foreach(['invoice','restore'] as $decision) {
            $order=$this->order(1);$repo->update('orders',['status'=>'delivered'],['id'=>$order]);$item=$repo->all('order_items',['order_id'=>$order])[0];
            $id=$this->service->returnOrder($order,['request_key'=>'orphan-'.$decision,'quantities'=>[$item['id']=>1],'reason'=>'Retur client','issue_invoice'=>'0'],$this->actor);
            self::assertCount(1,$workflow->missingDocuments($order));
            $input=['request_key'=>'repair-'.$decision,'order_version'=>$repo->editVersion($order),'decision'=>$decision,'notify_customer'=>'1'];
            $this->service->reconcileReturn($order,$id,$input,$this->actor);$this->service->reconcileReturn($order,$id,$input,$this->actor);
            self::assertSame([],$workflow->missingDocuments($order));
            $loaded=(new \App\Modules\Orders\OrderService($this->pdo))->findForAdmin($order);
            self::assertSame($decision==='invoice'?0:1,$loaded['items'][0]['returnable_quantity']);
            self::assertSame($decision==='invoice'?'returned':'delivered',$loaded['status']);
            self::assertCount($decision==='invoice'?2:0,$repo->all('invoices',['order_id'=>$order,'deleted_at'=>null]));
            if($decision==='invoice') { self::assertSame('Retur client',$repo->one('invoices',['order_id'=>$order,'kind'=>'return'])['reason']); }
        }
        self::assertCount(1,$repo->all('outbox_messages',['message_type'=>'invoice']));
    }

    public function testReturnRequestWithoutInvoiceIsReviewedSeparatelyAndAcceptsOnlyRequestedItems(): void
    {
        $repo=new InvoiceRepository($this->pdo);$requests=new \App\Modules\Invoicing\OrderReturnRequests($repo);
        $order=$this->order(2);$repo->update('orders',['status'=>'delivered'],['id'=>$order]);$item=$repo->all('order_items',['order_id'=>$order])[0];
        $input=['request_key'=>'request-without-invoice','refund_method'=>'cash','reason'=>'Mărime nepotrivită','quantities'=>[$item['id']=>1]];
        $id=$requests->create($order,$input,$this->actor);self::assertSame($id,$requests->create($order,$input,$this->actor));
        self::assertCount(0,$repo->all('invoices'));self::assertCount(0,$repo->all('order_returns'));self::assertSame(0,$this->balance());
        self::assertSame('delivered',$repo->one('orders',['id'=>$order])['status']);
        $accepted=['request_key'=>'accept-request-no-invoice','return_request_id'=>$id,'reason'=>'Retur solicitat de client','quantities'=>[$item['id']=>1],'notify_customer'=>'1','issue_invoice'=>'0'];
        try { $this->service->returnOrder($order,array_replace($accepted,['quantities'=>[$item['id']=>2]]),$this->actor);self::fail('Accepted different quantities'); } catch(InvoiceException) {}
        $returnId=$this->service->returnOrder($order,$accepted,$this->actor);self::assertSame($returnId,$this->service->returnOrder($order,$accepted,$this->actor));
        self::assertSame('accepted',$repo->one('order_return_requests',['id'=>$id])['state']);
        self::assertCount(1,$repo->all('order_returns'));self::assertCount(2,$repo->all('outbox_messages',['message_type'=>'order_status_update']));
        try { $requests->create($order,array_replace($input,['request_key'=>'another-return-request']),$this->actor); self::fail('Second request accepted'); } catch(InvoiceException $e) { self::assertSame(409,$e->getCode()); }
        $order=$this->order(1);$repo->update('orders',['status'=>'delivered'],['id'=>$order]);$item=$repo->all('order_items',['order_id'=>$order])[0];
        $other=$requests->create($order,array_replace($input,['request_key'=>'separate-return-request','quantities'=>[$item['id']=>1]]),$this->actor);
        $requests->reject($order,$other,'Produsul nu îndeplinește condițiile de retur.',$this->actor);
        self::assertSame('rejected',$repo->one('order_return_requests',['id'=>$other])['state']);self::assertCount(1,$repo->all('order_returns'));
    }

    public function testInvoiceResendsAndReturnReceiptEmailsReachInjectedTransportExactlyOnce(): void
    {
        $saved=$_ENV;
        try {
            $_ENV['APP_ENV']='development';$_ENV['MAIL_DELIVERY_MODE']='smtp';$_ENV['MAIL_SEND_AFTER_ID']='0';$_ENV['MAIL_MESSAGE_TYPES']='invoice,invoice_return_notice';$_ENV['MAILER_DSN']='smtp://127.0.0.1:2525';$_ENV['MAIL_FROM_ADDRESS']='shop@example.test';
            $this->purchase(4,'25','MAIL-RETURN-RECEIPT');$order=$this->order(1);$repo=new InvoiceRepository($this->pdo);
            $repo->update('orders',['status'=>'delivered'],['id'=>$order]);$invoice=$this->service->issue($order,['request_key'=>'mail-sale-invoice'],$this->actor);
            $this->service->queueEmail($invoice['id'],'resend-invoice-first',$this->actor);$this->service->queueEmail($invoice['id'],'resend-invoice-second',$this->actor);$this->service->queueEmail($invoice['id'],'resend-invoice-second',$this->actor);
            $credit=$this->service->credit($invoice['id'],['request_key'=>'mail-return-credit','version'=>$invoice['row_version'],'reason'=>'Retur client','quantities'=>[$invoice['lines'][0]['id']=>1]],$this->actor);
            $nir=new NirService($this->pdo);$defaults=$nir->defaults($this->actor)['values'];
            $returnId=$credit['order_return']['return_id'];
            $receipt=$nir->receiveReturn($returnId,['physically_received'=>true,'warehouse_id'=>$defaults['warehouse_id'],'location_id'=>$defaults['location_id']],'mail-return-nir',$this->actor);
            $nir->receiveReturn($returnId,['physically_received'=>true,'warehouse_id'=>$defaults['warehouse_id'],'location_id'=>$defaults['location_id']],'mail-return-nir',$this->actor);
            self::assertCount(1,$repo->all('outbox_messages',['message_type'=>'invoice_return_notice']));
            self::assertCount(3,$repo->all('outbox_messages',['message_type'=>'invoice']));
            $notice=$repo->all('outbox_messages',['message_type'=>'invoice_return_notice'])[0];
            $rendered=(new \App\Modules\Invoicing\InvoiceEmail($repo,Config::load($this->root),$this->root))->render(json_decode($notice['payload_json'],true),$notice['recipient']);
            self::assertStringContainsString('recepționate',$rendered['subject']);self::assertStringContainsString('logo-la-mariuca',$rendered['html']);self::assertStringContainsString('cab-it-mark',$rendered['html']);
            $this->pdo->exec("UPDATE outbox_messages SET available_at='2020-01-01'");
            $processor=new \App\Shared\Mail\OutboxProcessor($this->pdo,Config::load($this->root),$this->root,new \Symfony\Component\Mailer\Transport\NullTransport());
            self::assertSame(['processed'=>4,'sent'=>4,'previewed'=>0,'failed'=>0],$processor->process());
            self::assertSame(0,$processor->process()['processed']);self::assertSame('sent',$repo->one('invoices',['id'=>$invoice['id']])['email_state']);
            self::assertSame($receipt['id'],$credit['order_return']['return_id']>0?$this->service->document($credit['id'],$this->actor)['receipt_nir']['id']:null);
        } finally { $_ENV=$saved; }
    }

    public function testOrderRefundStatusesWithoutInvoicePreserveCapturedMoneyAndRetries(): void
    {
        $repo=new InvoiceRepository($this->pdo);
        foreach(['cancelled','returned','partial_return'] as $status) {
            $order=$this->order($status==='partial_return'?2:1);
            $this->service->setOrderPayment($order,['request_key'=>'refund-paid-'.$status,'order_version'=>$repo->paymentVersion($order),'payment_status'=>'paid'],$this->actor);
            if($status==='partial_return') { $repo->update('orders',['status'=>'delivered'],['id'=>$order]);$item=$repo->all('order_items',['order_id'=>$order])[0];$this->service->returnOrder($order,['request_key'=>'refund-partial-return','quantities'=>[$item['id']=>1],'reason'=>'Retur confirmat','issue_invoice'=>'0'],$this->actor); } else { $repo->update('orders',['status'=>$status],['id'=>$order]); }$received=$repo->orderCash($order)['received'];
            $input=['request_key'=>'refund-partial-'.$status,'order_version'=>$repo->paymentVersion($order),'payment_status'=>'partially_refunded','refund_amount'=>'10.25','notify_customer'=>'1'];
            $this->service->setOrderPayment($order,$input,$this->actor);$this->service->setOrderPayment($order,$input,$this->actor);
            self::assertSame(['received'=>$received,'refunded'=>1025],$repo->orderCash($order));
            self::assertSame('partially_refunded',$repo->one('orders',['id'=>$order])['payment_status']);
            self::assertCount(1,$repo->all('order_refunds',['order_id'=>$order]));
            $full=['request_key'=>'refund-full-'.$status,'order_version'=>$repo->paymentVersion($order),'payment_status'=>'refunded','notify_customer'=>'1'];
            $this->service->setOrderPayment($order,$full,$this->actor);$this->service->setOrderPayment($order,$full,$this->actor);
            self::assertSame(['received'=>$received,'refunded'=>$received],$repo->orderCash($order));
            self::assertSame('refunded',$repo->one('orders',['id'=>$order])['payment_status']);self::assertSame($status==='partial_return'?'delivered':$status,$repo->one('orders',['id'=>$order])['status']);
            self::assertCount(2,$repo->all('order_refunds',['order_id'=>$order]));
        }
        self::assertCount(0,$repo->all('invoices'));self::assertSame(0,$this->balance());self::assertCount(6,$repo->all('outbox_messages',['message_type'=>'order_status_update']));
    }

    public function testOrderRefundsAllocateAcrossExistingAndLaterCreditsWithoutDoubleCounting(): void
    {
        $repo=new InvoiceRepository($this->pdo);$order=$this->order(2);
        $this->service->setOrderPayment($order,['request_key'=>'allocate-refund-paid','order_version'=>$repo->paymentVersion($order),'payment_status'=>'paid'],$this->actor);
        $invoice=$this->service->issue($order,['request_key'=>'allocate-refund-sale'],$this->actor);$repo->update('orders',['status'=>'delivered'],['id'=>$order]);
        $line=$invoice['lines'][0];$credit=$this->service->credit($invoice['id'],['request_key'=>'allocate-refund-credit','version'=>$invoice['row_version'],'reason'=>'Retur client','quantities'=>[$line['id']=>1]],$this->actor);
        $total=abs((int)$credit['gross_bani'])+1000;
        $this->service->setOrderPayment($order,['request_key'=>'allocate-refund-first','order_version'=>$repo->paymentVersion($order),'payment_status'=>'partially_refunded','refund_amount'=>(string)($total/100)],$this->actor);
        self::assertSame($total,$repo->orderCash($order)['refunded']);
        self::assertSame(abs((int)$credit['gross_bani']),$this->service->document($credit['id'],$this->actor)['refunded_bani']);
        $original=$this->service->load($invoice['id']);
        $second=$this->service->credit($invoice['id'],['request_key'=>'allocate-refund-credit-two','version'=>$original['row_version'],'reason'=>'Retur suplimentar','quantities'=>[$line['id']=>1]],$this->actor);
        self::assertSame(1000,$second['refunded_bani']);self::assertSame($total,$repo->orderCash($order)['refunded']);
        $this->service->payment($second['id'],['request_key'=>'allocate-refund-document','version'=>$second['row_version'],'reason'=>'Restituire verificată','amount'=>'5'],$this->actor);
        self::assertSame($total+500,$repo->orderCash($order)['refunded']);
        self::assertSame(0,$repo->summary()['outstanding']);
    }

    public function testOrderRefundValidationAndStaleAmountsPreventOverRefunding(): void
    {
        $repo=new InvoiceRepository($this->pdo);$order=$this->order(1);
        $this->service->setOrderPayment($order,['request_key'=>'refund-guard-paid','order_version'=>$repo->paymentVersion($order),'payment_status'=>'partial','amount'=>'50'],$this->actor);
        $reject=function(array $input)use($order):void {try{$this->service->setOrderPayment($order,$input,$this->actor);self::fail('Invalid refund accepted');}catch(InvoiceException){}};
        $base=['request_key'=>'refund-guard-validation','order_version'=>$repo->paymentVersion($order),'payment_status'=>'partially_refunded','refund_amount'=>'10'];
        $reject($base);self::assertCount(0,$repo->all('order_refunds'));
        $repo->update('orders',['status'=>'cancelled'],['id'=>$order]);$base['order_version']=$repo->paymentVersion($order);
        foreach(['0','-1','50','51','invalid'] as $amount) { $reject(array_replace($base,['refund_amount'=>$amount])); }
        self::assertSame(['received'=>5000,'refunded'=>0],$repo->orderCash($order));
        $this->service->setOrderPayment($order,$base,$this->actor);
        $reject(array_replace($base,['request_key'=>'refund-guard-stale','refund_amount'=>'20']));
        $reject(array_replace($base,['request_key'=>'refund-guard-lower','order_version'=>$repo->paymentVersion($order),'refund_amount'=>'5']));
        self::assertCount(1,$repo->all('order_refunds'));self::assertSame(1000,$repo->orderCash($order)['refunded']);
    }

    public function testPrivateLinkEmailPreservesLinksAndSupportsIdempotentResending(): void
    {
        $repo=new InvoiceRepository($this->pdo);$invoice=$this->service->issue($this->order(1),['request_key'=>'share-invoice'],$this->actor);
        $first=$this->service->publicLink($invoice['id'],$this->actor);
        $token=$this->service->publicLink($invoice['id'],$this->actor,false,true);
        self::assertSame($invoice['id'],$this->service->publicDocument($first)['id']);
        $message="Mulțumim!\n<script>alert(1)</script>";
        $this->service->queueLinkEmail($invoice['id'],'share-email-first',$token,$message,$this->actor);
        $this->service->queueLinkEmail($invoice['id'],'share-email-first',$token,$message,$this->actor);
        $this->service->queueLinkEmail($invoice['id'],'share-email-second',$token,'',$this->actor);
        $rows=$repo->all('outbox_messages',['message_type'=>'invoice']);self::assertCount(2,$rows);self::assertCount(2,$repo->all('invoice_public_links'));
        $renderer=new \App\Modules\Invoicing\InvoiceEmail($repo,Config::load($this->root),$this->root);
        $mail=$renderer->render(json_decode($rows[0]['payload_json'],true),$rows[0]['recipient']);
        self::assertNull($mail['attachment']);self::assertNull($mail['attachment_name']);
        self::assertStringContainsString('/factura/'.$token.'/?view=1',$mail['html']);
        self::assertStringContainsString('Vizualizează factura',$mail['html']);self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;',$mail['html']);
        self::assertStringNotContainsString('<script>',$mail['html']);self::assertStringNotContainsString($invoice['lines'][0]['sku'],$mail['html']);
        foreach(['logo-la-mariuca','CAB IT','ANPC','Confidențialitate'] as $part) { self::assertStringContainsString($part,$mail['html']); }
        self::assertStringContainsString($message,$mail['text']);
        $plain=$renderer->render(json_decode($rows[1]['payload_json'],true),$rows[1]['recipient']);self::assertStringNotContainsString('Mulțumim!',$plain['html']);
        try { $this->service->queueLinkEmail($invoice['id'],'share-email-first',$token,'Alt mesaj',$this->actor);self::fail('Reused confirmation accepted'); } catch(InvoiceException $e) { self::assertSame(409,$e->getCode()); }
        try { $this->service->queueLinkEmail($invoice['id'],'share-too-long',$token,str_repeat('a',2001),$this->actor);self::fail('Oversized message accepted'); } catch(InvoiceException) {}
        $other=$this->service->issue($this->order(1),['request_key'=>'share-other-invoice'],$this->actor);
        try { $this->service->queueLinkEmail($other['id'],'share-wrong-invoice',$token,'',$this->actor);self::fail('Foreign invoice link accepted'); } catch(InvoiceException $e) { self::assertSame(403,$e->getCode()); }
        $saved=$_ENV;
        try {
            $_ENV['APP_ENV']='development';$_ENV['MAIL_DELIVERY_MODE']='smtp';$_ENV['MAIL_SEND_AFTER_ID']='0';$_ENV['MAIL_MESSAGE_TYPES']='invoice';$_ENV['MAILER_DSN']='smtp://127.0.0.1:2525';$_ENV['MAIL_FROM_ADDRESS']='shop@example.test';
            $this->pdo->exec("UPDATE outbox_messages SET available_at='2020-01-01'");
            $processor=new \App\Shared\Mail\OutboxProcessor($this->pdo,Config::load($this->root),$this->root,new \Symfony\Component\Mailer\Transport\NullTransport());
            self::assertSame(['processed'=>2,'sent'=>2,'previewed'=>0,'failed'=>0],$processor->process());self::assertSame(0,$processor->process()['processed']);
        } finally { $_ENV=$saved; }
        $this->service->publicLink($invoice['id'],$this->actor,true);
        $this->expectException(InvoiceException::class);$renderer->render(json_decode($rows[0]['payload_json'],true),$rows[0]['recipient']);
    }

    public function testRefundBeforeReturnInvoiceKeepsCashWhenFiscalPairIsCreated(): void
    {
        $repo=new InvoiceRepository($this->pdo);$order=$this->order(1);
        $this->service->setOrderPayment($order,['request_key'=>'refund-before-invoice-paid','order_version'=>$repo->paymentVersion($order),'payment_status'=>'paid'],$this->actor);
        $received=$repo->orderCash($order)['received'];$repo->update('orders',['status'=>'delivered'],['id'=>$order]);$item=$repo->all('order_items',['order_id'=>$order])[0];
        $this->service->returnOrder($order,['request_key'=>'refund-before-invoice-return','quantities'=>[$item['id']=>1],'reason'=>'Retur confirmat','issue_invoice'=>'0'],$this->actor);
        $this->service->setOrderPayment($order,['request_key'=>'refund-before-invoice-full','order_version'=>$repo->paymentVersion($order),'payment_status'=>'refunded'],$this->actor);
        $return=$repo->all('order_returns',['order_id'=>$order])[0];
        $this->service->reconcileReturn($order,$return['id'],['request_key'=>'refund-before-invoice-reconcile','order_version'=>$repo->editVersion($order),'decision'=>'invoice'],$this->actor);
        self::assertSame(['received'=>$received,'refunded'=>$received],$repo->orderCash($order));self::assertCount(2,$repo->all('invoices',['order_id'=>$order]));
        self::assertSame('refunded',$repo->one('orders',['id'=>$order])['payment_status']);self::assertCount(1,$repo->all('order_refunds',['order_id'=>$order]));self::assertCount(1,$repo->all('invoice_refunds'));
    }

    public function testPrivateLinkModalHttpCsrfAndNonJavascriptFlow(): void
    {
        $repo=new InvoiceRepository($this->pdo);$invoice=$this->service->issue($this->order(1),['request_key'=>'share-http-invoice'],$this->actor);
        $path='/admin/facturi/'.$invoice['id'].'/link/';$factory=new \Slim\Psr7\Factory\ServerRequestFactory();
        $send=function(string $method,array $input=[],bool $json=false)use($factory,$path) { $app=\App\AppFactory::create($this->root);$request=$factory->createServerRequest($method,'http://localhost'.$path,['REMOTE_ADDR'=>'127.0.0.81'])->withParsedBody($input)->withHeader('Accept',$json?'application/json':'text/html');$response=$app->handle($request);unset($app);gc_collect_cycles();return $response; };
        self::assertContains($send('GET')->getStatusCode(),[302,303,401,403]);$_SESSION['user_id']=$this->actor['id'];
        $form=$send('GET');self::assertSame(200,$form->getStatusCode());self::assertCount(0,$repo->all('invoice_public_links'));
        self::assertStringNotContainsString('name="revoke"',(string)$form->getBody());self::assertSame(419,$send('POST',['link_action'=>'prepare'],true)->getStatusCode());
        $input=['_csrf'=>$_SESSION['_csrf'],'link_action'=>'prepare'];
        $response=$send('POST',$input,true);self::assertSame(200,$response->getStatusCode(),(string)$response->getBody());$data=json_decode((string)$response->getBody(),true);
        self::assertStringStartsWith('https://wa.me/40700000000?text=',$data['whatsapp_url']);self::assertStringContainsString(rawurlencode($data['link']),$data['whatsapp_url']);
        self::assertStringEndsWith('/?view=1',$data['link']);self::assertFalse($data['link_email_sent']);
        $plain=$send('POST',$input);self::assertSame(200,$plain->getStatusCode());self::assertStringContainsString('Copiază linkul',(string)$plain->getBody());self::assertStringContainsString('name="link_action" value="email"',(string)$plain->getBody());
        $email=$send('POST',['_csrf'=>$_SESSION['_csrf'],'link_action'=>'email','public_token'=>$data['public_token'],'request_key'=>$data['next_request_key'],'personal_message'=>'Cu drag!'],true);
        $sent=json_decode((string)$email->getBody(),true);self::assertSame(200,$email->getStatusCode(),(string)$email->getBody());self::assertTrue($sent['link_email_sent']);self::assertNotSame($data['next_request_key'],$sent['next_request_key']);
        self::assertCount(1,$repo->all('outbox_messages',['message_type'=>'invoice']));self::assertCount(2,$repo->all('invoice_public_links'));
    }

    public function testReturnCostsPersistAndReconcileIntoTheSameNetInvoiceWithoutMovingMoney(): void
    {
        $repo=new InvoiceRepository($this->pdo);$order=$this->order(2);$repo->update('orders',['status'=>'delivered'],['id'=>$order]);
        $item=$repo->all('order_items',['order_id'=>$order])[0];$cash=$repo->orderCash($order);$stock=$this->balance();
        $input=['request_key'=>'costs-direct-return-1','reason'=>'Retur client','quantities'=>[$item['id']=>1],'refund_method'=>'cash','transport_enabled'=>'1','transport_cost'=>'20,50','other_costs_enabled'=>'1','return_costs'=>[['name'=>'Reambalare','amount'=>'5.25']],'custom_refund_enabled'=>'1','refund_total'=>'100.00'];
        $id=$this->service->returnOrder($order,$input,$this->actor);
        self::assertSame($id,$this->service->returnOrder($order,$input,$this->actor));
        $plan=json_decode($repo->one('order_returns',['id'=>$id])['settlement_json'],true);
        self::assertSame(2575,$plan['costs_bani']);self::assertSame(10000,$plan['refund_bani']);self::assertSame('cash',$plan['destination']['refund_method']);
        self::assertSame($cash,$repo->orderCash($order));self::assertSame($stock,$this->balance());
        $this->service->reconcileReturn($order,$id,['request_key'=>'costs-reconcile-1','order_version'=>$repo->editVersion($order),'decision'=>'invoice'],$this->actor);
        $credit=$repo->one('invoices',['order_id'=>$order,'kind'=>'return']);self::assertSame(-10000,(int)$credit['gross_bani']);
        self::assertSame($cash,$repo->orderCash($order));self::assertSame(0,(int)$repo->one('order_returns',['id'=>$id])['refund_bani']);
        self::assertCount(0,$repo->all('invoice_refunds'));self::assertCount(0,$repo->all('invoice_receipts'));
        $changed=array_replace($input,['refund_total'=>'110.00']);
        try { $this->service->returnOrder($order,$changed,$this->actor);self::fail('Changed retry accepted'); } catch(InvoiceException $e) { self::assertSame(409,$e->getCode()); }
    }

    public function testInvalidReturnCostsRollBackAndAZeroAgreedRefundCanStillDocumentTheProducts(): void
    {
        $repo=new InvoiceRepository($this->pdo);$order=$this->order(1);$repo->update('orders',['status'=>'delivered'],['id'=>$order]);$item=$repo->all('order_items',['order_id'=>$order])[0];
        $input=['request_key'=>'zero-return-total','reason'=>'Retur client','quantities'=>[$item['id']=>1],'issue_invoice'=>'1','transport_enabled'=>'1','transport_cost'=>'99999'];
        try { $this->service->returnOrder($order,$input,$this->actor);self::fail('Excessive costs accepted'); } catch(InvoiceException) {}
        self::assertCount(0,$repo->all('order_returns'));self::assertCount(0,$repo->all('invoices'));self::assertSame('delivered',$repo->one('orders',['id'=>$order])['status']);
        $id=$this->service->returnOrder($order,array_replace($input,['transport_enabled'=>'0','custom_refund_enabled'=>'1','refund_total'=>'0']),$this->actor);
        self::assertSame(0,(int)$repo->one('invoices',['order_id'=>$order,'kind'=>'return'])['gross_bani']);
        self::assertSame(0,json_decode($repo->one('order_returns',['id'=>$id])['settlement_json'],true)['refund_bani']);
        self::assertCount(1,$repo->all('order_return_items',['return_id'=>$id]));self::assertCount(0,$repo->all('invoice_receipts'));self::assertCount(0,$repo->all('invoice_refunds'));
    }

    public function testManualRequestIsUniqueAcrossBothEntryPointsAndBankDetailsSurviveAcceptance(): void
    {
        $repo=new InvoiceRepository($this->pdo);$order=$this->order(2);$repo->update('orders',['status'=>'delivered'],['id'=>$order]);
        $invoice=$this->service->issue($order,['request_key'=>'unique-return-sale'],$this->actor);$item=$repo->all('order_items',['order_id'=>$order])[0];
        $requests=new \App\Modules\Invoicing\OrderReturnRequests($repo);
        $input=['request_key'=>'unique-admin-request','reason'=>'Mărime nepotrivită','quantities'=>[$item['id']=>1],'refund_method'=>'bank','holder'=>'Ana Pop','iban'=>'ro49 aaaa 1b31 0075 9384 0000','bank_name'=>'Banca QA'];
        foreach([['iban'=>'RO49INVALID'],['bank_name'=>''],['holder'=>'']] as $invalid) { try { $requests->create($order,array_replace($input,$invalid),$this->actor);self::fail('Invalid bank data accepted'); } catch(InvoiceException $e) { self::assertNotSame('',$e->getMessage()); } }
        $id=$requests->create($order,$input,$this->actor);self::assertSame($id,$requests->create($order,$input,$this->actor));
        try { $requests->create($order,array_replace($input,['bank_name'=>'Altă bancă']),$this->actor);self::fail('Changed bank retry accepted'); } catch(InvoiceException $e) { self::assertSame(409,$e->getCode()); }
        $public=['request_key'=>'unique-public-request','reason'=>'Retur client','quantities'=>[$invoice['lines'][0]['id']=>'1'],'refund_method'=>'cash'];
        try { $this->service->requestReturn($invoice['id'],$public,$this->actor);self::fail('Second entry point accepted'); } catch(InvoiceException) {}
        $tracking=new \App\Modules\Orders\OrderTrackingReturns($repo);$rows=$tracking->forOrder($order);self::assertSame('pending',$rows[0]['state']);self::assertStringNotContainsString('RO49',json_encode($rows));
        $return=$this->service->returnOrder($order,['request_key'=>'unique-admin-accept','return_request_id'=>$id,'reason'=>$input['reason'],'quantities'=>[$item['id']=>1],'issue_invoice'=>'1','transport_enabled'=>'1','transport_cost'=>'10'],$this->actor);
        $plan=json_decode($repo->one('order_returns',['id'=>$return])['settlement_json'],true);self::assertSame('RO49AAAA1B31007593840000',$plan['destination']['iban']);self::assertSame('Banca QA',$plan['destination']['bank_name']);
        self::assertSame($plan['base_bani']-1000,$plan['refund_bani']);self::assertSame(-$plan['refund_bani'],(int)$repo->one('invoices',['order_id'=>$order,'kind'=>'return'])['gross_bani']);
        self::assertSame('accepted',$tracking->forOrder($order)[0]['state']);self::assertSame($plan['refund_bani'],$tracking->forOrder($order)[0]['settlement']['refund_bani']);
        try { $requests->create($order,array_replace($input,['request_key'=>'unique-request-after-accept']),$this->actor);self::fail('Second request after decision accepted'); } catch(InvoiceException $e) { self::assertSame(409,$e->getCode()); }
    }

    public function testCustomerModalAndRequestDecisionWithCostsArePrivateAndFunctional(): void
    {
        $repo=new InvoiceRepository($this->pdo);$order=$this->order(2);$repo->update('orders',['status'=>'delivered'],['id'=>$order]);
        $repo->insert('audit_log',['action'=>'order.deliver','entity_type'=>'order','entity_id'=>$order,'context_json'=>'{}','created_at'=>gmdate('c')]);
        $invoice=$this->service->issue($order,['request_key'=>'modal-return-sale'],$this->actor);$id=$invoice['id'];$line=$invoice['lines'][0];
        $_SESSION['tracked_order']=['id'=>$order,'expires'=>time()+3600];
        $request=(new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET','http://localhost/urmarire-comanda/retur/'.$id.'/',['REMOTE_ADDR'=>'127.0.0.1'])->withHeader('X-Return-Modal','1');
        $app=\App\AppFactory::create($this->root);$response=$app->handle($request);$html=(string)$response->getBody();
        self::assertSame(200,$response->getStatusCode());self::assertStringContainsString('data-customer-return-form',$html);self::assertStringContainsString('customer-return__photo',$html);self::assertStringContainsString('name="bank_name"',$html);self::assertStringNotContainsString('<html',$html);self::assertSame('private, no-store',$response->getHeaderLine('Cache-Control'));
        $post=$request->withMethod('POST')->withParsedBody(['request_key'=>'modal-return-request','quantities'=>[$line['id']=>1],'reason'=>'Mărime nepotrivită','refund_method'=>'cash']);
        self::assertSame(419,$app->handle($post)->getStatusCode());
        $response=$app->handle($post->withParsedBody($post->getParsedBody()+['_csrf'=>$_SESSION['_csrf']]));self::assertSame(200,$response->getStatusCode());self::assertStringContainsString('data-return-success',(string)$response->getBody());
        $saved=$repo->one('invoice_return_requests',['order_id'=>$order]);$tracking=new \App\Modules\Orders\OrderTrackingReturns($repo);self::assertSame('pending',$tracking->forOrder($order)[0]['state']);
        $credit=$this->service->decideReturn($id,['request_key'=>'modal-return-accept','return_request_id'=>$saved['id'],'version'=>$invoice['row_version'],'reason'=>'Retur acceptat','decisions'=>[$line['id']=>['quantity'=>'1']],'settlement_form'=>'1','transport_enabled'=>'1','transport_cost'=>'12.50','other_costs_enabled'=>'1','return_costs'=>[['name'=>'Reambalare','amount'=>'2.50']]],$this->actor);
        self::assertSame(-(int)$line['gross_bani']/2+1500,$credit['gross_bani']);self::assertSame('accepted',$tracking->forOrder($order)[0]['state']);self::assertSame(1500,$tracking->forOrder($order)[0]['settlement']['costs_bani']);
        $_SESSION['tracked_order']['id']=$order+1;self::assertSame(303,$app->handle($request)->getStatusCode());
        unset($app);gc_collect_cycles();
    }

    public function testReopenedCancellationCanBeInvoicedAgainAfterExplicitDeletion(): void
    {
        $id=$this->order(1);$orders=new \App\Modules\Orders\OrderService($this->pdo);$repo=new InvoiceRepository($this->pdo);
        $first=$this->service->issue($id,['request_key'=>'reopen-first-sale'],$this->actor);
        $this->cancelWithDocuments($id,'delete','reopen-first-delete');
        $orders->transition($id,'reopen',null,$this->actor['id']);
        self::assertTrue($orders->findForAdmin($id)['document_actions']['issue_invoice']['allowed']);
        $second=$this->service->issue($id,['request_key'=>'reopen-second-sale'],$this->actor);
        self::assertNotSame($first['id'],$second['id']);self::assertSame($first['number'],$second['number']);
        $this->cancelWithDocuments($id,'delete','reopen-second-delete');
        $orders->transition($id,'reopen',null,$this->actor['id']);
        self::assertCount(0,$repo->currentOrderInvoices($id));
        self::assertSame('new',$repo->one('orders',['id'=>$id])['status']);
    }

    public function testCancelledOrderWithReturnInvoiceCannotBeReopenedAndNeedsNir(): void
    {
        $this->purchase(4,'40','CANCEL-STOCK');
        $id=$this->order(2);$orders=new \App\Modules\Orders\OrderService($this->pdo);$repo=new InvoiceRepository($this->pdo);
        $original=$this->service->issue($id,['request_key'=>'cancel-return-sale'],$this->actor);
        $orders->transition($id,'ship',null,$this->actor['id']);
        $this->cancelWithDocuments($id,'credit','cancel-return-credit');
        $documents=$repo->currentOrderInvoices($id);self::assertCount(2,$documents);self::assertSame(0,$repo->orderNet($id));
        self::assertSame('cancelled',$repo->one('orders',['id'=>$id])['status']);
        self::assertSame([],$orders->findForAdmin($id)['available_actions']);
        self::assertSame(20000,$this->balance());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM invoice_receipts')->fetchColumn());
        try { $orders->transition($id,'reopen',null,$this->actor['id']);self::fail('Reopened credited cancellation'); }
        catch(\RuntimeException $e) { self::assertStringContainsString('nu mai poate fi reluată',$e->getMessage()); }
        $credit=$this->service->document((int)$documents[1]['id'],$this->actor);
        self::assertTrue($credit['requires_nir']);self::assertNotEmpty($credit['order_return']);
        $exports=new \App\Modules\Invoicing\InvoiceExportService($repo,$this->root);
        self::assertSame('FACTURĂ DE RETUR',$exports->pdfData($credit)['document_title']);
        $nir=new NirService($this->pdo);$defaults=$nir->defaults($this->actor)['values'];
        $input=['physically_received'=>true,'warehouse_id'=>$defaults['warehouse_id'],'location_id'=>$defaults['location_id']];
        $receipt=$nir->receiveReturn((int)$credit['order_return']['return_id'],$input,'cancel-return-receipt',$this->actor);
        self::assertSame(40000,$this->balance());
        self::assertSame($receipt['id'],$nir->receiveReturn((int)$credit['order_return']['return_id'],$input,'cancel-return-receipt',$this->actor)['id']);
        self::assertSame(40000,$this->balance());
        self::assertNotEmpty($this->service->document((int)$credit['id'],$this->actor)['receipt_nir']);
        self::assertSame('cancelled',$repo->one('orders',['id'=>$id])['status']);
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM invoice_refunds')->fetchColumn());
    }

    public function testClientCancellationLeavesDocumentsForExplicitAdminDecision(): void
    {
        $repo=new InvoiceRepository($this->pdo);$orders=new \App\Modules\Orders\OrderService($this->pdo);
        $workflow=new \App\Modules\Invoicing\CancellationInvoiceWorkflow($repo,$this->service);
        $first=$this->service->issue($this->order(1),['request_key'=>'client-cancel-first'],$this->actor);
        $last=$this->service->issue($this->order(1),['request_key'=>'client-cancel-last'],$this->actor);
        $before=(int)$this->pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn();
        foreach([$first,$last] as $document) {
            $orders->transition($document['order_id'],'cancel',null,$this->actor['id'],'',false,null,'customer_tracking');
            self::assertSame($document['snapshot_json'],$this->service->load($document['id'])['snapshot_json']);
            $plan=$workflow->plan($document['order_id'],$this->actor);
            self::assertTrue($plan['pending']);self::assertTrue($plan['customer_cancelled']);
            self::assertSame($document['id']===$last['id'],$plan['can_delete']);
        }
        self::assertSame(-20000,$this->balance());self::assertSame(2,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
        self::assertSame($before,(int)$this->pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
        self::assertFalse($workflow->plan($last['order_id'],['id'=>$this->actor['id'],'role'=>'manager'])['can_delete']);
        $orders->transition($last['order_id'],'reopen',null,$this->actor['id']);
        self::assertSame('new',$repo->one('orders',['id'=>$last['order_id']])['status']);
        self::assertCount(1,$repo->currentOrderInvoices($last['order_id']));
    }

    public function testCancellationRejectsStaleAndForgedDocumentChoicesAtomically(): void
    {
        $repo=new InvoiceRepository($this->pdo);$orders=new \App\Modules\Orders\OrderService($this->pdo);
        $workflow=new \App\Modules\Invoicing\CancellationInvoiceWorkflow($repo,$this->service);
        $invoice=$this->service->issue($this->order(1),['request_key'=>'cancel-stale-first'],$this->actor);
        $id=$invoice['order_id'];$plan=$workflow->plan($id,$this->actor);
        $before=$repo->one('orders',['id'=>$id]);$reservation=$repo->all('inventory_reservations',['order_id'=>$id]);
        $this->service->issue($this->order(1),['request_key'=>'cancel-stale-later'],$this->actor);
        $input=['decision'=>'delete','document_version'=>$plan['version'],'request_key'=>'cancel-stale-decision'];
        try { $orders->transition($id,'cancel',null,$this->actor['id'],'',false,null,'admin',$input);self::fail('Stale choice accepted'); }
        catch(InvoiceException $e) { self::assertSame(409,$e->getCode()); }
        self::assertSame($before,$repo->one('orders',['id'=>$id]));self::assertSame($reservation,$repo->all('inventory_reservations',['order_id'=>$id]));
        $input['document_version']=$workflow->plan($id,$this->actor)['version'];
        try { $orders->transition($id,'cancel',null,$this->actor['id'],'',false,null,'admin',$input);self::fail('Nonlatest deletion accepted'); }
        catch(InvoiceException $e) { self::assertSame(409,$e->getCode()); }
        self::assertSame($before,$repo->one('orders',['id'=>$id]));
        $orders->transition($id,'cancel',null,$this->actor['id']);$input['decision']='credit';
        $orders->transition($id,'reopen',null,$this->actor['id']);
        try { $workflow->resolve($id,$input,$this->actor);self::fail('Stale cancelled form accepted'); }
        catch(InvoiceException $e) { self::assertSame(409,$e->getCode()); }
    }

    public function testCancellationHttpChoicesWarningAndReceiptPrompt(): void
    {
        $id=$this->order(1);$invoice=$this->service->issue($id,['request_key'=>'cancel-http-sale'],$this->actor);
        $_SESSION['user_id']=$this->actor['id'];$factory=new \Slim\Psr7\Factory\ServerRequestFactory();
        $send=function(string $method,string $path,array $input=[],array $query=[],bool $modal=false)use($factory) {
            $request=$factory->createServerRequest($method,'http://localhost'.$path,['REMOTE_ADDR'=>'127.0.0.84'])->withParsedBody($input)->withQueryParams($query)->withHeader('Accept','application/json');
            if($modal) { $request=$request->withHeader('X-Invoice-Modal','1')->withHeader('X-Requested-With','XMLHttpRequest'); }
            $app=\App\AppFactory::create($this->root);$response=$app->handle($request);unset($app);gc_collect_cycles();return $response;
        };
        $base='/admin/comenzi/'.$id.'/';
        $form=$send('GET',$base.'status/modal/',[],['action'=>'cancel'],true);self::assertSame(200,$form->getStatusCode());
        self::assertStringContainsString('name="cancellation_document_action" value="delete"',(string)$form->getBody());
        $this->service->issue($this->order(1),['request_key'=>'cancel-http-later-sale'],$this->actor);
        $form=$send('GET',$base.'status/modal/',[],['action'=>'cancel'],true);$html=(string)$form->getBody();
        self::assertStringNotContainsString('name="cancellation_document_action" value="delete"',$html);
        self::assertStringContainsString('name="cancellation_document_action" value="credit"',$html);
        $response=$send('POST',$base.'actiune/',['_csrf'=>$_SESSION['_csrf'],'action'=>'cancel','cancellation_document_action'=>'later']);
        self::assertSame(200,$response->getStatusCode(),(string)$response->getBody());
        $page=$send('GET',$base);$html=(string)$page->getBody();
        self::assertStringContainsString('Comandă anulată cu factură emisă',$html);
        self::assertStringContainsString('Emite factura de retur',$html);self::assertStringNotContainsString('decision=delete',$html);
        self::assertLessThan(strpos($html,'Documentele comenzii'),strpos($html,'Comandă anulată cu factură emisă'));
        $form=$send('GET',$base.'anulare/factura/',[],['decision'=>'credit'],true);$html=(string)$form->getBody();
        self::assertSame(200,$form->getStatusCode());self::assertStringContainsString('iv-edit-notify',$html);
        preg_match('/name="document_version" value="([^"]+)"/',$html,$version);preg_match('/name="request_key" value="([^"]+)"/',$html,$key);
        $input=['decision'=>'credit','document_version'=>$version[1],'request_key'=>$key[1],'notify_customer'=>'0'];
        self::assertSame(419,$send('POST',$base.'anulare/factura/',$input)->getStatusCode());
        $input['_csrf']=$_SESSION['_csrf'];$response=$send('POST',$base.'anulare/factura/',$input);
        self::assertSame(200,$response->getStatusCode(),(string)$response->getBody());
        self::assertSame(200,$send('POST',$base.'anulare/factura/',$input)->getStatusCode());
        $page=$send('GET',$base);$html=(string)$page->getBody();
        self::assertStringNotContainsString('Comandă anulată cu factură emisă',$html);self::assertStringNotContainsString('Reia comanda',$html);
        self::assertStringContainsString('Verifică produsele și creează NIR',$html);
        self::assertStringContainsString('is-warning is-prominent',$html);
        self::assertLessThan(strpos($html,'Documentele comenzii'),strpos($html,'Au ajuns produsele?'));
        self::assertSame('cancelled',$this->pdo->query('SELECT status FROM orders WHERE id='.$id)->fetchColumn());
    }

    public function testPaidCardDocumentsCannotBeDeletedEvenAfterCancellationOrRefund(): void
    {
        $id=$this->order(1);$repo=new InvoiceRepository($this->pdo);
        $repo->update('orders',['payment_method'=>'stripe','payment_status'=>'paid'],['id'=>$id]);
        $order=$repo->one('orders',['id'=>$id]);
        $payment=$repo->insert('payments',['order_id'=>$id,'provider'=>'stripe','provider_payment_id'=>'pi_cancellation_qa','amount_bani'=>$order['total_bani'],'status'=>'paid','currency'=>'RON','refunded_bani'=>0,'created_at'=>$repo->now(),'updated_at'=>$repo->now()]);
        $invoice=$this->service->issue($id,['request_key'=>'card-cancel-sale'],$this->actor);
        self::assertStringContainsString('plătite cu cardul',$this->service->deleteBlocker($invoice));
        self::assertNotEmpty($this->service->document($invoice['id'],$this->actor)['delete_blocker']);
        $workflow=new \App\Modules\Invoicing\CancellationInvoiceWorkflow($repo,$this->service);
        self::assertFalse($workflow->plan($id,$this->actor)['can_delete']);
        try { $this->service->delete($invoice['id'],['request_key'=>'card-delete-denied','reason'=>'Verificare protecție card','version'=>$invoice['row_version']],$this->actor);self::fail('Card invoice deleted'); }
        catch(InvoiceException $e) { self::assertSame(409,$e->getCode()); }
        $this->cancelWithDocuments($id,'credit','card-cancel-credit');
        $credit=$repo->one('invoices',['order_id'=>$id,'kind'=>'return']);
        foreach(['paid','partially_refunded','refunded'] as $state) {
            $repo->update('payments',['status'=>$state],['id'=>$payment]);$repo->update('orders',['payment_status'=>$state],['id'=>$id]);
            self::assertStringContainsString('plătite cu cardul',$this->service->deleteBlocker($this->service->load($credit['id'])));
            try { $this->service->delete($credit['id'],['request_key'=>'card-credit-delete-'.$state,'reason'=>'Verificare protecție card','version'=>$credit['row_version']],$this->actor);self::fail('Card return invoice deleted'); }
            catch(InvoiceException $e) { self::assertSame(409,$e->getCode()); }
        }
        self::assertCount(2,$repo->currentOrderInvoices($id));
        self::assertSame('cancelled',$repo->one('orders',['id'=>$id])['status']);
        self::assertSame(0,$repo->orderNet($id));
    }

    public function testPaidCardCancellationAutomaticallyCreatesDocumentsForAdminAndCustomer(): void
    {
        $repo=new InvoiceRepository($this->pdo);$orders=new \App\Modules\Orders\OrderService($this->pdo);
        $workflow=new \App\Modules\Invoicing\CancellationInvoiceWorkflow($repo,$this->service);
        foreach(['admin','customer_tracking'] as $source) {
            $id=$this->order(1);$repo->update('orders',['payment_method'=>'stripe','payment_status'=>'paid'],['id'=>$id]);
            // Cover both a pending initial invoice and an already invoiced checkout.
            if($source==='customer_tracking') { $this->service->issue($id,['request_key'=>'card-auto-existing'],$this->actor); }
            $orders->transition($id,'cancel',null,$this->actor['id'],'',false,null,$source,['decision'=>'later']);
            $documents=$repo->currentOrderInvoices($id);self::assertCount(2,$documents);
            self::assertSame(['sale','return'],array_column($documents,'kind'));
            self::assertSame(0,$repo->orderNet($id));self::assertFalse($workflow->plan($id,$this->actor)['pending']);
            self::assertSame('cancelled',$repo->one('orders',['id'=>$id])['status']);
            self::assertSame([],$orders->findForAdmin($id)['available_actions']);
            self::assertNotEmpty($this->service->document((int)$documents[1]['id'],$this->actor)['order_return']);
        }
        self::assertSame(-20000,$this->balance());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM invoice_receipts')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM invoice_refunds')->fetchColumn());
    }

    public function testPaidCardReturnCannotDisableInvoiceAndRequestWaitsForDecision(): void
    {
        $id=$this->order(2);$repo=new InvoiceRepository($this->pdo);
        $repo->update('orders',['payment_method'=>'stripe','payment_status'=>'paid','status'=>'delivered'],['id'=>$id]);
        $item=$repo->all('order_items',['order_id'=>$id])[0];
        $requests=new \App\Modules\Invoicing\OrderReturnRequests($repo);
        $request=$requests->create($id,['request_key'=>'card-auto-return-request','quantities'=>[$item['id']=>1],'reason'=>'Mărime nepotrivită','refund_method'=>'original'],$this->actor);
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
        $input=['request_key'=>'card-auto-return-accept','return_request_id'=>$request,'quantities'=>[$item['id']=>1],'reason'=>'Mărime nepotrivită','issue_invoice'=>'0','notify_customer'=>'0'];
        $returnId=$this->service->returnOrder($id,$input,$this->actor);
        self::assertSame($returnId,$this->service->returnOrder($id,$input,$this->actor));
        $documents=$repo->currentOrderInvoices($id);self::assertSame(['sale','return'],array_column($documents,'kind'));
        self::assertSame('accepted',$repo->one('order_return_requests',['id'=>$request])['state']);
        self::assertSame(-10000,(int)$repo->all('invoice_lines',['invoice_id'=>$documents[1]['id']])[0]['quantity_units']);
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM invoice_receipts')->fetchColumn());
        self::assertTrue((new \App\Modules\Orders\OrderService($this->pdo))->findForAdmin($id)['return_invoice_required']);
    }

    /** @return array<string,mixed> */
    private function cancelWithDocuments(int $id,string $decision,string $key): array
    {
        $workflow=new \App\Modules\Invoicing\CancellationInvoiceWorkflow(new InvoiceRepository($this->pdo),$this->service);
        $input=['decision'=>$decision,'document_version'=>$workflow->plan($id,$this->actor)['version'],'request_key'=>$key,'notify_customer'=>'0'];
        (new \App\Modules\Orders\OrderService($this->pdo))->transition($id,'cancel',null,$this->actor['id'],'',false,null,'admin',$input);
        return $input;
    }

    public function testAdminReturnRequestsCanOnlyBeCreatedFromTheOrder(): void
    {
        $order=$this->order(2); $this->pdo->exec("UPDATE orders SET status='delivered' WHERE id=".$order);
        $invoice=$this->service->issue($order,['request_key'=>'single-order-request-invoice'],$this->actor);
        $_SESSION['user_id']=$this->actor['id'];
        $factory=new \Slim\Psr7\Factory\ServerRequestFactory();
        $send=function(string $method,string $path,array $input=[],array $headers=[])use($factory) {
            $request=$factory->createServerRequest($method,'http://localhost'.$path,['REMOTE_ADDR'=>'127.0.0.83'])->withParsedBody($input);
            foreach($headers as $name=>$value) { $request=$request->withHeader($name,$value); }
            $app=\App\AppFactory::create($this->root);$response=$app->handle($request);unset($app);gc_collect_cycles();return $response;
        };
        $legacy='/admin/facturi/'.$invoice['id'].'/request/';
        $canonical='/admin/comenzi/'.$order.'/cerere-retur/modal/';
        $page=$send('GET','/admin/comenzi/'.$order.'/');
        self::assertSame(200,$page->getStatusCode());
        self::assertSame(1,substr_count((string)$page->getBody(),'href="'.$canonical.'"'));
        self::assertStringNotContainsString($legacy,(string)$page->getBody());
        $page=$send('GET','/admin/facturi/'.$invoice['id'].'/');
        self::assertSame(200,$page->getStatusCode());self::assertStringNotContainsString($legacy,(string)$page->getBody());
        foreach([[],['X-Invoice-Modal'=>'1']] as $headers) {
            $response=$send('GET',$legacy,[],$headers);
            self::assertSame(303,$response->getStatusCode());self::assertSame($canonical,$response->getHeaderLine('Location'));
        }
        $page=$send('GET',$canonical);$html=(string)$page->getBody();
        self::assertSame(200,$page->getStatusCode());
        self::assertStringContainsString('data-order-return-form',$html);
        self::assertStringContainsString('return-editor.css',$html);
        self::assertStringContainsString('name="bank_name"',$html);
        self::assertStringContainsString('order-return-item__image',$html);
        $before=(int)$this->pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn();
        $oldInput=['request_key'=>'legacy-request-denied','quantities'=>[$invoice['lines'][0]['id']=>'1'],'reason'=>'Produs nepotrivit','refund_method'=>'cash'];
        self::assertSame(419,$send('POST',$legacy,$oldInput,['Accept'=>'application/json'])->getStatusCode());
        $oldInput['_csrf']=$_SESSION['_csrf'];
        $response=$send('POST',$legacy,$oldInput,['Accept'=>'application/json']);
        self::assertSame(410,$response->getStatusCode());
        $payload=json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR);
        self::assertFalse($payload['ok']);self::assertSame($canonical,$payload['redirect']);
        $response=$send('POST',$legacy,$oldInput);
        self::assertSame(303,$response->getStatusCode());self::assertSame($canonical,$response->getHeaderLine('Location'));
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM invoice_return_requests')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM order_return_requests')->fetchColumn());
        self::assertSame($before,(int)$this->pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
        $item=(int)$this->pdo->query('SELECT id FROM order_items WHERE order_id='.$order)->fetchColumn();
        $input=['_csrf'=>$_SESSION['_csrf'],'request_key'=>'canonical-order-request','quantities'=>[$item=>'1'],'reason'=>'Produs nepotrivit','refund_method'=>'cash'];
        $response=$send('POST','/admin/comenzi/'.$order.'/cerere-retur/',$input,['Accept'=>'application/json']);
        self::assertSame(200,$response->getStatusCode(),(string)$response->getBody());
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM order_return_requests')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM invoice_return_requests')->fetchColumn());
        self::assertSame('pending',$this->pdo->query('SELECT state FROM order_return_requests')->fetchColumn());
        self::assertSame($before+1,(int)$this->pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
        self::assertSame('delivered',$this->pdo->query('SELECT status FROM orders WHERE id='.$order)->fetchColumn());
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
