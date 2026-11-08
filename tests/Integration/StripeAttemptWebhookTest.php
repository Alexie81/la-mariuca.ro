<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Admin\AdminPushService;
use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Payments\StripeWebhookController;
use App\Shared\Config\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\DatabaseTestCase;

/** Real signed webhook decoding, isolated database and no external network or SMTP. */
final class StripeAttemptWebhookTest extends DatabaseTestCase
{
    private array $environment;
    private array $attempt;
    private int $variantId;
    private int $total;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        parent::setUp();
        $this->seed();
        $_ENV['COD_ENABLED'] = 'true';
        $_ENV['STRIPE_ENABLED'] = 'true';
        $_ENV['STRIPE_MODE'] = 'test';
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_attempt_webhooks';
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_attempt_webhooks';
        $_ENV['WEB_PUSH_ENABLED'] = 'true';
        $_ENV['WEB_PUSH_VAPID_PUBLIC_KEY'] = 'test-public-key';
        $_ENV['WEB_PUSH_VAPID_PRIVATE_KEY'] = 'test-private-key';
        $_ENV['MAIL_DELIVERY_MODE'] = 'preview';
        // No devices exist: push persistence is verified without any network delivery.
        $this->variantId = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND v.status='active' AND i.physical_quantity-i.reserved_quantity>4 ORDER BY v.id LIMIT 1")->fetchColumn();
        $shipping = (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($this->variantId, 2);
        $config = Config::load($this->root);
        $this->attempt = (new CheckoutService($this->pdo, $cart, $config, push: new AdminPushService($this->pdo, $config)))->place([
            'email' => 'attempt@example.test', 'first_name' => 'Ana', 'last_name' => 'Test', 'phone' => '0700000000',
            'address' => 'Strada Test 1', 'city' => 'București — Sector 1', 'county' => 'București', 'postal_code' => '',
            'shipping_method_id' => $shipping, 'payment_method' => 'stripe', 'idempotency_key' => bin2hex(random_bytes(20)), 'terms' => '1',
        ]);
        $this->total = (int) $this->pdo->query('SELECT total_bani FROM checkout_attempts WHERE id=' . $this->attempt['id'])->fetchColumn();
        $this->pdo->prepare("UPDATE checkout_attempts SET checkout_session_id='cs_attempt' WHERE id=:id")->execute(['id' => $this->attempt['id']]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_ENV = $this->environment;
    }

    public function testPendingCheckoutCreatesOnlyAnAttemptAndHolds(): void
    {
        self::assertSame($this->attempt['id'], $this->attempt['attempt_id']);
        $this->assertNoOrderOrNotifications();
        self::assertSame('pending', $this->pdo->query('SELECT status FROM checkout_attempts')->fetchColumn());
        self::assertSame('active', $this->pdo->query('SELECT status FROM checkout_attempt_holds')->fetchColumn());
        self::assertSame(2, $this->reservedQuantity());
    }

    public function testPaidEventCreatesExactlyOneConfirmedOrderAndTransfersExistingStockHold(): void
    {
        $this->assertNoOrderOrNotifications();
        $originalPhysical = (int) $this->pdo->query('SELECT physical_quantity FROM inventory WHERE variant_id=' . $this->variantId)->fetchColumn();
        self::assertSame(200, $this->send('evt_attempt_paid'));
        self::assertSame('completed', $this->pdo->query('SELECT status FROM checkout_attempts')->fetchColumn());
        self::assertSame('confirmed', $this->pdo->query('SELECT status FROM orders')->fetchColumn());
        self::assertSame('paid', $this->pdo->query('SELECT payment_status FROM orders')->fetchColumn());
        self::assertSame($this->total, (int) $this->pdo->query('SELECT total_bani FROM orders')->fetchColumn());
        self::assertSame(hash('sha256', $this->attempt['token']), $this->pdo->query('SELECT public_token_hash FROM orders')->fetchColumn());
        self::assertSame('cs_attempt', $this->pdo->query('SELECT checkout_session_id FROM payments')->fetchColumn());
        self::assertSame('pi_attempt', $this->pdo->query('SELECT provider_payment_id FROM payments')->fetchColumn());
        self::assertSame('transferred', $this->pdo->query('SELECT status FROM checkout_attempt_holds')->fetchColumn());
        self::assertSame('active', $this->pdo->query('SELECT status FROM inventory_reservations')->fetchColumn());
        self::assertSame(2, $this->reservedQuantity());
        self::assertSame($originalPhysical, (int) $this->pdo->query('SELECT physical_quantity FROM inventory WHERE variant_id=' . $this->variantId)->fetchColumn());
        self::assertSame(200, $this->send('evt_attempt_paid'));
        self::assertSame(200, $this->send('evt_attempt_paid_distinct', 'checkout.session.async_payment_succeeded'));
        foreach (['orders', 'order_items', 'payments', 'inventory_reservations'] as $table) {
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(), $table);
        }
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='order_confirmation'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM admin_push_events WHERE event_key LIKE 'order:%'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM admin_push_events WHERE event_key LIKE 'payment:%:paid'")->fetchColumn());
        self::assertSame(2, $this->reservedQuantity());
    }

