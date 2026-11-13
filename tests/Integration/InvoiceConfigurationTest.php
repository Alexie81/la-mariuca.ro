<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Invoicing\InvoiceConfigurationException;
use App\Modules\Invoicing\InvoiceConfigurationRepository;
use App\Modules\Invoicing\InvoiceConfigurationService;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class InvoiceConfigurationTest extends DatabaseTestCase
{
    private InvoiceConfigurationService $service;
    private const BASE = '/admin/configurator-factura/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InvoiceConfigurationService(new InvoiceConfigurationRepository($this->pdo));
    }

    protected function tearDown(): void
    {
        unset($this->service);
        parent::tearDown();
    }

    public function testAlwaysOneActiveSeriesAndExplicitReplacementOnDeletion(): void
    {
        self::assertSame('LM', $this->service->state()['settings']['active_code']);
        $second = $this->create('WEB');
        self::assertSame(1, (int) $this->service->state()['settings']['active_series_id']);
        $this->service->changeUse($second, $this->input(), 0);
        self::assertSame($second, (int) $this->service->state()['settings']['active_series_id']);
        self::assertCount(1, array_filter($this->service->state()['series'], static fn(array $row): bool => (bool) $row['in_use']));
        $this->assertRejected(fn() => $this->service->changeUse($second, $this->input(), 0));
        $this->assertRejected(fn() => $this->service->delete($second, $this->input(), 0));
        $this->assertRejected(fn() => $this->service->delete($second, $this->input(['replacement_id'=>$second]), 0));
        $this->service->delete($second, $this->input(['replacement_id'=>1]), 0);
        self::assertSame(1, (int) $this->service->state()['settings']['active_series_id']);
        self::assertCount(1, $this->service->state()['series']);
        $this->assertRejected(fn() => $this->service->delete(1, $this->input(), 0));
        $this->assertRejected(fn() => $this->service->changeUse(1, $this->input(['replacement_id'=>$second]), 0));
    }

    public function testInvoiceSnapshotSurvivesSwitchRenameDeleteAndRepeatedRegeneration(): void
    {
        $this->service->saveDefaults($this->input(['due_days'=>14,'default_notes'=>"Achitare în cont.\nVă mulțumim!"]), 0);
        $old = $this->service->freezeForInvoice('invoice:original', 1, new DateTimeImmutable('2026-11-13T23:30:00Z'));
        self::assertSame('LM', $old['series_code']);
        self::assertSame('2026-11-28', $old['due_date'], 'Due date follows the Romanian invoice date, including UTC date rollover.');
        $second = $this->create('NOI', true);
        $this->service->saveDefaults($this->input(['due_days'=>0,'default_notes'=>'Note noi']), 0);
        $this->service->saveSeries($this->input(['code'=>'ARHIVA','label'=>'Redenumită','in_use'=>0]), 1, 0);
        $this->service->delete(1, $this->input(), 0);
        self::assertSame($old, $this->service->snapshotForInvoice('invoice:original'));
        self::assertSame($old, $this->service->freezeForInvoice('invoice:original', 999, new DateTimeImmutable('2027-01-01')));
        $new = $this->service->freezeForInvoice('invoice:new', 1, new DateTimeImmutable('2026-11-14T10:00:00Z'));
        self::assertSame('NOI', $new['series_code']);
        self::assertSame('Note noi', $new['default_notes']);
        self::assertSame('2026-11-14', $new['due_date']);
        self::assertSame($second, $new['series_id']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM invoice_configuration_snapshots')->fetchColumn());
    }

    public function testEditingActiveSeriesOnlyChangesFutureSnapshots(): void
    {
        $before = $this->service->freezeForInvoice('before', 5, new DateTimeImmutable('2026-11-13'));
        $this->service->saveSeries($this->input(['code'=>' lm-2027 ','label'=>'Noua serie']), 1, 0);
        self::assertSame('LM-2027', $this->service->state()['settings']['active_code']);
        self::assertSame($before, $this->service->snapshotForInvoice('before'));
        self::assertSame('LM-2027', $this->service->freezeForInvoice('after', 1, new DateTimeImmutable('2026-11-13'))['series_code']);
    }

    public function testRecreatedCodeCannotReuseAnIssuedNumber(): void
    {
        $this->service->freezeForInvoice('old', 1, new DateTimeImmutable('2026-11-13'));
        $second = $this->create('ALT', true);
        $this->service->delete(1, $this->input(), 0);
        $replacement = $this->create('LM', true);
        self::assertNotSame(1, $replacement);
        $this->assertRejected(fn() => $this->service->freezeForInvoice('collision', 1, new DateTimeImmutable('2026-11-14')));
        self::assertSame(2, $this->service->freezeForInvoice('next', 2, new DateTimeImmutable('2026-11-14'))['invoice_number']);
        self::assertSame('LM', $this->service->snapshotForInvoice('old')['series_code']);
        $this->service->delete($second, $this->input(), 0);
    }

    public function testStaleFormsCannotOverwriteNewerConfiguration(): void
    {
        $second = $this->create('ALT');
        $stale = $this->input();
        $this->service->changeUse($second, $stale, 0);
        foreach ([
            fn() => $this->service->saveDefaults($stale + ['due_days'=>30,'default_notes'=>'stale'], 0),
            fn() => $this->service->saveSeries($stale + ['code'=>'STALE'], null, 0),
            fn() => $this->service->delete($second, $stale + ['replacement_id'=>1], 0),
        ] as $write) { $this->assertRejected($write, 409); }
        self::assertSame('ALT', $this->service->state()['settings']['active_code']);
        self::assertSame(0, (int) $this->service->state()['settings']['due_days']);
        self::assertCount(2, $this->service->state()['series']);
    }

    public function testValidationAndRollbackLeaveStateUntouched(): void
    {
        $before = $this->service->state();
        foreach (['', '   ', 'LM', 'lm', '<script>', str_repeat('A',31), []] as $code) {
            $this->assertRejected(fn() => $this->service->saveSeries($this->input(['code'=>$code,'in_use'=>1]), null, 0));
        }
        foreach (['-1', '1.5', '1e2', '3651', [], ''] as $days) {
            $this->assertRejected(fn() => $this->service->saveDefaults($this->input(['due_days'=>$days]), 0));
        }
        $this->assertRejected(fn() => $this->service->saveDefaults($this->input(['due_days'=>1,'default_notes'=>str_repeat('a',3001)]), 0));
        self::assertSame($before, $this->service->state());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
        // Participates in the future invoice transaction; a failed issue must leave no snapshot.
        $this->pdo->beginTransaction();
        $this->service->freezeForInvoice('rolled-back', 1, new DateTimeImmutable('2026-11-13'));
        $this->pdo->rollBack();
        $this->assertRejected(fn() => $this->service->snapshotForInvoice('rolled-back'), 404);
    }

    public function testHttpCrudAndProgressiveEnhancement(): void
    {
        $this->login();
        $page = $this->request('GET', self::BASE);
        self::assertSame(200, $page->getStatusCode());
        $html = (string) $page->getBody();
        self::assertLessThan(strpos($html, '>Produse și clienți<'), strpos($html, '>Facturare<'));
        self::assertStringContainsString('data-inv-defaults', $html);
        $modal = $this->request('GET', self::BASE . 'serii/nou/', [], true);
        self::assertSame(200, $modal->getStatusCode());
        self::assertStringNotContainsString('<!doctype', (string) $modal->getBody());
        $save = $this->request('POST', self::BASE . 'serii/nou/', $this->input(['code'=>'WEB','label'=>'Magazin','in_use'=>'1','_csrf'=>$_SESSION['_csrf']]), true);
        self::assertSame(200, $save->getStatusCode());
        $payload = json_decode((string) $save->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);
        self::assertStringContainsString('WEB', $payload['html']);
        self::assertSame('no-store', $save->getHeaderLine('Cache-Control'));
        $settings = $this->request('POST', self::BASE . 'setari/', $this->input(['due_days'=>'7','default_notes'=>'<script>alert(1)</script>','_csrf'=>$_SESSION['_csrf']]));
        self::assertSame(303, $settings->getStatusCode());
        self::assertSame(self::BASE, $settings->getHeaderLine('Location'));
        $html = (string) $this->request('GET', self::BASE)->getBody();
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        $invalid = $this->request('POST', self::BASE . 'setari/', $this->input(['due_days'=>'-4','default_notes'=>'Nu pierde acest text','_csrf'=>$_SESSION['_csrf']]));
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('Nu pierde acest text', (string) $invalid->getBody());
        $id = $this->service->state()['settings']['active_series_id'];
        $remove = $this->request('POST', self::BASE . 'serii/' . $id . '/sterge/', $this->input(['replacement_id'=>1,'_csrf'=>$_SESSION['_csrf']]), true);
        self::assertSame(200, $remove->getStatusCode());
        self::assertSame('LM', $this->service->state()['settings']['active_code']);
    }

    public function testAdminAuthorizationAndCsrfProtectEveryMutation(): void
    {
        self::assertSame(303, $this->request('GET', self::BASE)->getStatusCode());
        $this->login();
        $state = $this->service->state();
        foreach (['setari/','serii/nou/','serii/1/editare/','serii/1/sterge/','serii/1/folosire/'] as $path) {
            self::assertSame(419, $this->request('POST', self::BASE . $path, $this->input(['code'=>'INVALID','due_days'=>99]), true)->getStatusCode());
        }
        self::assertSame($state, $this->service->state());
        self::assertSame(404, $this->request('GET', self::BASE . 'serii/999/editare/')->getStatusCode());
        $this->pdo->exec("UPDATE users SET role='customer'");
        $denied = $this->request('GET', self::BASE);
        self::assertSame(303, $denied->getStatusCode());
        self::assertSame('/admin', $denied->getHeaderLine('Location'));
        self::assertSame(303, $this->request('POST', self::BASE . 'setari/', $this->input(['due_days'=>2,'_csrf'=>$_SESSION['_csrf']]), true)->getStatusCode());
        self::assertSame($state, $this->service->state());
    }

    private function create(string $code, bool $use = false): int
    {
        return $this->service->saveSeries($this->input(['code'=>$code,'in_use'=>$use ? '1' : '0']), null, 0);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function input(array $data = []): array
    {
        return $data + ['revision'=>(string) $this->service->state()['settings']['revision']];
    }

    private function assertRejected(callable $action, int $status = 422): void
    {
        try { $action(); self::fail('Expected protected operation to be rejected.'); }
        catch (InvoiceConfigurationException $error) { self::assertSame($status, $error->status); }
    }

    private function login(): void
    {
        $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES ('invoice@example.test',:password,'Paula','Test','administrator','active',:now,:now,:now)")->execute(['password'=>password_hash('Invoice-test-2026!', PASSWORD_DEFAULT),'now'=>gmdate('c')]);
        $this->request('GET', '/admin');
        $response = $this->request('POST', '/admin', ['_csrf'=>$_SESSION['_csrf'],'email'=>'invoice@example.test','password'=>'Invoice-test-2026!']);
        self::assertSame(303, $response->getStatusCode());
    }

    /** @param array<string,mixed> $input */
    private function request(string $method, string $path, array $input = [], bool $ajax = false): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR'=>'127.0.0.71'])->withHeader('User-Agent','Invoice-configuration-tests');
        if ($input !== []) { $request = $request->withParsedBody($input); }
        if ($ajax) { $request = $request->withHeader('X-Requested-With', 'XMLHttpRequest'); }
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app); gc_collect_cycles();
        return $response;
    }
}
