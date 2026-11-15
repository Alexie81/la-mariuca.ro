<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class SpvPreviewTest extends DatabaseTestCase
{
    public function testSpvWorkspaceRequiresAdministratorAndSavesOnlyValidatedSettings(): void
    {
        self::assertSame(303, $this->request()->getStatusCode());
        $this->pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES ('spv@example.test','unusable','Paula','Test','customer','active',:now,:now)")
            ->execute(['now' => gmdate('c')]);
        $_SESSION['user_id'] = (int) $this->pdo->lastInsertId();
        self::assertSame(303, $this->request()->getStatusCode());

        $this->pdo->prepare("UPDATE users SET role='administrator'")->execute();
        $page = $this->request();
        self::assertSame(200, $page->getStatusCode());
        self::assertSame('no-store', $page->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('Mediu de test', (string) $page->getBody());
        self::assertStringNotContainsString('Date demonstrative.', (string) $page->getBody());
        self::assertStringContainsString('ANAF — Agenția Națională de Administrare Fiscală', (string) $page->getBody());

        $revision = (int) $this->pdo->query('SELECT revision FROM spv_settings WHERE id=1')->fetchColumn();
        $saved = $this->request('POST', 'salveaza', [
            '_csrf' => $_SESSION['_csrf'], 'revision' => (string) $revision, 'environment' => 'test',
            'sale_mode' => 'scheduled', 'sale_days' => '2', 'return_mode' => 'manual', 'return_days' => '1',
            'deadline_days' => '5', 'alerts' => '1', 'holidays' => '2026-12-25',
        ]);
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame($revision + 1, (int) $this->pdo->query('SELECT revision FROM spv_settings WHERE id=1')->fetchColumn());
        self::assertSame('scheduled', $this->pdo->query('SELECT sale_mode FROM spv_settings WHERE id=1')->fetchColumn());

        $this->pdo->prepare("UPDATE users SET role='operator'")->execute();
        self::assertSame(403, $this->request()->getStatusCode());
        self::assertSame(403, $this->request('POST', 'salveaza', [
            '_csrf' => $_SESSION['_csrf'], 'revision' => (string) ($revision + 1), 'environment' => 'test',
        ])->getStatusCode());
        self::assertSame($revision + 1, (int) $this->pdo->query('SELECT revision FROM spv_settings WHERE id=1')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM invoice_automation_intents')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
    }

    /** @param array<string,mixed>|null $body */
    private function request(string $method = 'GET', string $operation = '', ?array $body = null): ResponseInterface
    {
        $suffix = $operation === '' ? '' : $operation . '/';
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/admin/spv-efactura/' . $suffix, ['REMOTE_ADDR' => '127.0.0.73']);
        if ($method === 'POST') {
            $request = $request->withParsedBody($body ?? ['_csrf' => $_SESSION['_csrf'] ?? '']);
        }
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();
        return $response;
    }
}
