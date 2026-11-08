<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Admin\AdminPushService;
use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Checkout\CheckoutAttemptService;
use App\Modules\Orders\InternalOrderNotificationService;
use App\Modules\Orders\OrderNotificationSettingsService;
use App\Modules\Payments\StripeWebhookController;
use App\Shared\Config\Config;
use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\DatabaseTestCase;

final class InternalOrderNotificationTest extends DatabaseTestCase
{
    /** @var array<string,mixed> */
    private array $originalEnv;
    private int $adminId;
    private OrderNotificationSettingsService $settings;

    protected function setUp(): void
    {
        $this->originalEnv = $_ENV;
        parent::setUp();
        $this->seed();
        $_ENV['COD_ENABLED'] = 'true';
        $_ENV['STRIPE_ENABLED'] = 'true';
        $_ENV['STRIPE_MODE'] = 'test';
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_internal_notifications';
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_internal_notifications';
        // No device is registered: events are checked locally, without network calls.
        $_ENV['WEB_PUSH_ENABLED'] = 'true';
        $_ENV['WEB_PUSH_VAPID_PUBLIC_KEY'] = 'test-public-key';
        $_ENV['WEB_PUSH_VAPID_PRIVATE_KEY'] = 'test-private-key';
        $this->pdo->exec("INSERT INTO users (email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES ('admin.notifications@example.test','unused','Admin','Notifications','administrator','active','2026-11-08T10:00:00+00:00','2026-11-08T10:00:00+00:00')");
        $this->adminId = (int) $this->pdo->lastInsertId();
        $this->settings = new OrderNotificationSettingsService($this->pdo);
    }

    protected function tearDown(): void
    {
        unset($this->settings);
        parent::tearDown();
        $_ENV = $this->originalEnv;
    }

    public function testSettingsNormalizeDeduplicateAndAllowDisablingWithoutSendingOldOrders(): void
    {
        self::assertSame([], $this->settings->recipients());
        $this->place('cod');
        $this->settings->save(" First@Example.test ;second@example.test\nfirst@example.test\r\n", $this->adminId);
        self::assertSame(['first@example.test', 'second@example.test'], $this->settings->recipients());
        self::assertSame(0, $this->countInternal());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='order_confirmation'")->fetchColumn());
        $audit = (string) $this->pdo->query("SELECT context_json FROM audit_log WHERE action='order_notification.settings_updated' ORDER BY id DESC LIMIT 1")->fetchColumn();
        self::assertSame(['recipient_count' => 2], json_decode($audit, true));
        $this->settings->save([], $this->adminId);
        self::assertSame([], $this->settings->recipients());
        $this->place('cod');
        self::assertSame(0, $this->countInternal());
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='order_confirmation'")->fetchColumn());
    }

    /** @return iterable<string,array{array<mixed>|string}> */
    public static function invalidRecipients(): iterable
    {
        yield 'invalid second email' => [['first@example.test', 'invalid-address']];
        yield 'header injection in array' => [["first@example.test\r\nBcc: attacker@example.test"]];
        yield 'nested array' => [[['email' => 'first@example.test']]];
        yield 'number' => [[123]];
        yield '11 unique recipients' => [array_map(static fn (int $i): string => 'team' . $i . '@example.test', range(1, 11))];
        yield 'oversized string' => [str_repeat('a', 10001)];
        yield 'oversized raw list' => [array_fill(0, 101, 'first@example.test')];
    }

    #[DataProvider('invalidRecipients')]
    public function testInvalidListsDoNotPartiallySave(array|string $recipients): void
    {
        $this->settings->save(['original@example.test'], $this->adminId);
        try {
            $this->settings->save($recipients, $this->adminId);
            self::fail('Expected invalid recipient rejection.');
        } catch (InvalidArgumentException) {
            self::assertSame(['original@example.test'], $this->settings->recipients());
            self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='order_notification.settings_updated'")->fetchColumn());
        }
    }

    public function testTenRecipientsAreAcceptedAndCustomerCannotChangeSettings(): void
    {
        $emails = array_map(static fn (int $i): string => 'team' . $i . '@example.test', range(1, 10));
        $this->settings->save($emails, $this->adminId);
        self::assertSame($emails, $this->settings->recipients());
        $this->pdo->exec("UPDATE users SET role='customer' WHERE id=" . $this->adminId);
        $this->expectException(DomainException::class);
        try { $this->settings->save([], $this->adminId); }
        finally { self::assertSame($emails, $this->settings->recipients()); }
    }

