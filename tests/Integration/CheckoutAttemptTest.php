<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutAttemptService;
use App\Modules\Checkout\CheckoutService;
use App\Shared\Config\Config;
use RuntimeException;
use Tests\DatabaseTestCase;

final class CheckoutAttemptTest extends DatabaseTestCase
{
    private array $environment;
    private CartService $cart;
    private Config $config;
    private int $variant;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        parent::setUp();
        $this->seed();
        $_ENV['STRIPE_ENABLED'] = 'true';
        $_ENV['STRIPE_MODE'] = 'test';
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_attempt_fixture';
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_attempt_fixture';
        $_ENV['COD_ENABLED'] = 'true';
        $_ENV['WEB_PUSH_ENABLED'] = 'false';
        $this->config = Config::load($this->root);
        $this->cart = new CartService($this->pdo);
        $this->variant = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND v.status='active' AND i.physical_quantity>4 ORDER BY v.id LIMIT 1")->fetchColumn();
        $this->cart->add($this->variant, 2);
        $this->pdo->exec("UPDATE order_notification_settings SET recipients_json='[\"internal-a@example.test\",\"internal-b@example.test\"]' WHERE id=1");
    }

    protected function tearDown(): void { unset($this->cart); parent::tearDown(); $_ENV = $this->environment; }

    public function testPendingAttemptHasNoOrderPaymentOrMailAndRetryReusesHolds(): void
    {
        $result = $this->place();
        $attempts = $this->attempts();
        $attempt = $attempts->byId($result['attempt_id']);
        self::assertSame('', $result['order_number']);
        self::assertSame('pending', $attempt['status']);
        self::assertSame(0, $this->rowCount('orders'));
        self::assertSame(0, $this->rowCount('payments'));
        self::assertSame(0, $this->rowCount('outbox_messages'));
        self::assertSame(2, $this->reserved());
        self::assertSame($result['token'], $attempts->publicToken($attempt));
        self::assertSame($attempt, $attempts->byToken($result['token']));
        self::assertNull($attempts->byToken(str_repeat('f', 64)));
        self::assertSame($result, $this->place());
        self::assertSame(1, $this->rowCount('checkout_attempts'));
        self::assertSame(2, $this->reserved());
        self::assertSame('active', $this->pdo->query('SELECT status FROM carts ORDER BY id DESC LIMIT 1')->fetchColumn());
        self::assertCount(1, $this->cart->summary()['items']);
    }

    public function testCompletionCreatesOnePaidOrderWithFrozenSnapshotAndExactlyOneNotificationSet(): void
    {
        $result = $this->place();
        $before = $this->attempts()->snapshot($this->attempts()->byId($result['id']));
        $this->pdo->exec('UPDATE product_variants SET price_bani=1 WHERE id=' . $this->variant);
        $this->associate($result['id'], 'cs_test_complete');
        $this->pdo->beginTransaction();
        $orderId = $this->attempts()->complete($result['id'], 'pi_test_complete', 'cs_test_complete');
        self::assertSame($orderId, $this->attempts()->complete($result['id'], 'pi_test_complete', 'cs_test_complete'));
        $this->pdo->commit();
        $order = $this->pdo->query('SELECT * FROM orders WHERE id=' . $orderId)->fetch();
        self::assertSame('confirmed', $order['status']);
        self::assertSame('paid', $order['payment_status']);
        self::assertSame($before['total_bani'], (int) $order['total_bani']);
        self::assertSame($before['items'][0]['unit_price_bani'], (int) $this->pdo->query('SELECT unit_price_bani FROM order_items LIMIT 1')->fetchColumn());
        self::assertSame(1, $this->rowCount('orders'));
        self::assertSame(1, $this->rowCount('payments'));
        self::assertSame(3, $this->rowCount('outbox_messages'));
        self::assertSame(2, $this->reserved());
        self::assertSame('transferred', $this->pdo->query('SELECT status FROM checkout_attempt_holds')->fetchColumn());
        self::assertSame(1, $this->rowCount('inventory_reservations'));
        self::assertNull($this->attempts()->byId($result['id'])['active_cart_id']);
        self::assertSame([], $this->cart->summary()['items']);
    }

    public function testTerminalReleasePreservesCartAndAllowsNewAttemptWithoutDuplicateReservation(): void
    {
        $first = $this->place();
        $token = $_SESSION['cart_token'];
        $this->pdo->beginTransaction();
        $this->attempts()->release($first['id'], 'expired');
        $this->attempts()->release($first['id'], 'expired');
        $this->pdo->commit();
        self::assertSame(0, $this->reserved());
        self::assertSame(0, $this->rowCount('orders'));
        self::assertSame($token, $_SESSION['cart_token']);
        self::assertCount(1, $this->cart->summary()['items']);
        $this->cart->update((int) $this->cart->summary()['items'][0]['id'], 1);
        $second = $this->place();
        self::assertNotSame($first['id'], $second['id']);
        self::assertSame(1, $this->reserved());
    }

    public function testUnknownSessionFailureKeepsAttemptStockAndCart(): void
    {
        $result = $this->place();
        $this->attempts()->markSessionFailure($result['id']);
        $attempt = $this->attempts()->byId($result['id']);
        self::assertSame('pending', $attempt['status']);
        self::assertNotEmpty($attempt['last_error']);
        self::assertSame('failed', $this->attempts()->snapshot($attempt)['latest_payment_status']);
        self::assertSame(2, $this->reserved());
        self::assertSame(0, $this->rowCount('orders'));
        self::assertSame($result, $this->place());
    }

    public function testPendingCartCannotBeEditedOrSwitchedToCod(): void
    {
        $this->place();
        $itemId = (int) $this->cart->summary()['items'][0]['id'];
        foreach ([fn () => $this->cart->add($this->variant, 1), fn () => $this->cart->toggle($this->variant),
            fn () => $this->cart->update($itemId, 0), fn () => $this->cart->applyCoupon(''), fn () => $this->place('cod')] as $change) {
            try { $change(); self::fail('A pending checkout was changed.'); }
            catch (RuntimeException $error) { self::assertStringContainsString('plat', $error->getMessage()); }
        }
        self::assertSame(2, $this->cart->summary()['items'][0]['quantity']);
    }

    public function testLatePaymentWithoutStockCreatesPaidManualReviewInsteadOfOverselling(): void
    {
        $result = $this->place();
        $this->associate($result['id'], 'cs_test_late');
        $this->pdo->beginTransaction();
        $this->attempts()->release($result['id'], 'expired');
        $this->pdo->commit();
        $this->pdo->exec('UPDATE inventory SET physical_quantity=0 WHERE variant_id=' . $this->variant);
        $this->pdo->beginTransaction();
        $orderId = $this->attempts()->complete($result['id'], 'pi_test_late', 'cs_test_late');
        $this->pdo->commit();
        $order = $this->pdo->query('SELECT status,payment_status FROM orders WHERE id=' . $orderId)->fetch();
        self::assertSame('manual_review', $order['status']);
        self::assertSame('paid', $order['payment_status']);
        self::assertSame(0, $this->reserved());
        self::assertSame(0, $this->rowCount('inventory_reservations'));
        self::assertSame(3, $this->rowCount('outbox_messages'));
    }

    public function testTwoLateSuccessfulAttemptsForSameCartRemainTraceable(): void
    {
        $first = $this->place();
        $this->associate($first['id'], 'cs_test_old');
        $this->pdo->beginTransaction();
        $this->attempts()->release($first['id'], 'expired');
        $this->pdo->commit();
        $second = $this->place();
        $this->associate($second['id'], 'cs_test_new');
        $this->pdo->beginTransaction();
        $this->attempts()->complete($second['id'], 'pi_test_new', 'cs_test_new');
        $this->attempts()->complete($first['id'], 'pi_test_old', 'cs_test_old');
        $this->pdo->commit();
        self::assertSame(2, $this->rowCount('orders'));
        self::assertSame(2, $this->rowCount('payments'));
        self::assertSame(['confirmed','manual_review'], $this->pdo->query('SELECT status FROM orders ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(4, $this->reserved());
    }

    public function testCodStillCreatesOrderImmediately(): void
    {
        $result = $this->place('cod');
        self::assertArrayNotHasKey('attempt_id', $result);
        self::assertSame(0, $this->rowCount('checkout_attempts'));
        self::assertSame(1, $this->rowCount('orders'));
        self::assertSame('new', $this->pdo->query('SELECT status FROM orders')->fetchColumn());
        self::assertSame(3, $this->rowCount('outbox_messages'));
    }

    public function testCouponReservationBelongsToAttemptUntilSuccessfulCompletion(): void
    {
        $this->pdo->prepare("INSERT INTO coupons (code,type,value,minimum_bani,usage_limit,per_customer_limit,status,created_at) VALUES ('ATTEMPT10','percent',1000,0,1,1,'active',:now)")->execute(['now' => gmdate('c')]);
        $this->cart->applyCoupon('ATTEMPT10');
        $result = $this->place();
        $reservation = $this->pdo->query('SELECT * FROM coupon_redemptions')->fetch();
        self::assertNull($reservation['order_id']);
        self::assertSame($result['id'], (int) $reservation['attempt_id']);
        self::assertSame('reserved', $reservation['status']);
        self::assertGreaterThan(0, (int) $reservation['merchandise_discount_bani']);
        $this->associate($result['id'], 'cs_test_coupon');
        $this->pdo->beginTransaction();
        $order = $this->attempts()->complete($result['id'], 'pi_test_coupon', 'cs_test_coupon');
        $this->pdo->commit();
        $after = $this->pdo->query('SELECT * FROM coupon_redemptions')->fetch();
        self::assertSame($order, (int) $after['order_id']);
        self::assertSame('confirmed', $after['status']);
        self::assertSame(1, $this->rowCount('coupon_redemptions'));
    }

    public function testPersonalizedLinesAggregateOneHoldAndOneOrderReservation(): void
    {
        $this->cart->add($this->variant, 1, ['gift_note' => 'Ana']);
        $result = $this->place();
        self::assertSame(1, $this->rowCount('checkout_attempt_holds'));
        self::assertSame(3, $this->reserved());
        self::assertSame(3, (int) $this->pdo->query('SELECT quantity FROM checkout_attempt_holds')->fetchColumn());
        $this->associate($result['id'], 'cs_test_personalized');
        $this->pdo->beginTransaction();
        $this->attempts()->complete($result['id'], 'pi_test_personalized', 'cs_test_personalized');
        $this->pdo->commit();
        self::assertSame(2, $this->rowCount('order_items'));
        self::assertSame(1, $this->rowCount('inventory_reservations'));
        self::assertSame(3, (int) $this->pdo->query('SELECT quantity FROM inventory_reservations')->fetchColumn());
    }

    private function attempts(): CheckoutAttemptService { return new CheckoutAttemptService($this->pdo, $this->config); }
    private function reserved(): int { return (int) $this->pdo->query('SELECT reserved_quantity FROM inventory WHERE variant_id=' . $this->variant)->fetchColumn(); }
    private function rowCount(string $table): int { return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(); }
    private function associate(int $id, string $session): void { $this->pdo->prepare('UPDATE checkout_attempts SET checkout_session_id=:session WHERE id=:id')->execute(['session' => $session, 'id' => $id]); }
    private function place(string $method = 'stripe'): array
    {
        return (new CheckoutService($this->pdo, $this->cart, $this->config))->place([
            'idempotency_key' => bin2hex(random_bytes(32)), 'email' => 'attempt@example.test', 'first_name' => 'Ana', 'last_name' => 'Test',
            'phone' => '0712345678', 'address' => 'Strada Test nr. 1', 'city' => 'Cluj-Napoca', 'county' => 'Cluj', 'postal_code' => '',
            'terms' => '1', 'payment_method' => $method,
            'shipping_method_id' => (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn(),
        ]);
    }
}
