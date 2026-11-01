<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Shared\Security\Auth;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class CustomerAdminTest extends DatabaseTestCase
{
    private const ADMIN_PASSWORD = 'Customers-admin-2026!';
    private const CUSTOMER_PASSWORD = 'Customer-login-2026!';
    private int $featuredCustomerId;

    protected function setUp(): void
    {
        parent::setUp();
        $now = gmdate('c');
        $admin = $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES ('customers.admin@example.test',:password,'Maria','Admin','administrator','active',:now,:now,:now)");
        $admin->execute(['password' => password_hash(self::ADMIN_PASSWORD, PASSWORD_DEFAULT), 'now' => $now]);
        $this->seedCustomers();

        $this->get('/admin');
        $login = $this->post('/admin', [
            '_csrf' => $_SESSION['_csrf'],
            'email' => 'customers.admin@example.test',
            'password' => self::ADMIN_PASSWORD,
        ]);
        self::assertSame(303, $login->getStatusCode());
    }

    public function testCustomerCrmSupportsSemanticSearchPaginationProfileAndOrderHistory(): void
    {
        $page = (string) $this->get('/admin/clienti/')->getBody();
        self::assertStringContainsString('<h1 id="admin-customers-title">Clienți</h1>', $page);
        self::assertStringContainsString('data-admin-customer-dialog', $page);
        self::assertStringContainsString('Căutare inteligentă', $page);
        self::assertStringContainsString('assets/css/customer-admin.css', $page);
        self::assertStringContainsString('Paginarea clienților', $page);
        $categories = strpos($page, 'href="/admin/categorii/"');
        $customers = strpos($page, 'href="/admin/clienti/"');
        $stock = strpos($page, 'Stoc și achiziții');
        self::assertIsInt($categories);
        self::assertIsInt($customers);
        self::assertIsInt($stock);
        self::assertTrue($categories < $customers && $customers < $stock);

        $secondPage = (string) $this->get('/admin/clienti/?pagina=2')->getBody();
        self::assertStringContainsString('Pagina anterioară', $secondPage);

        $semantic = (string) $this->get('/admin/clienti/?cautare=Google%20abonat')->getBody();
        self::assertStringContainsString('Ștefania Popescu', $semantic);
        self::assertStringContainsString('stefania@example.test', $semantic);
        self::assertStringNotContainsString('Client 4', $semantic);
        $withoutDiacritics = (string) $this->get('/admin/clienti/?cautare=stefania')->getBody();
        self::assertStringContainsString('Ștefania Popescu', $withoutDiacritics);
        $orderSearch = (string) $this->get('/admin/clienti/?cautare=LM-CLIENT-007')->getBody();
        self::assertStringContainsString('Ștefania Popescu', $orderSearch);

        $modal = (string) $this->get('/admin/clienti/' . $this->featuredCustomerId . '/modal/')->getBody();
        self::assertStringContainsString('data-admin-customer-profile', $modal);
        self::assertStringContainsString('Ștefania Popescu', $modal);
        self::assertStringContainsString('Abonat la noutăți', $modal);
        self::assertStringContainsString('Conectare prin Google', $modal);
        self::assertStringContainsString('Ultima conectare', $modal);
        self::assertStringContainsString('7</strong><em>comenzi plasate', $modal);
        self::assertStringContainsString('LM-CLIENT-007', $modal);
        self::assertStringContainsString('Pagina <strong>1</strong> din 2', $modal);
        self::assertStringContainsString('Strada Atelierului 7', $modal);

        $modalPageTwo = (string) $this->get('/admin/clienti/' . $this->featuredCustomerId . '/modal/?pagina_comenzi=2')->getBody();
        self::assertStringContainsString('Pagina <strong>2</strong> din 2', $modalPageTwo);
        self::assertStringContainsString('LM-CLIENT-001', $modalPageTwo);
        $standalone = $this->get('/admin/clienti/' . $this->featuredCustomerId . '/?pagina_comenzi=2');
        self::assertSame(200, $standalone->getStatusCode());
        self::assertStringContainsString('Înapoi la clienți', (string) $standalone->getBody());

        $discountForm = (string) $this->get('/admin/clienti/' . $this->featuredCustomerId . '/reduceri/noua/modal/')->getBody();
        self::assertStringContainsString('data-admin-customer-discount-form', $discountForm);
        self::assertStringContainsString('Toată comanda', $discountForm);
        self::assertStringContainsString('Livrare', $discountForm);
        self::assertStringContainsString('Fără termen', $discountForm);
        $csrf = (string) $_SESSION['_csrf'];
        $orderDiscount = $this->post('/admin/clienti/' . $this->featuredCustomerId . '/reduceri/noua/', [
            '_csrf' => $csrf, 'scope' => 'order', 'value_type' => 'percent', 'value' => '12,5', 'validity' => 'always',
        ]);
        self::assertSame(303, $orderDiscount->getStatusCode());
        $shippingDiscount = $this->post('/admin/clienti/' . $this->featuredCustomerId . '/reduceri/noua/', [
            '_csrf' => $csrf, 'scope' => 'shipping', 'value_type' => 'free', 'value' => '', 'validity' => 'always',
        ]);
        self::assertSame(303, $shippingDiscount->getStatusCode());
        $discountModal = (string) $this->get('/admin/clienti/' . $this->featuredCustomerId . '/modal/')->getBody();
        self::assertStringContainsString('12,5% reducere', $discountModal);
        self::assertStringContainsString('Livrare gratuită', $discountModal);
        $discountId = (int) $this->pdo->query("SELECT id FROM customer_discounts WHERE user_id={$this->featuredCustomerId} AND scope='order'")->fetchColumn();
        $deleted = $this->post('/admin/clienti/' . $this->featuredCustomerId . '/reduceri/' . $discountId . '/sterge/', ['_csrf' => $csrf]);
        self::assertSame(303, $deleted->getStatusCode());
        self::assertSame('inactive', $this->pdo->query('SELECT status FROM customer_discounts WHERE id=' . $discountId)->fetchColumn());

        $adminSession = $_SESSION;
        $_SESSION = [];
        self::assertSame('authenticated', (new Auth($this->pdo))->attempt('stefania@example.test', self::CUSTOMER_PASSWORD));
        $lastLogin = $this->pdo->query('SELECT last_login_at FROM users WHERE id=' . $this->featuredCustomerId)->fetchColumn();
        self::assertIsString($lastLogin);
        self::assertNotSame('', $lastLogin);
        $_SESSION = $adminSession;

        $blocked = $this->post('/admin/clienti/' . $this->featuredCustomerId . '/stare/', ['_csrf' => $csrf, 'action' => 'block', 'reason' => 'Spam repetat în formularele magazinului.']);
        self::assertSame(303, $blocked->getStatusCode());
        self::assertSame('blocked', $this->pdo->query('SELECT status FROM users WHERE id=' . $this->featuredCustomerId)->fetchColumn());
        self::assertSame('Spam repetat în formularele magazinului.', $this->pdo->query('SELECT blocked_reason FROM users WHERE id=' . $this->featuredCustomerId)->fetchColumn());
        self::assertStringContainsString('Cont dezactivat', (string) $this->get('/admin/clienti/?cautare=stefania')->getBody());
        self::assertStringContainsString('Spam repetat în formularele magazinului.', (string) $this->get('/admin/clienti/' . $this->featuredCustomerId . '/modal/')->getBody());
        $_SESSION = [];
        self::assertSame('invalid', (new Auth($this->pdo))->attempt('stefania@example.test', self::CUSTOMER_PASSWORD));

        $_SESSION = $adminSession;
        $reactivated = $this->post('/admin/clienti/' . $this->featuredCustomerId . '/stare/', ['_csrf' => $csrf, 'action' => 'activate']);
        self::assertSame(303, $reactivated->getStatusCode());
        self::assertSame('active', $this->pdo->query('SELECT status FROM users WHERE id=' . $this->featuredCustomerId)->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT blocked_reason IS NULL FROM users WHERE id=' . $this->featuredCustomerId)->fetchColumn());
        $reactivatedModal = (string) $this->get('/admin/clienti/' . $this->featuredCustomerId . '/modal/')->getBody();
        self::assertStringContainsString('Cont activ', $reactivatedModal);
        self::assertStringContainsString('Dezactivează contul', $reactivatedModal);
        $_SESSION = [];
        self::assertSame('authenticated', (new Auth($this->pdo))->attempt('stefania@example.test', self::CUSTOMER_PASSWORD));
        $_SESSION = $adminSession;
    }

    private function seedCustomers(): void
    {
        $user = $this->pdo->prepare('INSERT INTO users (email,password_hash,first_name,last_name,phone,role,status,email_verified_at,created_at,updated_at,last_login_at) VALUES (:email,:password,:first,:last,:phone,\'customer\',\'active\',:verified,:created,:created,:login)');
        $base = new \DateTimeImmutable('2026-09-01 10:00:00', new \DateTimeZone('UTC'));
        for ($index = 1; $index <= 17; $index++) {
            $featured = $index === 1;
            $created = $base->modify('+' . $index . ' days')->format('c');
            $user->execute([
                'email' => $featured ? 'stefania@example.test' : 'client' . $index . '@example.test',
                'password' => password_hash($featured ? self::CUSTOMER_PASSWORD : 'Unused-2026!', PASSWORD_DEFAULT),
                'first' => $featured ? 'Ștefania' : 'Client',
                'last' => $featured ? 'Popescu' : (string) $index,
                'phone' => '07' . str_pad((string) (30000000 + $index), 8, '0', STR_PAD_LEFT),
                'verified' => $created,
                'created' => $created,
                'login' => $featured ? $base->modify('+40 days')->format('c') : null,
            ]);
            if ($featured) {
                $this->featuredCustomerId = (int) $this->pdo->lastInsertId();
            }
        }
        $this->pdo->prepare("INSERT INTO oauth_identities (user_id,provider,provider_subject,created_at,updated_at) VALUES (:user,'google','google-stefania',:now,:now)")->execute(['user' => $this->featuredCustomerId, 'now' => gmdate('c')]);
        $this->seedOrders();
    }

    private function seedOrders(): void
    {
        $cart = $this->pdo->prepare("INSERT INTO carts (token,user_id,status,coupon_code,created_at,updated_at,expires_at) VALUES (:token,:user,'converted',NULL,:created,:created,:expires)");
        $order = $this->pdo->prepare("INSERT INTO orders (order_number,public_token_hash,idempotency_key,cart_id,user_id,email,first_name,last_name,phone,customer_type,company_json,shipping_address_json,billing_address_json,subtotal_bani,discount_bani,shipping_bani,tax_bani,total_bani,currency,status,payment_status,payment_method,shipping_method,internal_notes,terms_accepted_at,marketing_consent,created_at,updated_at) VALUES (:number,:public_token,:idempotency,:cart,:user,'stefania@example.test','Ștefania','Popescu','0730000001','person','{}',:address,:address,:total,0,0,0,:total,'RON',:status,:payment,:method,'manual','',:created,:consent,:created,:created)");
        $item = $this->pdo->prepare("INSERT INTO order_items (order_id,product_id,variant_id,product_name,variant_summary,sku,quantity,unit_price_bani,personalization_bani,line_total_bani,tax_bani,personalization_json) VALUES (:order,NULL,NULL,:name,'Culoare: Roz',:sku,:quantity,:price,0,:total,0,'{}')");
        $base = new \DateTimeImmutable('2026-10-01 10:00:00', new \DateTimeZone('UTC'));
        for ($index = 1; $index <= 7; $index++) {
            $created = $base->modify('+' . $index . ' days')->format('c');
            $cart->execute(['token' => 'customer-admin-cart-' . $index, 'user' => $this->featuredCustomerId, 'created' => $created, 'expires' => $base->modify('+90 days')->format('c')]);
            $cartId = (int) $this->pdo->lastInsertId();
            $address = json_encode(['first_name' => 'Ștefania', 'last_name' => 'Popescu', 'address' => 'Strada Atelierului ' . $index, 'city' => 'București — Sector 1', 'county' => 'București', 'postal_code' => '010101'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $total = 10000 + ($index * 1000);
            $order->execute([
                'number' => 'LM-CLIENT-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'public_token' => hash('sha256', 'customer-public-' . $index),
                'idempotency' => 'customer-idempotency-' . $index,
                'cart' => $cartId,
                'user' => $this->featuredCustomerId,
                'address' => $address,
                'total' => $total,
                'status' => $index === 7 ? 'delivered' : 'confirmed',
                'payment' => $index === 7 ? 'paid' : 'pending',
                'method' => $index === 7 ? 'stripe' : 'cod',
                'consent' => $index === 7 ? 1 : 0,
                'created' => $created,
            ]);
            $item->execute(['order' => (int) $this->pdo->lastInsertId(), 'name' => 'Rochiță atelier ' . $index, 'sku' => 'ATELIER-' . $index, 'quantity' => 1, 'price' => $total, 'total' => $total]);
        }
    }

    private function get(string $path): ResponseInterface
    {
        return $this->request('GET', $path);
    }

    /** @param array<string,mixed> $data */
    private function post(string $path, array $data): ResponseInterface
    {
        return $this->request('POST', $path, $data);
    }

    /** @param array<string,mixed> $data */
    private function request(string $method, string $path, array $data = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.97'])->withHeader('User-Agent', 'LaMariuca-CustomerAdmin-Test/1.0');
        if ($data !== []) {
            $request = $request->withParsedBody($data);
        }
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();
        return $response;
    }
}
