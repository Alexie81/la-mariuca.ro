<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Companies\CompanyAdminService;
use App\Modules\Companies\CompanyRepository;
use App\Modules\Companies\CompanyStampStorage;
use App\Modules\Inventory\AccountingStockService;
use App\Modules\Inventory\AccountingStockReadModel;
use App\Modules\Catalog\CatalogAdminService;
use App\Modules\Admin\AdminNotificationService;
use App\Modules\Inventory\NirConflictException;
use App\Modules\Inventory\NirService;
use App\Modules\Inventory\NirValidationException;
use App\Modules\Suppliers\SupplierAdminService;
use App\Shared\Config\Config;
use Tests\DatabaseTestCase;

final class NirDomainTest extends DatabaseTestCase
{
    private NirService $service;
    private array $actor;
    private array $base;
    private array $variant;
    private int $supplier;
    private int $company;

    protected function setUp(): void
    {
        parent::setUp(); $this->seed(); $now=gmdate('c');
        $this->pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES ('nir-domain@example.test','none','Paula','Admin','administrator','active',:now,:now)")->execute(['now'=>$now]);
        $this->actor=['id'=>(int)$this->pdo->lastInsertId(),'role'=>'administrator'];
        $companies=new CompanyAdminService(new CompanyRepository($this->pdo),new CompanyStampStorage($this->root));
        $this->company=$companies->saveCompany(['legal_name'=>'Firma QA','registered_address'=>'Strada Test 10'],null,null); $companies->saveWarehouse($this->company,['name'=>'Depozit A'],null);
        $this->supplier=(new SupplierAdminService($this->pdo))->save(['alias'=>'Furnizor QA','company_name'=>'Furnizor QA SRL']);
        $this->service=new NirService($this->pdo); $this->base=$this->service->defaults($this->actor)['values'];
        $this->variant=$this->pdo->query("SELECT v.* FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.track_in_conta=1 AND i.physical_quantity>=3 ORDER BY v.id LIMIT 1")->fetch();
        $this->pdo->prepare('UPDATE product_variants SET track_in_conta=0 WHERE product_id=:id')->execute(['id'=>$this->variant['product_id']]);
    }

    protected function tearDown(): void { unset($this->service); parent::tearDown(); }

    public function testDraftHasNoStockConfirmIsAtomicIdempotentAndCommercialStockIsUntouched(): void
    {
        $commercial=$this->pdo->query('SELECT * FROM inventory ORDER BY variant_id')->fetchAll();
        $draft=$this->draft(); self::assertSame(0,$this->countRows('nir_stock_balances')); self::assertSame(0,$this->countRows('nir_fifo_layers'));
        $confirmed=$this->service->confirm($draft['id'],$draft['row_version'],'confirm-atomic-001',true,$this->actor);
        self::assertSame('confirmed',$confirmed['status']); self::assertMatchesRegularExpression('/^LM-NIR-\d{4}-1$/',$confirmed['number']);
        self::assertSame(30000,$this->balance()); self::assertSame(1,$this->countRows('nir_fifo_layers')); self::assertSame(1,$this->countRows('nir_stock_movements'));
        $retry=$this->service->confirm($draft['id'],$draft['row_version'],'confirm-atomic-001',true,$this->actor);
        self::assertSame($confirmed['id'],$retry['id']); self::assertSame(30000,$this->balance());
        self::assertSame($commercial,$this->pdo->query('SELECT * FROM inventory ORDER BY variant_id')->fetchAll());
        self::assertTrue($this->service->fifo([],$this->actor)['reconciliation'][0]['balanced']);
    }

    public function testStaleDraftCannotOverwriteAndLastConfirmedCanBeDeleted(): void
    {
        $draft=$this->draft(); $input=$draft; $input['notes']='Actualizat'; $updated=$this->service->save($draft['id'],$input,$this->actor);
        try { $this->service->save($draft['id'],$draft,$this->actor); self::fail('Stale version accepted'); } catch(NirConflictException $e) { self::assertSame(409,$e->getCode()); }
        $confirmed=$this->service->confirm($updated['id'],$updated['row_version'],'confirm-delete-001',true,$this->actor);
        $this->service->delete($confirmed['id'],$confirmed['row_version'],$this->actor);
        self::assertSame(0,$this->countRows('nir_documents')); self::assertSame(0,$this->balance());
    }

    public function testNegativeStockReceiptAndDeletionRestoreMinusOneWithoutChangingStorefront(): void
    {
        $commercial=$this->pdo->query('SELECT * FROM inventory ORDER BY variant_id')->fetchAll();
        $draft=$this->draft(['invoice_quantity'=>'2','received_quantity'=>'2','accepted_quantity'=>'2']); $line=$draft['lines'][0];
        $target=array_intersect_key($line,array_flip(['scope_type','scope_id','product_id','variant_id','sku']));
        $this->service->consume($draft['warehouse_id'],$target,10000,'invoice_line',19001,'sale-before-purchase',$this->actor);
        self::assertSame(-10000,$this->balance());
        $confirmed=$this->service->confirm($draft['id'],$draft['row_version'],'purchase-after-sale',true,$this->actor);
        self::assertSame(10000,$this->balance()); self::assertSame(0,$this->countRows('nir_pending_consumptions'));
        $sales=$this->pdo->query("SELECT * FROM nir_stock_movements WHERE source_type='invoice_line'")->fetchAll();
        $this->service->delete($confirmed['id'],$confirmed['row_version'],$this->actor);
        self::assertSame(-10000,$this->balance()); self::assertSame(0,$this->countRows('nir_fifo_layers'));
        self::assertSame(10000,(int)$this->pdo->query('SELECT SUM(quantity_units) FROM nir_pending_consumptions')->fetchColumn());
        self::assertSame($sales,$this->pdo->query("SELECT * FROM nir_stock_movements WHERE source_type='invoice_line'")->fetchAll());
        self::assertSame($commercial,$this->pdo->query('SELECT * FROM inventory ORDER BY variant_id')->fetchAll());
        self::assertTrue($this->service->fifo([],$this->actor)['reconciliation'][0]['balanced']);
        self::assertSame('-1',(new AccountingStockReadModel($this->pdo))->quantities([(int)$line['product_id']])[$line['product_id']]);
        $next=$this->draft([],['invoice_number'=>'REPLACEMENT']);
        $next=$this->service->confirm($next['id'],$next['row_version'],'replacement-purchase',true,$this->actor);
        self::assertSame($confirmed['number'],$next['number']); self::assertSame(20000,$this->balance());
        // Original sale and deleted confirmation retries cannot consume twice or recreate the receipt.
        $this->service->consume($draft['warehouse_id'],$target,10000,'invoice_line',19001,'sale-before-purchase',$this->actor);
        self::assertSame(20000,$this->balance());
        try { $this->service->confirm($draft['id'],$draft['row_version'],'purchase-after-sale',true,$this->actor); self::fail('Deleted confirmation recreated'); }
        catch(\App\Modules\Inventory\NirNotFoundException) { self::assertSame(20000,$this->balance()); }
    }

    public function testDeletionAfterSaleNeverConsumesStockFromAnotherReceipt(): void
    {
        $first=$this->confirmed(['invoice_quantity'=>'2','received_quantity'=>'2','accepted_quantity'=>'2']);
        $draft=$this->draft(['invoice_quantity'=>'1','received_quantity'=>'1','accepted_quantity'=>'1'],['invoice_number'=>'LAST-BACKDATED','received_at'=>'2025-01-01T12:00']);
        $last=$this->service->confirm($draft['id'],$draft['row_version'],'last-purchase-early-stock',true,$this->actor);
        $line=$last['lines'][0]; $target=array_intersect_key($line,array_flip(['scope_type','scope_id','product_id','variant_id','sku']));
        $this->service->consume($last['warehouse_id'],$target,10000,'invoice_line',19002,'sale-from-last-receipt',$this->actor);
        $other=$this->pdo->query('SELECT * FROM nir_fifo_layers WHERE nir_id='.$first['id'])->fetchAll();
        $this->service->delete($last['id'],$last['row_version'],$this->actor);
        self::assertSame(10000,$this->balance());
        self::assertSame($other,$this->pdo->query('SELECT * FROM nir_fifo_layers WHERE nir_id='.$first['id'])->fetchAll());
        self::assertSame(10000,(int)$this->pdo->query('SELECT SUM(quantity_units) FROM nir_pending_consumptions')->fetchColumn());
        self::assertTrue($this->service->fifo([],$this->actor)['reconciliation'][0]['balanced']);
    }

    public function testOnlyHighestConfirmedNumberCanBeDeletedIgnoringDraftsAndStaleScreens(): void
    {
        $first=$this->confirmed();
        $draft=$this->draft([],['invoice_number'=>'NEXT']); $last=$this->service->confirm($draft['id'],$draft['row_version'],'next-to-delete',true,$this->actor);
        $one=$this->draft([],['invoice_number'=>'DRAFT-A']); $two=$this->draft([],['invoice_number'=>'DRAFT-B']);
        self::assertFalse($this->service->document($first['id'],$this->actor)['can_delete']);
        self::assertTrue($this->service->document($last['id'],$this->actor)['can_delete']);
        try { $this->service->delete($first['id'],$first['row_version'],$this->actor); self::fail('Non-last deletion allowed'); }
        catch(NirConflictException) { self::assertSame(60000,$this->balance()); }
        try { $this->service->delete($last['id'],0,$this->actor); self::fail('Stale deletion allowed'); }
        catch(NirConflictException) { self::assertSame(60000,$this->balance()); }
        $this->service->delete($one['id'],$one['row_version'],$this->actor);
        self::assertSame('Draft #1',$this->service->document($two['id'],$this->actor)['display_number']);
        $this->service->delete($last['id'],$last['row_version'],$this->actor);
        self::assertSame(30000,$this->balance());
        self::assertTrue($this->service->document($first['id'],$this->actor)['can_delete']);
        self::assertSame('Draft #1',$this->service->document($two['id'],$this->actor)['display_number']);
        $this->service->delete($two['id'],$two['row_version'],$this->actor);
        self::assertSame('Draft #1',$this->draft([],['invoice_number'=>'DRAFT-C'])['display_number']);
    }

