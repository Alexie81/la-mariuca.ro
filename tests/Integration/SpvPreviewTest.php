<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class SpvPreviewTest extends DatabaseTestCase
{
    public function testPreviewRequiresAnAdminRoleAndCannotSaveSettings(): void
    {
        self::assertSame(303, $this->request()->getStatusCode());
        $this->pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES ('spv@example.test','unusable','Paula','Test','customer','active',:now,:now)")
            ->execute(['now' => gmdate('c')]);
        $_SESSION['user_id'] = (int) $this->pdo->lastInsertId();
        self::assertSame(303, $this->request()->getStatusCode());

        foreach (['administrator', 'operator'] as $role) {
            $this->pdo->prepare('UPDATE users SET role=:role')->execute(['role' => $role]);
            $page = $this->request();
            self::assertSame(200, $page->getStatusCode());
            self::assertSame('no-store', $page->getHeaderLine('Cache-Control'));
            self::assertStringContainsString('Date demonstrative.', (string) $page->getBody());
            self::assertStringContainsString('ANAF — Agenția Națională de Administrare Fiscală', (string) $page->getBody());
            // Even a valid authenticated submission has no mutation route in this design-only stage.
            self::assertSame(405, $this->request('POST')->getStatusCode());
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM invoice_automation_intents')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
    }

    private function request(string $method = 'GET'): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/admin/spv-efactura/', ['REMOTE_ADDR' => '127.0.0.73']);
        if ($method === 'POST') {
            $request = $request->withParsedBody(['_csrf' => $_SESSION['_csrf'] ?? '', 'environment' => 'production']);
        }
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();
        return $response;
    }
}
