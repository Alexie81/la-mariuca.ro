<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Cart\CartService;
use App\Modules\Customers\CustomerAccountRepository;
use App\Modules\Customers\CustomerAccountService;
use App\Shared\Security\Csrf;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class CheckoutAddressTest extends DatabaseTestCase
{
    public function testCheckoutPrefillsCustomerContactAndSavedAddressButAllowsGuestCheckout(): void
    {
        $this->seed();
        $variantId = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN inventory i ON i.variant_id=v.id JOIN products p ON p.id=v.product_id WHERE p.status='published' AND i.physical_quantity>0 LIMIT 1")->fetchColumn();
        (new CartService($this->pdo))->add($variantId, 1);
        $userId = $this->insertUser('customer', 'active');
        $_SESSION['user_id'] = $userId;
        $email = (string) $this->pdo->query('SELECT email FROM users WHERE id=' . $userId)->fetchColumn();
        $profilePage = $this->checkoutDocument();
        self::assertSame('Ana', $profilePage->evaluate('string(//input[@name="first_name"]/@value)'));
        self::assertSame('Pop', $profilePage->evaluate('string(//input[@name="last_name"]/@value)'));
        self::assertSame($email, $profilePage->evaluate('string(//input[@name="email"]/@value)'));
        self::assertSame('+40763715974', $profilePage->evaluate('string(//input[@name="phone"]/@value)'));
        self::assertSame(1, $profilePage->query('//select[@name="saved_address_id"]')->length);
        self::assertSame(1, $profilePage->query('//*[@data-endpoint="/finalizare-comanda/adresa/"]')->length);

        $accountService = new CustomerAccountService(new CustomerAccountRepository($this->pdo));
        $addressId = $accountService->saveAddress($userId, array_replace($this->addressData(), [
            'first_name' => 'Maria', 'last_name' => 'Ionescu', 'phone' => '0722 123 456',
        ]));
        $addressPage = $this->checkoutDocument();
        self::assertSame('Maria', $addressPage->evaluate('string(//input[@name="first_name"]/@value)'));
        self::assertSame('Ionescu', $addressPage->evaluate('string(//input[@name="last_name"]/@value)'));
        self::assertSame('+40722123456', $addressPage->evaluate('string(//input[@name="phone"]/@value)'));
        self::assertSame($email, $addressPage->evaluate('string(//input[@name="email"]/@value)'));
        self::assertSame('Strada Florilor 10', $addressPage->evaluate('string(//input[@name="address"]/@value)'));
        self::assertSame('București', $addressPage->evaluate('string(//input[@name="county"]/@value)'));
        self::assertSame('București — Sector 1', $addressPage->evaluate('string(//input[@name="city"]/@value)'));
        self::assertSame('010101', $addressPage->evaluate('string(//input[@name="postal_code"]/@value)'));
        self::assertSame((string) $addressId, $addressPage->evaluate('string(//select[@name="saved_address_id"]/option[@selected]/@value)'));

        unset($_SESSION['user_id']);
        $guestPage = $this->checkoutDocument();
        foreach (['first_name', 'last_name', 'email', 'phone', 'address'] as $field) {
            self::assertSame('', $guestPage->evaluate('string(//input[@name="' . $field . '"]/@value)'));
        }
        self::assertSame(0, $guestPage->query('//select[@name="saved_address_id"]')->length);
        self::assertSame(0, $guestPage->query('//*[@data-endpoint="/finalizare-comanda/adresa/"]')->length);
        self::assertSame(1, $guestPage->query('//a[@href="/cont/autentificare/?continuare=comanda"]')->length);
        self::assertSame(1, $guestPage->query('//a[@href="/cont/inregistrare/?continuare=comanda"]')->length);
        self::assertSame(1, $guestPage->query('//form[@id="checkout-form" and @method="post" and @action="/finalizare-comanda/"]')->length);
        self::assertSame(1, $guestPage->query('//button[@form="checkout-form" and @type="submit" and not(@name) and not(@disabled)]')->length);
        self::assertStringContainsString('Poți continua și fără cont.', (string) $guestPage->evaluate('string(//body)'));
    }

    public function testCheckoutAddressSavingRequiresCsrfAndAnActiveCustomer(): void
    {
        self::assertSame(419, $this->post([])->getStatusCode());
        $data = $this->addressData();
        self::assertSame(401, $this->post($data)->getStatusCode());
        $_SESSION['user_id'] = $this->insertUser('administrator', 'active');
        self::assertSame(401, $this->post($data)->getStatusCode());
        $_SESSION['user_id'] = $this->insertUser('customer', 'blocked');
        self::assertSame(401, $this->post($data)->getStatusCode());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM addresses')->fetchColumn());
    }

    public function testCheckoutCreatesCanonicalAddressOnlyForCurrentCustomer(): void
    {
        $otherId = $this->insertUser('customer', 'active');
        $accountService = new CustomerAccountService(new CustomerAccountRepository($this->pdo));
        $otherAddressId = $accountService->saveAddress($otherId, $this->addressData());
        $userId = $this->insertUser('customer', 'active');
        $_SESSION['user_id'] = $userId;
        $data = array_replace($this->addressData(), [
            'county' => 'Bucuresti',
            'city' => 'Bucuresti — Sector 1',
            'label' => '',
            'user_id' => $otherId,
            'address_id' => $otherAddressId,
        ]);
        $response = $this->post($data);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);
        self::assertSame('Adresă de livrare', $payload['address']['label']);
        self::assertTrue($payload['address']['is_default']);
        self::assertSame('București', $payload['address']['details']['county']);
        self::assertSame('București — Sector 1', $payload['address']['details']['city']);
        self::assertSame('+40763715974', $payload['address']['details']['phone']);
        self::assertCount(1, $payload['addresses']);
        self::assertNotSame($otherAddressId, $payload['address']['id']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM addresses WHERE user_id=' . $userId)->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM addresses WHERE user_id=' . $otherId)->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        $defaults = $accountService->checkoutDefaults($userId);
        self::assertSame('Ana', $defaults['profile']['first_name']);
        self::assertSame('Pop', $defaults['profile']['last_name']);
        self::assertSame('+40763715974', $defaults['profile']['phone']);
        self::assertSame($payload['address']['id'], (int) $defaults['default_address']['id']);
    }

    public function testInvalidQuickAddressIsRejectedWithoutPersistence(): void
    {
        $_SESSION['user_id'] = $this->insertUser('customer', 'active');
        foreach ([['postal_code' => '123'], ['city' => 'Localitate inventată'], ['phone' => 'abc']] as $invalid) {
            $response = $this->post(array_replace($this->addressData(), $invalid));
            self::assertSame(422, $response->getStatusCode());
            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertFalse($payload['ok']);
            self::assertNotEmpty($payload['message']);
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM addresses')->fetchColumn());
    }

    private function insertUser(string $role, string $status): int
    {
        $statement = $this->pdo->prepare('INSERT INTO users (email,password_hash,first_name,last_name,phone,role,status,created_at,updated_at) VALUES (:email,\'unused\',\'Ana\',\'Pop\',\'+40763715974\',:role,:status,:now,:now)');
        $statement->execute(['email' => bin2hex(random_bytes(6)) . '@example.test', 'role' => $role, 'status' => $status, 'now' => gmdate('c')]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function addressData(): array
    {
        return ['_csrf' => (new Csrf())->token(), 'label' => 'Acasă', 'first_name' => 'Ana', 'last_name' => 'Pop', 'phone' => '0763 715 974', 'address' => 'Strada Florilor 10', 'city' => 'București — Sector 1', 'county' => 'București', 'postal_code' => '010101'];
    }

    private function checkoutDocument(): \DOMXPath
    {
        $app = AppFactory::create($this->root);
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/finalizare-comanda/'));
        unset($app);
        gc_collect_cycles();
        self::assertSame(200, $response->getStatusCode());
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . (string) $response->getBody(), LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($document);
    }

    /** @param array<string,mixed> $data */
    private function post(array $data): ResponseInterface
    {
        $app = AppFactory::create($this->root);
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/finalizare-comanda/adresa/')->withParsedBody($data);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();
        return $response;
    }
}
