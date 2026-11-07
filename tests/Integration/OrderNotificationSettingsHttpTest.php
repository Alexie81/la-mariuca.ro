<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Orders\OrderNotificationSettingsService;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class OrderNotificationSettingsHttpTest extends DatabaseTestCase
{
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $now = gmdate('c');
        $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES ('notifications.admin@example.test',:password,'Ana','Admin','administrator','active',:now,:now)")
            ->execute(['password' => password_hash('Settings-Admin-2026!', PASSWORD_DEFAULT), 'now' => $now]);
        $this->adminId = (int) $this->pdo->lastInsertId();
        $this->request('GET', '/admin');
    }

    public function testSettingsEndpointsRequireAdminAndCsrf(): void
    {
        foreach (['/admin/comenzi/notificari/', '/admin/comenzi/notificari/modal/'] as $path) {
            self::assertSame(303, $this->request('GET', $path)->getStatusCode());
        }
        self::assertSame(303, $this->request('POST', '/admin/comenzi/notificari/', ['_csrf' => $_SESSION['_csrf'], 'recipients' => ['outside@example.test']])->getStatusCode());
        $_SESSION['user_id'] = $this->adminId;
        self::assertSame(419, $this->request('POST', '/admin/comenzi/notificari/', ['recipients' => ['outside@example.test']])->getStatusCode());
        self::assertSame([], (new OrderNotificationSettingsService($this->pdo))->recipients());
    }

    public function testAjaxAddEditRemoveAndValidationPreserveSavedAddresses(): void
    {
        $_SESSION['user_id'] = $this->adminId;
        $settings = new OrderNotificationSettingsService($this->pdo);
        $first = $this->save([' office@example.test ', 'warehouse@example.test']);
        self::assertSame(200, $first->getStatusCode());
        $result = json_decode((string) $first->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($result['ok']);
        self::assertEqualsCanonicalizing(['office@example.test', 'warehouse@example.test'], $result['recipients']);
        self::assertSame('no-store', $first->getHeaderLine('Cache-Control'));

        $invalid = $this->save(['changed@example.test', 'not-an-email']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertFalse(json_decode((string) $invalid->getBody(), true, 512, JSON_THROW_ON_ERROR)['ok']);
        self::assertEqualsCanonicalizing(['office@example.test', 'warehouse@example.test'], $settings->recipients());
        self::assertSame(422, $this->save([['nested' => 'bad']])->getStatusCode());

        self::assertSame(200, $this->save(['changed@example.test'])->getStatusCode());
        self::assertSame(['changed@example.test'], $settings->recipients());
        self::assertSame(200, $this->save([])->getStatusCode());
        self::assertSame([], $settings->recipients());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
    }

    public function testNoJavascriptFormShowsErrorsAndSupportsSave(): void
    {
        $_SESSION['user_id'] = $this->adminId;
        foreach (['/admin/comenzi/notificari/', '/admin/comenzi/notificari/modal/'] as $path) {
            $response = $this->request('GET', $path);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('name="recipients[]"', (string) $response->getBody());
            self::assertStringContainsString('name="_csrf"', (string) $response->getBody());
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        }
        $invalid = $this->request('POST', '/admin/comenzi/notificari/', ['_csrf' => $_SESSION['_csrf'], 'recipients' => ['wrong-address']]);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('wrong-address', (string) $invalid->getBody());
        $saved = $this->request('POST', '/admin/comenzi/notificari/', ['_csrf' => $_SESSION['_csrf'], 'recipients' => ['office@example.test']]);
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame('/admin/comenzi/', $saved->getHeaderLine('Location'));
    }

    /** @param array<mixed> $recipients */
    private function save(array $recipients): ResponseInterface
    {
        return $this->request('POST', '/admin/comenzi/notificari/', ['_csrf' => $_SESSION['_csrf'], 'recipients' => $recipients], true);
    }

    /** @param array<string,mixed> $data */
    private function request(string $method, string $path, array $data = [], bool $ajax = false): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.96']);
        if ($data !== []) { $request = $request->withParsedBody($data); }
        if ($ajax) { $request = $request->withHeader('X-Requested-With', 'XMLHttpRequest')->withHeader('Accept', 'application/json'); }
        $app = AppFactory::create($this->root);
        try {
            return $app->handle($request);
        } finally {
            // Release the route-container cycles so Windows can delete the isolated SQLite file.
            unset($app);
            gc_collect_cycles();
        }
    }
}
