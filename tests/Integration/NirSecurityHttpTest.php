<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Companies\CompanyAdminService;
use App\Modules\Companies\CompanyRepository;
use App\Modules\Companies\CompanyStampStorage;
use App\Modules\Inventory\NirDocumentStorage;
use App\Modules\Inventory\NirService;
use App\Modules\Suppliers\SupplierAdminService;
use DateTimeImmutable;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;
use Tests\DatabaseTestCase;

/** Exercises browser boundaries independently of the stock service tests. */
final class NirSecurityHttpTest extends DatabaseTestCase
{
    private int $adminId;
    private int $operatorId;
    private int $supplierId;
    /** @var list<string> */
    private array $temporaryFiles = [];
    /** @var list<string> */
    private array $uploadedHashes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $statement = $this->pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES (:email,'disabled','Paula','NIR',:role,'active',:now,:now)");
        foreach (['administrator', 'operator'] as $role) {
            $statement->execute(['email' => 'nir-security-' . $role . '@example.test', 'role' => $role, 'now' => gmdate('c')]);
            if ($role === 'administrator') {
                $this->adminId = (int) $this->pdo->lastInsertId();
            } else {
                $this->operatorId = (int) $this->pdo->lastInsertId();
            }
        }
        $_SESSION['user_id'] = $this->adminId;
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        $companies = new CompanyAdminService(new CompanyRepository($this->pdo), new CompanyStampStorage($this->root));
        $companyId = $companies->saveCompany(['legal_name' => 'Firma HTTP QA', 'registered_address' => 'Strada QA 1', 'physical_address' => 'Depozit QA'], null, null);
        $companies->saveWarehouse($companyId, ['name' => 'Gestiune HTTP QA'], null);
        $this->supplierId = (new SupplierAdminService($this->pdo))->save(['alias' => 'Furnizor siguranță', 'company_name' => 'Furnizor siguranță SRL']);
    }

    protected function tearDown(): void
    {
        $storage = new NirDocumentStorage($this->root);
        foreach ($this->pdo->query('SELECT stored_name FROM nir_attachments')->fetchAll() as $attachment) {
            $storage->remove((string) $attachment['stored_name']);
        }
        foreach ($this->uploadedHashes as $hash) {
            foreach ($this->filesWithHash($hash) as $file) {
                $storage->remove(basename($file));
            }
        }
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function testActionFormsUseThePersistedDocumentAndCurrentVersion(): void
    {
        [$document, $values] = $this->draft('CONTEXT-QA');
        $values['row_version'] = $document['row_version'];
        $values['notes'] = 'O modificare ulterioară';
        $document = $this->service()->save((int) $document['id'], $values, $this->actor());

        foreach (['confirma', 'documente', 'sterge'] as $action) {
            $response = $this->request('GET', '/admin/nir-uri/' . $document['id'] . '/' . $action . '/modal/');
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $xpath = $this->html((string) $response->getBody());
            self::assertSame((string) $document['row_version'], $xpath->evaluate('string(//input[@name="row_version"]/@value)'), $action);
            if ($action === 'confirma') {
                self::assertStringContainsString((string) $document['invoice_number'], (string) $response->getBody(), $action);
                self::assertSame('/admin/nir-uri/' . $document['id'] . '/confirma/', $xpath->evaluate('string(//form/@action)'));
            } else {
                self::assertStringContainsString((string) $document['display_number'], (string) $response->getBody(), $action);
            }
        }
    }

    public function testStaleSaveConfirmAndDeleteCannotOverwriteAnotherEdit(): void
    {
        [$document, $values] = $this->draft('STALE-QA');
        $staleVersion = $document['row_version'];
        $values['row_version'] = $staleVersion;
        $values['notes'] = 'Valoarea salvată de alt administrator';
        $latest = $this->service()->save((int) $document['id'], $values, $this->actor());
        $values['notes'] = 'Nu trebuie să suprascrie';

        foreach (['editare', 'confirma', 'sterge'] as $action) {
            $body = $action === 'editare' ? $values : ['row_version' => $staleVersion, 'idempotency_key' => 'stale-http-' . $action, 'accept_warnings' => '1'];
            $response = $this->request('POST', '/admin/nir-uri/' . $document['id'] . '/' . $action . '/', $body, true);
            self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        }
        $current = $this->service()->document((int) $document['id'], $this->actor());
        self::assertSame($latest['row_version'], $current['row_version']);
        self::assertSame('Valoarea salvată de alt administrator', $current['notes']);
        self::assertSame('draft', $current['status']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM nir_fifo_layers')->fetchColumn());
    }

    public function testNoJavascriptExportHonoursMonthAndLocalMidnightBoundary(): void
    {
        [$current] = $this->draft('CURRENT-MONTH-ONLY');
        [$previous] = $this->draft('PREVIOUS-MONTH-ONLY');
        $month = new DateTimeImmutable('first day of this month 00:15:00', new DateTimeZone('Europe/Bucharest'));
        $update = $this->pdo->prepare('UPDATE nir_documents SET nir_at=:date WHERE id=:id');
        $update->execute(['id' => $current['id'], 'date' => $month->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')]);
        $update->execute(['id' => $previous['id'], 'date' => $month->modify('-15 days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')]);

        $response = $this->request('GET', '/admin/nir-uri/export/?format=xlsx&period=month');
        $text = $this->workbookText($response);
        self::assertStringContainsString('CURRENT-MONTH-ONLY', $text);
        self::assertStringNotContainsString('PREVIOUS-MONTH-ONLY', $text);

        $response = $this->request('GET', '/admin/nir-uri/export/?format=xlsx&period=previous_month');
        $text = $this->workbookText($response);
        self::assertStringContainsString('PREVIOUS-MONTH-ONLY', $text);
        self::assertStringNotContainsString('CURRENT-MONTH-ONLY', $text);
    }

    public function testExportRejectsInvalidAndReversedCustomDates(): void
    {
        foreach (['from=2026-02-30&to=2026-03-01', 'from=2026-03-02&to=2026-03-01', 'from=&to='] as $dates) {
            $response = $this->request('GET', '/admin/nir-uri/export/?format=xlsx&period=custom&' . $dates, [], true);
            self::assertSame(422, $response->getStatusCode(), 'Invalid custom export: ' . $dates);
            self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        }
    }

    public function testNoJavascriptCorrectionExposesWarningsAndPreservesInput(): void
    {
        [$document, $values] = $this->draft('CORRECTION-WARNING');
        $document = $this->service()->confirm((int) $document['id'], (int) $document['row_version'], 'confirm-correction-warning', true, $this->actor());
        $values['row_version'] = $document['row_version'];
        $values['idempotency_key'] = 'correction-warning-http';
        $values['correction_reason'] = 'Codul furnizorului este revizuit conform facturii.';
        $values['lines'][0]['supplier_code'] = '';
        $values['notes'] = 'Păstrează datele introduse';
        $response = $this->request('POST', '/admin/nir-uri/' . $document['id'] . '/corectie/', $values);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $html = (string) $response->getBody();
        $xpath = $this->html($html);
        self::assertSame(1, $xpath->query('//input[@name="accept_warnings"]')->length);
        self::assertSame(0, $xpath->query('//input[@name="accept_warnings"]/ancestor::*[@hidden]')->length);
        self::assertStringContainsString('codul furnizorului nu este completat', $html);
        self::assertStringContainsString('Păstrează datele introduse', $html);
        self::assertSame($document['row_version'], $this->service()->document((int) $document['id'], $this->actor())['row_version']);
        $values['accept_warnings'] = '1';
        $saved = $this->request('POST', '/admin/nir-uri/' . $document['id'] . '/corectie/', $values);
        self::assertSame(303, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertGreaterThan($document['row_version'], $this->service()->document((int) $document['id'], $this->actor())['row_version']);
    }

    public function testNoJavascriptReversePreservesQuantitiesAndOffersWarningAcknowledgment(): void
    {
        [$document] = $this->draft('PARTIAL-RETURNS');
        $document = $this->service()->confirm((int) $document['id'], (int) $document['row_version'], 'confirm-partial-returns', true, $this->actor());
        $lineId = $document['lines'][0]['id'];
        $input = ['attachments' => [['stored_name'=>'qa-return.pdf','original_name'=>'Dovadă.pdf','sha256'=>hash('sha256','qa-return')]], 'invoice_number' => 'RV-REPEATED', 'invoice_date' => $document['invoice_date'], 'reason' => 'Ambalaj deteriorat', 'quantities' => [$lineId => '0.5']];
        $this->service()->reverse((int) $document['id'], (int) $document['row_version'], $input, 'first-partial-return', $this->actor());
        $document = $this->service()->document((int) $document['id'], $this->actor());
        $input['quantities'][$lineId] = '0.25';
        $input['row_version'] = $document['row_version'];
        $input['idempotency_key'] = 'second-partial-return';
        $response = $this->request('POST', '/admin/nir-uri/' . $document['id'] . '/storno/', $input, false, ['attachments'=>[$this->returnUpload()]]);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $xpath = $this->html((string) $response->getBody());
        self::assertSame('0.25', $xpath->evaluate('string(//input[@name="quantities[' . $lineId . ']"]/@value)'));
        self::assertSame(1, $xpath->query('//input[@name="accept_warnings"]')->length);
        self::assertSame(0, $xpath->query('//input[@name="accept_warnings"]/ancestor::*[@hidden]')->length);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM nir_documents')->fetchColumn());
        $input['accept_warnings'] = '1';
        self::assertSame(303, $this->request('POST', '/admin/nir-uri/' . $document['id'] . '/storno/', $input, false, ['attachments'=>[$this->returnUpload()]])->getStatusCode());
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM nir_documents')->fetchColumn());
    }

    public function testFormDifferenceChoicesCanBeSavedAndConfirmed(): void
    {
        $form = $this->request('GET', '/admin/nir-uri/nou/');
        self::assertSame(200, $form->getStatusCode());
        $xpath = $this->html((string) $form->getBody());
        $options = $xpath->query('//select[@name="lines[0][difference_reason]"]/option');
        $value = null;
        foreach ($options as $option) {
            if (trim($option->textContent) === 'Lipsă') {
                $value = $option->getAttribute('value');
            }
        }
        self::assertSame('shortage', $value);
        [$document, $input] = $this->draft('SHORTAGE-FROM-UI');
        $input['row_version'] = $document['row_version'];
        $input['lines'][0]['received_quantity'] = '1';
        $input['lines'][0]['accepted_quantity'] = '1';
        $input['lines'][0]['difference_reason'] = $value;
        $save = $this->request('POST', '/admin/nir-uri/' . $document['id'] . '/editare/', $input, true);
        self::assertSame(200, $save->getStatusCode(), (string) $save->getBody());
        $document = $this->service()->document((int) $document['id'], $this->actor());
        $confirm = $this->request('POST', '/admin/nir-uri/' . $document['id'] . '/confirma/', ['row_version' => $document['row_version'], 'idempotency_key' => 'confirm-shortage-http', 'accept_warnings' => '1'], true);
        self::assertSame(200, $confirm->getStatusCode(), (string) $confirm->getBody());
    }

    public function testAttachmentMetadataCannotBeForgedAndDownloadsRemainPrivate(): void
    {
        [$document, $values] = $this->draft('PRIVATE-FILE');
        $values['row_version'] = $document['row_version'];
        $values['attachments'] = [['stored_name' => str_repeat('a', 40) . '.pdf', 'original_name' => 'forged.pdf', 'sha256' => str_repeat('a', 64)]];
        $save = $this->request('POST', '/admin/nir-uri/' . $document['id'] . '/editare/', $values, true);
        self::assertSame(200, $save->getStatusCode(), (string) $save->getBody());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM nir_attachments')->fetchColumn());
        $document = $this->service()->document((int) $document['id'], $this->actor());

        $path = $this->temporary('.png');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4s8AAAAASUVORK5CYII='));
        $file = new UploadedFile($path, 'dovada.png', 'image/png', filesize($path));
        $upload = $this->request('POST', '/admin/nir-uri/' . $document['id'] . '/documente/', ['row_version' => $document['row_version']], true, ['attachments' => [$file]]);
        self::assertSame(200, $upload->getStatusCode(), (string) $upload->getBody());
        $attachment = $this->pdo->query('SELECT * FROM nir_attachments')->fetch();
        self::assertIsArray($attachment);
        $url = '/admin/nir-uri/' . $document['id'] . '/documente/' . $attachment['id'] . '/';
        $download = $this->request('GET', $url);
        self::assertSame(200, $download->getStatusCode());
        self::assertSame('nosniff', $download->getHeaderLine('X-Content-Type-Options'));
        self::assertStringContainsString('private', $download->getHeaderLine('Cache-Control'));
        self::assertSame(file_get_contents($path), (string) $download->getBody());
        [$other] = $this->draft('OTHER-DOCUMENT');
        self::assertSame(404, $this->request('GET', '/admin/nir-uri/' . $other['id'] . '/documente/' . $attachment['id'] . '/', [], true)->getStatusCode());
        $_SESSION['user_id'] = $this->operatorId;
        self::assertSame(403, $this->request('GET', $url, [], true)->getStatusCode());
        unset($_SESSION['user_id']);
        self::assertSame(303, $this->request('GET', $url)->getStatusCode());
    }

    public function testMultipartReturnRetryIsIdempotentAndRemovesUnusedUploadCopies(): void
    {
        [$document] = $this->draft('RETRY-WITH-FILE');
        $document = $this->service()->confirm((int) $document['id'], (int) $document['row_version'], 'confirm-retry-file', true, $this->actor());
        $input = ['row_version' => $document['row_version'], 'idempotency_key' => 'return-with-file-http', 'invoice_number' => 'RV-RETRY-FILE', 'invoice_date' => $document['invoice_date'], 'reason' => 'Produs deteriorat, conform fotografiei', 'quantities' => [$document['lines'][0]['id'] => '1']];
        $path = $this->temporary('.png');
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4s8AAAAASUVORK5CYII=') . 'nir-http-unique-' . bin2hex(random_bytes(12));
        file_put_contents($path, $bytes);
        $hash = hash('sha256', $bytes);
        $this->uploadedHashes[] = $hash;
        $returnId = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $file = new UploadedFile($path, 'dovada-retur.png', 'image/png', filesize($path));
            $response = $this->request('POST', '/admin/nir-uri/' . $document['id'] . '/storno/', $input, true, ['attachments' => [$file]]);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $returnId ??= $data['document']['id'];
            self::assertSame($returnId, $data['document']['id']);
        }
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM nir_documents')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM nir_attachments')->fetchColumn());
        self::assertCount(1, $this->filesWithHash($hash), 'A replay must discard the second temporary copy of the same upload.');
        $page = $this->request('GET', '/admin/nir-uri/' . $returnId . '/');
        self::assertSame(200, $page->getStatusCode());
        $body = (string) $page->getBody();
        foreach (['Retur confirmat', 'Produsele returnate', 'Documentele returului', 'Factura corectivă și returul', 'Totalul returului'] as $label) {
            self::assertStringContainsString($label, $body);
        }
        foreach (['Recepție confirmată', 'Produsele recepționate', 'Documentele recepției', 'Totalul recepției'] as $label) {
            self::assertStringNotContainsString($label, $body);
        }
        $xpath = $this->html($body);
        self::assertSame((string) $document['number'], $xpath->evaluate('string(//button[@data-nir-copy]/@data-nir-copy)'));
        self::assertSame('/admin/nir-uri/' . $document['id'] . '/', $xpath->evaluate('string(//a[@class="nir-original__open"]/@href)'));
        $originalDate = (new DateTimeImmutable($document['nir_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Bucharest'))->format('d.m.Y, H:i');
        self::assertStringContainsString($originalDate, $xpath->evaluate('string(//aside[@aria-label="Documentul original"])'));
    }

    public function testOriginalReceiptListsEachReturnWithDateCopyAndNavigation(): void
    {
        [$draft]=$this->draft('LINKED-RETURNS');
        $document=$this->service()->confirm((int)$draft['id'],(int)$draft['row_version'],'linked-returns-confirm',true,$this->actor());
        $returns=[];
        for($i=1;$i<=2;$i++) {
            $current=$this->service()->document((int)$document['id'],$this->actor());
            $input=['row_version'=>$current['row_version'],'idempotency_key'=>'linked-returns-'.$i,'invoice_number'=>'RV-LINK-'.$i,'invoice_date'=>$document['invoice_date'],'reason'=>'Retur în tranșe','quantities'=>[$document['lines'][0]['id']=>'1']];
            $response=$this->request('POST','/admin/nir-uri/'.$document['id'].'/storno/',$input,true,['attachments'=>[$this->returnUpload()]]);
            self::assertSame(200,$response->getStatusCode(),(string)$response->getBody());
            $returns[]=json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR)['document'];
        }
        $page=$this->request('GET','/admin/nir-uri/'.$document['id'].'/');
        self::assertSame(200,$page->getStatusCode());
        $xpath=$this->html((string)$page->getBody());
        $root='//section[@aria-label="Retururi către furnizor asociate"]';
        self::assertSame(2,$xpath->query($root.'//article')->length);
        foreach($returns as $i=>$return) {
            $card=$root.'//article['.($i+1).']';
            self::assertSame($return['number'],$xpath->evaluate('string('.$card.'//button/@data-nir-copy)'));
            self::assertSame('/admin/nir-uri/'.$return['id'].'/',$xpath->evaluate('string('.$card.'//a/@href)'));
            self::assertStringContainsString('RV-LINK-'.($i+1),$xpath->evaluate('string('.$card.')'));
            $date=(new DateTimeImmutable($return['nir_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Bucharest'))->format('d.m.Y, H:i');
            self::assertStringContainsString($date,$xpath->evaluate('string('.$card.')'));
            $target=$this->request('GET','/admin/nir-uri/'.$return['id'].'/');
            self::assertSame(200,$target->getStatusCode());
            self::assertStringContainsString('Documentul original',(string)$target->getBody());
        }
        $last=end($returns);
        $this->service()->delete((int)$last['id'],(int)$last['row_version'],$this->actor());
        $page=$this->request('GET','/admin/nir-uri/'.$document['id'].'/');
        self::assertSame(1,$this->html((string)$page->getBody())->query($root.'//article')->length);
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function draft(string $invoice): array
    {
        $values = $this->service()->defaults($this->actor())['values'];
        $variant = $this->pdo->query('SELECT v.id,v.product_id FROM product_variants v JOIN products p ON p.id=v.product_id WHERE p.track_in_conta=1 LIMIT 1')->fetch();
        $values['supplier_id'] = $this->supplierId;
        $values['invoice_number'] = $invoice;
        $values['lines'] = [['product_id' => $variant['product_id'], 'variant_id' => $variant['id'], 'supplier_name' => 'Produs recepționat', 'supplier_code' => 'SECURITY-01', 'invoice_quantity' => '2', 'received_quantity' => '2', 'accepted_quantity' => '2', 'rejected_quantity' => '0', 'unit_price' => '10', 'vat_percent' => '21', 'conversion_factor' => '1', 'purchase_unit' => 'buc', 'stock_unit' => 'buc', 'price_mode' => 'unit']];
        return [$this->service()->save(null, $values, $this->actor()), $values];
    }

    private function returnUpload(): UploadedFile
    {
        $path=$this->temporary('.png');
        $bytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4s8AAAAASUVORK5CYII=') . 'nir-return-' . bin2hex(random_bytes(12));
        file_put_contents($path,$bytes); $this->uploadedHashes[]=hash('sha256',$bytes);
        return new UploadedFile($path,'dovada-retur.png','image/png',strlen($bytes));
    }

    public function testSupplierReturnRequiresAnActualUploadedFileBeforeChangingStock(): void
    {
        [$draft]=$this->draft('MANDATORY-RETURN-DOCUMENT');
        $document=$this->service()->confirm((int)$draft['id'],(int)$draft['row_version'],'mandatory-document-confirm',true,$this->actor());
        $stock=$this->pdo->query('SELECT * FROM nir_stock_balances')->fetchAll();
        $layers=$this->pdo->query('SELECT * FROM nir_fifo_layers')->fetchAll();
        $input=['row_version'=>$document['row_version'],'idempotency_key'=>'mandatory-document-return','invoice_number'=>'RV-DOCUMENT','invoice_date'=>$document['invoice_date'],'reason'=>'Produs deteriorat','quantities'=>[$document['lines'][0]['id']=>'1']];
        foreach ([[],[['stored_name'=>'fake.pdf','sha256'=>str_repeat('a',64)]]] as $forged) {
            $response=$this->request('POST','/admin/nir-uri/'.$document['id'].'/storno/',$input+['attachments'=>$forged],true);
            self::assertSame(422,$response->getStatusCode());
            $result=json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('attachments',$result['errors']);
            self::assertSame($stock,$this->pdo->query('SELECT * FROM nir_stock_balances')->fetchAll());
            self::assertSame($layers,$this->pdo->query('SELECT * FROM nir_fifo_layers')->fetchAll());
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM nir_documents')->fetchColumn());
            self::assertSame($document['row_version'],$this->service()->document((int)$document['id'],$this->actor())['row_version']);
        }
        $page=$this->request('GET','/admin/nir-uri/'.$document['id'].'/storno/');
        self::assertStringContainsString('Vezi cât poți returna din fiecare produs.',(string)$page->getBody());
        self::assertStringContainsString('Din alte intrări compatibile',(string)$page->getBody());
        $xpath=$this->html((string)$page->getBody());
        self::assertSame(1,$xpath->query('//input[@type="file" and @required]')->length);
        self::assertGreaterThan(0,$xpath->query('//input[@role="switch" and @data-reverse-select]')->length);
        $saved=$this->request('POST','/admin/nir-uri/'.$document['id'].'/storno/',$input,true,['attachments'=>[$this->returnUpload()]]);
        self::assertSame(200,$saved->getStatusCode(),(string)$saved->getBody());
        self::assertSame(2,(int)$this->pdo->query('SELECT COUNT(*) FROM nir_documents')->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM nir_attachments')->fetchColumn());
    }

    private function service(): NirService { return new NirService($this->pdo); }
    /** @return array{id:int,role:string} */
    private function actor(): array { return ['id' => $this->adminId, 'role' => 'administrator']; }

    /** @param array<string,mixed> $body @param array<string,mixed> $uploads */
    private function request(string $method, string $path, array $body = [], bool $json = false, array $uploads = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1']);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        if ($method === 'POST') {
            $request = $request->withParsedBody(['_csrf' => $_SESSION['_csrf']] + $body);
        }
        if ($json) {
            $request = $request->withHeader('Accept', 'application/json');
        }
        if ($uploads !== []) {
            $request = $request->withUploadedFiles($uploads);
        }
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();
        return $response;
    }

    private function html(string $html): \DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new \DOMXPath($document);
    }

    private function workbookText(ResponseInterface $response): string
    {
        self::assertSame(200, $response->getStatusCode(), substr((string) $response->getBody(), 0, 800));
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->getHeaderLine('Content-Type'));
        $path = $this->temporary('.xlsx');
        file_put_contents($path, (string) $response->getBody());
        $book = IOFactory::load($path);
        $values = $book->getActiveSheet()->toArray(null, false, false);
        $book->disconnectWorksheets();
        return json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function temporary(string $extension): string
    {
        $path = sys_get_temp_dir() . '/nir-http-' . bin2hex(random_bytes(8)) . $extension;
        $this->temporaryFiles[] = $path;
        return $path;
    }

    /** @return list<string> */
    private function filesWithHash(string $hash): array
    {
        return array_values(array_filter(glob($this->root . '/storage/private/nir-documents/*') ?: [], static fn (string $path): bool => is_file($path) && hash_file('sha256', $path) === $hash));
    }
}