    public function testDeletingLatestSupplierReturnRestoresExactQuantitiesAndValues(): void
    {
        $original=$this->confirmed(['unit_price'=>'0.333333','vat_percent'=>'0']);
        $layers=$this->pdo->query('SELECT * FROM nir_fifo_layers')->fetchAll();
        $returned=$this->service->reverse($original['id'],$original['row_version'],['attachments'=>$this->returnEvidence(),'reason'=>'Retur test','invoice_number'=>'RETURN-UNDO','invoice_date'=>gmdate('Y-m-d'),'quantities'=>[$original['lines'][0]['id']=>'1']],'return-to-delete',$this->actor);
        self::assertSame(20000,$this->balance());
        $this->service->delete($returned['id'],$returned['row_version'],$this->actor);
        self::assertSame(30000,$this->balance()); self::assertSame($layers,$this->pdo->query('SELECT * FROM nir_fifo_layers')->fetchAll());
        self::assertSame('none',$this->service->document($original['id'],$this->actor)['reversal_status']);
        self::assertSame(0,$this->countRows('nir_fifo_consumptions'));
    }

    public function testInvoiceBeforeFirstReceiptRetainsLegacyShortage(): void
    {
        $now=gmdate('c');
        $this->pdo->prepare("INSERT INTO accounting_stock_balances(scope_type,scope_id,product_id,variant_id,sku,quantity,created_at,updated_at) VALUES ('product',:id,:id,NULL,'OLD',-1,:now,:now)")->execute(['id'=>$this->variant['product_id'],'now'=>$now]);
        $draft=$this->draft();
        $order=$this->order(1);
        $accounting=new AccountingStockService($this->pdo);
        $accounting->recordInvoice($order,'LEGACY-NEXT-INVOICE',$this->actor['id']);
        self::assertSame(-20000,$this->balance());
        $accounting->recordInvoice($order,'LEGACY-NEXT-INVOICE',$this->actor['id']);
        self::assertSame(-20000,$this->balance());
        $confirmed=$this->service->confirm($draft['id'],$draft['row_version'],'legacy-purchase-later',true,$this->actor);
        self::assertSame(10000,$this->balance());
        $this->service->delete($confirmed['id'],$confirmed['row_version'],$this->actor);
        self::assertSame(-20000,$this->balance());
        self::assertTrue($this->service->fifo([],$this->actor)['reconciliation'][0]['balanced']);
    }

    public function testPostedInvoiceWithoutReceiptsDebitsOnlyOnceAndLeavesOnlineStockUntouched(): void
    {
        $order=$this->order(1);
        $online=$this->pdo->query('SELECT * FROM inventory ORDER BY variant_id')->fetchAll();
        $accounting=new AccountingStockService($this->pdo);
        $accounting->recordInvoice($order,'EMPTY-STOCK-INVOICE',$this->actor['id']);
        self::assertSame(-10000,$this->balance());
        $accounting->recordInvoice($order,'EMPTY-STOCK-INVOICE',$this->actor['id']);
        self::assertSame(-10000,$this->balance());
        self::assertSame($online,$this->pdo->query('SELECT * FROM inventory ORDER BY variant_id')->fetchAll());
        self::assertTrue($this->service->fifo([],$this->actor)['reconciliation'][0]['balanced']);
    }

    public function testFirstReceiptPreservesExistingLegacyNegativeBalanceAndDeletionRestoresIt(): void
    {
        $now=gmdate('c');
        $this->pdo->prepare("INSERT INTO accounting_stock_balances(scope_type,scope_id,product_id,variant_id,sku,quantity,created_at,updated_at) VALUES ('product',:id,:id,NULL,'OLD',-1,:now,:now)")->execute(['id'=>$this->variant['product_id'],'now'=>$now]);
        $confirmed=$this->confirmed(['invoice_quantity'=>'1','received_quantity'=>'1','accepted_quantity'=>'1']);
        self::assertSame(0,$this->balance());
        $this->service->delete($confirmed['id'],$confirmed['row_version'],$this->actor);
        self::assertSame(-10000,$this->balance());
        self::assertSame(-1,(int)$this->pdo->query('SELECT quantity FROM accounting_stock_balances')->fetchColumn());
        self::assertTrue($this->service->fifo([],$this->actor)['reconciliation'][0]['balanced']);
    }

    public function testWarningsAndStrictDuplicateAreCheckedBeforeAnyStockEffect(): void
    {
        $draft=$this->draft(['supplier_code'=>'']);
        try { $this->service->confirm($draft['id'],$draft['row_version'],'warning-test-001',false,$this->actor); self::fail('Missing code should warn'); } catch(NirValidationException $e) { self::assertNotEmpty($e->warnings); }
        self::assertSame(0,$this->countRows('nir_fifo_layers'));
        $this->service->confirm($draft['id'],$draft['row_version'],'warning-test-001',true,$this->actor);
        $duplicate=$this->draft(['supplier_code'=>'']);
        try { $this->service->confirm($duplicate['id'],$duplicate['row_version'],'duplicate-test-001',true,$this->actor); self::fail('Duplicate accepted'); } catch(NirValidationException $e) { self::assertArrayHasKey('invoice_number',$e->errors); }
        self::assertSame(30000,$this->balance());
    }

    public function testControlledCorrectionRollsBackCompletelyWhenNewValuesFail(): void
    {
        $confirmed=$this->confirmed(); $before=$this->pdo->query('SELECT * FROM nir_fifo_layers')->fetchAll(); $input=$confirmed;
        $input['correction_reason']='Corectare cantitate conform documentului'; $input['lines'][0]['accepted_quantity']='5';
        try { $this->service->correct($confirmed['id'],$confirmed['row_version'],$input,'correction-invalid-001',true,$this->actor); self::fail('Invalid correction accepted'); } catch(NirValidationException) {}
        self::assertSame($before,$this->pdo->query('SELECT * FROM nir_fifo_layers')->fetchAll()); self::assertSame(30000,$this->balance());
        self::assertSame($confirmed['row_version'],$this->service->document($confirmed['id'],$this->actor)['row_version']);
        $input=$confirmed; $input['correction_reason']='Corectare cantitate conform documentului'; foreach(['accepted_quantity','received_quantity','invoice_quantity'] as $field) { $input['lines'][0][$field]='4'; }
        $corrected=$this->service->correct($confirmed['id'],$confirmed['row_version'],$input,'correction-valid-001',true,$this->actor);
        self::assertSame($confirmed['number'],$corrected['number']); self::assertSame(40000,$this->balance()); self::assertSame(1,$this->countRows('nir_fifo_layers'));
    }

    public function testPartialSupplierReturnsNeverExceedRemainingAndFinalFragmentAbsorbsRounding(): void
    {
        $draft=$this->draft(['unit_price'=>'0.333333','vat_percent'=>'0'],['invoice_series'=>'INI','invoice_number'=>'000042','invoice_date'=>'2026-10-30']);
        $confirmed=$this->service->confirm($draft['id'],$draft['row_version'],'original-invoice-reference',true,$this->actor); $line=$confirmed['lines'][0]; $sum=0;
        self::assertArrayNotHasKey('original_invoice_number',$confirmed);
        for($index=1;$index<=3;$index++) {
            $current=$this->service->document($confirmed['id'],$this->actor);
            $input=['attachments'=>$this->returnEvidence(),'reason'=>'Retur către furnizor','invoice_number'=>'ST-'.$index,'invoice_date'=>gmdate('Y-m-d'),'quantities'=>[$line['id']=>'1']];
            $reverse=$this->service->reverse($confirmed['id'],$current['row_version'],$input,'reverse-fragment-'.$index,$this->actor); $sum+=(int)$reverse['net_bani'];
            foreach ([$reverse,$this->service->document($reverse['id'],$this->actor)] as $returned) {
                self::assertSame($confirmed['number'],$returned['original_number']);
                self::assertSame('INI',$returned['original_invoice_series']);
                self::assertSame('000042',$returned['original_invoice_number']);
                self::assertSame('2026-10-30',$returned['original_invoice_date']);
                self::assertSame('ST-'.$index,$returned['invoice_number'],'The credit invoice must stay distinct from the original invoice.');
            }
            $again=$this->service->reverse($confirmed['id'],$current['row_version'],$input,'reverse-fragment-'.$index,$this->actor); self::assertSame($reverse['id'],$again['id']);
        }
        self::assertSame(-100,$sum); self::assertSame(0,$this->balance()); self::assertSame('full',$this->service->document($confirmed['id'],$this->actor)['reversal_status']);
        self::assertSame('0.000000',$this->pdo->query('SELECT unit_cost_ron FROM nir_stock_balances')->fetchColumn());
        $current=$this->service->document($confirmed['id'],$this->actor);
        $this->expectException(NirValidationException::class); $this->service->reverse($confirmed['id'],$current['row_version'],['attachments'=>$this->returnEvidence(),'reason'=>'Prea mult','invoice_number'=>'ST-4','invoice_date'=>gmdate('Y-m-d'),'quantities'=>[$line['id']=>'1']],'reverse-too-many',$this->actor);
    }

    public function testFifoConsumedReceiptCannotBeCorrectedAndNewLayersReconcilePendingSales(): void
    {
        $confirmed=$this->confirmed(); $line=$confirmed['lines'][0];
        $target=['scope_type'=>$line['scope_type'],'scope_id'=>$line['scope_id'],'product_id'=>$line['product_id'],'variant_id'=>$line['variant_id'],'sku'=>$line['sku']];
        $this->service->consume($confirmed['warehouse_id'],$target,40000,'invoice_line',99,'sale-before-next-lot',$this->actor);
        self::assertSame(-10000,$this->balance()); self::assertSame(1,$this->countRows('nir_pending_consumptions'));
        self::assertFalse($this->service->document($confirmed['id'],$this->actor)['can_correct']);
        $second=$this->draft([],['invoice_number'=>'NEXT-LOT']); $this->service->confirm($second['id'],$second['row_version'],'confirm-next-lot',true,$this->actor);
        self::assertSame(20000,$this->balance()); self::assertSame(0,$this->countRows('nir_pending_consumptions')); self::assertTrue($this->service->fifo([],$this->actor)['reconciliation'][0]['balanced']);
        $this->service->consume($confirmed['warehouse_id'],$target,40000,'invoice_line',99,'sale-before-next-lot',$this->actor); self::assertSame(20000,$this->balance());
    }

