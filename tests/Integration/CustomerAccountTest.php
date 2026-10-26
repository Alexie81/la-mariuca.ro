<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class CustomerAccountTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $now = gmdate('c');
        $statement = $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,phone,role,status,email_verified_at,created_at,updated_at) VALUES ('alexie@example.test',:password,'Alexie','Popescu','+40763715974','customer','active',:now,:now,:now)");
        $statement->execute(['password' => password_hash('Parola8!', PASSWORD_DEFAULT), 'now' => $now]);
        $this->userId = (int) $this->pdo->lastInsertId();
        $_SESSION['user_id'] = $this->userId;
    }

    public function testAccountPageRendersCompleteCustomerHub(): void
    {
        $body = (string) $this->get('/cont/')->getBody();

        self::assertStringContainsString('<body class="account-page-body">', $body);
        self::assertStringContainsString('Bine ai revenit,', $body);
        self::assertStringContainsString('Comenzile mele', $body);
        self::assertStringContainsString('Date personale', $body);
        self::assertStringContainsString('Adresele mele', $body);
        self::assertStringContainsString('name="label"', $body);
        self::assertStringContainsString('name="is_default"', $body);
        self::assertStringContainsString('Cont protejat', $body);
        self::assertStringContainsString('assets/css/storefront/account.css', $body);
    }

    public function testCustomerCanUpdateProfileAndCreateAliasedDefaultAddress(): void
    {
        $this->get('/cont/');
        $profileResponse = $this->post('/cont/detalii/', [
            '_csrf' => $_SESSION['_csrf'],
            'first_name' => 'Alexandra',
            'last_name' => 'Popescu',
            'phone' => '0763 715 974',
        ]);
        self::assertSame(303, $profileResponse->getStatusCode());
        self::assertSame('/cont/#date-personale', $profileResponse->getHeaderLine('Location'));

        $addressResponse = $this->post('/cont/adrese/', [
            '_csrf' => $_SESSION['_csrf'],
            'label' => 'Bunici',
            'first_name' => 'Maria',
            'last_name' => 'Popescu',
            'phone' => '0763 715 974',
            'address' => 'Strada Florilor 10',
            'city' => 'București — Sector 1',
            'county' => 'București',
            'postal_code' => '010101',
            'notes' => 'Interfon 12',
        ]);
        self::assertSame(303, $addressResponse->getStatusCode());
        self::assertSame('/cont/#adrese', $addressResponse->getHeaderLine('Location'));

        $profile = $this->pdo->query('SELECT first_name,phone FROM users WHERE id=' . $this->userId)->fetch();
        self::assertSame('Alexandra', $profile['first_name']);
        self::assertSame('+40763715974', $profile['phone']);
        $address = $this->pdo->query('SELECT label,address_json,is_default FROM addresses WHERE user_id=' . $this->userId)->fetch();
        self::assertSame('Bunici', $address['label']);
        self::assertSame(1, (int) $address['is_default']);
        self::assertSame('Strada Florilor 10', json_decode((string) $address['address_json'], true, 512, JSON_THROW_ON_ERROR)['address']);

        $body = (string) $this->get('/cont/')->getBody();
        self::assertStringContainsString('Bunici', $body);
        self::assertStringContainsString('Strada Florilor 10', $body);
    }

    public function testAddressActionsAreRestrictedToTheAuthenticatedCustomer(): void
    {
        $now = gmdate('c');
        $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES ('other@example.test','x','Alt','Client','customer','active',:now,:now,:now)")->execute(['now' => $now]);
        $otherUserId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO addresses (user_id,label,address_json,is_default,created_at,updated_at) VALUES (:user,'Secretă','{}',1,:now,:now)")->execute(['user' => $otherUserId, 'now' => $now]);
        $addressId = (int) $this->pdo->lastInsertId();
        $this->get('/cont/');

        $response = $this->post('/cont/adrese/' . $addressId . '/sterge/', ['_csrf' => $_SESSION['_csrf']]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM addresses WHERE id=' . $addressId)->fetchColumn());
    }

    private function get(string $path): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . $path);
        return $this->handle($request);
    }

    /** @param array<string, mixed> $data */
    private function post(string $path, array $data): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost' . $path)->withParsedBody($data);
        return $this->handle($request);
    }

    private function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
    {
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();

        return $response;
    }
}