    public function testUnpaidSessionAndDeclinedCardDoNotCreateOrderAndCanLaterSucceed(): void
    {
        self::assertSame(200, $this->send('evt_unpaid', changes: ['payment_status' => 'unpaid']));
        self::assertSame(200, $this->send('evt_declined', 'payment_intent.payment_failed', ['id' => 'pi_attempt', 'object' => 'payment_intent']));
        $this->assertNoOrderOrNotifications();
        self::assertSame('pending', $this->pdo->query('SELECT status FROM checkout_attempts')->fetchColumn());
        self::assertSame(2, $this->reservedQuantity());
        self::assertSame(200, $this->send('evt_retry_paid'));
        self::assertSame('confirmed', $this->pdo->query('SELECT status FROM orders')->fetchColumn());
    }

    public function testInvalidSignatureOrModeCannotMutateAttempt(): void
    {
        self::assertSame(400, $this->send('evt_signature', validSignature: false));
        self::assertSame(400, $this->send('evt_live', live: true));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM payment_events')->fetchColumn());
        $this->assertNoOrderOrNotifications();
        self::assertSame(2, $this->reservedQuantity());
    }

    public function testSessionAmountCurrencyAndIntentMustMatchTheAttempt(): void
    {
        self::assertSame(200, $this->send('evt_wrong_session', changes: ['id' => 'cs_someone_else']));
        self::assertSame(200, $this->send('evt_wrong_amount', changes: ['amount_total' => $this->total + 1]));
        self::assertSame(200, $this->send('evt_wrong_currency', changes: ['currency' => 'eur']));
        $this->pdo->exec("UPDATE checkout_attempts SET provider_payment_id='pi_original'");
        self::assertSame(200, $this->send('evt_wrong_intent'));
        self::assertSame(4, (int) $this->pdo->query("SELECT COUNT(*) FROM payment_events WHERE status='failed'")->fetchColumn());
        $this->assertNoOrderOrNotifications();
        self::assertSame(2, $this->reservedQuantity());
    }

    public function testSessionPersistenceRaceCanRetryWithoutLosingThePaidEvent(): void
    {
        $this->pdo->exec('UPDATE checkout_attempts SET checkout_session_id=NULL');
        self::assertSame(500, $this->send('evt_session_race'));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM payment_events')->fetchColumn());
        $this->assertNoOrderOrNotifications();
        $this->pdo->exec("UPDATE checkout_attempts SET checkout_session_id='cs_attempt'");
        self::assertSame(200, $this->send('evt_session_race'));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    }

    public function testAttemptIdentifierAloneCannotAuthorizeAnOrderAndAmountMustBeAnInteger(): void
    {
        self::assertSame(200, $this->send('evt_missing_key', changes: ['metadata' => ['checkout_attempt_id' => (string) $this->attempt['id']]]));
        self::assertSame(200, $this->send('evt_wrong_key', changes: ['metadata' => ['checkout_attempt_id' => (string) $this->attempt['id'], 'checkout_attempt_key' => 'other-attempt-key']]));
        self::assertSame(200, $this->send('evt_string_amount', changes: ['amount_total' => (string) $this->total]));
        self::assertSame(3, (int) $this->pdo->query("SELECT COUNT(*) FROM payment_events WHERE status='failed'")->fetchColumn());
        $this->assertNoOrderOrNotifications();
        self::assertSame(2, $this->reservedQuantity());
    }

    public function testOnlyTheBoundPaymentIntentCanCancelAnAttempt(): void
    {
        self::assertSame(200, $this->send('evt_unknown_cancel', 'payment_intent.canceled', ['id' => 'pi_unknown', 'object' => 'payment_intent', 'amount' => $this->total]));
        self::assertSame('active', $this->pdo->query('SELECT status FROM checkout_attempt_holds')->fetchColumn());
        $this->pdo->exec("UPDATE checkout_attempts SET provider_payment_id='pi_attempt'");
        self::assertSame(200, $this->send('evt_bound_cancel', 'payment_intent.canceled', ['id' => 'pi_attempt', 'object' => 'payment_intent', 'amount' => $this->total]));
        $this->assertNoOrderOrNotifications();
        self::assertSame('released', $this->pdo->query('SELECT status FROM checkout_attempt_holds')->fetchColumn());
        self::assertSame(0, $this->reservedQuantity());
    }