    public function testSupplierReferenceIsExactScopedAndCannotSilentlyChangeProducts(): void
    {
        $draft=$this->draft(['supplier_code'=>'  001-Ａ  ','save_reference'=>true]);
        $match=$this->service->matchLine($this->supplier,['supplier_code'=>'001-A'],$this->actor); self::assertTrue($match['matched']); self::assertSame($this->variant['product_id'],$match['product_id']);
        $falseMatch=$this->service->matchLine($this->supplier,['supplier_code'=>$this->variant['sku'],'supplier_name'=>'Nume aproape dar diferit'],$this->actor); self::assertFalse($falseMatch['matched']);
        $other=$this->pdo->query('SELECT id,product_id FROM product_variants WHERE product_id<>'.(int)$this->variant['product_id'].' LIMIT 1')->fetch();
        $input=$draft; $input['lines'][0]['product_id']=$other['product_id']; $input['lines'][0]['variant_id']=$other['id'];
        $this->expectException(NirConflictException::class); $this->service->save($draft['id'],$input,$this->actor);
    }

    public function testCostsNeverLeakInOperatorDocumentRegistryOrAmbiguousReferenceSuggestions(): void
    {
        $confirmed=$this->confirmed(['save_reference'=>true]); $operator=['id'=>$this->actor['id'],'role'=>'operator'];
        $document=$this->service->document($confirmed['id'],$operator); $encoded=json_encode($document);
        foreach(['unit_price','total_bani','inventory_bani','unit_cost_ron','before_json','after_json','last_price'] as $forbidden) { self::assertStringNotContainsString('"'.$forbidden.'"',$encoded); }
        $registry=$this->service->registry([],$operator); self::assertArrayNotHasKey('total_bani',$registry['items'][0]);
        $this->expectException(\RuntimeException::class); $this->expectExceptionCode(403); $this->service->confirm($confirmed['id'],$confirmed['row_version'],'operator-must-not',$operator!==[],$operator);
    }

    public function testAttachmentsRemainIndependentAcrossReceiptsEvenWithSameNameOrContent(): void
    {
        $file=['stored_name'=>'abc123.pdf','original_name'=>'factura.pdf','mime_type'=>'application/pdf','extension'=>'pdf','size_bytes'=>123,'sha256'=>hash('sha256','invoice'),'extraction_status'=>'manual'];
        $draft=$this->draft([],['attachments'=>[$file]]);
        try { $this->service->attach($draft['id'],[$file],$draft['row_version'],$this->actor); self::fail('Duplicate file accepted'); } catch(NirValidationException) {}
        $this->service->confirm($draft['id'],$draft['row_version'],'attachment-confirm',true,$this->actor);
        $file['stored_name']='def456.pdf'; $other=$this->draft([],['invoice_number'=>'OTHER-INVOICE','attachments'=>[$file]]);
        $other=$this->service->confirm($other['id'],$other['row_version'],'attachment-reused',true,$this->actor);
        self::assertSame('confirmed',$other['status']);
        $file['stored_name']='different-content.pdf'; $file['sha256']=hash('sha256','another invoice with the same filename');
        $third=$this->draft([],['invoice_number'=>'THIRD-INVOICE','attachments'=>[$file]]);
        $third=$this->service->confirm($third['id'],$third['row_version'],'attachment-same-name',true,$this->actor);
        self::assertSame('confirmed',$third['status']);
        $original=$this->service->document($draft['id'],$this->actor);
        self::assertSame('abc123.pdf',$original['attachments'][0]['stored_name']);
        self::assertSame('def456.pdf',$other['attachments'][0]['stored_name']);
        self::assertSame('different-content.pdf',$third['attachments'][0]['stored_name']);
        self::assertNotSame($original['attachments'][0]['id'],$other['attachments'][0]['id']);
        self::assertSame($original['attachments'][0]['sha256'],$other['attachments'][0]['sha256']);
        self::assertNotSame($original['attachments'][0]['sha256'],$third['attachments'][0]['sha256']);
        self::assertSame(90000,$this->balance());
    }

    public function testTrackingChangesAfterDraftRequireResaveAndUntrackedLinesRemainDocumentary(): void
    {
        $draft=$this->draft(); $this->pdo->prepare('UPDATE products SET track_in_conta=0 WHERE id=:id')->execute(['id'=>$this->variant['product_id']]);
        try { $this->service->confirm($draft['id'],$draft['row_version'],'changed-tracking',true,$this->actor); self::fail('Stale tracking accepted'); } catch(NirValidationException) {}
        $draft=$this->service->save($draft['id'],$draft,$this->actor); $confirmed=$this->service->confirm($draft['id'],$draft['row_version'],'changed-tracking-after-save',true,$this->actor);
        self::assertSame(3630,$confirmed['total_bani']); self::assertSame(0,$confirmed['inventory_bani']); self::assertSame(0,$this->countRows('nir_fifo_layers'));
    }

    public function testPostedInvoiceConsumesOriginalCostsAndCustomerReturnExplicitlyRestoresThem(): void
    {
        $confirmed=$this->confirmed(); $orderId=$this->order(1); self::assertSame(30000,$this->balance());
        (new AccountingStockService($this->pdo))->recordInvoice($orderId,'ISSUED-001',$this->actor['id']); self::assertSame(20000,$this->balance());
        $item=$this->pdo->query('SELECT * FROM order_items WHERE order_id='.$orderId)->fetch();
        $returnId=$this->makeReturn($orderId,$item['id']); self::assertSame(20000,$this->balance());
        $input=['physically_received'=>true,'invoice_number'=>'CREDIT-001','invoice_date'=>gmdate('Y-m-d'),'warehouse_id'=>$confirmed['warehouse_id'],'location_id'=>$confirmed['location_id']];
        $receipt=$this->service->receiveReturn($returnId,$input,'customer-return-001',$this->actor); self::assertSame('customer_return',$receipt['type']); self::assertSame(1000,$receipt['inventory_bani']); self::assertSame(30000,$this->balance());
        self::assertSame($receipt['id'],$this->service->receiveReturn($returnId,$input,'customer-return-001',$this->actor)['id']);
        $original=$this->service->document($confirmed['id'],$this->actor); self::assertSame(30000,$original['lines'][0]['available_units']);
        $reverse=$this->service->reverse($original['id'],$original['row_version'],['attachments'=>$this->returnEvidence(),'reason'=>'Retur furnizor după retur client','invoice_number'=>'CREDIT-SUPPLIER','invoice_date'=>gmdate('Y-m-d'),'quantities'=>[$original['lines'][0]['id']=>'3']],'customer-followed-supplier',$this->actor);
        self::assertSame(-3000,$reverse['inventory_bani']); self::assertSame(0,$this->balance()); self::assertTrue($this->service->fifo([],$this->actor)['reconciliation'][0]['balanced']);
    }

    public function testReturnInAnotherWarehouseCannotBeRemovedFromOriginalWarehouse(): void
    {
        $confirmed=$this->confirmed(); $orderId=$this->order(1); (new AccountingStockService($this->pdo))->recordInvoice($orderId,'ISSUED-OTHER-WAREHOUSE',$this->actor['id']);
        $item=$this->pdo->query('SELECT * FROM order_items WHERE order_id='.$orderId)->fetch(); $returnId=$this->makeReturn($orderId,$item['id']);
        $companies=new CompanyAdminService(new CompanyRepository($this->pdo),new CompanyStampStorage($this->root)); $warehouse=$companies->saveWarehouse($this->company,['name'=>'Depozit B'],null);
        $defaults=$this->service->defaults($this->actor); $location=array_values(array_filter($defaults['locations'],static fn(array $row):bool=>(int)$row['warehouse_id']===$warehouse))[0];
        $this->service->receiveReturn($returnId,['physically_received'=>true,'invoice_number'=>'CREDIT-B','invoice_date'=>gmdate('Y-m-d'),'warehouse_id'=>$warehouse,'location_id'=>$location['id']],'return-warehouse-b',$this->actor);
        $original=$this->service->document($confirmed['id'],$this->actor); self::assertSame(20000,$original['lines'][0]['available_units']);
        try { $this->service->reverse($original['id'],$original['row_version'],['attachments'=>$this->returnEvidence(),'reason'=>'Retur total','invoice_number'=>'CREDIT-SUPPLIER-B','invoice_date'=>gmdate('Y-m-d'),'quantities'=>[$original['lines'][0]['id']=>'3']],'reverse-wrong-warehouse',$this->actor); self::fail('Cross warehouse removal accepted'); } catch(NirValidationException) {}
        foreach($this->service->fifo([],$this->actor)['reconciliation'] as $row) { self::assertTrue($row['balanced']); }
    }

    public function testOpeningBalanceRequiresRealCostAndRetainsDecimalQuantity(): void
    {
        $input=['warehouse_id'=>$this->base['warehouse_id'],'product_id'=>$this->variant['product_id'],'variant_id'=>$this->variant['id'],'quantity'=>'1.2500','unit_cost_ron'=>'12.345678','reason'=>'Inventar fizic inițial','date'=>gmdate('Y-m-d')];
        $document=$this->service->opening($input,'opening-stock-001',$this->actor); self::assertSame('opening_balance',$document['type']); self::assertSame(12500,$this->balance()); self::assertSame(1543,$document['inventory_bani']);
        self::assertSame($document['id'],$this->service->opening($input,'opening-stock-001',$this->actor)['id']);
    }