    public function testDatabaseFailureRollsBackBothSettingsAndAudit(): void
    {
        $this->settings->save(['original@example.test'], $this->adminId);
        $this->pdo->exec("CREATE TRIGGER reject_notification_audit BEFORE INSERT ON audit_log WHEN NEW.action='order_notification.settings_updated' BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        $this->expectException(PDOException::class);
        try { $this->settings->save(['new@example.test'], $this->adminId); }
        finally {
            self::assertSame(['original@example.test'], $this->settings->recipients());
            self::assertFalse($this->pdo->inTransaction());
        }
    }

    public function testCodQueuesOneIndependentMessagePerRecipientAndPreservesCustomerAndPush(): void
    {
        $this->settings->save(['first@example.test', 'second@example.test'], $this->adminId);
        $orderId = $this->place('cod');
        $messages = $this->pdo->query("SELECT id,recipient,payload_json,status FROM outbox_messages WHERE message_type='internal_order_notification' ORDER BY id")->fetchAll();
        self::assertCount(2, $messages);
        self::assertSame(['first@example.test', 'second@example.test'], array_column($messages, 'recipient'));
        self::assertNotSame($messages[0]['id'], $messages[1]['id']);
        foreach ($messages as $message) {
            self::assertSame(['order_id' => $orderId], json_decode($message['payload_json'], true));
            self::assertSame('pending', $message['status']);
        }
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='order_confirmation' AND recipient='buyer@example.test'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM admin_push_events WHERE event_key='order:" . $orderId . "'")->fetchColumn());
        (new InternalOrderNotificationService($this->pdo))->queue($orderId);
        self::assertSame(2, $this->countInternal());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM order_internal_email_deliveries')->fetchColumn());
        // One recipient's delivery status does not affect the other recipient or customer.
        $this->pdo->exec("UPDATE outbox_messages SET status='failed',attempts=1 WHERE id=" . (int) $messages[0]['id']);
        self::assertSame('pending', $this->pdo->query('SELECT status FROM outbox_messages WHERE id=' . (int) $messages[1]['id'])->fetchColumn());
    }

    public function testChangingRecipientsAffectsFutureOrdersWithoutRewritingQueuedSnapshots(): void
    {
        $this->settings->save(['old@example.test'], $this->adminId);
        $first = $this->place('cod');
        $this->settings->save(['new@example.test'], $this->adminId);
        self::assertSame(1, $this->countInternal());
        $second = $this->place('cod');
        $rows = $this->pdo->query('SELECT order_id,recipient FROM order_internal_email_deliveries ORDER BY id')->fetchAll();
        self::assertSame([['order_id' => $first, 'recipient' => 'old@example.test'], ['order_id' => $second, 'recipient' => 'new@example.test']], $rows);
    }

    public function testFailedQueueRollsBackTheOrderCustomerEmailAndAllInternalRecipients(): void
    {
        $this->settings->save(['first@example.test', 'second@example.test'], $this->adminId);
        $this->pdo->exec("CREATE TRIGGER reject_second_internal_email BEFORE INSERT ON outbox_messages WHEN NEW.message_type='internal_order_notification' AND NEW.recipient='second@example.test' BEGIN SELECT RAISE(ABORT, 'test queue failure'); END");
        $this->expectException(PDOException::class);
        try { $this->place('cod'); }
        finally {
            foreach (['orders', 'outbox_messages', 'order_internal_email_deliveries', 'inventory_reservations', 'admin_push_events'] as $table) {
                self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
            }
            self::assertSame(0, (int) $this->pdo->query('SELECT SUM(reserved_quantity) FROM inventory')->fetchColumn());
        }
    }

    public function testCardRequiresVerifiedPaidEventAndDoesNotDuplicateAcrossEvents(): void
    {
        $this->settings->save(['first@example.test', 'second@example.test'], $this->adminId);
        $orderId = $this->place('stripe'); // An attempt ID until the verified paid event.
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM admin_push_events')->fetchColumn());
        self::assertSame(0, $this->countInternal());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
        (new InternalOrderNotificationService($this->pdo))->queue($orderId);
        self::assertSame(0, $this->countInternal());
        self::assertSame(400, $this->webhook($orderId, 'evt_bad_signature', 'paid', 0, false));
        self::assertSame(200, $this->webhook($orderId, 'evt_unpaid', 'unpaid'));
        self::assertSame(200, $this->webhook($orderId, 'evt_amount_mismatch', 'paid', 1));
        self::assertSame(0, $this->countInternal());
        self::assertSame(200, $this->webhook($orderId, 'evt_paid', 'paid'));
        self::assertSame(2, $this->countInternal());
        self::assertSame(200, $this->webhook($orderId, 'evt_paid', 'paid'));
        self::assertSame(200, $this->webhook($orderId, 'evt_distinct_paid', 'paid'));
        self::assertSame(2, $this->countInternal());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='order_confirmation'")->fetchColumn());
        $orderId = (int) $this->pdo->query('SELECT order_id FROM checkout_attempts WHERE id=' . $orderId)->fetchColumn();
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM admin_push_events WHERE event_key='order:" . $orderId . "'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM admin_push_events WHERE event_key='payment:" . $orderId . ":paid'")->fetchColumn());
        self::assertSame('confirmed', $this->pdo->query('SELECT status FROM orders WHERE id=' . $orderId)->fetchColumn());
    }

    public function testLatePaidCardNotifiesTeamWithManualReviewState(): void
    {
        $this->settings->save(['team@example.test'], $this->adminId);
        $orderId = $this->place('stripe');
        $this->pdo->beginTransaction();
        (new CheckoutAttemptService($this->pdo, Config::load($this->root)))->release($orderId, 'expired');
        $this->pdo->commit();
        $this->pdo->exec('UPDATE inventory SET physical_quantity=0,reserved_quantity=0');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertSame(200, $this->webhook($orderId, 'evt_late_paid', 'paid'));
        $orderId = (int) $this->pdo->query('SELECT order_id FROM checkout_attempts WHERE id=' . $orderId)->fetchColumn();
        self::assertSame('manual_review', $this->pdo->query('SELECT status FROM orders WHERE id=' . $orderId)->fetchColumn());
        self::assertSame(1, $this->countInternal());
    }

    private function countInternal(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='internal_order_notification'")->fetchColumn();
    }

    private function place(string $paymentMethod): int
    {
        $variant = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND v.status='active' AND i.physical_quantity-i.reserved_quantity>4 ORDER BY v.id LIMIT 1")->fetchColumn();
        $shipping = (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($variant, 1);
        $config = Config::load($this->root);
        $result = (new CheckoutService($this->pdo, $cart, $config, push: new AdminPushService($this->pdo, $config)))->place([
            'email' => 'buyer@example.test', 'first_name' => 'Ana', 'last_name' => 'Pop', 'phone' => '0700000000',
            'address' => 'Strada Test 1', 'city' => 'București — Sector 1', 'county' => 'București', 'postal_code' => '',
            'shipping_method_id' => $shipping, 'payment_method' => $paymentMethod, 'idempotency_key' => bin2hex(random_bytes(20)), 'terms' => '1',
        ]);
        return $result['id'];
    }

    private function webhook(int $orderId, string $eventId, string $paymentStatus, int $amountDelta = 0, bool $validSignature = true): int
    {
        $amount = (int) $this->pdo->query('SELECT total_bani FROM checkout_attempts WHERE id=' . $orderId)->fetchColumn();
        $this->pdo->prepare("UPDATE checkout_attempts SET checkout_session_id=:session WHERE id=:id")
            ->execute(['session' => 'cs_order_' . $orderId, 'id' => $orderId]);
        $payload = json_encode([
            'id' => $eventId, 'object' => 'event', 'api_version' => '2025-09-30.clover', 'created' => time(),
            'type' => 'checkout.session.completed', 'livemode' => false,
            'data' => ['object' => ['id' => 'cs_order_' . $orderId, 'object' => 'checkout.session', 'payment_status' => $paymentStatus,
                'amount_total' => $amount + $amountDelta, 'currency' => 'ron', 'payment_intent' => 'pi_order_' . $orderId, 'metadata' => ['checkout_attempt_id' => (string) $orderId, 'checkout_attempt_key' => (string) $this->pdo->query('SELECT idempotency_key FROM checkout_attempts WHERE id=' . $orderId)->fetchColumn()]]],
        ], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $validSignature ? 'whsec_internal_notifications' : 'wrong');
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/stripe/webhook')
            ->withHeader('Stripe-Signature', 't=' . $timestamp . ',v1=' . $signature)
            ->withBody((new StreamFactory())->createStream($payload));
        $config = Config::load($this->root);
        return (new StripeWebhookController($this->pdo, $config, new AdminPushService($this->pdo, $config)))($request)->getStatusCode();
    }
}
