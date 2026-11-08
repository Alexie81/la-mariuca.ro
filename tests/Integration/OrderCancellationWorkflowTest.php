<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Admin\AdminNotificationService;
use App\Modules\Admin\AdminPushService;
use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Orders\OrderCancellationService;
use App\Modules\Orders\OrderNotificationSettingsService;
use App\Modules\Orders\OrderService;
use App\Modules\Orders\OrderTrackingRepository;
use App\Modules\Orders\OrderTrackingService;
use App\Shared\Config\Config;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class OrderCancellationWorkflowTest extends DatabaseTestCase
{
    /** @var array<string,mixed> */
    private array $environment;
    private int $adminId;
    private int $orderId;
    private int $variantId;
    private OrderTrackingService $tracking;
    private OrderCancellationService $decisions;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        $_ENV['WEB_PUSH_ENABLED'] = 'false';
        $_ENV['COD_ENABLED'] = 'true';
        parent::setUp();
        $this->seed();
        $now = gmdate('c');
        $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES ('reviewer@example.test','unused','Maria','Admin','administrator','active',?,?)")->execute([$now, $now]);
        $this->adminId = (int) $this->pdo->lastInsertId();
        $this->variantId = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND v.status='active' AND v.stock_unlimited=0 AND i.physical_quantity-i.reserved_quantity>=2 ORDER BY v.id LIMIT 1")->fetchColumn();
        $this->pdo->prepare("INSERT INTO coupons (code,type,scope,value_type,value,minimum_bani,status,created_at) VALUES ('CANCEL500','fixed','order','fixed',500,0,'active',?)")->execute([$now]);
        $cart = new CartService($this->pdo);
        $cart->add($this->variantId, 2);
        $cart->applyCoupon('CANCEL500');
        $shipping = (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        $config = Config::load($this->root);
        $order = (new CheckoutService($this->pdo, $cart, $config))->place([
            'email'=>'cancellation-client@example.test', 'first_name'=>'Ana', 'last_name'=>'Pop', 'phone'=>'0700000000',
            'address'=>'Strada Test 10', 'city'=>'București — Sector 1', 'county'=>'București',
            'shipping_method_id'=>(string) $shipping, 'payment_method'=>'cod', 'terms'=>'1', 'idempotency_key'=>bin2hex(random_bytes(16)),
        ]);
        $this->orderId = (int) $order['id'];
        $repository = new OrderTrackingRepository($this->pdo);
        $orders = new OrderService($this->pdo);
        $this->tracking = new OrderTrackingService($repository, $orders, $config, $this->root);
        $this->decisions = new OrderCancellationService($repository, $orders);
    }

    protected function tearDown(): void
    {
        unset($this->tracking, $this->decisions);
        parent::tearDown();
        $_ENV = $this->environment;
    }

    public function testRequestNotifiesClientAndEverySavedInternalRecipientOnlyOnce(): void
    {
        (new OrderNotificationSettingsService($this->pdo))->save(['orders@example.test','team@example.test','ORDERS@example.test'], $this->adminId);
        $before = $this->economicSnapshot();
        $request = $this->tracking->requestCancellation($this->orderId, 'Am comandat altă mărime.');
        self::assertSame($before, $this->economicSnapshot());
        self::assertSame($request, $this->tracking->requestCancellation($this->orderId, 'Nu suprascrie motivul inițial.'));
        self::assertSame([
            ['message_type'=>'order_cancellation_received','recipient'=>'cancellation-client@example.test'],
            ['message_type'=>'internal_order_cancellation','recipient'=>'orders@example.test'],
            ['message_type'=>'internal_order_cancellation','recipient'=>'team@example.test'],
        ], $this->pdo->query("SELECT message_type,recipient FROM outbox_messages WHERE message_type LIKE '%cancellation%' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));
        foreach ($this->pdo->query("SELECT payload_json FROM outbox_messages WHERE message_type LIKE '%cancellation%'")->fetchAll(PDO::FETCH_COLUMN) as $payload) {
            self::assertSame(['order_id'=>$this->orderId,'request_id'=>$request], json_decode($payload, true));
        }
        self::assertSame('Am comandat altă mărime.', $this->requestRecord()['reason']);
    }

    public function testEmptyRecipientListStillSendsCustomerReceipt(): void
    {
        $this->tracking->requestCancellation($this->orderId, 'Doresc alt produs.');
        self::assertSame(1, $this->mailCount('order_cancellation_received'));
        self::assertSame(0, $this->mailCount('internal_order_cancellation'));
    }

    public function testMigrationKeepsLegacyRequestsPendingWithoutInventingADecision(): void
    {
        $legacy = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $legacy->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $legacy->exec('CREATE TABLE order_cancellation_requests (id INTEGER PRIMARY KEY,order_id INTEGER,reason TEXT,order_status_at_request TEXT,created_at TEXT)');
        $legacy->exec("INSERT INTO order_cancellation_requests VALUES (1,20,'Cerere istorică','preparing','2026-10-01T10:00:00+00:00')");
        $legacy->exec((string) file_get_contents($this->root . '/database/migrations/sqlite/040_order_cancellation_decisions.sql'));
        self::assertSame(['reason'=>'Cerere istorică','status'=>'pending','decision_reason'=>'','resolved_at'=>null,'resolved_by'=>null],
            $legacy->query('SELECT reason,status,decision_reason,resolved_at,resolved_by FROM order_cancellation_requests')->fetch(PDO::FETCH_ASSOC));
    }

    public function testApprovalAtomicallyReleasesStockAndCouponWithoutChangingPaymentAndRetriesOnce(): void
    {
        $this->tracking->requestCancellation($this->orderId, 'Vreau anularea comenzii.');
        $stock = $this->pdo->query('SELECT physical_quantity,reserved_quantity FROM inventory WHERE variant_id=' . $this->variantId)->fetch(PDO::FETCH_ASSOC);
        $payments = $this->pdo->query('SELECT * FROM payments')->fetchAll();
        $accepted = $this->decisions->decide($this->orderId, 'accepted', 'Am anulat conform solicitării tale.', $this->adminId);
        self::assertSame('accepted', $accepted['status']);
        self::assertNotEmpty($accepted['resolved_at']);
        self::assertSame($this->adminId, (int) $this->requestRecord()['resolved_by']);
        self::assertSame('cancelled', $this->orderStatus());
        self::assertSame('pending', $this->pdo->query('SELECT payment_status FROM orders WHERE id=' . $this->orderId)->fetchColumn());
        self::assertSame($payments, $this->pdo->query('SELECT * FROM payments')->fetchAll());
        self::assertSame((int) $stock['physical_quantity'], (int) $this->pdo->query('SELECT physical_quantity FROM inventory WHERE variant_id=' . $this->variantId)->fetchColumn());
        self::assertSame((int) $stock['reserved_quantity'] - 2, (int) $this->pdo->query('SELECT reserved_quantity FROM inventory WHERE variant_id=' . $this->variantId)->fetchColumn());
        self::assertSame('released', $this->pdo->query('SELECT status FROM inventory_reservations WHERE order_id=' . $this->orderId)->fetchColumn());
        self::assertSame('released', $this->pdo->query('SELECT status FROM coupon_redemptions WHERE order_id=' . $this->orderId)->fetchColumn());
        self::assertSame(1, $this->mailCount('order_cancellation_decision'));
        $snapshot = $this->economicSnapshot();
        self::assertSame($accepted, $this->decisions->decide($this->orderId, 'accepted', 'Retry text must not overwrite.', $this->adminId));
        $this->assertFailure(fn()=>$this->decisions->decide($this->orderId, 'rejected', 'O altă decizie nu este permisă.', $this->adminId), 'deja soluționată');
        self::assertSame($snapshot, $this->economicSnapshot());
        self::assertSame(1, $this->mailCount('order_cancellation_decision'));
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='order.cancellation_accepted'")->fetchColumn());
        self::assertSame(0, (new OrderService($this->pdo))->paginateForAdmin(1, 15, '', 'cancellation_requested')['total']);
        self::assertSame(0, (int) (new OrderService($this->pdo))->paginateForAdmin(1, 15)['items'][0]['cancellation_requested']);
    }

    public function testPaidCardApprovalNeverMarksPaymentRefundedOrCreatesAccountingChanges(): void
    {
        $this->pdo->exec("UPDATE orders SET payment_method='stripe',payment_status='paid',status='confirmed' WHERE id=" . $this->orderId);
        $this->pdo->exec("UPDATE payments SET provider='stripe',status='paid' WHERE order_id=" . $this->orderId);
        $this->tracking->requestCancellation($this->orderId, 'Doresc anularea înainte de expediere.');
        $payments = $this->pdo->query('SELECT * FROM payments')->fetchAll();
        $this->decisions->decide($this->orderId, 'accepted', '', $this->adminId);
        self::assertSame('cancelled', $this->orderStatus());
        self::assertSame('paid', $this->pdo->query('SELECT payment_status FROM orders WHERE id=' . $this->orderId)->fetchColumn());
        self::assertSame($payments, $this->pdo->query('SELECT * FROM payments')->fetchAll());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM order_returns')->fetchColumn());
    }

    public function testRejectionKeepsOrderAndStockAndPublishesReasonWithoutAdminIdentity(): void
    {
        $this->tracking->requestCancellation($this->orderId, 'Aș dori să anulez.');
        $this->pdo->exec("UPDATE orders SET status='shipped' WHERE id=" . $this->orderId);
        $before = $this->economicSnapshot();
        $reason = 'Coletul a fost predat curierului. <script>alert(1)</script>';
        $this->decisions->decide($this->orderId, 'rejected', $reason, $this->adminId);
        self::assertSame($before, $this->economicSnapshot());
        $public = $this->tracking->details($this->orderId)['cancellation_request'];
        self::assertSame('rejected', $public['status']);
        self::assertSame($reason, $public['decision_reason']);
        self::assertArrayNotHasKey('resolved_by', $public);
        self::assertSame(1, $this->mailCount('order_cancellation_decision'));
        $feed = (new AdminNotificationService($this->pdo))->feed($this->adminId, 1, 30, 'comenzi');
        $requestEvents = array_values(array_filter($feed['items'], static fn(array $row): bool=>str_starts_with($row['key'], 'order-cancellation:')));
        self::assertStringContainsString('respinsă', $requestEvents[0]['detail']);
        self::assertStringNotContainsString('Verifică motivul', $requestEvents[0]['detail']);
        $this->get('/urmarire-comanda/');
        $_SESSION['tracked_order'] = ['id'=>$this->orderId, 'expires'=>time()+1800];
        $html = (string) $this->get('/urmarire-comanda/')->getBody();
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>alert', $html);
    }

    public function testAdminCanReviewTimelyRequestAfterFiveDaysAndAlreadyCancelledOrderWithoutDoubleRelease(): void
    {
        $this->tracking->requestCancellation($this->orderId, 'Solicitare trimisă în termen.');
        $this->pdo->prepare('UPDATE orders SET created_at=? WHERE id=?')->execute([gmdate('c', time()-6*86400), $this->orderId]);
        (new OrderService($this->pdo))->transition($this->orderId, 'cancel', null, $this->adminId);
        $before = $this->economicSnapshot();
        $this->decisions->decide($this->orderId, 'accepted', '', $this->adminId);
        self::assertSame($before, $this->economicSnapshot());
        self::assertSame('accepted', $this->requestRecord()['status']);
        self::assertSame(1, $this->mailCount('order_cancellation_decision'));
    }

    public function testLateStatusesReasonValidationAndUnauthorizedActorsCannotResolve(): void
    {
        $this->tracking->requestCancellation($this->orderId, 'Vreau anularea.');
        foreach (['shipped','delivered','returned','manual_review'] as $status) {
            $this->pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$status, $this->orderId]);
            $this->assertFailure(fn()=>$this->decisions->decide($this->orderId, 'accepted', '', $this->adminId), 'starea actuală');
            self::assertFalse((new OrderService($this->pdo))->findForAdmin($this->orderId)['can_accept_cancellation']);
        }
        foreach (['', 'abc', "\u{200B}\u{00A0}\u{2007}\u{202F}", str_repeat('ă',1001)] as $reason) {
            $this->assertFailure(fn()=>$this->decisions->decide($this->orderId, 'rejected', $reason, $this->adminId), '5–1.000');
        }
        $this->assertFailure(fn()=>$this->decisions->decide($this->orderId, 'accepted', str_repeat('a',1001), $this->adminId), 'maximum');
        $this->assertFailure(fn()=>$this->decisions->decide($this->orderId, 'invalid', 'Reason', $this->adminId), 'Alege');
        $this->assertFailure(fn()=>$this->decisions->decide($this->orderId, 'rejected', 'Reason', 999999), 'permisiunea');
        $this->pdo->exec("UPDATE users SET role='customer' WHERE id=" . $this->adminId);
        $this->assertFailure(fn()=>$this->decisions->decide($this->orderId, 'rejected', 'Reason', $this->adminId), 'permisiunea');
        self::assertSame('pending', $this->requestRecord()['status']);
        self::assertSame(0, $this->mailCount('order_cancellation_decision'));
    }

    public function testQueueFailureRollsBackNewRequestAndAllReceiptMessages(): void
    {
        (new OrderNotificationSettingsService($this->pdo))->save(['orders@example.test'], $this->adminId);
        $this->pdo->exec("CREATE TRIGGER reject_internal_cancel BEFORE INSERT ON outbox_messages WHEN NEW.message_type='internal_order_cancellation' BEGIN SELECT RAISE(ABORT,'simulated queue failure'); END;");
        $this->assertFailure(fn()=>$this->tracking->requestCancellation($this->orderId, 'Vreau să anulez comanda.'), 'simulated');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM order_cancellation_requests')->fetchColumn());
        self::assertSame(0, $this->mailCount('order_cancellation_received'));
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='order.cancellation_requested'")->fetchColumn());
    }

    public function testDecisionQueueFailureAndInconsistentStockRollBackTheWholeApproval(): void
    {
        $this->tracking->requestCancellation($this->orderId, 'Vreau anularea comenzii.');
        $before = $this->economicSnapshot();
        $this->pdo->exec("CREATE TRIGGER reject_cancel_decision BEFORE INSERT ON outbox_messages WHEN NEW.message_type='order_cancellation_decision' BEGIN SELECT RAISE(ABORT,'simulated decision failure'); END;");
        $this->assertFailure(fn()=>$this->decisions->decide($this->orderId, 'accepted', '', $this->adminId), 'simulated');
        self::assertSame($before, $this->economicSnapshot());
        self::assertSame('pending', $this->requestRecord()['status']);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action IN ('order.cancel','order.cancellation_accepted')")->fetchColumn());
        $this->pdo->exec('DROP TRIGGER reject_cancel_decision');
        $this->pdo->exec('UPDATE inventory SET reserved_quantity=0 WHERE variant_id=' . $this->variantId);
        $this->assertFailure(fn()=>$this->decisions->decide($this->orderId, 'accepted', '', $this->adminId), 'Stoc inconsistent');
        self::assertSame('pending', $this->requestRecord()['status']);
        self::assertSame('active', $this->pdo->query('SELECT status FROM inventory_reservations WHERE order_id=' . $this->orderId)->fetchColumn());
        self::assertSame('new', $this->orderStatus());
        self::assertSame(0, $this->mailCount('order_cancellation_decision'));
    }

    public function testAdminRoutesRequireAuthorizationCsrfAndPreserveValidationWithoutJavascript(): void
    {
        $this->tracking->requestCancellation($this->orderId, 'Aș dori să anulez comanda.');
        $base = '/admin/comenzi/' . $this->orderId . '/anulare/';
        self::assertSame(303, $this->get($base . 'accepta/')->getStatusCode());
        $_SESSION['user_id'] = $this->adminId;
        $this->get('/admin');
        self::assertSame(419, $this->post($base . 'decizie/', ['decision'=>'accepted'])->getStatusCode());
        $html = (string) $this->get($base . 'respinge/')->getBody();
        self::assertStringContainsString('name="reason"', $html);
        self::assertStringContainsString('name="_csrf"', $html);
        $modal = $this->get($base . 'accepta/', ['X-Requested-With'=>'XMLHttpRequest']);
        self::assertSame(200, $modal->getStatusCode());
        self::assertStringNotContainsString('<!DOCTYPE', (string) $modal->getBody());
        $invalid = $this->post($base . 'decizie/', ['_csrf'=>$_SESSION['_csrf'], 'decision'=>'rejected', 'reason'=>'abc']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('>abc</textarea>', (string) $invalid->getBody());
        $array = $this->post($base . 'decizie/', ['_csrf'=>$_SESSION['_csrf'], 'decision'=>'accepted', 'reason'=>['x']], ['Accept'=>'application/json']);
        self::assertSame(422, $array->getStatusCode());
        self::assertFalse(json_decode((string) $array->getBody(), true)['ok']);
        $this->pdo->exec("UPDATE users SET role='operator' WHERE id=" . $this->adminId);
        $ok = $this->post($base . 'decizie/', ['_csrf'=>$_SESSION['_csrf'], 'decision'=>'rejected', 'reason'=>'Coletul a fost preluat.'], ['Accept'=>'application/json']);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertTrue(json_decode((string) $ok->getBody(), true)['ok']);
        self::assertSame('rejected', $this->requestRecord()['status']);
        self::assertSame('new', $this->orderStatus());
    }

    public function testCancellationPushUsesOrderPreferencesAndDeduplicatesWithoutSending(): void
    {
        $_ENV['WEB_PUSH_ENABLED'] = 'true';
        $_ENV['WEB_PUSH_VAPID_PUBLIC_KEY'] = 'test-public-key';
        $_ENV['WEB_PUSH_VAPID_PRIVATE_KEY'] = 'test-private-key';
        $push = new AdminPushService($this->pdo, Config::load($this->root));
        foreach (['one','two'] as $device) {
            $push->subscribe($this->adminId, ['endpoint'=>'https://push.example.test/' . $device,
                'keys'=>['p256dh'=>'browser-key', 'auth'=>'browser-auth']], 'Test browser');
        }
        $id = $this->tracking->requestCancellation($this->orderId, 'Doresc anularea.');
        // Exercise queueing only. No push provider is contacted by this test.
        $push->queueCancellationRequest($this->orderId, $id);
        $push->queueCancellationRequest($this->orderId, $id);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM admin_push_events')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM admin_push_deliveries')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT SUM(attempts) FROM admin_push_deliveries')->fetchColumn());
        self::assertSame('admin/comenzi/' . $this->orderId . '/#cerere-anulare', $this->pdo->query('SELECT target_path FROM admin_push_events')->fetchColumn());
        self::assertStringContainsString('Ana Pop', (string) $this->pdo->query('SELECT body FROM admin_push_events')->fetchColumn());
        (new AdminNotificationService($this->pdo))->savePreferences($this->adminId, ['plati'=>'1']);
        $push->queueCancellationRequest($this->orderId, $id + 1);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM admin_push_events')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM admin_push_deliveries')->fetchColumn());
    }

    /** @return array<string,mixed> */
    private function requestRecord(): array
    {
        return $this->pdo->query('SELECT * FROM order_cancellation_requests WHERE order_id=' . $this->orderId)->fetch(PDO::FETCH_ASSOC);
    }

    private function orderStatus(): string
    {
        return (string) $this->pdo->query('SELECT status FROM orders WHERE id=' . $this->orderId)->fetchColumn();
    }

    private function mailCount(string $type): int
    {
        $query = $this->pdo->prepare('SELECT COUNT(*) FROM outbox_messages WHERE message_type=?');
        $query->execute([$type]);
        return (int) $query->fetchColumn();
    }

    /** @return array<string,array<mixed>> */
    private function economicSnapshot(): array
    {
        $result = [];
        foreach (['orders','payments','inventory','inventory_reservations','inventory_movements','coupon_redemptions'] as $table) {
            $result[$table] = $this->pdo->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        }
        return $result;
    }

    private function assertFailure(callable $action, string $text): void
    {
        try { $action(); self::fail('Expected rejection.'); }
        catch (\DomainException|\RuntimeException $error) { self::assertStringContainsString($text, $error->getMessage()); }
    }

    /** @param array<string,string> $headers */
    private function get(string $path, array $headers = []): ResponseInterface
    {
        return $this->request('GET', $path, [], $headers);
    }

    /** @param array<string,mixed> $data @param array<string,string> $headers */
    private function post(string $path, array $data, array $headers = []): ResponseInterface
    {
        return $this->request('POST', $path, $data, $headers);
    }

    /** @param array<string,mixed> $data @param array<string,string> $headers */
    private function request(string $method, string $path, array $data = [], array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path)->withParsedBody($data);
        foreach ($headers as $key=>$value) { $request = $request->withHeader($key, $value); }
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();
        return $response;
    }
}
