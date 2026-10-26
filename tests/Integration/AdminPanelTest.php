<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Shared\Security\AdminRememberMe;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class AdminPanelTest extends DatabaseTestCase
{
    private const PASSWORD = 'Admin-password-2026!';
    private const USER_AGENT = 'LaMariuca-AdminPanel-Test/1.0';

    protected function setUp(): void
    {
        parent::setUp();
        $now = gmdate('c');
        $statement = $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES ('admin.panel@example.test',:password,'Maria','Admin','administrator','active',:now,:now,:now)");
        $statement->execute(['password' => password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'now' => $now]);
        $customer = $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES ('client.panel@example.test',:password,'Ana','Client','customer','active',:now,:now,:now)");
        $customer->execute(['password' => password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'now' => $now]);
    }

    public function testAdminEntryRendersDedicatedResponsiveLogin(): void
    {
        $body = (string) $this->get('/admin')->getBody();

        self::assertStringContainsString('<body class="admin-login-body">', $body);
        self::assertStringContainsString('action="/admin"', $body);
        self::assertStringContainsString('name="remember"', $body);
        self::assertStringContainsString('Ține-mă minte', $body);
        self::assertStringContainsString('assets/js/admin.js', $body);
        self::assertStringNotContainsString('admin/produse/', $body);
    }

    public function testCustomerCredentialsCannotEnterAdmin(): void
    {
        $this->get('/admin');
        $response = $this->post('/admin', [
            '_csrf' => $_SESSION['_csrf'],
            'email' => 'client.panel@example.test',
            'password' => self::PASSWORD,
        ], '127.0.0.81');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin', $response->getHeaderLine('Location'));
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testAdminLoginDashboardAndRememberMeRotation(): void
    {
        $this->get('/admin');
        $login = $this->post('/admin', [
            '_csrf' => $_SESSION['_csrf'],
            'email' => 'admin.panel@example.test',
            'password' => self::PASSWORD,
            'remember' => '1',
        ], '127.0.0.82');

        self::assertSame(303, $login->getStatusCode());
        self::assertSame('/admin', $login->getHeaderLine('Location'));
        $cookie = $this->rememberCookie($login);
        self::assertNotSame('', $cookie);
        self::assertStringContainsString('HttpOnly', implode(';', $login->getHeader('Set-Cookie')));
        self::assertStringContainsString('SameSite=Strict', implode(';', $login->getHeader('Set-Cookie')));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM admin_remember_tokens')->fetchColumn());

        $_SESSION = [];
        $dashboard = $this->request('GET', '/admin', [], '127.0.0.82', [AdminRememberMe::COOKIE_NAME => $cookie]);
        $body = (string) $dashboard->getBody();

        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('<body class="admin-body">', $body);
        self::assertStringContainsString('Prezentare <em>generală</em>', $body);
        self::assertStringContainsString('Săptămâna curentă', $body);
        self::assertStringContainsString('Vânzări nete', $body);
        self::assertStringContainsString('Comenzi recente', $body);
        self::assertStringContainsString('data-count-up=', $body);
        self::assertStringContainsString('data-money-count-up=', $body);
        self::assertStringContainsString('data-chart-bar', $body);
        self::assertStringContainsString('aria-current="page"', $body);
        self::assertStringNotContainsString('href="/admin/produse/"', $body);
        self::assertArrayHasKey('user_id', $_SESSION);
        self::assertNotSame($cookie, $this->rememberCookie($dashboard), 'Tokenul persistent trebuie rotit la restaurarea sesiunii.');
    }

    public function testAdminLogoutRevokesRememberToken(): void
    {
        $this->get('/admin');
        $login = $this->post('/admin', [
            '_csrf' => $_SESSION['_csrf'],
            'email' => 'admin.panel@example.test',
            'password' => self::PASSWORD,
            'remember' => '1',
        ], '127.0.0.83');
        $cookie = $this->rememberCookie($login);

        $logout = $this->request('POST', '/admin/deconectare/', ['_csrf' => $_SESSION['_csrf']], '127.0.0.83', [AdminRememberMe::COOKIE_NAME => $cookie]);

        self::assertSame(303, $logout->getStatusCode());
        self::assertSame('/admin', $logout->getHeaderLine('Location'));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM admin_remember_tokens')->fetchColumn());
        self::assertStringContainsString('Max-Age=0', implode(';', $logout->getHeader('Set-Cookie')));
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    private function get(string $path): ResponseInterface
    {
        return $this->request('GET', $path);
    }

    /** @param array<string, mixed> $data */
    private function post(string $path, array $data, string $ip): ResponseInterface
    {
        return $this->request('POST', $path, $data, $ip);
    }

    /** @param array<string, mixed> $data @param array<string, string> $cookies */
    private function request(string $method, string $path, array $data = [], string $ip = '127.0.0.80', array $cookies = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => $ip])
            ->withHeader('User-Agent', self::USER_AGENT)
            ->withCookieParams($cookies);
        if ($data !== []) {
            $request = $request->withParsedBody($data);
        }
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();

        return $response;
    }

    private function rememberCookie(ResponseInterface $response): string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (preg_match('/(?:^|;\s*)' . preg_quote(AdminRememberMe::COOKIE_NAME, '/') . '=([^;]*)/', $header, $matches) === 1) {
                return rawurldecode($matches[1]);
            }
        }

        return '';
    }
}
