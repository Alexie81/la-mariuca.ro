<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Shared\Security\Csrf;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class CheckoutAuthReturnTest extends DatabaseTestCase
{
    public function testCustomerReturnsToCheckoutOnlyAfterSuccessfulAuthentication(): void
    {
        $this->insertUser('customer');
        $this->request('GET', '/cont/autentificare/?continuare=comanda');
        self::assertSame('comanda', $_SESSION['checkout_auth_continuation']);
        $invalid = $this->login('wrong-password');
        self::assertSame('/cont/autentificare/', $invalid->getHeaderLine('Location'));
        self::assertSame('comanda', $_SESSION['checkout_auth_continuation']);
        $success = $this->login('Valid-password-2026!');
        self::assertSame('/finalizare-comanda/', $success->getHeaderLine('Location'));
        self::assertArrayNotHasKey('checkout_auth_continuation', $_SESSION);
        self::assertSame('/cont/', $this->login('Valid-password-2026!')->getHeaderLine('Location'));
    }

    public function testRegistrationRetainsCheckoutIntentUntilCustomerCanLogIn(): void
    {
        $this->request('GET', '/cont/inregistrare/?continuare=comanda');
        $response = $this->request('POST', '/cont/inregistrare/', [
            '_csrf' => (new Csrf())->token(),
            'first_name' => 'Ana', 'last_name' => 'Pop', 'email' => 'checkout-return@example.test',
            'phone' => '0763 715 974', 'password' => 'Valid-password-2026!',
        ]);
        self::assertSame('/cont/autentificare/', $response->getHeaderLine('Location'));
        self::assertSame('comanda', $_SESSION['checkout_auth_continuation']);
        $this->request('GET', '/cont/autentificare/');
        self::assertSame('comanda', $_SESSION['checkout_auth_continuation']);
        $this->pdo->exec("UPDATE users SET status='active' WHERE email='checkout-return@example.test'");
        self::assertSame('/finalizare-comanda/', $this->login('Valid-password-2026!')->getHeaderLine('Location'));
    }

    public function testArbitraryContinuationIsIgnoredAndAdministratorKeepsAdminDestination(): void
    {
        $this->insertUser('administrator');
        $this->request('GET', '/cont/autentificare/?continuare=https%3A%2F%2Fevil.example');
        self::assertArrayNotHasKey('checkout_auth_continuation', $_SESSION);
        $this->request('GET', '/cont/autentificare/?continuare=comanda');
        self::assertSame('/admin/', $this->login('Valid-password-2026!')->getHeaderLine('Location'));
        self::assertArrayNotHasKey('checkout_auth_continuation', $_SESSION);
    }

    public function testUnavailableGoogleEntryRetainsCheckoutIntentForAlternativeLogin(): void
    {
        $previous = $_ENV['GOOGLE_OAUTH_ENABLED'] ?? null;
        $_ENV['GOOGLE_OAUTH_ENABLED'] = 'false';
        try {
            $this->insertUser('customer');
            $response = $this->request('GET', '/cont/google/?continuare=comanda');
            self::assertSame('/cont/autentificare/', $response->getHeaderLine('Location'));
            self::assertSame('comanda', $_SESSION['checkout_auth_continuation']);
            self::assertSame('/finalizare-comanda/', $this->login('Valid-password-2026!')->getHeaderLine('Location'));
        } finally {
            if ($previous === null) {
                unset($_ENV['GOOGLE_OAUTH_ENABLED']);
            } else {
                $_ENV['GOOGLE_OAUTH_ENABLED'] = $previous;
            }
        }
    }

    private function insertUser(string $role): void
    {
        $statement = $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES ('checkout-return@example.test',:password,'Ana','Pop',:role,'active',:now,:now)");
        $statement->execute(['password' => password_hash('Valid-password-2026!', PASSWORD_DEFAULT), 'role' => $role, 'now' => gmdate('c')]);
    }

    private function login(string $password): ResponseInterface
    {
        return $this->request('POST', '/cont/autentificare/', ['_csrf' => (new Csrf())->token(), 'email' => 'checkout-return@example.test', 'password' => $password]);
    }

    /** @param array<string,mixed> $data */
    private function request(string $method, string $path, array $data = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path)->withParsedBody($data);
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();
        return $response;
    }
}