    #[DataProvider('terminalEvents')]
    public function testTerminalFailureReleasesHoldsWithoutCreatingAnyOrder(string $type): void
    {
        self::assertSame(200, $this->send('evt_terminal', $type, ['payment_status' => 'unpaid']));
        $this->assertNoOrderOrNotifications();
        self::assertSame(0, $this->reservedQuantity());
        self::assertSame('released', $this->pdo->query('SELECT status FROM checkout_attempt_holds')->fetchColumn());
        self::assertSame(200, $this->send('evt_terminal', $type, ['payment_status' => 'unpaid']));
        self::assertSame(200, $this->send('evt_terminal_distinct', $type, ['payment_status' => 'unpaid']));
        self::assertSame(0, $this->reservedQuantity());
        $this->assertNoOrderOrNotifications();
    }

    public static function terminalEvents(): array
    {
        return [['checkout.session.expired'], ['checkout.session.async_payment_failed']];
    }

    public function testFailedOrderFinalizationRollsBackAndTheSameWebhookCanBeRetried(): void
    {
        $this->pdo->exec("CREATE TRIGGER reject_attempt_confirmation BEFORE INSERT ON outbox_messages WHEN NEW.message_type='order_confirmation' BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        self::assertSame(500, $this->send('evt_queue_retry'));
        $this->assertNoOrderOrNotifications();
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM payment_events')->fetchColumn());
        self::assertSame('pending', $this->pdo->query('SELECT status FROM checkout_attempts')->fetchColumn());
        self::assertSame('active', $this->pdo->query('SELECT status FROM checkout_attempt_holds')->fetchColumn());
        self::assertSame(2, $this->reservedQuantity());
        $this->pdo->exec('DROP TRIGGER reject_attempt_confirmation');
        self::assertSame(200, $this->send('evt_queue_retry'));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    }

    public function testLateTerminalEventsDoNotUndoPaidOrRefundedOrder(): void
    {
        self::assertSame(200, $this->send('evt_paid'));
        $this->pdo->exec("UPDATE orders SET payment_status='refunded',status='returned'");
        $this->pdo->exec('UPDATE payments SET status=\'refunded\',refunded_bani=amount_bani');
        self::assertSame(200, $this->send('evt_late_success'));
        self::assertSame(200, $this->send('evt_late_expiry', 'checkout.session.expired', ['payment_status' => 'unpaid']));
        self::assertSame('refunded', $this->pdo->query('SELECT payment_status FROM orders')->fetchColumn());
        self::assertSame('returned', $this->pdo->query('SELECT status FROM orders')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='order_confirmation'")->fetchColumn());
    }

    private function assertNoOrderOrNotifications(): void
    {
        foreach (['orders', 'order_items', 'payments', 'inventory_reservations', 'outbox_messages', 'order_internal_email_deliveries', 'admin_push_events'] as $table) {
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(), $table);
        }
    }

    private function reservedQuantity(): int
    {
        return (int) $this->pdo->query('SELECT reserved_quantity FROM inventory WHERE variant_id=' . $this->variantId)->fetchColumn();
    }

    private function send(string $id, string $type = 'checkout.session.completed', array $changes = [], bool $live = false, bool $validSignature = true): int
    {
        $payload = json_encode([
            'id' => $id, 'object' => 'event', 'created' => time(), 'type' => $type, 'livemode' => $live,
            'data' => ['object' => array_replace([
                'id' => 'cs_attempt', 'object' => 'checkout.session', 'payment_status' => 'paid',
                'amount_total' => $this->total, 'currency' => 'ron', 'payment_intent' => 'pi_attempt',
                'metadata' => ['checkout_attempt_id' => (string) $this->attempt['id'], 'checkout_attempt_key' => $this->attempt['idempotency_key']],
            ], $changes)],
        ], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/stripe/webhook')
            ->withHeader('Stripe-Signature', 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $validSignature ? 'whsec_attempt_webhooks' : 'wrong'))
            ->withBody((new StreamFactory())->createStream($payload));
        $config = Config::load($this->root);
        return (new StripeWebhookController($this->pdo, $config, new AdminPushService($this->pdo, $config)))($request)->getStatusCode();
    }
}
