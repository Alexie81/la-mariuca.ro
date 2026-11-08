<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Orders\OrderService;
use App\Modules\Payments\StripeWebhookController;
use App\Modules\Promotions\PromotionAdminService;
use App\Shared\Config\Config;
use PDO;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\DatabaseTestCase;

final class OfferUsageReportingTest extends DatabaseTestCase
{
    /** @var array<string,mixed> */
    private array $environment;
    private int $variantId;
    private int $adminId;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        $_ENV['STRIPE_ENABLED'] = 'true';
        $_ENV['COD_ENABLED'] = 'true';
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_offer_reporting';
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_offer_reporting';
        $_ENV['WEB_PUSH_ENABLED'] = 'false';
        $_ENV['MAIL_DELIVERY_MODE'] = 'spool';
        parent::setUp();
        $this->seed();
        $this->variantId = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.status='active' AND p.status='published' ORDER BY v.price_bani DESC LIMIT 1")->fetchColumn();
        $this->pdo->exec('UPDATE product_variants SET stock_unlimited=1 WHERE id=' . $this->variantId);
        $now = gmdate('c');
        $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES ('offer-admin@example.test','unused','Ana','Admin','administrator','active',?,?)")->execute([$now, $now]);
        $this->adminId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_ENV = $this->environment;
    }

    public function testSnapshotMigrationBackfillsOnlyTheCouponsOwnComponents(): void
    {
        $legacy = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $legacy->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY,discount_bani INTEGER,shipping_discount_bani INTEGER,promotion_id INTEGER,customer_discount_id INTEGER,shipping_promotion_id INTEGER,customer_shipping_discount_id INTEGER)');
        $legacy->exec('CREATE TABLE coupon_redemptions (id INTEGER PRIMARY KEY,order_id INTEGER)');
        $legacy->exec('INSERT INTO orders VALUES (1,600,100,5,NULL,NULL,NULL),(2,300,200,NULL,6,7,NULL),(3,900,1900,NULL,NULL,NULL,NULL),(4,800,400,NULL,NULL,NULL,8)');
        $legacy->exec('INSERT INTO coupon_redemptions VALUES (1,1),(2,2),(3,3),(4,4),(5,NULL)');
        $legacy->exec((string) file_get_contents($this->root . '/database/migrations/sqlite/039_coupon_discount_snapshots.sql'));
        self::assertSame([
            ['merchandise_discount_bani' => 0, 'shipping_discount_bani' => 100],
            ['merchandise_discount_bani' => 0, 'shipping_discount_bani' => 0],
            ['merchandise_discount_bani' => 900, 'shipping_discount_bani' => 1900],
            ['merchandise_discount_bani' => 800, 'shipping_discount_bani' => 0],
            ['merchandise_discount_bani' => 0, 'shipping_discount_bani' => 0],
        ], $legacy->query('SELECT merchandise_discount_bani,shipping_discount_bani FROM coupon_redemptions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testAutomaticOffersExcludeInvalidOrdersAndSeparatePendingCardPayments(): void
    {
        $promotion = $this->promotion('order', 'fixed', 1000);
        $shipping = $this->promotion('shipping', 'free', 0);
        $cod = $this->order();
        $pending = $this->order('stripe');
        $paid = $this->order('stripe');
        $this->updateOrder($paid, 'confirmed', 'paid');
        foreach ([['cancelled','paid'], ['returned','paid'], ['delivered','refunded'], ['new','failed']] as [$status, $payment]) {
            $id = $this->order('stripe');
            $this->updateOrder($id, $status, $payment);
        }
        $partial = $this->order('stripe');
        $this->updateOrder($partial, 'delivered', 'partially_refunded');
        $zero = $this->order();
        $this->pdo->exec('UPDATE orders SET discount_bani=0,shipping_discount_bani=0 WHERE id=' . $zero);

        $this->assertMetrics('promotion', $promotion, 3, 1, 3000);
        $shippingAmount = (int) $this->pdo->query('SELECT shipping_discount_bani FROM orders WHERE id=' . $cod)->fetchColumn();
        self::assertGreaterThan(0, $shippingAmount);
        $this->assertMetrics('promotion', $shipping, 3, 1, $shippingAmount * 3);

        $this->pdo->exec("UPDATE promotions SET value=5000,status='inactive' WHERE id=" . $promotion);
        $this->assertMetrics('promotion', $promotion, 3, 1, 3000);
        $this->updateOrder($pending, 'confirmed', 'paid');
        $this->assertMetrics('promotion', $promotion, 4, 0, 4000);
    }

    public function testSameOfferOnProductsAndShippingCountsOneOrderAndSumsBothComponents(): void
    {
        $promotion = $this->promotion('order', 'fixed', 700);
        $id = $this->order();
        $this->pdo->prepare('UPDATE orders SET shipping_promotion_id=?,shipping_discount_bani=1900 WHERE id=?')->execute([$promotion, $id]);
        $this->assertMetrics('promotion', $promotion, 1, 0, 2600);
    }

    public function testCouponSnapshotDoesNotIncludeShippingPromotionAndSurvivesOfferEdits(): void
    {
        $coupon = $this->coupon('MERCH500', 'order', 'fixed', 500);
        $shipping = $this->promotion('shipping', 'free', 0);
        $id = $this->order('cod', 'MERCH500');
        $snapshot = $this->pdo->query('SELECT merchandise_discount_bani,shipping_discount_bani FROM coupon_redemptions WHERE order_id=' . $id)->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['merchandise_discount_bani' => 500, 'shipping_discount_bani' => 0], $snapshot);
        $this->assertMetrics('coupon', $coupon, 1, 0, 500);
        $shippingAmount = (int) $this->pdo->query('SELECT shipping_discount_bani FROM orders WHERE id=' . $id)->fetchColumn();
        $this->assertMetrics('promotion', $shipping, 1, 0, $shippingAmount);
        $this->pdo->exec("UPDATE coupons SET scope='shipping',value_type='free',value=10000 WHERE id=" . $coupon);
        $this->assertMetrics('coupon', $coupon, 1, 0, 500);
        (new OrderService($this->pdo))->transition($id, 'cancel', null, $this->adminId);
        $this->assertMetrics('coupon', $coupon, 0, 0, 0);
        $this->assertMetrics('promotion', $shipping, 0, 0, 0);
        self::assertSame('released', $this->pdo->query('SELECT status FROM coupon_redemptions WHERE order_id=' . $id)->fetchColumn());
    }

    public function testShippingCouponDoesNotTakePersonalProductDiscount(): void
    {
        $coupon = $this->coupon('SHIPFREE', 'shipping', 'free', 0);
        $now = gmdate('c');
        $this->pdo->prepare("INSERT INTO customer_discounts (user_id,scope,value_type,value,status,created_at,updated_at) VALUES (?,'order','fixed',650,'active',?,?)")->execute([$this->adminId, $now, $now]);
        $_SESSION['user_id'] = $this->adminId;
        $id = $this->order('cod', 'SHIPFREE');
        $snapshot = $this->pdo->query('SELECT merchandise_discount_bani,shipping_discount_bani FROM coupon_redemptions WHERE order_id=' . $id)->fetch(PDO::FETCH_ASSOC);
        self::assertSame(0, $snapshot['merchandise_discount_bani']);
        self::assertGreaterThan(0, $snapshot['shipping_discount_bani']);
        self::assertSame(650, (int) $this->pdo->query('SELECT discount_bani FROM orders WHERE id=' . $id)->fetchColumn());
        $this->assertMetrics('coupon', $coupon, 1, 0, $snapshot['shipping_discount_bani']);
    }

    public function testSignedCardEventsMoveCouponPendingToUsedOnceAndFailureReleasesIt(): void
    {
        $coupon = $this->coupon('CARD500', 'order', 'fixed', 500);
        $id = $this->order('stripe', 'CARD500');
        $this->assertMetrics('coupon', $coupon, 0, 1, 0);
        $this->webhook($id, 'checkout.session.completed', 'paid-one');
        $this->webhook($id, 'checkout.session.completed', 'paid-two');
        $this->assertMetrics('coupon', $coupon, 1, 0, 500);
        $expired = $this->order('stripe', 'CARD500');
        $this->assertMetrics('coupon', $coupon, 1, 1, 500);
        $this->webhook($expired, 'checkout.session.expired', 'expired');
        $this->assertMetrics('coupon', $coupon, 1, 0, 500);
        $this->updateOrder($id, 'delivered', 'partially_refunded');
        $this->assertMetrics('coupon', $coupon, 1, 0, 500);
        $this->updateOrder($id, 'returned', 'refunded');
        $this->assertMetrics('coupon', $coupon, 0, 0, 0);
    }

    private function promotion(string $scope, string $type, int $value): int
    {
        $now = gmdate('c');
        $this->pdo->prepare("INSERT INTO promotions (name,scope,value_type,value,status,created_at,updated_at) VALUES (?,?,?,?,'active',?,?)")->execute(['Reporting ' . $scope, $scope, $type, $value, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    private function coupon(string $code, string $scope, string $type, int $value): int
    {
        $this->pdo->prepare("INSERT INTO coupons (code,name,type,scope,value_type,value,minimum_bani,status,created_at) VALUES (?,?,?,?,?,?,0,'active',?)")
            ->execute([$code, $code, $type === 'free' ? 'percent' : $type, $scope, $type, $type === 'free' ? 10000 : $value, gmdate('c')]);
        return (int) $this->pdo->lastInsertId();
    }

    private function order(string $method = 'cod', ?string $code = null): int
    {
        unset($_SESSION['cart_token']);
        $cart = new CartService($this->pdo);
        $cart->add($this->variantId, 1);
        if ($code !== null) { $cart->applyCoupon($code); }
        $shipping = (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        $order = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place([
            'email' => 'offer-client@example.test', 'first_name' => 'Ana', 'last_name' => 'Pop', 'phone' => '0700000000',
            'address' => 'Strada Test 1', 'city' => 'București — Sector 1', 'county' => 'București', 'postal_code' => '010101',
            'shipping_method_id' => (string) $shipping, 'payment_method' => $method, 'idempotency_key' => bin2hex(random_bytes(16)), 'terms' => '1',
        ]);
        return (int) $order['id'];
    }

    private function updateOrder(int $id, string $status, string $payment): void
    {
        $this->pdo->prepare('UPDATE orders SET status=?,payment_status=? WHERE id=?')->execute([$status, $payment, $id]);
    }

    private function assertMetrics(string $type, int $id, int $used, int $pending, int $total): void
    {
        $items = (new PromotionAdminService($this->pdo))->paginate(1, 50)['items'];
        $matches = array_values(array_filter($items, static fn (array $row): bool => $row['offer_type'] === $type && (int) $row['id'] === $id));
        self::assertCount(1, $matches);
        self::assertSame([$used, $pending, $total], [$matches[0]['usage_count'], $matches[0]['pending_usage_count'], $matches[0]['total_discount_bani']]);
    }

    private function webhook(int $id, string $type, string $event): void
    {
        $amount = (int) $this->pdo->query('SELECT total_bani FROM orders WHERE id=' . $id)->fetchColumn();
        $this->pdo->prepare("UPDATE payments SET checkout_session_id=:session WHERE order_id=:id AND provider='stripe'")
            ->execute(['session' => 'cs_reporting_' . $id, 'id' => $id]);
        $payload = json_encode(['id' => 'evt_reporting_' . $event, 'object' => 'event', 'api_version' => '2025-09-30.clover', 'created' => time(), 'type' => $type, 'livemode' => false,
            'data' => ['object' => ['id' => 'cs_reporting_' . $id, 'object' => 'checkout.session', 'payment_status' => $type === 'checkout.session.completed' ? 'paid' : 'unpaid',
                'amount_total' => $amount, 'currency' => 'ron', 'payment_intent' => 'pi_reporting_' . $id, 'metadata' => ['order_id' => (string) $id]]]], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/stripe/webhook')
            ->withHeader('Stripe-Signature', 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, 'whsec_offer_reporting'))
            ->withBody((new StreamFactory())->createStream($payload));
        self::assertSame(200, (new StripeWebhookController($this->pdo, Config::load($this->root)))($request)->getStatusCode());
    }
}
