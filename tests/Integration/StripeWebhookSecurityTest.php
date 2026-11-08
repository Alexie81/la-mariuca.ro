<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Payments\StripeWebhookController;
use App\Shared\Config\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\DatabaseTestCase;

final class StripeWebhookSecurityTest extends DatabaseTestCase
{
    private array $originalEnv;
    private int $orderId;
    private int $total;

    protected function setUp(): void
    {
        $this->originalEnv = $_ENV;
        parent::setUp();
        $this->seed();
        $_ENV['STRIPE_MODE'] = 'test';
        $_ENV['STRIPE_ENABLED'] = 'true';
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_webhook_security';
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_webhook_security';
        $_ENV['WEB_PUSH_ENABLED'] = 'false';
        $variant = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND v.status='active' AND i.physical_quantity-i.reserved_quantity>2 ORDER BY v.id LIMIT 1")->fetchColumn();
        $shipping = (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($variant, 1);
        $order = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place([
            'email' => 'stripe.security@example.test', 'first_name' => 'Test', 'last_name' => 'Stripe', 'phone' => '0700000000',
            'address' => 'Strada Test 1', 'city' => 'București — Sector 1', 'county' => 'București', 'postal_code' => '',
            'shipping_method_id' => $shipping, 'payment_method' => 'stripe', 'idempotency_key' => bin2hex(random_bytes(20)), 'terms' => '1',
        ]);
        $this->orderId = $order['id'];
        $this->total = (int) $this->pdo->query('SELECT total_bani FROM orders WHERE id=' . $this->orderId)->fetchColumn();
        $this->pdo->prepare("UPDATE payments SET checkout_session_id='cs_security' WHERE order_id=:id")->execute(['id' => $this->orderId]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_ENV = $this->originalEnv;
    }

    public function testWrongSessionCannotConfirmOrCancelAnOrderWithMatchingAmount(): void
    {
        foreach (['checkout.session.completed', 'checkout.session.expired', 'checkout.session.async_payment_failed'] as $index => $type) {
            self::assertSame(200, $this->send('evt_wrong_session_' . $index, $type, ['id' => 'cs_other']));
        }
        $this->assertPending();
        self::assertSame(3, (int) $this->pdo->query("SELECT COUNT(*) FROM payment_events WHERE status='failed'")->fetchColumn());
    }

    public function testModeMismatchAndInvalidSignatureAreRejectedBeforeAnyPersistence(): void
    {
        self::assertSame(400, $this->send('evt_live', live: true));
        self::assertSame(400, $this->send('evt_signature', validSignature: false));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM payment_events')->fetchColumn());
        $this->assertPending();
    }

    public function testSessionPersistenceRaceCanRetryTheSameEvent(): void
    {
        $this->pdo->exec('UPDATE payments SET checkout_session_id=NULL');
        self::assertSame(500, $this->send('evt_race'));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM payment_events')->fetchColumn());
        $this->assertPending();
        $this->pdo->exec("UPDATE payments SET checkout_session_id='cs_security'");
        self::assertSame(200, $this->send('evt_race'));
        self::assertSame('paid', $this->pdo->query('SELECT payment_status FROM orders')->fetchColumn());
        self::assertSame('cs_security', $this->pdo->query('SELECT checkout_session_id FROM payments')->fetchColumn());
        self::assertSame('pi_security', $this->pdo->query('SELECT provider_payment_id FROM payments')->fetchColumn());
        self::assertSame(200, $this->send('evt_race'));
        self::assertSame(200, $this->send('evt_distinct'));
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='order_confirmation'")->fetchColumn());
    }

    public function testCurrencyAmountAndPaymentIntentMustMatchPersistedPayment(): void
    {
        self::assertSame(200, $this->send('evt_wrong_currency', changes: ['currency' => 'eur']));
        self::assertSame(200, $this->send('evt_wrong_amount', changes: ['amount_total' => $this->total + 1]));
        $this->pdo->exec("UPDATE payments SET provider_payment_id='pi_original'");
        self::assertSame(200, $this->send('evt_wrong_intent'));
        $this->assertPending();
        self::assertSame(3, (int) $this->pdo->query("SELECT COUNT(*) FROM payment_events WHERE status='failed'")->fetchColumn());
    }

    #[DataProvider('settledStatuses')]
    public function testLateEventsNeverEraseSettledOrRefundedPayment(string $status): void
    {
        $this->pdo->prepare("UPDATE orders SET status='returned',payment_status=:status")->execute(['status' => $status]);
        $this->pdo->prepare("UPDATE payments SET provider_payment_id='pi_security',status=:status,refunded_bani=:refund")
            ->execute(['status' => $status, 'refund' => $status === 'paid' ? 0 : 100]);
        self::assertSame(200, $this->send('evt_late_success'));
        self::assertSame(200, $this->send('evt_late_expired', 'checkout.session.expired'));
        self::assertSame(200, $this->send('evt_late_cancelled', 'payment_intent.canceled', ['id' => 'pi_security', 'object' => 'payment_intent']));
        self::assertSame($status, $this->pdo->query('SELECT payment_status FROM orders')->fetchColumn());
        self::assertSame($status, $this->pdo->query('SELECT status FROM payments')->fetchColumn());
        self::assertSame('returned', $this->pdo->query('SELECT status FROM orders')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
    }

    public static function settledStatuses(): array
    {
        return [['paid'], ['partially_refunded'], ['refunded']];
    }

    public function testPaidAfterExpiryEntersManualReviewWithoutReacquiringReleasedStock(): void
    {
        self::assertSame(200, $this->send('evt_expire', 'checkout.session.expired'));
        self::assertSame('cancelled', $this->pdo->query('SELECT status FROM orders')->fetchColumn());
        self::assertSame('released', $this->pdo->query('SELECT status FROM inventory_reservations')->fetchColumn());
        self::assertSame(200, $this->send('evt_paid_late'));
        self::assertSame('manual_review', $this->pdo->query('SELECT status FROM orders')->fetchColumn());
        self::assertSame('paid', $this->pdo->query('SELECT payment_status FROM orders')->fetchColumn());
        self::assertSame('released', $this->pdo->query('SELECT status FROM inventory_reservations')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT SUM(reserved_quantity) FROM inventory')->fetchColumn());
    }

    public function testUnboundPaymentIntentCannotCancelAnotherOrder(): void
    {
        self::assertSame(200, $this->send('evt_unbound_intent', 'payment_intent.canceled', ['id' => 'pi_unknown', 'object' => 'payment_intent']));
        $this->assertPending();
        $this->pdo->exec("UPDATE payments SET provider_payment_id='pi_security'");
        self::assertSame(200, $this->send('evt_bound_intent', 'payment_intent.canceled', ['id' => 'pi_security', 'object' => 'payment_intent']));
        self::assertSame('cancelled', $this->pdo->query('SELECT status FROM orders')->fetchColumn());
    }

    private function assertPending(): void
    {
        self::assertSame('new', $this->pdo->query('SELECT status FROM orders')->fetchColumn());
        self::assertSame('pending', $this->pdo->query('SELECT payment_status FROM orders')->fetchColumn());
        self::assertSame('active', $this->pdo->query('SELECT status FROM inventory_reservations')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
    }

    private function send(string $id, string $type = 'checkout.session.completed', array $changes = [], bool $live = false, bool $validSignature = true): int
    {
        $payload = json_encode([
            'id' => $id, 'object' => 'event', 'created' => time(), 'type' => $type, 'livemode' => $live,
            'data' => ['object' => array_replace([
                'id' => 'cs_security', 'object' => 'checkout.session', 'payment_status' => 'paid',
                'amount_total' => $this->total, 'currency' => 'ron', 'payment_intent' => 'pi_security',
                'metadata' => ['order_id' => (string) $this->orderId],
            ], $changes)],
        ], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/stripe/webhook')
            ->withHeader('Stripe-Signature', 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $validSignature ? 'whsec_webhook_security' : 'wrong'))
            ->withBody((new StreamFactory())->createStream($payload));
        return (new StripeWebhookController($this->pdo, Config::load($this->root)))($request)->getStatusCode();
    }
}
