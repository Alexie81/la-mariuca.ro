<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Invoicing\InvoiceAutomationRepository;
use App\Modules\Invoicing\InvoiceAutomationService;
use App\Modules\Invoicing\InvoiceConfigurationException;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class InvoiceAutomationTest extends DatabaseTestCase
{
    private InvoiceAutomationService $service;
    private const PATH = '/admin/automatizari/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InvoiceAutomationService(new InvoiceAutomationRepository($this->pdo));
    }

    protected function tearDown(): void
    {
        unset($this->service);
        parent::tearDown();
    }

    public function testSettingsPersistAndEmailRequiresIssuanceForBothMethods(): void
    {
        foreach (InvoiceAutomationService::FIELDS as $field) { self::assertFalse($this->service->settings()[$field]); }
        $this->save(['card_issue'=>1,'card_email'=>1,'cod_issue'=>1,'cod_email'=>1]);
        $reloaded = new InvoiceAutomationService(new InvoiceAutomationRepository($this->pdo));
        foreach (InvoiceAutomationService::FIELDS as $field) { self::assertTrue($reloaded->settings()[$field]); }
        $state = $this->save(['card_issue'=>0,'card_email'=>1,'cod_issue'=>0,'cod_email'=>1]);
        self::assertFalse($state['card_email']);
        self::assertFalse($state['cod_email']);
        $state = $this->save(['card_issue'=>1,'cod_issue'=>1]);
        self::assertFalse($state['card_email'], 'Re-enabling issuance does not silently enable email.');
        self::assertFalse($state['cod_email']);
    }

    public function testStaleWritesAreRejectedButRetriesAreIdempotent(): void
    {
        $revision = $this->service->settings()['revision'];
        $new = $this->save(['card_issue'=>1]);
        $this->reject(fn() => $this->service->save(['revision'=>$revision,'cod_issue'=>1],0),409);
        self::assertSame($new, $this->service->settings());
        self::assertSame($new, $this->service->save(['revision'=>$revision,'card_issue'=>1],0));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
    }

    public function testInvalidInputAndRollbackDoNotChangeSettings(): void
    {
        $before = $this->service->settings();
        foreach (['yes','on','2',[],new \stdClass()] as $invalid) {
            $this->reject(fn() => $this->save(['card_issue'=>$invalid]),422);
        }
        foreach (['',0,-1,[], '1.5'] as $revision) {
            $this->reject(fn() => $this->service->save(['revision'=>$revision,'card_issue'=>1],0),409);
        }
        self::assertSame($before, $this->service->settings());
        $this->pdo->beginTransaction();
        $this->save(['card_issue'=>1,'card_email'=>1]);
        $this->pdo->rollBack();
        self::assertSame($before, $this->service->settings());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
    }

    public function testFutureHookUsesPersistedPaymentAndOrderState(): void
    {
        $this->save(['card_issue'=>1,'card_email'=>1,'cod_issue'=>1]);
        foreach ([['stripe','pending','new'],['stripe','failed','confirmed'],['stripe','paid','cancelled'],['stripe','paid','manual_review'],['cod','pending','confirmed'],['cod','failed','new']] as [$method,$payment,$status]) {
            self::assertNull($this->service->prepareForOrder($this->order($method,$payment,$status)));
        }
        self::assertNull($this->service->prepareForOrder(99999));
        $card = $this->order('stripe','paid','confirmed');
        $cod = $this->order('cod','pending','new');
        $cardIntent = $this->service->prepareForOrder($card);
        $codIntent = $this->service->prepareForOrder($cod);
        self::assertSame(1, (int) $cardIntent['send_email']);
        self::assertSame(0, (int) $codIntent['send_email']);
        self::assertSame('order:'.$card.':invoice:issue', $cardIntent['issue_key']);
        self::assertNotSame($cardIntent['issue_key'], $cardIntent['email_key']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM invoice_configuration_snapshots')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
    }

    public function testIntentIsFrozenOnceAndDisabledRulesDoNothing(): void
    {
        $order = $this->order('stripe','paid','confirmed');
        self::assertNull($this->service->prepareForOrder($order));
        $this->save(['card_issue'=>1,'card_email'=>1]);
        $intent = $this->service->prepareForOrder($order);
        $this->save([]);
        self::assertSame($intent, $this->service->prepareForOrder($order));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM invoice_automation_intents')->fetchColumn());
        self::assertNull($this->service->prepareForOrder($this->order('stripe','paid','confirmed')));
        $this->save(['cod_issue'=>1]);
        $cod = $this->order('cod','pending','new');
        $this->pdo->beginTransaction();
        self::assertNotNull($this->service->prepareForOrder($cod));
        $this->pdo->rollBack();
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM invoice_automation_intents')->fetchColumn());
        self::assertNotNull($this->service->prepareForOrder($cod));
    }

    public function testHttpAutosaveConflictAndHtmlFallback(): void
    {
        $this->login();
        $page = $this->request('GET');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('data-invoice-automations', (string) $page->getBody());
        self::assertStringContainsString('Conectat la comenzi', (string) $page->getBody());
        self::assertSame('no-store', $page->getHeaderLine('Cache-Control'));
        $revision = $this->service->settings()['revision'];
        $response = $this->request('POST', ['revision'=>$revision,'_csrf'=>$_SESSION['_csrf'],'card_issue'=>'1','card_email'=>'1'],true);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->payload($response)['settings']['card_email']);
        $conflict = $this->request('POST', ['revision'=>$revision,'_csrf'=>$_SESSION['_csrf'],'cod_issue'=>'1'],true);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertFalse($this->payload($conflict)['settings']['cod_issue']);
        self::assertTrue($this->payload($this->request('GET',[],true))['settings']['card_email']);
        $fallback = $this->request('POST', ['revision'=>$this->service->settings()['revision'],'_csrf'=>$_SESSION['_csrf'],'cod_issue'=>'1']);
        self::assertSame(303, $fallback->getStatusCode());
        self::assertSame(self::PATH, $fallback->getHeaderLine('Location'));
        self::assertTrue($this->service->settings()['cod_issue']);
        self::assertFalse($this->service->settings()['card_issue']);
    }

    public function testEveryEndpointRequiresAdminAndMutationsRequireCsrf(): void
    {
        self::assertSame(303, $this->request('GET',[],true)->getStatusCode());
        $this->login();
        $before = $this->service->settings();
        self::assertSame(419, $this->request('POST',['revision'=>1,'card_issue'=>1],true)->getStatusCode());
        $this->pdo->exec("UPDATE users SET role='customer'");
        self::assertSame(303, $this->request('GET',[],true)->getStatusCode());
        self::assertSame(303, $this->request('POST',['revision'=>1,'card_issue'=>1,'_csrf'=>$_SESSION['_csrf']],true)->getStatusCode());
        self::assertSame($before, $this->service->settings());
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private function save(array $values): array
    {
        return $this->service->save($values + ['revision'=>$this->service->settings()['revision']],0);
    }

    private function order(string $method, string $payment, string $status): int
    {
        $key = bin2hex(random_bytes(16)); $now = gmdate('c');
        $this->pdo->prepare("INSERT INTO carts(token,created_at,updated_at,expires_at) VALUES (:key,:now,:now,:now)")->execute(['key'=>$key,'now'=>$now]);
        $cart = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO orders(public_token_hash,idempotency_key,cart_id,email,first_name,last_name,phone,shipping_address_json,billing_address_json,subtotal_bani,total_bani,status,payment_status,payment_method,terms_accepted_at,created_at,updated_at) VALUES (:key,:key,:cart,'invoice@example.test','Ana','Test','0700000000','{}','{}',1000,1000,:status,:payment,:method,:now,:now,:now)")
            ->execute(['key'=>$key,'cart'=>$cart,'status'=>$status,'payment'=>$payment,'method'=>$method,'now'=>$now]);
        return (int) $this->pdo->lastInsertId();
    }

    private function reject(callable $action, int $status): void
    {
        try { $action(); self::fail('Expected rejection.'); }
        catch (InvoiceConfigurationException $error) { self::assertSame($status,$error->status); }
    }

    private function login(): void
    {
        $this->pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES ('automations@example.test','unusable','Paula','Test','administrator','active',:now,:now)")->execute(['now'=>gmdate('c')]);
        $_SESSION['user_id'] = (int) $this->pdo->lastInsertId();
        $this->request('GET');
    }

    private function request(string $method, array $input = [], bool $ajax = false): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method,'http://localhost'.self::PATH,['REMOTE_ADDR'=>'127.0.0.71']);
        if ($input !== []) { $request = $request->withParsedBody($input); }
        if ($ajax) { $request = $request->withHeader('Accept','application/json'); }
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app); gc_collect_cycles();
        return $response;
    }

    private function payload(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(),true,512,JSON_THROW_ON_ERROR);
    }
}
