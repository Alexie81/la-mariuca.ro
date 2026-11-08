<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Payments\StripeCheckoutPayload;
use App\Modules\Payments\StripeCheckoutService;
use App\Shared\Config\Config;
use PDO;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\StripeClientInterface;
use Tests\DatabaseTestCase;

final class StripeHostedCheckoutTest extends DatabaseTestCase
{
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        parent::setUp();
        $this->seed();
        $_ENV['STRIPE_ENABLED'] = 'true';
        $_ENV['STRIPE_MODE'] = 'test';
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_hosted_checkout';
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_hosted_checkout';
        $_ENV['WEB_PUSH_ENABLED'] = 'false';
        $_ENV['MAIL_DELIVERY_MODE'] = 'spool';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_ENV = $this->environment;
    }

    public function testDiscountIsVisibleSeparatelyAndKeepsProductsQuantitiesAndDelivery(): void
    {
        $_ENV['APP_URL'] = 'https://la-mariuca.ro';
        $_ENV['APP_BASE_PATH'] = '/boutique';
        $order = $this->orderData(800, 101, 1900);
        $payload = $this->builder()->build($order, [$this->item(3, 300, '/media/rochita.jpg'), $this->item(2, 500)], 'secret-token');
        self::assertSame(2700, $this->total($payload));
        self::assertSame(101, $this->builder()->coupon($order)['amount_off']);
        self::assertSame($this->builder()->coupon($order)['id'], $payload['discounts'][0]['coupon']);
        self::assertSame(6, array_sum(array_column($payload['line_items'], 'quantity'))); // Five products and delivery.
        self::assertSame('hosted', $payload['ui_mode']);
        self::assertSame('ro', $payload['locale']);
        self::assertSame(['card'], $payload['payment_method_types']);
        self::assertSame(['enabled' => false], $payload['automatic_tax']);
        self::assertArrayNotHasKey('allow_promotion_codes', $payload);
        self::assertSame('https://la-mariuca.ro/boutique/comanda/secret-token/?plata=verificare', $payload['success_url']);
        self::assertSame(['https://la-mariuca.ro/boutique/media/rochita.jpg'], $payload['line_items'][0]['price_data']['product_data']['images']);
        self::assertStringContainsString('SKU: LM-TEST', $payload['line_items'][0]['price_data']['product_data']['description']);
        self::assertArrayNotHasKey('billing_address_collection', $payload);
        self::assertArrayNotHasKey('shipping_address_collection', $payload);
        self::assertArrayNotHasKey('branding_settings', $payload); // Stripe account's chosen design applies.
        self::assertArrayNotHasKey('setup_future_usage', $payload['payment_intent_data']);
    }

    public function testFixedDiscountNeverLosesOneBanOrChangesProductPrices(): void
    {
        foreach ([0, 1, 2, 13, 101, 799, 800] as $discount) {
            $order = $this->orderData(800, $discount, 1900);
            $payload = $this->builder()->build($order, [$this->item(3, 300), $this->item(2, 500)], 'token');
            self::assertSame(2700 - $discount, $this->total($payload) - ($this->builder()->coupon($order)['amount_off'] ?? 0));
            self::assertSame(6, array_sum(array_column($payload['line_items'], 'quantity')));
            foreach ($payload['line_items'] as $line) {
                self::assertGreaterThanOrEqual(0, $line['price_data']['unit_amount']);
                self::assertIsInt($line['price_data']['unit_amount']);
            }
        }
    }

    public function testFreeDeliveryIsVisibleWithoutChargingItsOriginalPriceAgain(): void
    {
        $order = $this->orderData(300, 30, 0) + ['shipping_discount_bani' => 1900];
        $payload = $this->builder()->build($order, [$this->item(3, 300)], 'token');
        self::assertSame(270, $this->total($payload) - $this->builder()->coupon($order)['amount_off']);
        $shipping = $payload['line_items'][array_key_last($payload['line_items'])];
        self::assertSame(0, $shipping['price_data']['unit_amount']);
        self::assertSame(30, $this->builder()->coupon($order)['amount_off']);
        self::assertStringContainsString('19,00 Lei', $shipping['price_data']['product_data']['description']);
        self::assertStringContainsString('gratuită', $shipping['price_data']['product_data']['description']);
    }

    public function testLocalImagesAreNotSentToStripeAndPublicHttpsIsRetained(): void
    {
        foreach (['/media/preview.jpg', 'https://localhost/media/x.jpg', 'https://127.0.0.1/x.jpg', 'https://192.168.1.1/x.jpg', 'http://la-mariuca.ro/x.jpg', 'https://private.test/x.jpg'] as $path) {
            $payload = $this->builder()->build($this->orderData(300, 0, 0), [$this->item(1, 300, $path)], 'token');
            self::assertArrayNotHasKey('images', $payload['line_items'][0]['price_data']['product_data']);
        }
        $payload = $this->builder()->build($this->orderData(300, 0, 0), [$this->item(1, 300, 'https://la-mariuca.ro/media/x.jpg')], 'token');
        self::assertSame(['https://la-mariuca.ro/media/x.jpg'], $payload['line_items'][0]['price_data']['product_data']['images']);
    }

    public function testInconsistentStoredTotalIsRejectedBeforeContactingStripe(): void
    {
        $order = $this->orderData(300, 20, 1900);
        $order['total_bani']++;
        $this->expectException(RuntimeException::class);
        $this->builder()->build($order, [$this->item(1, 300)], 'token');
    }

    public function testExpiredInitiationWindowIsRejected(): void
    {
        $order = $this->orderData(300, 20, 1900);
        $order['created_at'] = gmdate('c', time() - 1900);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Fereastra');
        $this->builder()->build($order, [$this->item(1, 300)], 'token');
    }

    public function testSessionCreationPersistsAssociationAndRetryReusesSessionWithoutEmailsOrPaymentConfirmation(): void
    {
        $order = $this->place();
        $stored = $this->pdo->query('SELECT * FROM orders WHERE id=' . $order['id'])->fetch(PDO::FETCH_ASSOC);
        $client = $this->createMock(StripeClientInterface::class);
        $session = Session::constructFrom([
            'id' => 'cs_test_hosted', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_hosted',
            'status' => 'open', 'payment_status' => 'unpaid', 'livemode' => false,
            'amount_total' => (int) $stored['total_bani'], 'currency' => 'ron', 'metadata' => ['order_id' => (string) $order['id']], 'payment_intent' => null,
        ]);
        $requests = [];
        $client->expects(self::exactly(3))->method('request')->willReturnCallback(function ($method, $path, $params, $options) use (&$requests, $session, $stored) {
            $requests[] = [$method, $path];
            if ($path === '/v1/customers') {
                self::assertSame('hosted@example.test', $params['email']);
                self::assertSame('Ana Test', $params['name']);
                self::assertSame('Strada Test nr. 1', $params['address']['line1']);
                self::assertSame('Cluj-Napoca', $params['shipping']['address']['city']);
                self::assertSame('RO', $params['shipping']['address']['country']);
                return \Stripe\Customer::constructFrom(['id' => 'cus_test_prefilled']);
            }
            if ($method === 'post') {
                self::assertSame('/v1/checkout/sessions', $path);
                self::assertSame((int) $stored['total_bani'], $this->total($params));
                self::assertStringStartsWith('checkout-', $options['idempotency_key']);
                self::assertNotSame('Comanda ' . $stored['order_number'], $params['line_items'][0]['price_data']['product_data']['name']);
                self::assertSame('cus_test_prefilled', $params['customer']);
                self::assertArrayNotHasKey('customer_email', $params);
                self::assertSame('Cluj-Napoca', $params['payment_intent_data']['shipping']['address']['city']);
            }
            return $session;
        });
        $service = new StripeCheckoutService($this->pdo, Config::load($this->root), null, $client);
        self::assertSame($session->url, $service->createSession($order['id'], $order['token'], $order['idempotency_key']));
        self::assertSame('cs_test_hosted', $this->pdo->query('SELECT checkout_session_id FROM payments')->fetchColumn());
        self::assertSame($session->url, $service->createSession($order['id'], $order['token'], $order['idempotency_key']));
        self::assertSame([['post', '/v1/customers'], ['post', '/v1/checkout/sessions'], ['get', '/v1/checkout/sessions/cs_test_hosted']], $requests);
        self::assertSame('pending', $this->pdo->query('SELECT payment_status FROM orders')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
    }

    public function testCancelledOrderCannotStartACharge(): void
    {
        $order = $this->place();
        $this->pdo->exec("UPDATE orders SET status='cancelled'");
        $client = $this->createMock(StripeClientInterface::class);
        $client->expects(self::never())->method('request');
        $this->expectException(RuntimeException::class);
        (new StripeCheckoutService($this->pdo, Config::load($this->root), null, $client))->createSession($order['id'], $order['token'], $order['idempotency_key']);
    }

    public function testOrderSpecificCouponOnlyReducesMerchandiseWhileShippingIsAlreadyNet(): void
    {
        $order = $this->place();
        $this->pdo->exec("UPDATE orders SET discount_bani=101,shipping_discount_bani=shipping_bani,total_bani=total_bani-shipping_bani-101,shipping_bani=0");
        $this->pdo->exec('UPDATE payments SET amount_bani=(SELECT total_bani FROM orders WHERE orders.id=payments.order_id)');
        $stored = $this->pdo->query('SELECT * FROM orders WHERE id=' . $order['id'])->fetch(PDO::FETCH_ASSOC);
        $client = $this->createMock(StripeClientInterface::class);
        $coupon = $this->builder()->coupon($stored);
        $client->expects(self::exactly(4))->method('request')->willReturnCallback(function ($method, $path, $params, $options) use ($coupon, $stored) {
            self::assertSame('post', $method);
            if ($path === '/v1/customers') {
                return \Stripe\Customer::constructFrom(['id' => 'cus_test_discount']);
            }
            if ($path === '/v1/products') {
                self::assertSame('merchandise', $params['metadata']['line_type']);
                self::assertStringStartsWith('lm_checkout_', $params['id']);
                return \Stripe\Product::constructFrom($params);
            }
            if ($path === '/v1/coupons') {
                self::assertSame(101, $params['amount_off']);
                self::assertSame('ron', $params['currency']);
                self::assertSame('once', $params['duration']);
                self::assertCount(1, $params['applies_to']['products']);
                self::assertStringStartsWith('lm_checkout_', $params['applies_to']['products'][0]);
                self::assertStringStartsWith('checkout-discount-', $options['idempotency_key']);
                return \Stripe\Coupon::constructFrom($params);
            }
            self::assertSame('/v1/checkout/sessions', $path);
            self::assertSame([['coupon' => $coupon['id']]], $params['discounts']);
            self::assertSame((int) $stored['total_bani'], $this->total($params) - $coupon['amount_off']);
            return Session::constructFrom(['id' => 'cs_test_discount', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_discount',
                'status' => 'open', 'payment_status' => 'unpaid', 'livemode' => false, 'amount_total' => (int) $stored['total_bani'],
                'currency' => 'ron', 'metadata' => ['order_id' => (string) $stored['id']], 'payment_intent' => null]);
        });
        (new StripeCheckoutService($this->pdo, Config::load($this->root), null, $client))->createSession($order['id'], $order['token'], $order['idempotency_key']);
        self::assertSame('cs_test_discount', $this->pdo->query('SELECT checkout_session_id FROM payments')->fetchColumn());
    }

    private function builder(): StripeCheckoutPayload { return new StripeCheckoutPayload(Config::load($this->root)); }

    private function orderData(int $subtotal, int $discount, int $shipping): array
    {
        return ['id' => 12, 'order_number' => 'LM-TEST-12', 'email' => 'test@example.test', 'subtotal_bani' => $subtotal,
            'discount_bani' => $discount, 'shipping_bani' => $shipping, 'total_bani' => $subtotal - $discount + $shipping,
            'currency' => 'RON', 'shipping_method_name' => 'Curier standard', 'created_at' => gmdate('c'), 'idempotency_key' => 'test-order-key'];
    }

    private function item(int $quantity, int $total, ?string $image = null): array
    {
        return ['product_name' => 'Rochiță Ana', 'sku' => 'LM-TEST', 'variant_summary' => '2 ani', 'quantity' => $quantity, 'line_total_bani' => $total, 'image_path' => $image];
    }

    private function total(array $payload): int
    {
        return array_sum(array_map(static fn (array $line): int => $line['quantity'] * $line['price_data']['unit_amount'], $payload['line_items']));
    }

    private function place(): array
    {
        $variant = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND v.status='active' AND i.physical_quantity-i.reserved_quantity>4 ORDER BY v.id LIMIT 1")->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($variant, 2);
        return (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place([
            'idempotency_key' => bin2hex(random_bytes(32)), 'email' => 'hosted@example.test', 'first_name' => 'Ana', 'last_name' => 'Test',
            'phone' => '0712345678', 'address' => 'Strada Test nr. 1', 'city' => 'Cluj-Napoca', 'county' => 'Cluj', 'postal_code' => '', 'terms' => '1', 'payment_method' => 'stripe',
            'shipping_method_id' => (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn(),
        ]);
    }
}