    public function testCanonicalWarehouseStockReplacesOnlyMatchingLegacyScopesAndPreservesFractionsInCatalog(): void
    {
        $now=gmdate('c');
        $this->pdo->prepare("INSERT INTO accounting_stock_balances(scope_type,scope_id,product_id,variant_id,sku,quantity,created_at,updated_at) VALUES ('product',:product,:product,NULL,'OLD',-100,:now,:now)")->execute(['product'=>$this->variant['product_id'],'now'=>$now]);
        $read=new AccountingStockReadModel($this->pdo); self::assertSame(1,$read->negativeProductsCount());
        $this->service->opening(['warehouse_id'=>$this->base['warehouse_id'],'product_id'=>$this->variant['product_id'],'variant_id'=>$this->variant['id'],'quantity'=>'1.25','unit_cost_ron'=>'10','reason'=>'Inventar verificat','date'=>gmdate('Y-m-d')],'opening-display-001',$this->actor);
        self::assertSame('1.25',$read->quantities([(int)$this->variant['product_id']])[$this->variant['product_id']]); self::assertSame(0,$read->negativeProductsCount());
        $catalog=(new CatalogAdminService($this->pdo))->paginateProductsForAdmin(1,100); $product=array_values(array_filter($catalog['items'],fn(array $row):bool=>(int)$row['id']===(int)$this->variant['product_id']))[0]; self::assertSame('1.25',$product['physical_quantity']);
        $this->pdo->prepare("INSERT INTO accounting_stock_balances(scope_type,scope_id,product_id,variant_id,sku,quantity,created_at,updated_at) VALUES ('variant',:variant,:product,:variant,'OLD-VARIANT',-1,:now,:now)")->execute(['variant'=>$this->variant['id'],'product'=>$this->variant['product_id'],'now'=>$now]);
        self::assertSame('0.25',$read->quantities([(int)$this->variant['product_id']])[$this->variant['product_id']]);
    }

    public function testRegistryPeriodUsesBucharestDatesAcrossUtcMidnight(): void
    {
        $included=$this->draft([],['nir_at'=>'2026-11-12T00:30']); $this->draft([],['invoice_number'=>'BEFORE','nir_at'=>'2026-11-11T23:30']); $this->draft([],['invoice_number'=>'AFTER','nir_at'=>'2026-11-13T00:00']);
        self::assertSame('2026-11-11T22:30:00Z',$included['nir_at']);
        $registry=$this->service->registry(['from'=>'2026-11-12','to'=>'2026-11-12'],$this->actor); self::assertSame(1,$registry['total']); self::assertSame($included['id'],$registry['items'][0]['id']);
    }

    public function testMultipartReplayIgnoresRandomUploadNamesAndDoesNotRepeatSupplierReturn(): void
    {
        $original=$this->confirmed(); $file=['stored_name'=>'first-random.pdf','original_name'=>'factura.pdf','mime_type'=>'application/pdf','extension'=>'pdf','size_bytes'=>12,'sha256'=>hash('sha256','corrective invoice'),'extraction_status'=>'manual'];
        $input=['reason'=>'Retur documentat','invoice_number'=>'ST-MULTIPART','invoice_date'=>gmdate('Y-m-d'),'quantities'=>[$original['lines'][0]['id']=>'1'],'attachments'=>[$file],'_csrf'=>'first-csrf'];
        $first=$this->service->reverse($original['id'],$original['row_version'],$input,'multipart-replay-key',$this->actor);
        $input['attachments'][0]['stored_name']='second-random.pdf'; $input['_csrf']='second-csrf';
        $second=$this->service->reverse($original['id'],$original['row_version'],$input,'multipart-replay-key',$this->actor);
        self::assertSame($first['id'],$second['id']); self::assertSame(20000,$this->balance()); self::assertSame(1,$this->countRows('nir_attachments'));
    }

