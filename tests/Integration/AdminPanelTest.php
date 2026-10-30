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
        self::assertStringContainsString('--bar-scale:0', $body);
        self::assertStringContainsString('data-chart-bar class="is-zero"', $body);
        self::assertStringContainsString('aria-current="page"', $body);
        self::assertStringContainsString('Produse și clienți', $body);
        self::assertStringContainsString('href="/admin/produse/"', $body);
        self::assertStringContainsString('Catalog, preț și stoc', $body);
        self::assertArrayHasKey('user_id', $_SESSION);
        self::assertNotSame($cookie, $this->rememberCookie($dashboard), 'Tokenul persistent trebuie rotit la restaurarea sesiunii.');
    }

    public function testProductsRegistryUsesInventorySearchAndActiveNavigation(): void
    {
        $this->seed();
        $this->get('/admin');
        $login = $this->post('/admin', [
            '_csrf' => $_SESSION['_csrf'],
            'email' => 'admin.panel@example.test',
            'password' => self::PASSWORD,
        ], '127.0.0.86');
        self::assertSame(303, $login->getStatusCode());

        $body = (string) $this->get('/admin/produse/')->getBody();
        self::assertStringContainsString('Registrul catalogului', $body);
        self::assertStringContainsString('Stoc online', $body);
        self::assertStringContainsString('Stoc efectiv (Conta)', $body);
        self::assertStringContainsString('href="/admin/produse/" aria-current="page"', $body);
        self::assertStringContainsString('LM-NB-001', $body);
        self::assertStringContainsString('8 buc.', $body);
        self::assertStringContainsString('/sterge/', $body);
        self::assertStringContainsString('data-admin-product-delete-dialog', $body);
        self::assertStringContainsString('data-admin-product-editor-dialog', $body);
        self::assertStringContainsString('data-admin-product-editor-trigger', $body);
        self::assertStringContainsString('/editare/modal/', $body);
        self::assertStringNotContainsString('1 variantă', $body);

        $search = (string) $this->get('/admin/produse/?cautare=LM-SH-002')->getBody();
        self::assertStringContainsString('Ghete „Explorator”', $search);
        self::assertStringNotContainsString('Set nou-născut „Primul dar”', $search);
        self::assertStringContainsString('1 produs relevant', $search);

        $form = (string) $this->get('/admin/produse/nou/')->getBody();
        self::assertStringContainsString('href="/admin/produse/" aria-current="page"', $form);
        self::assertStringContainsString('Produs nou', $form);

        $newModal = $this->get('/admin/produse/nou/modal/');
        self::assertSame(200, $newModal->getStatusCode());
        self::assertStringContainsString('Adaugă o poveste nouă', (string) $newModal->getBody());
        self::assertStringContainsString('data-admin-product-form', (string) $newModal->getBody());
        self::assertStringContainsString('data-product-name-input', (string) $newModal->getBody());
        self::assertStringContainsString('/admin/produse/verifica-nume/', (string) $newModal->getBody());
        self::assertStringContainsString('data-product-sku-input', (string) $newModal->getBody());
        self::assertStringContainsString('/admin/produse/verifica-sku/', (string) $newModal->getBody());
        self::assertMatchesRegularExpression('/name="sku" value="LM-\d{2}-\d{3,}"/', (string) $newModal->getBody());

        $categoryId = (int) $this->pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
        $created = $this->request('POST', '/admin/produse/nou/', [
            '_csrf' => $_SESSION['_csrf'],
            'name' => 'Produs creat din modal',
            'sku' => 'LM-MODAL-001',
            'price' => '149,90',
            'stock' => '4',
            'category_id' => (string) $categoryId,
            'status' => 'draft',
            'featured' => 'on',
            'indexable' => 'on',
        ], '127.0.0.86', [], ['Accept' => 'application/json']);
        self::assertSame(200, $created->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $created->getHeaderLine('Content-Type'));
        $createdPayload = json_decode((string) $created->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($createdPayload);
        self::assertTrue((bool) $createdPayload['ok']);
        $createdId = (int) $createdPayload['id'];
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM product_variants WHERE sku='LM-MODAL-001'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT p.featured FROM products p JOIN product_variants v ON v.product_id=p.id WHERE v.sku='LM-MODAL-001'")->fetchColumn());
        $createdVariantId = (int) $this->pdo->query("SELECT id FROM product_variants WHERE sku='LM-MODAL-001'")->fetchColumn();

        $duplicateAvailability = json_decode((string) $this->get('/admin/produse/verifica-nume/?nume=PRODUS%20CREAT%20DIN%20MODAL')->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse((bool) $duplicateAvailability['available']);
        self::assertSame('Acest titlu există deja.', $duplicateAvailability['message']);
        $ownAvailability = json_decode((string) $this->get('/admin/produse/verifica-nume/?nume=Produs%20creat%20din%20modal&exclude_id=' . $createdId)->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) $ownAvailability['available']);
        $newAvailability = json_decode((string) $this->get('/admin/produse/verifica-nume/?nume=Titlu%20complet%20nou')->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) $newAvailability['available']);

        $duplicateSkuAvailability = json_decode((string) $this->get('/admin/produse/verifica-sku/?sku=lm-modal-001')->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse((bool) $duplicateSkuAvailability['available']);
        self::assertSame('Acest SKU este deja folosit.', $duplicateSkuAvailability['message']);
        $ownSkuAvailability = json_decode((string) $this->get('/admin/produse/verifica-sku/?sku=LM-MODAL-001&exclude_variant_id=' . $createdVariantId)->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) $ownSkuAvailability['available']);
        $newSkuAvailability = json_decode((string) $this->get('/admin/produse/verifica-sku/?sku=LM-MODAL-LIBER')->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) $newSkuAvailability['available']);

        $duplicateSave = $this->request('POST', '/admin/produse/nou/', [
            '_csrf' => $_SESSION['_csrf'],
            'name' => '  PRODUS creat din modal  ',
            'sku' => 'LM-MODAL-DUPLICAT',
            'price' => '129,00',
            'stock' => '2',
            'category_id' => (string) $categoryId,
            'status' => 'draft',
        ], '127.0.0.86', [], ['Accept' => 'application/json']);
        self::assertSame(422, $duplicateSave->getStatusCode());
        $duplicatePayload = json_decode((string) $duplicateSave->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse((bool) $duplicatePayload['ok']);
        self::assertStringContainsString('Acest titlu există deja', (string) $duplicatePayload['message']);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM product_variants WHERE sku='LM-MODAL-DUPLICAT'")->fetchColumn());

        $duplicateSkuSave = $this->request('POST', '/admin/produse/nou/', [
            '_csrf' => $_SESSION['_csrf'],
            'name' => 'Produs cu SKU duplicat',
            'sku' => 'lm-modal-001',
            'price' => '129,00',
            'stock' => '2',
            'category_id' => (string) $categoryId,
            'status' => 'draft',
        ], '127.0.0.86', [], ['Accept' => 'application/json']);
        self::assertSame(422, $duplicateSkuSave->getStatusCode());
        $duplicateSkuPayload = json_decode((string) $duplicateSkuSave->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Acest SKU există deja', (string) $duplicateSkuPayload['message']);

        $autoSkuCreated = $this->request('POST', '/admin/produse/nou/', [
            '_csrf' => $_SESSION['_csrf'],
            'name' => 'Produs cu SKU automat',
            'price' => '119,00',
            'stock' => '1',
            'category_id' => (string) $categoryId,
            'status' => 'draft',
        ], '127.0.0.86', [], ['Accept' => 'application/json']);
        self::assertSame(200, $autoSkuCreated->getStatusCode());
        $autoSkuPayload = json_decode((string) $autoSkuCreated->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $autoSku = (string) $this->pdo->query('SELECT sku FROM product_variants WHERE product_id=' . (int) $autoSkuPayload['id'])->fetchColumn();
        self::assertMatchesRegularExpression('/^LM-\d{2}-\d{3,}$/', $autoSku);

        $product = $this->pdo->query("SELECT p.id,p.name FROM products p JOIN product_variants v ON v.product_id=p.id WHERE v.sku='LM-SH-002' LIMIT 1")->fetch();
        self::assertIsArray($product);
        $editModal = $this->get('/admin/produse/' . $product['id'] . '/editare/modal/');
        self::assertSame(200, $editModal->getStatusCode());
        self::assertStringContainsString('Finisează produsul', (string) $editModal->getBody());
        self::assertStringContainsString('LM-SH-002', (string) $editModal->getBody());
        self::assertStringContainsString('data-variant-id=', (string) $editModal->getBody());
        $deleted = $this->post('/admin/produse/' . $product['id'] . '/sterge/', [
            '_csrf' => $_SESSION['_csrf'],
        ], '127.0.0.86');
        self::assertSame(303, $deleted->getStatusCode());
        self::assertSame('/admin/produse/', $deleted->getHeaderLine('Location'));
        self::assertSame('archived', $this->pdo->query('SELECT status FROM products WHERE id=' . (int) $product['id'])->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM product_variants WHERE product_id=" . (int) $product['id'] . " AND status='active'")->fetchColumn());
        self::assertStringNotContainsString('/admin/produse/' . $product['id'] . '/editare/', (string) $this->get('/admin/produse/')->getBody());
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

    public function testOrdersRegistryIsPaginatedAndShowsOperationalColumns(): void
    {
        $this->seedOrders(16);
        $this->get('/admin');
        $login = $this->post('/admin', [
            '_csrf' => $_SESSION['_csrf'],
            'email' => 'admin.panel@example.test',
            'password' => self::PASSWORD,
        ], '127.0.0.84');
        self::assertSame(303, $login->getStatusCode());

        $firstPage = (string) $this->get('/admin/comenzi/')->getBody();
        self::assertStringContainsString('Registrul comenzilor', $firstPage);
        self::assertStringContainsString('LM-TEST-016', $firstPage);
        self::assertStringContainsString('client16@example.test', $firstPage);
        self::assertStringContainsString('0712 000 016', $firstPage);
        self::assertStringContainsString('Card online', $firstPage);
        self::assertStringContainsString('Plătită', $firstPage);
        self::assertStringContainsString('Livrată', $firstPage);
        self::assertStringContainsString('admin/comenzi/?pagina=2', $firstPage);
        self::assertStringContainsString('href="/admin/comenzi/" aria-current="page"', $firstPage);
        self::assertStringNotContainsString('<option value="manual_review"', $firstPage);
        self::assertStringNotContainsString('LM-TEST-001</a>', $firstPage);

        $secondPage = (string) $this->get('/admin/comenzi/?pagina=2')->getBody();
        self::assertStringContainsString('LM-TEST-001', $secondPage);
        self::assertStringNotContainsString('LM-TEST-016</a>', $secondPage);
        self::assertStringContainsString('aria-current="page">2</a>', $secondPage);

        $semanticPayment = (string) $this->get('/admin/comenzi/?cautare=platita')->getBody();
        self::assertStringContainsString('LM-TEST-016', $semanticPayment);
        self::assertStringNotContainsString('LM-TEST-015</a>', $semanticPayment);

        $productSearch = (string) $this->get('/admin/comenzi/?cautare=Cuburi%20Poveste')->getBody();
        self::assertStringContainsString('LM-TEST-016', $productSearch);
        self::assertStringContainsString('1 rezultat relevant', $productSearch);

        $statusFilter = (string) $this->get('/admin/comenzi/?status=delivered')->getBody();
        self::assertStringContainsString('LM-TEST-016', $statusFilter);
        self::assertStringNotContainsString('LM-TEST-015</a>', $statusFilter);

        $searchPagination = (string) $this->get('/admin/comenzi/?cautare=client')->getBody();
        self::assertStringContainsString('pagina=2&amp;cautare=client', $searchPagination);
    }

    public function testNotificationCenterSupportsFiltersReadsAndPreferences(): void
    {
        $this->seedOrders(2);
        $this->get('/admin');
        $login = $this->post('/admin', [
            '_csrf' => $_SESSION['_csrf'],
            'email' => 'admin.panel@example.test',
            'password' => self::PASSWORD,
        ], '127.0.0.85');
        self::assertSame(303, $login->getStatusCode());

        $page = (string) $this->get('/admin/notificari/')->getBody();
        self::assertStringContainsString('Notificări <em>care contează</em>', $page);
        self::assertStringContainsString('Comandă nouă LM-TEST-002', $page);
        self::assertStringContainsString('Plată confirmată · LM-TEST-002', $page);
        self::assertStringContainsString('href="/admin/notificari/" aria-current="page"', $page);
        self::assertStringContainsString('Marchează citite', $page);
        self::assertStringContainsString('Șterge toate', $page);
        self::assertStringContainsString('aria-label="Șterge notificarea"', $page);
        self::assertStringContainsString('name="comenzi"', $page);

        $adminId = (int) $this->pdo->query("SELECT id FROM users WHERE email = 'admin.panel@example.test'")->fetchColumn();
        $orderId = (int) $this->pdo->query("SELECT id FROM orders WHERE order_number = 'LM-TEST-002'")->fetchColumn();
        $read = $this->post('/admin/notificari/citeste/', [
            '_csrf' => $_SESSION['_csrf'],
            'notification_key' => 'order:' . $orderId . ':created',
            'filtru' => 'comenzi',
            'necitite' => '1',
        ], '127.0.0.85');

        self::assertSame(303, $read->getStatusCode());
        self::assertSame('/admin/notificari/?filtru=comenzi&necitite=1', $read->getHeaderLine('Location'));
        $readCount = $this->pdo->query(
            'SELECT COUNT(*) FROM admin_notification_reads WHERE admin_user_id = ' . $adminId
        )->fetchColumn();
        self::assertSame(1, (int) $readCount);

        $deleted = $this->post('/admin/notificari/sterge/', [
            '_csrf' => $_SESSION['_csrf'],
            'notification_key' => 'order:' . $orderId . ':created',
            'filtru' => 'comenzi',
        ], '127.0.0.85');
        self::assertSame(303, $deleted->getStatusCode());
        self::assertSame('/admin/notificari/?filtru=comenzi', $deleted->getHeaderLine('Location'));
        $dismissedCount = $this->pdo->query(
            'SELECT COUNT(*) FROM admin_notification_dismissals WHERE admin_user_id = ' . $adminId
        )->fetchColumn();
        self::assertSame(1, (int) $dismissedCount);
        $ordersAfterDelete = (string) $this->get('/admin/notificari/?filtru=comenzi')->getBody();
        self::assertStringNotContainsString('Comandă nouă LM-TEST-002', $ordersAfterDelete);

        $deletePayments = $this->post('/admin/notificari/sterge/', [
            '_csrf' => $_SESSION['_csrf'],
            'all' => '1',
            'filtru' => 'plati',
        ], '127.0.0.85');
        self::assertSame(303, $deletePayments->getStatusCode());
        self::assertSame('/admin/notificari/?filtru=plati', $deletePayments->getHeaderLine('Location'));
        $paymentsAfterDelete = (string) $this->get('/admin/notificari/?filtru=plati')->getBody();
        self::assertStringNotContainsString('Plată confirmată · LM-TEST-002', $paymentsAfterDelete);
        self::assertStringContainsString('Totul este la zi', $paymentsAfterDelete);

        $preferences = $this->post('/admin/notificari/preferinte/', [
            '_csrf' => $_SESSION['_csrf'],
            'comenzi' => '1',
            'stoc' => '1',
        ], '127.0.0.85');
        self::assertSame(303, $preferences->getStatusCode());

        $saved = $this->pdo->query(
            'SELECT orders_enabled, payments_enabled, stock_enabled, reviews_enabled '
            . 'FROM admin_notification_preferences WHERE admin_user_id = ' . $adminId
        )->fetch();
        self::assertSame(1, (int) $saved['orders_enabled']);
        self::assertSame(0, (int) $saved['payments_enabled']);
        self::assertSame(1, (int) $saved['stock_enabled']);
        self::assertSame(0, (int) $saved['reviews_enabled']);
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

    /** @param array<string, mixed> $data @param array<string, string> $cookies @param array<string, string> $headers */
    private function request(string $method, string $path, array $data = [], string $ip = '127.0.0.80', array $cookies = [], array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => $ip])
            ->withHeader('User-Agent', self::USER_AGENT)
            ->withCookieParams($cookies);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
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

    private function seedOrders(int $count): void
    {
        $cart = $this->pdo->prepare(
            'INSERT INTO carts (token,user_id,status,coupon_code,created_at,updated_at,expires_at) '
            . "VALUES (:token,NULL,'converted',NULL,:created,:created,:expires)"
        );
        $order = $this->pdo->prepare(
            'INSERT INTO orders (order_number,public_token_hash,idempotency_key,cart_id,user_id,email,first_name,last_name,phone,customer_type,company_json,shipping_address_json,billing_address_json,subtotal_bani,discount_bani,shipping_bani,tax_bani,total_bani,currency,status,payment_status,payment_method,shipping_method,internal_notes,terms_accepted_at,marketing_consent,created_at,updated_at) '
            . "VALUES (:number,:token_hash,:idempotency,:cart,NULL,:email,:first_name,:last_name,:phone,'person','{}',:shipping,:billing,:subtotal,0,0,0,:total,'RON',:status,:payment_status,:payment_method,'manual','',:terms,0,:created,:created)"
        );
        $orderItem = $this->pdo->prepare(
            'INSERT INTO order_items (order_id,product_id,variant_id,product_name,variant_summary,sku,quantity,unit_price_bani,personalization_bani,line_total_bani,tax_bani,personalization_json) '
            . "VALUES (:order,NULL,NULL,:product_name,:variant_summary,:sku,1,:price,0,:price,0,'{}')"
        );
        $base = new \DateTimeImmutable('2026-10-01 10:00:00', new \DateTimeZone('UTC'));

        for ($index = 1; $index <= $count; $index++) {
            $created = $base->modify('+' . $index . ' minutes')->format('c');
            $expires = $base->modify('+30 days')->format('c');
            $cart->execute([
                'token' => 'admin-orders-cart-' . $index,
                'created' => $created,
                'expires' => $expires,
            ]);
            $cartId = (int) $this->pdo->lastInsertId();
            $isLatest = $index === $count;
            $address = json_encode([
                'address' => 'Strada Test ' . $index,
                'city' => 'București — Sector 1',
                'county' => 'București',
                'postal_code' => '010101',
            ], JSON_THROW_ON_ERROR);
            $order->execute([
                'number' => 'LM-TEST-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'token_hash' => hash('sha256', 'admin-order-token-' . $index),
                'idempotency' => 'admin-order-idempotency-' . $index,
                'cart' => $cartId,
                'email' => 'client' . $index . '@example.test',
                'first_name' => 'Client',
                'last_name' => (string) $index,
                'phone' => '0712 000 ' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'shipping' => $address,
                'billing' => $address,
                'subtotal' => 10000 + $index,
                'total' => 10000 + $index,
                'status' => $isLatest ? 'delivered' : 'new',
                'payment_status' => $isLatest ? 'paid' : 'pending',
                'payment_method' => $isLatest ? 'stripe' : 'cod',
                'terms' => $created,
                'created' => $created,
            ]);
            if ($isLatest) {
                $orderItem->execute([
                    'order' => (int) $this->pdo->lastInsertId(),
                    'product_name' => 'Cuburi moi Poveste',
                    'variant_summary' => 'Culoare: Roz',
                    'sku' => 'CUBURI-POVESTE-ROZ',
                    'price' => 10000 + $index,
                ]);
            }
        }
    }
}
