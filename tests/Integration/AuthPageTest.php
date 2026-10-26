<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class AuthPageTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        $_ENV['GOOGLE_OAUTH_ENABLED'] = 'false';
        unset($_ENV['GOOGLE_CLIENT_ID'], $_ENV['GOOGLE_CLIENT_SECRET']);
        parent::setUp();
    }

    public function testLoginPageUsesTheResponsiveBoutiqueExperience(): void
    {
        $this->seed();

        $body = (string) $this->get('/cont/autentificare/')->getBody();

        self::assertStringContainsString('<body class="login-page-body">', $body);
        self::assertStringContainsString('class="login-frame"', $body);
        self::assertStringContainsString('Bine ai revenit <em>în poveste.</em>', $body);
        self::assertStringContainsString('assets/images/icon-favorite-hearts.png', $body);
        self::assertStringContainsString('name="_csrf"', $body);
        self::assertStringContainsString('name="email"', $body);
        self::assertStringContainsString('name="password"', $body);
        self::assertStringContainsString('data-password-toggle', $body);
        self::assertStringContainsString('assets/js/storefront/auth.js', $body);
        self::assertStringContainsString('href="/cont/inregistrare/"', $body);
        self::assertStringContainsString('href="/cont/google/"', $body);
        self::assertStringContainsString('Continuă cu Google', $body);
    }

    public function testRegistrationPageUsesTheSameResponsiveExperience(): void
    {
        $this->seed();

        $body = (string) $this->get('/cont/inregistrare/')->getBody();

        self::assertStringContainsString('<body class="register-page-body">', $body);
        self::assertStringContainsString('class="login-frame login-frame--register"', $body);
        self::assertStringContainsString('name="first_name"', $body);
        self::assertStringContainsString('name="last_name"', $body);
        self::assertStringContainsString('name="email"', $body);
        self::assertStringContainsString('name="phone"', $body);
        self::assertStringContainsString('autocomplete="tel"', $body);
        self::assertStringContainsString('name="password"', $body);
        self::assertStringContainsString('minlength="8"', $body);
        self::assertStringContainsString('data-password-toggle', $body);
        self::assertStringContainsString('href="/cont/google/"', $body);
        self::assertStringContainsString('Continuă cu Google', $body);
        self::assertStringContainsString('href="/cont/autentificare/"', $body);
    }

    public function testManualRegistrationPersistsTheRequiredNormalizedPhone(): void
    {
        $this->seed();
        $this->get('/cont/inregistrare/');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/cont/inregistrare/')
            ->withParsedBody([
                '_csrf' => $_SESSION['_csrf'],
                'first_name' => 'Maria',
                'last_name' => 'Popescu',
                'email' => 'maria.cont@example.test',
                'phone' => '0763 715 974',
                'password' => 'Parola8!',
            ]);
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/cont/autentificare/', $response->getHeaderLine('Location'));
        $phone = $this->pdo->query("SELECT phone FROM users WHERE email='maria.cont@example.test'")->fetchColumn();
        self::assertSame('+40763715974', $phone);
    }

    public function testGoogleEntryPointFailsClosedUntilCredentialsAreConfigured(): void
    {
        $this->seed();

        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/cont/google/');
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/cont/autentificare/', $response->getHeaderLine('Location'));
    }

    public function testGoogleEntryPointUsesTheProductionAppUrlWithPrivateCredentialsFile(): void
    {
        $credentialsPath = sys_get_temp_dir() . '/la-mariuca-google-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($credentialsPath, json_encode([
            'web' => [
                'client_id' => 'client-id-for-test.apps.googleusercontent.com',
                'client_secret' => 'secret-for-test',
            ],
        ], JSON_THROW_ON_ERROR));

        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_URL'] = 'https://la-mariuca.ro';
        $_ENV['GOOGLE_OAUTH_ENABLED'] = 'true';
        $_ENV['GOOGLE_CREDENTIALS_FILE'] = $credentialsPath;
        try {
            $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://la-mariuca.ro/cont/google/');
            $app = AppFactory::create($this->root);
            $response = $app->handle($request);
            unset($app);
            gc_collect_cycles();

            self::assertSame(303, $response->getStatusCode());
            $location = $response->getHeaderLine('Location');
            $parts = parse_url($location);
            self::assertSame('accounts.google.com', $parts['host'] ?? null);
            parse_str((string) ($parts['query'] ?? ''), $query);
            self::assertSame('https://la-mariuca.ro/cont/google/retur/', $query['redirect_uri'] ?? null);
            self::assertSame('S256', $query['code_challenge_method'] ?? null);
            self::assertArrayHasKey('state', $query);
            self::assertArrayHasKey('nonce', $query);
        } finally {
            if (is_file($credentialsPath)) {
                unlink($credentialsPath);
            }
            $_ENV['APP_ENV'] = 'testing';
            $_ENV['APP_URL'] = 'http://localhost';
            $_ENV['GOOGLE_OAUTH_ENABLED'] = 'false';
            unset($_ENV['GOOGLE_CREDENTIALS_FILE']);
        }
    }

    private function get(string $path): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . $path);
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();

        self::assertSame(200, $response->getStatusCode());

        return $response;
    }
}
