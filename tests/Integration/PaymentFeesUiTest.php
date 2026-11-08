<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Orders\OrderEmailImages;
use App\Modules\Orders\OrderEmailViewData;
use App\Modules\Orders\OrderService;
use App\Modules\Orders\OrderTrackingRepository;
use App\Modules\Orders\OrderTrackingService;
use App\Modules\Payments\PaymentFeeService;
use App\Modules\Payments\PaymentMethodSettingsService;
use App\Shared\Config\Config;
use App\Shared\Security\Csrf;
use App\Shared\Support\Money;
use DOMDocument;
use DOMXPath;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class PaymentFeesUiTest extends DatabaseTestCase
{
    /** @var array<string,mixed> */
    private array $environment;
    private int $adminId;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        parent::setUp();
        $this->seed();
        $_ENV['COD_ENABLED'] = 'true';
        $_ENV['STRIPE_ENABLED'] = 'true';
        $_ENV['STRIPE_MODE'] = 'test';
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_ui_no_network';
        $_ENV['MAIL_DELIVERY_MODE'] = 'spool';
        $_ENV['WEB_PUSH_ENABLED'] = 'false';
        $this->pdo->exec("INSERT INTO users (email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES ('fees.admin@example.test','unused','Ana','Admin','administrator','active','2026-11-08T10:00:00+00:00','2026-11-08T10:00:00+00:00')");
        $this->adminId = (int) $this->pdo->lastInsertId();
        (new Csrf())->token();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_ENV = $this->environment;
    }

    public function testAdminFeeRoutesRequireAuthenticationAndCsrfAndKeepSettingsSwitches(): void
    {
        foreach (['/admin/metode-plata/costuri/nou/', '/admin/metode-plata/costuri/1/editare/', '/admin/metode-plata/costuri/1/sterge/'] as $path) {
            self::assertSame(303, $this->request('GET', $path)->getStatusCode());
            self::assertSame(303, $this->request('POST', $path, ['_csrf' => $_SESSION['_csrf']])->getStatusCode());
        }
        $_SESSION['user_id'] = $this->adminId;
        self::assertSame(419, $this->request('POST', '/admin/metode-plata/costuri/nou/', ['payment_method' => 'cod', 'name' => 'Ambalare', 'price' => '5'])->getStatusCode());
        self::assertSame([], $this->fees()->all());
        $page = (string) $this->request('GET', '/admin/metode-plata/')->getBody();
        self::assertStringContainsString('data-payment-fee-register', $page);
        self::assertStringContainsString('data-payment-fee-dialog', $page);
        self::assertStringContainsString('name="cod_enabled"', $page);
        self::assertStringContainsString('name="stripe_enabled"', $page);
        $this->pdo->exec("UPDATE users SET role='customer' WHERE id=" . $this->adminId);
        self::assertSame(303, $this->request('POST', '/admin/metode-plata/costuri/nou/', $this->data())->getStatusCode());
        self::assertSame([], $this->fees()->all());
    }

    public function testAjaxModalCreateEditAndDeleteReplaceOnlyFeeRegisterAndEscapeNames(): void
    {
        $_SESSION['user_id'] = $this->adminId;
        $form = $this->request('GET', '/admin/metode-plata/costuri/nou/?metoda=stripe', ajax: true);
        self::assertSame(200, $form->getStatusCode());
        self::assertSame('no-store', $form->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('name="payment_method" value="stripe"', (string) $form->getBody());
        self::assertStringNotContainsString('<!DOCTYPE', (string) $form->getBody());
        $name = 'Ambalare <b>cadou</b>';
        $response = $this->request('POST', '/admin/metode-plata/costuri/nou/', $this->data($name), true);
        $result = $this->json($response);
        self::assertTrue($result['ok']);
        self::assertStringContainsString('Ambalare &lt;b&gt;cadou&lt;/b&gt;', $result['html']);
        self::assertStringNotContainsString('Ambalare <b>cadou</b>', $result['html']);
        self::assertStringNotContainsString('name="cod_enabled"', $result['html']);
        $fee = $this->fees()->all()[0];
        $id = (int) $fee['id'];
        self::assertSame(550, (int) $fee['amount_bani']);
        $response = $this->request('POST', '/admin/metode-plata/costuri/' . $id . '/editare/', $this->data('Ambalare premium', '9,20'), true);
        self::assertTrue($this->json($response)['ok']);
        self::assertSame('Ambalare premium', $this->fees()->all()[0]['name']);
        $delete = $this->request('GET', '/admin/metode-plata/costuri/' . $id . '/sterge/', ajax: true);
        self::assertStringContainsString('Elimini acest cost?', (string) $delete->getBody());
        self::assertStringContainsString('Ambalare premium', (string) $delete->getBody());
        $response = $this->request('POST', '/admin/metode-plata/costuri/' . $id . '/sterge/', ['_csrf' => $_SESSION['_csrf']], true);
        self::assertTrue($this->json($response)['ok']);
        self::assertSame([], $this->fees()->all());
        self::assertSame(404, $this->request('GET', '/admin/metode-plata/costuri/' . $id . '/editare/', ajax: true)->getStatusCode());
    }

    public function testNoJavascriptFormPreservesInputOnValidationAndRedirectsAfterSave(): void
    {
        $_SESSION['user_id'] = $this->adminId;
        $response = $this->request('POST', '/admin/metode-plata/costuri/nou/', $this->data('Ambalare cadou', '0'));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('value="Ambalare cadou"', (string) $response->getBody());
        self::assertStringContainsString('Costul trebuie să fie între', (string) $response->getBody());
        self::assertSame([], $this->fees()->all());
        $invalid = $this->data();
        $invalid['name'] = ['malformed'];
        self::assertSame(422, $this->request('POST', '/admin/metode-plata/costuri/nou/', $invalid)->getStatusCode());
        $response = $this->request('POST', '/admin/metode-plata/costuri/nou/', $this->data());
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/metode-plata/', $response->getHeaderLine('Location'));
        self::assertCount(1, $this->fees()->all());
    }

    public function testCheckoutRendersSeparateMethodCostsAndNoJavascriptRecalculationKeepsSelectedMethod(): void
    {
        $this->fees()->save(['payment_method' => 'cod', 'name' => 'Ambalare ramburs', 'price' => '5,50'], $this->adminId);
        $this->fees()->save(['payment_method' => 'stripe', 'name' => 'Ambalare card', 'price' => '2,25'], $this->adminId);
        $cart = $this->cart();
        $shipping = $this->pdo->query("SELECT * FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $base = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->quoteShipping($cart->summary(), $shipping)['total_bani'];
        $first = $this->document($this->request('GET', '/finalizare-comanda/'));
        self::assertSame(1, $first->query('//input[@name="payment_method" and @value="cod" and @data-payment-fee-bani="550"]')->length);
        self::assertSame(1, $first->query('//input[@name="payment_method" and @value="stripe" and @data-payment-fee-bani="225"]')->length);
        foreach (['cod' => 550, 'stripe' => 225] as $method => $amount) {
            $response = $this->request('POST', '/finalizare-comanda/', ['_csrf' => $_SESSION['_csrf'], 'checkout_action' => 'recalculate', 'payment_method' => $method, 'shipping_method_id' => (string) $shipping['id'], 'first_name' => 'Ana']);
            self::assertSame(303, $response->getStatusCode());
            $document = $this->document($this->request('GET', '/finalizare-comanda/'));
            self::assertSame(1, $document->query('//input[@name="payment_method" and @value="' . $method . '" and @checked]')->length);
            self::assertSame(1, $document->query('//*[@data-checkout-fees="' . $method . '" and not(@hidden)]')->length);
            self::assertSame(Money::format($base + $amount), trim($document->query('//*[@data-checkout-total]')->item(0)->textContent));
            self::assertSame(1, $document->query('//input[@name="first_name" and @value="Ana"]')->length);
        }
        (new PaymentMethodSettingsService($this->pdo, Config::load($this->root)))->save(true, false, $this->adminId);
        $this->request('POST', '/finalizare-comanda/', ['_csrf' => $_SESSION['_csrf'], 'checkout_action' => 'recalculate', 'payment_method' => 'stripe']);
        $fallback = $this->document($this->request('GET', '/finalizare-comanda/'));
        self::assertSame(0, $fallback->query('//input[@name="payment_method" and @value="stripe"]')->length);
        self::assertSame(1, $fallback->query('//input[@name="payment_method" and @value="cod" and @checked]')->length);
        self::assertSame(Money::format($base + 550), trim($fallback->query('//*[@data-checkout-total]')->item(0)->textContent));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    }

    public function testOrderAdminTrackingAndEmailKeepHistoricalCostAfterAdminRemovesIt(): void
    {
        $feeId = $this->fees()->save(['payment_method' => 'cod', 'name' => 'Ambalare cadou', 'price' => '5,50'], $this->adminId);
        $config = Config::load($this->root);
        $shipping = (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        $placed = (new CheckoutService($this->pdo, $this->cart(), $config))->place([
            'email' => 'fees@example.test', 'first_name' => 'Ana', 'last_name' => 'Pop', 'phone' => '0700000000',
            'address' => 'Strada Florilor 10', 'city' => 'Cluj-Napoca', 'county' => 'Cluj', 'postal_code' => '',
            'shipping_method_id' => $shipping, 'payment_method' => 'cod', 'idempotency_key' => bin2hex(random_bytes(20)), 'terms' => '1',
        ]);
        $this->fees()->delete($feeId, $this->adminId);
        $orders = new OrderService($this->pdo);
        $order = $orders->byPublicToken($placed['token']);
        self::assertSame('Ambalare cadou', $order['payment_fees'][0]['name']);
        self::assertSame(550, $order['payment_fees'][0]['amount_bani']);
        $tracking = new OrderTrackingService(new OrderTrackingRepository($this->pdo), $orders, $config, $this->root);
        $emailOrder = $tracking->details($placed['id']);
        self::assertSame($order['payment_fees'], $emailOrder['payment_fees']);
        $email = (new OrderEmailViewData($config, new OrderEmailImages($config, $this->root)))->forOrder($emailOrder);
        self::assertContains(['label' => 'Ambalare cadou', 'value' => '5,50 Lei', 'saving' => false], $email['totals']);
        $_SESSION['tracked_order'] = ['id' => $placed['id'], 'expires' => time() + 1800];
        foreach (['/comanda/' . $placed['token'] . '/', '/urmarire-comanda/'] as $path) {
            $response = $this->request('GET', $path);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Ambalare cadou', (string) $response->getBody());
            self::assertStringContainsString('5,50 Lei', (string) $response->getBody());
        }
        $_SESSION['user_id'] = $this->adminId;
        $response = $this->request('GET', '/admin/comenzi/' . $placed['id'] . '/');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Ambalare cadou', (string) $response->getBody());
        self::assertStringContainsString('5,50 Lei', (string) $response->getBody());
    }

    private function fees(): PaymentFeeService { return new PaymentFeeService($this->pdo); }

    /** @return array<string,mixed> */
    private function data(string $name = 'Ambalare cadou', string $price = '5,50'): array
    {
        return ['_csrf' => $_SESSION['_csrf'], 'payment_method' => 'cod', 'name' => $name, 'price' => $price];
    }

    private function cart(): CartService
    {
        $variant = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND v.status='active' AND i.physical_quantity-i.reserved_quantity>4 ORDER BY v.id LIMIT 1")->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($variant, 1);
        return $cart;
    }

    private function document(ResponseInterface $response): DOMXPath
    {
        self::assertSame(200, $response->getStatusCode());
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . (string) $response->getBody(), LIBXML_NOERROR | LIBXML_NOWARNING);
        return new DOMXPath($document);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $data */
    private function request(string $method, string $path, array $data = [], bool $ajax = false): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.96']);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        if ($data !== []) { $request = $request->withParsedBody($data); }
        if ($ajax) { $request = $request->withHeader('X-Requested-With', 'XMLHttpRequest')->withHeader('Accept', 'application/json'); }
        $app = AppFactory::create($this->root);
        try { return $app->handle($request); }
        finally { unset($app); gc_collect_cycles(); }
    }
}
