<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Payments\CategoryPaymentPolicy;
use App\Modules\Payments\PaymentMethodSettingsService;
use App\Modules\Payments\StripeCheckoutService;
use App\Modules\Payments\StripeWebhookController;
use App\Shared\Config\Config;
use App\Shared\Security\Csrf;
use DomainException;
use DOMDocument;
use DOMXPath;
use PDO;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\DatabaseTestCase;

final class PaymentMethodSettingsTest extends DatabaseTestCase
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
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_private_payment_settings';
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_payment_settings';
        $_ENV['WEB_PUSH_ENABLED'] = 'false';
        $_ENV['MAIL_DELIVERY_MODE'] = 'preview';
        $this->pdo->exec("INSERT INTO users (email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES ('payment.admin@example.test','unused','Ana','Admin','administrator','active','2026-11-08T10:00:00+00:00','2026-11-08T10:00:00+00:00')");
        $this->adminId = (int) $this->pdo->lastInsertId();
        (new Csrf())->token();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_ENV = $this->environment;
    }

    public function testEnvironmentDefaultsArePreservedUntilAdminSavesAndNoSecretsAreReturned(): void
    {
        $_ENV['STRIPE_ENABLED'] = 'false';
        self::assertSame(['cod' => true, 'stripe' => false], $this->settings()->available());
        self::assertNull($this->settings()->settings()['updated_at']);
        $this->settings()->save(false, true, $this->adminId);
        self::assertSame(['cod' => false, 'stripe' => true], $this->settings()->available());
        self::assertNotNull($this->settings()->settings()['updated_at']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='payment_methods.settings_updated'")->fetchColumn());
        self::assertStringNotContainsString('sk_test', json_encode($this->settings()->settings(), JSON_THROW_ON_ERROR));
        $_ENV['STRIPE_SECRET_KEY'] = '';
        self::assertTrue($this->settings()->settings()['stripe_enabled']);
        self::assertFalse($this->settings()->settings()['stripe_configured']);
        self::assertSame(['cod' => false, 'stripe' => false], $this->settings()->available());
    }

    public function testSettingsRequireAdminAndCsrfAndRejectMalformedValues(): void
    {
        self::assertSame(303, $this->request('GET', '/admin/metode-plata/')->getStatusCode());
        self::assertSame(303, $this->request('POST', '/admin/metode-plata/', ['_csrf' => $_SESSION['_csrf']])->getStatusCode());
        $_SESSION['user_id'] = $this->adminId;
        self::assertSame(419, $this->request('POST', '/admin/metode-plata/', ['cod_enabled' => '0'])->getStatusCode());
        self::assertSame(422, $this->request('POST', '/admin/metode-plata/', ['_csrf' => $_SESSION['_csrf'], 'cod_enabled' => ['1']], true)->getStatusCode());
        self::assertSame(['cod' => true, 'stripe' => true], $this->settings()->available());
        $this->pdo->exec("UPDATE users SET role='customer' WHERE id=" . $this->adminId);
        self::assertSame(303, $this->request('GET', '/admin/metode-plata/')->getStatusCode());
        $this->expectException(DomainException::class);
        $this->settings()->save(false, false, $this->adminId);
    }

    public function testAjaxAndNoJavascriptFormSaveBothSwitchesAndAllowBothOff(): void
    {
        $_SESSION['user_id'] = $this->adminId;
        $page = $this->request('GET', '/admin/metode-plata/');
        self::assertSame(200, $page->getStatusCode());
        self::assertSame('no-store', $page->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('name="cod_enabled"', (string) $page->getBody());
        self::assertStringContainsString('name="stripe_enabled"', (string) $page->getBody());
        self::assertStringNotContainsString('sk_test_private', (string) $page->getBody());
        $response = $this->request('POST', '/admin/metode-plata/', ['_csrf' => $_SESSION['_csrf'], 'stripe_enabled' => '1'], true);
        self::assertSame(200, $response->getStatusCode());
        $json = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($json['ok']);
        self::assertFalse($json['payment_methods']['cod_enabled']);
        self::assertTrue($json['payment_methods']['stripe_enabled']);
        $response = $this->request('POST', '/admin/metode-plata/', ['_csrf' => $_SESSION['_csrf']]);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/metode-plata/', $response->getHeaderLine('Location'));
        self::assertSame(['cod' => false, 'stripe' => false], $this->settings()->available());
    }

    #[DataProviderExternal(CategoryPaymentPolicyTest::class, 'combinations')]
    public function testPersistedGlobalSettingsPreserveCategoryRulesAndSymmetricFallback(array $rules, bool $stripeEnabled, bool $codEnabled, bool $stripeAvailable, bool $codAvailable, ?string $preferred, bool $fallback, bool $fallbackToStripe): void
    {
        $products = $this->pdo->query('SELECT id,name,slug FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $items = [];
        foreach ($rules as $index => $rule) {
            [$card, $cod] = match ($rule) { 'card' => [1, 0], 'cod' => [0, 1], 'invalid' => [0, 0], default => [1, 1] };
            $this->pdo->prepare("INSERT INTO categories (name,slug,status,allow_card,allow_cod,created_at,updated_at) VALUES (?,?,'published',?,?,?,?)")
                ->execute(['Settings ' . $index, 'settings-' . $index, $card, $cod, gmdate('c'), gmdate('c')]);
            $categoryId = (int) $this->pdo->lastInsertId();
            $product = $products[$index];
            $this->pdo->prepare('DELETE FROM product_categories WHERE product_id=?')->execute([$product['id']]);
            $this->pdo->prepare('UPDATE products SET primary_category_id=? WHERE id=?')->execute([$categoryId, $product['id']]);
            $items[] = $product + ['product_id' => $product['id']];
        }
        // Environment intentionally contradicts the saved flags.
        $_ENV['STRIPE_ENABLED'] = $stripeEnabled ? 'false' : 'true';
        $_ENV['COD_ENABLED'] = $codEnabled ? 'false' : 'true';
        $this->settings()->save($codEnabled, $stripeEnabled, $this->adminId);
        $resolved = (new CategoryPaymentPolicy($this->pdo, Config::load($this->root)))->resolve($items);
        self::assertSame($stripeEnabled, $resolved['methods']['stripe']['globally_available']);
        self::assertSame($codEnabled, $resolved['methods']['cod']['globally_available']);
        self::assertSame($stripeAvailable, $resolved['methods']['stripe']['available']);
        self::assertSame($codAvailable, $resolved['methods']['cod']['available']);
        self::assertSame($preferred, $resolved['preferred']);
        self::assertSame($fallback, $resolved['fallback_to_cod']);
        self::assertSame($fallbackToStripe, $resolved['fallback_to_stripe']);
    }

    public function testGlobalChangeAfterCheckoutGetHidesMethodAndRejectsTamperedPostWithoutOrderSideEffects(): void
    {
        $this->cart();
        self::assertSame(2, $this->checkoutDocument()->query('//input[@name="payment_method"]')->length);
        $before = $this->pdo->query('SELECT * FROM inventory ORDER BY variant_id')->fetchAll(PDO::FETCH_ASSOC);
        $this->settings()->save(false, true, $this->adminId);
        $page = $this->checkoutDocument();
        self::assertSame(0, $page->query('//input[@name="payment_method" and @value="cod"]')->length);
        self::assertSame(1, $page->query('//input[@name="payment_method" and @value="stripe" and @checked]')->length);
        $response = $this->request('POST', '/finalizare-comanda/', $this->checkoutData('cod') + ['_csrf' => $_SESSION['_csrf']]);
        self::assertSame('/finalizare-comanda/', $response->getHeaderLine('Location'));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
        self::assertSame($before, $this->pdo->query('SELECT * FROM inventory ORDER BY variant_id')->fetchAll(PDO::FETCH_ASSOC));
        $this->settings()->save(false, false, $this->adminId);
        self::assertSame(0, $this->checkoutDocument()->query('//input[@name="payment_method"]')->length);
        $response = $this->request('POST', '/finalizare-comanda/', $this->checkoutData('stripe') + ['_csrf' => $_SESSION['_csrf']]);
        self::assertSame('/finalizare-comanda/', $response->getHeaderLine('Location'));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    }

    public function testCardSessionCannotStartAfterGlobalSwitchOff(): void
    {
        $this->settings()->save(true, false, $this->adminId);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nu este disponibilă');
        (new StripeCheckoutService($this->pdo, Config::load($this->root)))->createSession(123, 'not-used', 'not-used');
    }

    public function testAdminCanEnableConfiguredCardAndStartAttemptDespiteEnvironmentDefaultOff(): void
    {
        $_ENV['STRIPE_ENABLED'] = 'false';
        $this->settings()->save(false, true, $this->adminId);
        $cart = $this->cart();
        $order = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place($this->checkoutData('stripe'));
        self::assertSame('stripe', $order['payment_method']);
        self::assertSame('pending', $this->pdo->query('SELECT status FROM checkout_attempts')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
    }

    public function testSignedAlreadyInitiatedCardPaymentStillConfirmsAfterSwitchOff(): void
    {
        $cart = $this->cart();
        $order = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place($this->checkoutData('stripe'));
        $this->settings()->save(true, false, $this->adminId);
        $_ENV['STRIPE_ENABLED'] = 'false';
        $amount = (int) $this->pdo->query('SELECT total_bani FROM checkout_attempts WHERE id=' . (int) $order['id'])->fetchColumn();
        $this->pdo->prepare("UPDATE checkout_attempts SET checkout_session_id='cs_payment_after_switch_off' WHERE id=:id")
            ->execute(['id' => $order['id']]);
        $payload = json_encode([
            'id' => 'evt_payment_after_switch_off', 'object' => 'event', 'api_version' => '2025-09-30.clover', 'created' => time(),
            'type' => 'checkout.session.completed', 'livemode' => false,
            'data' => ['object' => ['id' => 'cs_payment_after_switch_off', 'object' => 'checkout.session', 'payment_status' => 'paid',
                'amount_total' => $amount, 'currency' => 'ron', 'payment_intent' => 'pi_payment_after_switch_off', 'metadata' => ['checkout_attempt_id' => (string) $order['id'], 'checkout_attempt_key' => $order['idempotency_key']]]],
        ], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/stripe/webhook')
            ->withHeader('Stripe-Signature', 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, 'whsec_payment_settings'))
            ->withBody((new StreamFactory())->createStream($payload));
        $handler = new StripeWebhookController($this->pdo, Config::load($this->root));
        self::assertSame(400, $handler($request->withHeader('Stripe-Signature', 'invalid'))->getStatusCode());
        self::assertSame('pending', $this->pdo->query('SELECT status FROM checkout_attempts')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertSame(200, $handler($request)->getStatusCode());
        self::assertSame('paid', $this->pdo->query('SELECT payment_status FROM orders')->fetchColumn());
        self::assertSame('confirmed', $this->pdo->query('SELECT status FROM orders')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='order_confirmation'")->fetchColumn());
        self::assertSame(200, $handler($request)->getStatusCode());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='order_confirmation'")->fetchColumn());
    }

    private function settings(): PaymentMethodSettingsService { return new PaymentMethodSettingsService($this->pdo, Config::load($this->root)); }

    private function cart(): CartService
    {
        $variant = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND v.status='active' AND i.physical_quantity-i.reserved_quantity>4 ORDER BY v.id LIMIT 1")->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($variant, 1);
        return $cart;
    }

    /** @return array<string,mixed> */
    private function checkoutData(string $method): array
    {
        return ['email' => 'payment-settings@example.test', 'first_name' => 'Ana', 'last_name' => 'Pop', 'phone' => '0700000000',
            'address' => 'Strada Florilor 10', 'city' => 'Cluj-Napoca', 'county' => 'Cluj', 'postal_code' => '',
            'shipping_method_id' => (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn(),
            'payment_method' => $method, 'idempotency_key' => bin2hex(random_bytes(20)), 'terms' => '1'];
    }

    private function checkoutDocument(): DOMXPath
    {
        $response = $this->request('GET', '/finalizare-comanda/');
        self::assertSame(200, $response->getStatusCode());
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . (string) $response->getBody(), LIBXML_NOERROR | LIBXML_NOWARNING);
        return new DOMXPath($document);
    }

    /** @param array<string,mixed> $data */
    private function request(string $method, string $path, array $data = [], bool $ajax = false): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.97']);
        if ($data !== []) { $request = $request->withParsedBody($data); }
        if ($ajax) { $request = $request->withHeader('Accept', 'application/json'); }
        $app = AppFactory::create($this->root);
        try { return $app->handle($request); }
        finally { unset($app); gc_collect_cycles(); }
    }
}