    public function testGlobalReturnReminderIsDailyAndDisappearsAfterPhysicalReceipt(): void
    {
        $confirmed=$this->confirmed(); $orderId=$this->order(1); (new AccountingStockService($this->pdo))->recordInvoice($orderId,'INVOICE-REMINDER',$this->actor['id']);
        $item=$this->pdo->query('SELECT * FROM order_items WHERE order_id='.$orderId)->fetch(); $returnId=$this->makeReturn($orderId,$item['id']);
        $notifications=new AdminNotificationService($this->pdo);
        $today=(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Bucharest')))->format('Y-m-d');
        $key='nir-return:'.$returnId.':'.$today;
        self::assertContains($key,array_column($notifications->feed($this->actor['id'],1,30,'stoc')['items'],'key'));
        self::assertTrue($notifications->dismiss($this->actor['id'],$key));
        self::assertNotContains($key,array_column($notifications->feed($this->actor['id'],1,30,'stoc')['items'],'key'));
        self::assertTrue($this->service->pendingReturnReceipts($this->actor)[0]['dismissed']);
        $yesterday=(new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $this->pdo->prepare('UPDATE nir_return_reminders SET dismissed_on=:day')->execute(['day'=>$yesterday]);
        $this->pdo->prepare('UPDATE admin_notification_dismissals SET notification_key=:key WHERE notification_key=:current')->execute(['key'=>'nir-return:'.$returnId.':'.$yesterday,'current'=>$key]);
        self::assertContains($key,array_column($notifications->feed($this->actor['id'],1,30,'stoc')['items'],'key'));
        $this->service->receiveReturn($returnId,['physically_received'=>true,'invoice_number'=>'CREDIT-REMINDER','invoice_date'=>$today,'warehouse_id'=>$confirmed['warehouse_id'],'location_id'=>$confirmed['location_id']],'receive-reminder-return',$this->actor);
        self::assertNotContains($key,array_column($notifications->feed($this->actor['id'],1,30,'stoc')['items'],'key')); self::assertSame([],$this->service->pendingReturnReceipts($this->actor));
    }

    public function testVatCanBeIncludedInInventoryCostAndCorrectionReasonIsAudited(): void
    {
        $draft=$this->draft([],['vat_in_cost'=>true]); $confirmed=$this->service->confirm($draft['id'],$draft['row_version'],'vat-in-cost-test',true,$this->actor); self::assertSame(3630,$confirmed['inventory_bani']);
        $input=$confirmed; $input['correction_reason']='Corectare conform documentului furnizorului';
        $corrected=$this->service->correct($confirmed['id'],$confirmed['row_version'],$input,'correction-reason-key',true,$this->actor);
        $event=array_values(array_filter($corrected['audit'],static fn(array $row):bool=>$row['action']==='corrected'))[0]; self::assertSame($input['correction_reason'],$event['context']['reason']);
        self::assertArrayNotHasKey('audit',$event['before']); self::assertArrayNotHasKey('audit',$event['after']);
    }

    public function testCorrectionRequiresReasonAndKeepsEveryLedgerUnchangedOnFailure(): void
    {
        $document=$this->confirmed(); $before=$this->pdo->query('SELECT * FROM nir_fifo_layers')->fetchAll();
        try { $this->service->correct($document['id'],$document['row_version'],$document,'correction-no-reason',true,$this->actor); self::fail('Missing audit reason accepted'); }
        catch(NirValidationException $error) { self::assertArrayHasKey('correction_reason',$error->errors); }
        self::assertSame($before,$this->pdo->query('SELECT * FROM nir_fifo_layers')->fetchAll()); self::assertSame(30000,$this->balance());
        self::assertSame($document['row_version'],$this->service->document($document['id'],$this->actor)['row_version']);
    }

    public function testSupplierReturnRejectsUnrepresentableConvertedQuantityWithValidation(): void
    {
        $document=$this->confirmed(['conversion_factor'=>'0.0001']);
        try { $this->service->reverse($document['id'],$document['row_version'],['attachments'=>$this->returnEvidence(),'reason'=>'Retur parțial','invoice_number'=>'PRECISION-RETURN','invoice_date'=>gmdate('Y-m-d'),'quantities'=>[$document['lines'][0]['id']=>'0.0001']],'return-precision-test',$this->actor); self::fail('Invalid precision accepted'); }
        catch(NirValidationException $error) { self::assertArrayHasKey('quantities.'.$document['lines'][0]['id'],$error->errors); }
        self::assertSame(3,$this->balance()); self::assertSame(1,$this->countRows('nir_documents'));
    }

    public function testDocumentNumberUsesLocalYearAndAnnualSequenceDespiteUtcStorage(): void
    {
        $first=$this->draft([],['nir_at'=>'2026-12-31T23:30']);
        $first=$this->service->confirm($first['id'],$first['row_version'],'year-local-first',true,$this->actor);
        $second=$this->draft([],['invoice_number'=>'NEXT-YEAR','nir_at'=>'2027-01-01T00:30']);
        $second=$this->service->confirm($second['id'],$second['row_version'],'year-local-second',true,$this->actor);
        self::assertSame('2026-12-31T22:30:00Z',$second['nir_at']); self::assertSame('LM-NIR-2026-1',$first['number']); self::assertSame('LM-NIR-2027-1',$second['number']);
    }

    public function testCustomerReceiptReconcilesPendingInvoiceBeforeReportingAvailableStock(): void
    {
        $document=$this->confirmed(); $order=$this->order(1); (new AccountingStockService($this->pdo))->recordInvoice($order,'ORIGINAL-INVOICE',$this->actor['id']);
        $item=$this->pdo->query('SELECT * FROM order_items WHERE order_id='.$order)->fetch(); $return=$this->makeReturn($order,$item['id']);
        $line=$document['lines'][0]; $target=array_intersect_key($line,array_flip(['scope_type','scope_id','product_id','variant_id','sku']));
        $this->service->consume($document['warehouse_id'],$target,30000,'invoice_line',913,'waiting-for-customer-return',$this->actor);
        self::assertSame(-10000,$this->balance()); self::assertSame(1,$this->countRows('nir_pending_consumptions'));
        $received=$this->service->receiveReturn($return,['physically_received'=>true,'invoice_number'=>'RETURN-PENDING','invoice_date'=>gmdate('Y-m-d'),'warehouse_id'=>$document['warehouse_id'],'location_id'=>$document['location_id']],'customer-reconciles-pending',$this->actor);
        self::assertSame(1000,$received['inventory_bani']); self::assertSame(0,$this->balance()); self::assertSame(0,$this->countRows('nir_pending_consumptions'));
        self::assertSame(10000,(int)$this->pdo->query('SELECT SUM(quantity_units) FROM nir_fifo_consumptions WHERE layer_id IN (SELECT id FROM nir_fifo_layers WHERE nir_id='.$received['id'].')')->fetchColumn());
        self::assertTrue($this->service->fifo([],$this->actor)['reconciliation'][0]['balanced']);
    }

    public function testPhysicalReturnRejectsInactiveReceiptLocationWithoutStockMutation(): void
    {
        $document=$this->confirmed(); $order=$this->order(1); (new AccountingStockService($this->pdo))->recordInvoice($order,'INVOICE-INACTIVE-LOCATION',$this->actor['id']);
        $item=$this->pdo->query('SELECT * FROM order_items WHERE order_id='.$order)->fetch(); $return=$this->makeReturn($order,$item['id']);
        $this->pdo->exec('UPDATE nir_locations SET is_active=0 WHERE id='.$document['location_id']);
        try { $this->service->receiveReturn($return,['physically_received'=>true,'invoice_number'=>'RETURN-INACTIVE','invoice_date'=>gmdate('Y-m-d'),'warehouse_id'=>$document['warehouse_id'],'location_id'=>$document['location_id']],'customer-inactive-location',$this->actor); self::fail('Inactive location accepted'); }
        catch(NirValidationException $error) { self::assertArrayHasKey('location_id',$error->errors); }
        self::assertSame(20000,$this->balance()); self::assertSame(1,$this->countRows('nir_documents')); self::assertSame(0,(int)$this->pdo->query('SELECT SUM(returned_units) FROM nir_fifo_consumptions')->fetchColumn());
    }

    public function testAllConfirmationPreconditionsAreRecheckedAfterDraftAndRolledBack(): void
    {
        foreach(['supplier','warehouse','location'] as $kind) {
            $document=$this->draft([],['invoice_number'=>'INACTIVE-'.$kind]);
            [$table,$field,$id,$inactive,$active]=match($kind) {
                'supplier'=>['suppliers','status',$document['supplier_id'],"'inactive'","'active'"],
                'warehouse'=>['company_warehouses','is_active',$document['warehouse_id'],'0','1'],
                default=>['nir_locations','is_active',$document['location_id'],'0','1'],
            };
            $this->pdo->exec('UPDATE '.$table.' SET '.$field.'='.$inactive.' WHERE id='.$id);
            try { $this->service->confirm($document['id'],$document['row_version'],'inactive-'.$kind,true,$this->actor); self::fail('Inactive '.$kind.' accepted'); }
            catch(NirValidationException $error) { self::assertArrayHasKey($kind.'_id',$error->errors); }
            $this->pdo->exec('UPDATE '.$table.' SET '.$field.'='.$active.' WHERE id='.$id);
        }
        self::assertSame(0,$this->countRows('nir_fifo_layers')); self::assertSame(0,$this->countRows('nir_stock_movements')); self::assertSame(0,$this->countRows('nir_sequences'));
    }

    public function testQuantityDifferencesNeedReasonAndAcceptedPlusRejectedCannotExceedReceived(): void
    {
        $document=$this->draft(['received_quantity'=>'2','accepted_quantity'=>'1','rejected_quantity'=>'2']);
        try { $this->service->confirm($document['id'],$document['row_version'],'quantity-mismatch-001',true,$this->actor); self::fail('Invalid receipt accepted'); }
        catch(NirValidationException $error) { self::assertArrayHasKey('lines.0.rejected_quantity',$error->errors); self::assertArrayHasKey('lines.0.difference_reason',$error->errors); }
        $document['lines'][0]['rejected_quantity']='1'; $document['lines'][0]['difference_reason']='other';
        $document=$this->service->save($document['id'],$document,$this->actor);
        try { $this->service->confirm($document['id'],$document['row_version'],'quantity-mismatch-002',true,$this->actor); self::fail('Missing explanation accepted'); }
        catch(NirValidationException $error) { self::assertArrayHasKey('lines.0.difference_notes',$error->errors); }
        $document['lines'][0]['difference_notes']='O bucată lipsește și una este deteriorată';
        $document=$this->service->save($document['id'],$document,$this->actor); $document=$this->service->confirm($document['id'],$document['row_version'],'quantity-mismatch-003',true,$this->actor);
        self::assertSame(10000,$this->balance()); self::assertSame(1000,$document['net_bani']);
    }

    public function testServerIgnoresClientTotalsAndHistoricalSnapshotsSurviveCatalogChanges(): void
    {
        $document=$this->draft(['net_bani'=>1,'inventory_bani'=>1,'unit_cost_ron'=>'0.01'],['net_bani'=>1,'inventory_bani'=>1]);
        self::assertSame(3000,$document['net_bani']); self::assertSame(3000,$document['inventory_bani']); self::assertSame('10.000000',$document['lines'][0]['unit_cost_ron']);
        $document=$this->service->confirm($document['id'],$document['row_version'],'snapshot-test-001',true,$this->actor);
        $this->pdo->exec("UPDATE suppliers SET company_name='Denumire nouă' WHERE id=".$document['supplier_id']);
        $this->pdo->exec("UPDATE products SET name='Produs redenumit' WHERE id=".$this->variant['product_id']);
        $this->pdo->exec("UPDATE company_warehouses SET name='Gestiune redenumită' WHERE id=".$document['warehouse_id']);
        $historical=$this->service->document($document['id'],$this->actor);
        self::assertSame($document['supplier'],$historical['supplier']); self::assertSame($document['warehouse'],$historical['warehouse']); self::assertSame($document['lines'][0]['product_name'],$historical['lines'][0]['product_name']);
    }

    public function testFifoUsesReceiptDateThenLotIdAndAllocatesExactCostsAcrossLots(): void
    {
        $newer=$this->draft(['invoice_quantity'=>'1','received_quantity'=>'1','accepted_quantity'=>'1','unit_price'=>'20'],['received_at'=>'2026-11-10T10:00','invoice_number'=>'LOT-NEW']);
        $newer=$this->service->confirm($newer['id'],$newer['row_version'],'fifo-newer-lot-key',true,$this->actor);
        $older=$this->draft(['invoice_quantity'=>'1','received_quantity'=>'1','accepted_quantity'=>'1','unit_price'=>'10'],['received_at'=>'2026-11-09T10:00','invoice_number'=>'LOT-OLD']);
        $older=$this->service->confirm($older['id'],$older['row_version'],'fifo-older-lot-key',true,$this->actor);
        $target=array_intersect_key($older['lines'][0],array_flip(['scope_type','scope_id','product_id','variant_id','sku']));
        $this->service->consume($older['warehouse_id'],$target,15000,'invoice_line',999,'fifo-chronology-consumption',$this->actor);
        $consumed=$this->pdo->query('SELECT c.*,l.nir_id FROM nir_fifo_consumptions c JOIN nir_fifo_layers l ON l.id=c.layer_id ORDER BY c.id')->fetchAll();
        self::assertSame([$older['id'],$newer['id']],array_column($consumed,'nir_id')); self::assertSame([10000,5000],array_column($consumed,'quantity_units')); self::assertSame([1000,1000],array_column($consumed,'value_bani')); self::assertSame(5000,$this->balance());
        self::assertSame('20.000000',$this->pdo->query('SELECT unit_cost_ron FROM nir_stock_balances')->fetchColumn());
    }

    public function testOpeningBalanceRejectsMissingActiveLocationAndZeroAcquisitionCost(): void
    {
        $input=['warehouse_id'=>$this->base['warehouse_id'],'product_id'=>$this->variant['product_id'],'variant_id'=>$this->variant['id'],'quantity'=>'1','unit_cost_ron'=>'0','reason'=>'Sold verificat','date'=>gmdate('Y-m-d')];
        try { $this->service->opening($input,'opening-zero-cost-test',$this->actor); self::fail('Zero opening cost accepted'); }
        catch(NirValidationException) { self::assertSame(0,$this->countRows('nir_documents')); }
        $input['unit_cost_ron']='10'; $this->pdo->exec('UPDATE nir_locations SET is_active=0');
        try { $this->service->opening($input,'opening-no-location-test',$this->actor); self::fail('Opening accepted without active location'); }
        catch(NirValidationException $error) { self::assertArrayHasKey('location_id',$error->errors); }
        self::assertSame(0,$this->countRows('nir_documents')); self::assertSame(0,$this->balance());
    }

    public function testConsumptionReplayRejectsChangedQuantityAndScopesRemainIsolated(): void
    {
        $document=$this->confirmed(); $target=array_intersect_key($document['lines'][0],array_flip(['scope_type','scope_id','product_id','variant_id','sku']));
        $this->service->consume($document['warehouse_id'],$target,10000,'invoice_line',1011,'consume-conflicting-payload',$this->actor);
        try { $this->service->consume($document['warehouse_id'],$target,20000,'invoice_line',1011,'consume-conflicting-payload',$this->actor); self::fail('Changed consumption replay accepted'); }
        catch(NirConflictException) { self::assertSame(20000,$this->balance()); }
        $companies=new CompanyAdminService(new CompanyRepository($this->pdo),new CompanyStampStorage($this->root)); $warehouse=$companies->saveWarehouse($this->company,['name'=>'Gestiune separată'],null);
        $this->service->consume($warehouse,$target,10000,'invoice_line',1012,'consume-other-warehouse',$this->actor);
        self::assertSame(10000,$this->balance()); self::assertSame(20000,(int)$this->pdo->query('SELECT quantity_units FROM nir_stock_balances WHERE warehouse_id='.$document['warehouse_id'])->fetchColumn()); self::assertSame(1,$this->countRows('nir_pending_consumptions')); self::assertSame($warehouse,(int)$this->pdo->query('SELECT warehouse_id FROM nir_pending_consumptions')->fetchColumn());
    }

    public function testPartialReturnsFromSeveralOriginalCostsPreserveEveryLastBan(): void
    {
        $this->confirmed(['invoice_quantity'=>'1','received_quantity'=>'1','accepted_quantity'=>'1','unit_price'=>'0.333333','vat_percent'=>'0']);
        $later=$this->draft(['invoice_quantity'=>'2','received_quantity'=>'2','accepted_quantity'=>'2','unit_price'=>'0.333333','vat_percent'=>'0'],['invoice_number'=>'RETURN-MULTIPLE-LOTS']);
        $later=$this->service->confirm($later['id'],$later['row_version'],'return-multiple-lots-receipt',true,$this->actor);
        $order=$this->order(3); (new AccountingStockService($this->pdo))->recordInvoice($order,'MULTILOT-INVOICE',$this->actor['id']);
        $item=$this->pdo->query('SELECT * FROM order_items WHERE order_id='.$order)->fetch(); self::assertSame(0,$this->balance());
        $sum=0;
        for($index=1;$index<=3;$index++) {
            $return=$this->makeReturn($order,$item['id']); $received=$this->service->receiveReturn($return,['physically_received'=>true,'invoice_number'=>'MULTILOT-RETURN-'.$index,'invoice_date'=>gmdate('Y-m-d'),'warehouse_id'=>$later['warehouse_id'],'location_id'=>$later['location_id']],'multilot-return-receipt-'.$index,$this->actor); $sum+=(int)$received['inventory_bani'];
        }
        self::assertSame(100,$sum); self::assertSame(30000,$this->balance());
        self::assertSame(100,(int)$this->pdo->query('SELECT SUM(returned_value_bani) FROM nir_fifo_consumptions')->fetchColumn());
        self::assertSame(100,(int)$this->pdo->query('SELECT SUM(remaining_value_bani) FROM nir_fifo_layers')->fetchColumn());
        self::assertTrue($this->service->fifo([],$this->actor)['reconciliation'][0]['balanced']);
    }

    public function testPhysicalReturnNeedsReceiptAndTraceableOriginalCost(): void
    {
        $order=$this->order(1); $item=$this->pdo->query('SELECT * FROM order_items WHERE order_id='.$order)->fetch(); $return=$this->makeReturn($order,$item['id']);
        $input=['invoice_number'=>'UNTRACED-RETURN','invoice_date'=>gmdate('Y-m-d'),'warehouse_id'=>$this->base['warehouse_id'],'location_id'=>$this->base['location_id']];
        try { $this->service->receiveReturn($return,$input,'return-physical-required',$this->actor); self::fail('Physical receipt not required'); }
        catch(NirValidationException $error) { self::assertArrayHasKey('physically_received',$error->errors); }
        $input['physically_received']=true;
        try { $this->service->receiveReturn($return,$input,'return-no-costs-allowed',$this->actor); self::fail('Original cost invented'); }
        catch(NirValidationException $error) { self::assertArrayHasKey('return_id',$error->errors); }
        self::assertSame(0,$this->countRows('nir_documents')); self::assertSame(0,$this->countRows('nir_fifo_layers')); self::assertSame(0,$this->balance());
    }

    public function testUntrackedSupplierReturnStaysDocumentaryAndCannotBeReturnedTwice(): void
    {
        $document=$this->confirmed(['tracked'=>false]); $line=$document['lines'][0];
        $reverse=$this->service->reverse($document['id'],$document['row_version'],['attachments'=>$this->returnEvidence(),'reason'=>'Retur linie documentară','invoice_number'=>'UNTRACKED-RETURN','invoice_date'=>gmdate('Y-m-d'),'quantities'=>[$line['id']=>'3']],'untracked-full-return',$this->actor);
        self::assertSame(-3630,$reverse['total_bani']); self::assertSame(0,$reverse['inventory_bani']); self::assertSame(0,$this->countRows('nir_stock_movements')); self::assertSame(0,$this->countRows('nir_fifo_layers'));
        $original=$this->service->document($document['id'],$this->actor); self::assertSame('full',$original['reversal_status']); self::assertFalse($original['can_reverse']); self::assertFalse($original['can_correct']);
    }

    public function testExplicitReferenceReassignmentIsAuditedAndNeverRewritesReceiptSnapshots(): void
    {
        $document=$this->confirmed(['save_reference'=>true]); $reference=$this->pdo->query('SELECT * FROM nir_supplier_references')->fetch();
        $other=$this->pdo->query('SELECT id,product_id FROM product_variants WHERE product_id<>'.(int)$this->variant['product_id'].' LIMIT 1')->fetch();
        $input=['product_id'=>$other['product_id'],'variant_id'=>$other['id']];
        try { $this->service->reassignReference($reference['id'],$input,$this->actor); self::fail('Reason missing'); }
        catch(NirValidationException $error) { self::assertArrayHasKey('reason',$error->errors); }
        $input['reason']='Cod reutilizat explicit de furnizor'; $updated=$this->service->reassignReference($reference['id'],$input,$this->actor);
        self::assertSame($other['product_id'],$updated['product_id']); self::assertSame($other['product_id'],$this->service->matchLine($this->supplier,['supplier_code'=>'SUP-001'],$this->actor)['product_id']);
        self::assertNull($updated['last_price']); self::assertNull($updated['last_currency']); self::assertNull($updated['last_received_at']);
        self::assertSame($this->variant['product_id'],$this->service->document($document['id'],$this->actor)['lines'][0]['product_id']);
        self::assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM nir_audit WHERE action='reference_reassigned'")->fetchColumn()); self::assertSame(30000,$this->balance());
    }

    public function testMatchingPriorityUsesSupplierCodeBeforeEanAndRequiresExplicitInternalSku(): void
    {
        $this->draft(['save_reference'=>true,'supplier_ean'=>'0123456789']);
        $other=$this->pdo->query('SELECT id,product_id,sku FROM product_variants WHERE product_id<>'.(int)$this->variant['product_id'].' LIMIT 1')->fetch();
        $this->draft(['product_id'=>$other['product_id'],'variant_id'=>$other['id'],'supplier_code'=>'OTHER-CODE','supplier_name'=>'Denumire alternativă','supplier_ean'=>'0123456789','save_reference'=>true],['invoice_number'=>'REFERENCE-OTHER']);
        $result=$this->service->matchLine($this->supplier,['supplier_code'=>'SUP-001','supplier_ean'=>'0123456789'],$this->actor); self::assertTrue($result['matched']); self::assertSame($this->variant['product_id'],$result['product_id']); self::assertSame('supplier_code',$result['source']);
        $ambiguous=$this->service->matchLine($this->supplier,['supplier_ean'=>'0123456789'],$this->actor); self::assertFalse($ambiguous['matched']); self::assertTrue($ambiguous['ambiguous']);
        $internal=$this->service->matchLine($this->supplier,['internal_sku'=>$other['sku']],$this->actor); self::assertTrue($internal['matched']); self::assertSame('internal_sku',$internal['source']);
    }

    public function testRegistryRejectsMalformedDateAndReversedPeriodRatherThanIgnoringFilters(): void
    {
        $this->draft();
        foreach([['from'=>'not-a-date'],['to'=>'2026-02-30'],['from'=>'2026-11-13','to'=>'2026-11-12']] as $filters) {
            try { $this->service->registry($filters,$this->actor); self::fail('Invalid period accepted'); }
            catch(NirValidationException $error) { self::assertNotEmpty($error->errors); }
        }
    }

    public function testSaveAndConfirmIsAtomicAndRetryDoesNotDoubleStock(): void
    {
        $draft=$this->draft(); $input=$draft; $input['idempotency_key']='save-confirm-atomic';
        $input['invoice_number']='DIRECT-001'; $input['accept_warnings']='1';
        $result=$this->service->saveAndConfirm(null,$input,$this->actor);
        self::assertSame('confirmed',$result['status']); self::assertSame('LM-NIR-'.date('Y').'-1',$result['number']);
        $retry=$this->service->saveAndConfirm(null,$input,$this->actor);
        self::assertSame($result['id'],$retry['id']); self::assertSame(30000,$this->balance());
        self::assertSame(1,$this->countRows('nir_fifo_layers'));
        $input['invoice_number']='DIRECT-002'; $input['idempotency_key']='save-confirm-invalid';
        $input['lines'][0]['accepted_quantity']='4';
        try { $this->service->saveAndConfirm(null,$input,$this->actor); self::fail('Invalid receipt committed'); } catch(NirValidationException) {}
        self::assertSame(2,$this->countRows('nir_documents')); self::assertSame(30000,$this->balance());
        self::assertSame(1,$this->countRows('nir_fifo_layers'));
    }

    public function testSaveConfirmWarningsRollbackEditsUntilAcknowledged(): void
    {
        $draft=$this->draft(['supplier_code'=>'']); $input=$draft;
        $input['notes']='Modificare ce trebuie păstrată doar la succes'; $input['idempotency_key']='warning-save-confirm';
        try { $this->service->saveAndConfirm($draft['id'],$input,$this->actor); self::fail('Unacknowledged warning accepted'); }
        catch(NirValidationException $error) { self::assertNotEmpty($error->warnings); }
        $unchanged=$this->service->document($draft['id'],$this->actor);
        self::assertSame($draft['row_version'],$unchanged['row_version']); self::assertSame($draft['notes'],$unchanged['notes']);
        self::assertSame(0,$this->balance());
        $input['accept_warnings']='1'; $confirmed=$this->service->saveAndConfirm($draft['id'],$input,$this->actor);
        self::assertSame('confirmed',$confirmed['status']); self::assertSame($input['notes'],$confirmed['notes']);
        self::assertSame(30000,$this->balance());
    }

    public function testIncompleteDraftStatusUsesConfirmationRulesAndClearsWhenFixed(): void
    {
        $draft=$this->service->save(null,$this->base,$this->actor);
        self::assertTrue($draft['draft_incomplete']); self::assertSame('Draft #1',$draft['display_number']);
        self::assertContains('supplier_id',$draft['draft_issues']);
        self::assertTrue($this->service->registry([],$this->actor)['items'][0]['draft_incomplete']);
        $complete=$this->draft(['supplier_code'=>'']);
        self::assertFalse($complete['draft_incomplete'],'Optional supplier code only warns; it is not incomplete.');
        $complete['lines'][0]['accepted_quantity']='2';
        $incomplete=$this->service->save($complete['id'],$complete,$this->actor);
        self::assertTrue($incomplete['draft_incomplete']); self::assertContains('lines.0.difference_reason',$incomplete['draft_issues']);
        $incomplete['lines'][0]['difference_reason']='shortage';
        $fixed=$this->service->save($incomplete['id'],$incomplete,$this->actor);
        self::assertFalse($fixed['draft_incomplete']); self::assertSame(0,$this->balance());
    }

    public function testProductSearchExactIdentifiersNamesAndScopedSupplierCodes(): void
    {
        $id=(int)$this->variant['product_id']; $variant=(int)$this->variant['id'];
        $this->pdo->prepare('UPDATE products SET name=:name WHERE id=:id')->execute(['name'=>'Cardigan „Norișor”','id'=>$id]);
        $this->pdo->prepare('UPDATE product_variants SET ean=:ean WHERE id=:id')->execute(['ean'=>'0012345678901','id'=>$variant]);
        $this->draft(['supplier_code'=>'FURN-EXACT-91','save_reference'=>'1']);
        foreach ([$this->variant['sku'],'0012345678901','FURN-EXACT-91'] as $query) {
            $found=$this->service->lookupProducts($query,$this->supplier,$this->actor);
            self::assertNotEmpty($found,$query); self::assertCount(1,$found,$query);
            self::assertSame($variant,(int)$found[0]['variant_id']); self::assertSame('exact',$found[0]['search_match']);
        }
        $found=$this->service->lookupProducts('cardigan norisor',$this->supplier,$this->actor);
        self::assertNotEmpty($found); self::assertSame([$id],array_values(array_unique(array_map(static fn(array $row):int=>(int)$row['product_id'],$found))));
        self::assertSame('exact',$found[0]['search_match']);
        self::assertSame([],$this->service->lookupProducts('FURN-EXACT-91',0,$this->actor));
        self::assertSame([],$this->service->lookupProducts('0012345678902',$this->supplier,$this->actor));
        $semantic=$this->service->lookupProducts('pulover norisor',$this->supplier,$this->actor);
        self::assertNotEmpty($semantic); self::assertSame($id,(int)$semantic[0]['product_id']);
        self::assertSame([],$this->service->lookupProducts('cardigan extraterestru',$this->supplier,$this->actor));
    }

    public function testDraftAndConfirmedCountersAreIndependentAndDoNotReuseDeletedDrafts(): void
    {
        $year=(int)date('Y');
        $this->pdo->prepare('INSERT INTO nir_sequences(year,next_number) VALUES (:year,101)')->execute(['year'=>$year]);
        $first=$this->draft(); self::assertSame('Draft #1',$first['display_number']);
        $direct=$first; $direct['idempotency_key']='independent-direct'; $direct['accept_warnings']='1'; $direct['invoice_number']='DIRECT-101';
        $confirmed=$this->service->saveAndConfirm(null,$direct,$this->actor);
        self::assertSame('LM-NIR-'.$year.'-101',$confirmed['number']);
        $second=$this->draft([],['invoice_number'=>'DRAFT-SECOND']); self::assertSame('Draft #2',$second['display_number']);
        self::assertSame('Draft #1',$this->service->document($first['id'],$this->actor)['display_number']);
        $this->service->delete($second['id'],$second['row_version'],$this->actor);
        self::assertSame('Draft #2',$this->draft([],['invoice_number'=>'DRAFT-THIRD'])['display_number']);
        $confirmedFirst=$this->service->confirm($first['id'],$first['row_version'],'confirm-first-draft',true,$this->actor);
        self::assertSame('LM-NIR-'.$year.'-102',$confirmedFirst['number']);
    }

    public function testSupplierReturnRechecksConsumedStockEvenWhenAnotherReceiptHasEnoughUnits(): void
    {
        $original=$this->confirmed(); $line=$original['lines'][0];
        $second=$this->draft(['invoice_quantity'=>'5','received_quantity'=>'5','accepted_quantity'=>'5'],['invoice_number'=>'SECOND-LOT']);
        $this->service->confirm($second['id'],$second['row_version'],'confirm-second-return-lot',true,$this->actor);
        // The form was opened before a sale consumed two units from the original receipt.
        $target=['scope_type'=>$line['scope_type'],'scope_id'=>$line['scope_id'],'product_id'=>$line['product_id'],'variant_id'=>$line['variant_id'],'sku'=>$line['sku']];
        $this->service->consume($original['warehouse_id'],$target,20000,'invoice_line',9999,'consume-after-return-open',$this->actor);
        self::assertSame(60000,$this->balance());
        $before=[]; foreach(['nir_documents','nir_fifo_layers','nir_stock_balances','nir_stock_movements','nir_attachments','nir_idempotency','nir_sequences'] as $table) { $before[$table]=$this->pdo->query('SELECT * FROM '.$table)->fetchAll(); }
        $input=['attachments'=>$this->returnEvidence(),'reason'=>'Retur după vânzare','invoice_number'=>'CREDIT-AFTER-SALE','invoice_date'=>gmdate('Y-m-d'),'quantities'=>[$line['id']=>'2']];
        try { $this->service->reverse($original['id'],$original['row_version'],$input,'return-consumed-units',$this->actor); self::fail('Consumed stock from the original receipt was returned'); }
        catch(NirValidationException $e) { self::assertArrayHasKey('accept_alternative_stock',$e->errors); }
        foreach($before as $table=>$rows) { self::assertSame($rows,$this->pdo->query('SELECT * FROM '.$table)->fetchAll(),$table.' changed after rejection'); }
        $current=$this->service->document($original['id'],$this->actor); self::assertSame(10000,$current['lines'][0]['available_units']);
        $input['quantities'][$line['id']]='1';
        $returned=$this->service->reverse($original['id'],$original['row_version'],$input,'return-only-available-unit',$this->actor);
        self::assertSame('confirmed',$returned['status']); self::assertSame(50000,$this->balance());
        self::assertTrue($this->service->document($original['id'],$this->actor)['can_reverse']);
        self::assertTrue($this->service->fifo([],$this->actor)['reconciliation'][0]['balanced']);
    }

    public function testTwoPlusOneIdenticalUnitsRemainSeparateForSupplierReturns(): void
    {
        $first=$this->confirmed(['invoice_quantity'=>'2','received_quantity'=>'2','accepted_quantity'=>'2']);
        $draft=$this->draft(['invoice_quantity'=>'1','received_quantity'=>'1','accepted_quantity'=>'1'],['invoice_number'=>'SAME-PRICE-NEXT-RECEIPT']);
        $second=$this->service->confirm($draft['id'],$draft['row_version'],'confirm-next-identical-unit',true,$this->actor);
        $line=$first['lines'][0];
        $target=['scope_type'=>$line['scope_type'],'scope_id'=>$line['scope_id'],'product_id'=>$line['product_id'],'variant_id'=>$line['variant_id'],'sku'=>$line['sku']];
        $this->service->consume($first['warehouse_id'],$target,10000,'invoice_line',9901,'sell-first-of-two',$this->actor);
        self::assertSame(10000,$this->service->document($first['id'],$this->actor)['lines'][0]['available_units']);
        self::assertSame(10000,$this->service->document($second['id'],$this->actor)['lines'][0]['available_units']);
        $this->service->consume($first['warehouse_id'],$target,10000,'invoice_line',9902,'sell-second-of-two',$this->actor);
        self::assertSame(0,$this->service->document($first['id'],$this->actor)['lines'][0]['available_units']);
        self::assertSame(10000,$this->service->document($second['id'],$this->actor)['lines'][0]['available_units']);
        $input=['attachments'=>$this->returnEvidence(),'reason'=>'Retur după vânzarea primului lot','invoice_number'=>'CREDIT-SAME-PRICE','invoice_date'=>gmdate('Y-m-d'),'quantities'=>[$line['id']=>'1']];
        try { $this->service->reverse($first['id'],$first['row_version'],$input,'reject-sold-identical-unit',$this->actor); self::fail('The second receipt replaced stock already consumed from the first'); }
        catch(NirValidationException $e) { self::assertNotEmpty($e->errors); }
        self::assertSame(10000,$this->balance());
        $input['quantities']=[$second['lines'][0]['id']=>'1'];
        $returned=$this->service->reverse($second['id'],$second['row_version'],$input,'return-unsold-identical-unit',$this->actor);
        self::assertSame('confirmed',$returned['status']);
        self::assertSame(0,$this->balance());
        self::assertTrue($this->service->fifo([],$this->actor)['reconciliation'][0]['balanced']);
    }

    public function testAlternativeReturnReducesActualLotAndCannotUseAnotherPriceOrSupplier(): void
    {
        $first=$this->confirmed(['invoice_quantity'=>'1','received_quantity'=>'1','accepted_quantity'=>'1']);
        $secondDraft=$this->draft(['invoice_quantity'=>'2','received_quantity'=>'2','accepted_quantity'=>'2'],['invoice_number'=>'TWO-COMPATIBLE']);
        $second=$this->service->confirm($secondDraft['id'],$secondDraft['row_version'],'confirm-two-compatible',true,$this->actor);
        $third=$this->draft(['unit_price'=>'20'],['invoice_number'=>'OTHER-PRICE']);
        $this->service->confirm($third['id'],$third['row_version'],'confirm-other-price',true,$this->actor);
        $otherSupplier=(new SupplierAdminService($this->pdo))->save(['alias'=>'Alt furnizor','company_name'=>'Alt furnizor SRL']);
        $fourth=$this->draft([],['invoice_number'=>'OTHER-SUPPLIER','supplier_id'=>$otherSupplier]);
        $this->service->confirm($fourth['id'],$fourth['row_version'],'confirm-other-supplier',true,$this->actor);
        $line=$first['lines'][0];
        $target=['scope_type'=>$line['scope_type'],'scope_id'=>$line['scope_id'],'product_id'=>$line['product_id'],'variant_id'=>$line['variant_id'],'sku'=>$line['sku']];
        $this->service->consume($first['warehouse_id'],$target,10000,'invoice_line',9921,'sell-original-single-unit',$this->actor);
        $sourceBefore=$this->pdo->query('SELECT * FROM nir_documents WHERE id='.$second['id'])->fetch();
        $sourceLinesBefore=$this->pdo->query('SELECT * FROM nir_lines WHERE nir_id='.$second['id'])->fetchAll();
        $salesBefore=$this->pdo->query("SELECT * FROM nir_fifo_consumptions WHERE source_type='invoice_line'")->fetchAll();
        $input=['attachments'=>$this->returnEvidence(),'reason'=>'Retur produs identic','invoice_number'=>'CREDIT-COMPATIBLE','invoice_date'=>gmdate('Y-m-d'),'quantities'=>[$line['id']=>'1'],'accept_alternative_stock'=>'1'];
        $returned=$this->service->reverse($first['id'],$first['row_version'],$input,'return-alternative-unit',$this->actor);
        self::assertSame($returned['id'],$this->service->reverse($first['id'],$first['row_version'],$input,'return-alternative-unit',$this->actor)['id']);
        $current=$this->service->document($second['id'],$this->actor);
        self::assertSame(10000,$current['lines'][0]['available_units']); self::assertSame(0,$current['lines'][0]['compatible_units']); self::assertSame(10000,$current['lines'][0]['returnable_units']);
        self::assertSame($sourceBefore,$this->pdo->query('SELECT * FROM nir_documents WHERE id='.$second['id'])->fetch());
        self::assertSame($sourceLinesBefore,$this->pdo->query('SELECT * FROM nir_lines WHERE nir_id='.$second['id'])->fetchAll());
        self::assertSame($salesBefore,$this->pdo->query("SELECT * FROM nir_fifo_consumptions WHERE source_type='invoice_line'")->fetchAll());
        $allocations=$this->pdo->query("SELECT c.*,l.nir_id FROM nir_fifo_consumptions c JOIN nir_fifo_layers l ON l.id=c.layer_id WHERE c.source_type='supplier_return_line'")->fetchAll();
        self::assertCount(1,$allocations);self::assertSame($second['id'],(int)$allocations[0]['nir_id']); self::assertSame(10000,(int)$allocations[0]['quantity_units']);
        $input['quantities']=[$second['lines'][0]['id']=>'2'];$input['attachments']=$this->returnEvidence();$input['invoice_number']='CREDIT-SECOND';
        $before=$this->balance();
        try { $this->service->reverse($second['id'],$second['row_version'],$input,'reject-other-price-stock',$this->actor);self::fail('Incompatible stock completed the return'); }
        catch(NirValidationException $e) { self::assertStringContainsString('mai ai doar 1 buc',$e->errors['quantities.'.$second['lines'][0]['id']]); }
        self::assertSame($before,$this->balance());
        $input['quantities'][$second['lines'][0]['id']]='1';
        $this->service->reverse($second['id'],$second['row_version'],$input,'return-second-remaining',$this->actor);
        self::assertSame(60000,$this->balance());self::assertFalse($this->service->document($second['id'],$this->actor)['can_reverse']);
        self::assertTrue($this->service->fifo([],$this->actor)['reconciliation'][0]['balanced']);
    }

    private function returnEvidence(): array
    {
        $identity=bin2hex(random_bytes(10));
        return [['stored_name'=>'qa-return-'.$identity.'.pdf','original_name'=>'Dovadă retur.pdf','mime_type'=>'application/pdf','extension'=>'pdf','size_bytes'=>12,'sha256'=>hash('sha256',$identity)]];
    }

    public function testDeletedCustomerReturnRestoresOriginalSaleAndCanBeReceivedAgain(): void
    {
        $receipt=$this->confirmed(); $order=$this->order(1);
        (new AccountingStockService($this->pdo))->recordInvoice($order,'SALE-RETURN-DELETE',$this->actor['id']);
        $item=$this->pdo->query('SELECT * FROM order_items WHERE order_id='.$order)->fetch(); $return=$this->makeReturn($order,$item['id']);
        $input=['physically_received'=>true,'invoice_number'=>'RETURN-DELETE','invoice_date'=>gmdate('Y-m-d'),'warehouse_id'=>$receipt['warehouse_id'],'location_id'=>$receipt['location_id']];
        $received=$this->service->receiveReturn($return,$input,'customer-return-delete-001',$this->actor);
        self::assertSame(30000,$this->balance());
        $this->service->delete($received['id'],$received['row_version'],$this->actor);
        self::assertSame(20000,$this->balance());
        self::assertSame(0,(int)$this->pdo->query('SELECT SUM(returned_units) FROM nir_fifo_consumptions')->fetchColumn());
        self::assertSame(1,$this->countRows('order_returns')); self::assertSame(1,$this->countRows('orders'));
        $this->service->receiveReturn($return,$input,'customer-return-delete-002',$this->actor);
        self::assertSame(30000,$this->balance());
    }

    public function testLastNumberIsNumericAndDeletionRaceIsRejectedAtomically(): void
    {
        $this->pdo->prepare('INSERT INTO nir_sequences(year,next_number) VALUES (:year,9)')->execute(['year'=>(int)date('Y')]);
        $nine=$this->confirmed(); self::assertStringEndsWith('-9',$nine['number']);
        $draft=$this->draft([],['invoice_number'=>'TEN']);
        self::assertTrue($this->service->document($nine['id'],$this->actor)['can_delete']);
        $ten=$this->service->confirm($draft['id'],$draft['row_version'],'confirm-number-ten',true,$this->actor);
        self::assertStringEndsWith('-10',$ten['number']);
        $state=[]; foreach(['nir_documents','nir_stock_balances','nir_stock_movements','nir_fifo_layers','nir_audit','nir_sequences'] as $table) { $state[$table]=$this->pdo->query('SELECT * FROM '.$table)->fetchAll(); }
        try { $this->service->delete($nine['id'],$nine['row_version'],$this->actor); self::fail('A formerly last receipt was deleted after another confirmation'); }
        catch(NirConflictException) { foreach($state as $table=>$rows) { self::assertSame($rows,$this->pdo->query('SELECT * FROM '.$table)->fetchAll()); } }
        $this->service->delete($ten['id'],$ten['row_version'],$this->actor);
        self::assertSame(10,(int)$this->pdo->query('SELECT next_number FROM nir_sequences')->fetchColumn());
    }

    public function testNegativeStockMigrationCarriesExistingPendingSalesOnce(): void
    {
        $draft=$this->draft(); $line=$draft['lines'][0];
        $target=array_intersect_key($line,array_flip(['scope_type','scope_id','product_id','variant_id','sku']));
        $this->service->consume($draft['warehouse_id'],$target,20000,'invoice_line',19008,'existing-pending-invoice',$this->actor);
        // Model the old schema's balance: pending stock exits had not been debited.
        $this->pdo->exec('UPDATE nir_stock_balances SET quantity_units=0');
        $this->pdo->exec("DELETE FROM nir_stock_movements WHERE type='INVOICE_OUT_PENDING'");
        $this->pdo->exec((string)file_get_contents($this->root.'/database/migrations/sqlite/049_nir_negative_accounting_stock.sql'));
        self::assertSame(-20000,$this->balance());
        self::assertSame(-20000,(int)$this->pdo->query('SELECT SUM(quantity_units) FROM nir_stock_movements')->fetchColumn());
        $receipt=$this->service->confirm($draft['id'],$draft['row_version'],'confirm-existing-pending',true,$this->actor);
        self::assertSame(10000,$this->balance());
        $this->service->delete($receipt['id'],$receipt['row_version'],$this->actor);
        self::assertSame(-20000,$this->balance());
    }

    private function draft(array $line=[],array $header=[]): array
    {
        $values=array_replace($this->base,['supplier_id'=>$this->supplier,'invoice_number'=>'INV-001'], $header);
        $values['lines']=[array_replace($this->base['lines'][0],['product_id'=>$this->variant['product_id'],'variant_id'=>$this->variant['id'],'supplier_name'=>'Produs facturat','supplier_code'=>'SUP-001','invoice_quantity'=>'3','received_quantity'=>'3','accepted_quantity'=>'3','unit_price'=>'10'], $line)];
        return $this->service->save(null,$values,$this->actor);
    }
    private function confirmed(array $line=[]): array { $draft=$this->draft($line); return $this->service->confirm($draft['id'],$draft['row_version'],'confirm-fixture-001',true,$this->actor); }
    private function countRows(string $table): int { return (int)$this->pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(); }
    private function balance(): int { return (int)$this->pdo->query('SELECT COALESCE(SUM(quantity_units),0) FROM nir_stock_balances')->fetchColumn(); }
    private function order(int $quantity): int
    {
        $_SESSION['cart_token']=bin2hex(random_bytes(32)); $cart=new CartService($this->pdo); $cart->add($this->variant['id'],$quantity);
        $shipping=(int)$this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        $order=(new CheckoutService($this->pdo,$cart,Config::load($this->root)))->place(['email'=>'nir.customer@example.test','first_name'=>'Ana','last_name'=>'Pop','phone'=>'0700000000','address'=>'Strada Test 1','city'=>'București — Sector 1','county'=>'București','postal_code'=>'010101','shipping_method_id'=>(string)$shipping,'payment_method'=>'cod','idempotency_key'=>'nir-customer-order','terms'=>'1']);
        return (int)$order['id'];
    }
    private function makeReturn(int $orderId,int $itemId): int
    {
        $this->pdo->prepare("INSERT INTO order_returns(order_id,return_type,reason,notes,restock,refund_mode,refund_bani,actor_user_id,created_at) VALUES (:order,'partial','Mărime nepotrivită','',0,'none',0,:actor,:now)")->execute(['order'=>$orderId,'actor'=>$this->actor['id'],'now'=>gmdate('c')]); $id=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO order_return_items(return_id,order_item_id,quantity,restocked_quantity,line_refund_bani) VALUES (:return,:item,1,0,0)')->execute(['return'=>$id,'item'=>$itemId]); return $id;
    }
}
