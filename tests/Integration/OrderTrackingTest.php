<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Orders\OrderService;
use App\Modules\Orders\OrderTrackingEmail;
use App\Modules\Orders\OrderTrackingRepository;
use App\Modules\Orders\OrderTrackingService;
use App\Shared\Config\Config;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class OrderTrackingTest extends DatabaseTestCase
{
    private int $orderId;
    private string $number;
    private OrderTrackingService $tracking;
    private string $invoiceFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $variant = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND i.physical_quantity>=2 ORDER BY v.id LIMIT 1")->fetchColumn();
        $shipping = (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($variant, 2);
        $config = Config::load($this->root);
        $order = (new CheckoutService($this->pdo, $cart, $config))->place([
            'email'=>'tracking@example.test', 'first_name'=>'Ana', 'last_name'=>'Pop', 'phone'=>'0700000000',
            'address'=>'Strada Florilor 10', 'city'=>'București — Sector 1', 'county'=>'București',
            'shipping_method_id'=>(string) $shipping, 'payment_method'=>'cod', 'terms'=>'1', 'idempotency_key'=>bin2hex(random_bytes(32)),
        ]);
        $this->orderId = $order['id'];
        $this->number = $order['order_number'];
        $this->tracking = new OrderTrackingService(new OrderTrackingRepository($this->pdo), new OrderService($this->pdo), $config, $this->root);
        $this->invoiceFile = 'tracking-test-' . bin2hex(random_bytes(8)) . '.pdf';
    }

    protected function tearDown(): void
    {
        $path = $this->root . '/storage/private/invoices/' . $this->invoiceFile;
        if (is_file($path)) {
            unlink($path);
        }
        unset($this->tracking);
        gc_collect_cycles();
        parent::tearDown();
    }

    public function testLookupRequiresBothDetailsAndCsrfAndDoesNotExposeInternalData(): void
    {
        $page = $this->request('GET');
        self::assertStringContainsString('Hai să găsim comanda ta', (string) $page->getBody());
        self::assertSame(419, $this->request('POST', data:['order_number'=>$this->number, 'email'=>'tracking@example.test'])->getStatusCode());
        foreach ([['wrong','tracking@example.test'], [$this->number,'wrong@example.test']] as [$number,$email]) {
            $response = $this->request('POST', data:['_csrf'=>$_SESSION['_csrf'], 'order_number'=>$number, 'email'=>$email]);
            self::assertSame(422, $response->getStatusCode());
            self::assertStringNotContainsString('Strada Florilor', (string) $response->getBody());
        }
        $this->pdo->prepare('UPDATE orders SET internal_notes=:note WHERE id=:id')->execute(['note'=>'SECRET INTERNAL NOTE', 'id'=>$this->orderId]);
        $response = $this->request('POST', data:['_csrf'=>$_SESSION['_csrf'], 'order_number'=>strtolower($this->number), 'email'=>'TRACKING@example.test']);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/urmarire-comanda/', $response->getHeaderLine('Location'));
        $response = $this->request('GET');
        $html = (string) $response->getBody();
        self::assertStringContainsString($this->number, $html);
        self::assertStringContainsString('Strada Florilor 10', $html);
        self::assertStringContainsString('SKU:', $html);
        self::assertStringContainsString('/ buc.', $html);
        self::assertStringNotContainsString('SECRET INTERNAL NOTE', $html);
        self::assertStringNotContainsString('Descarcă factura', $html);
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    }

    public function testSignedEmailLinkAuthorizesOnlyItsOrderAndCanBeRevoked(): void
    {
        $url = $this->tracking->emailUrl($this->orderId, 'tracking@example.test');
        self::assertStringNotContainsString('tracking@example.test', $url);
        $path = (string) parse_url($url, PHP_URL_PATH);
        self::assertSame(303, $this->request('GET', $path)->getStatusCode());
        self::assertStringContainsString($this->number, (string) $this->request('GET')->getBody());
        $_SESSION['tracked_order']['expires'] = time() - 1;
        self::assertStringNotContainsString('Strada Florilor', (string) $this->request('GET')->getBody());
        self::assertSame(404, $this->request('GET', '/urmarire-comanda/acces/' . $this->orderId . '/' . str_repeat('a',64) . '/')->getStatusCode());
        $this->pdo->prepare('UPDATE orders SET public_token_hash=:hash WHERE id=:id')->execute(['hash'=>hash('sha256', 'rotated'), 'id'=>$this->orderId]);
        self::assertSame(404, $this->request('GET', $path)->getStatusCode());
    }

    public function testEmailHasEscapedHtmlButtonAndPlainTextPrivateUrl(): void
    {
        $email = (new OrderTrackingEmail($this->tracking))->withTracking('Bună <script>unsafe</script>', $this->orderId, 'tracking@example.test');
        self::assertStringContainsString('Urmărește comanda', $email['html']);
        self::assertStringNotContainsString('<script>', $email['html']);
        self::assertStringContainsString($this->tracking->emailUrl($this->orderId, 'tracking@example.test'), $email['text']);
        $this->expectException(\RuntimeException::class);
        $this->tracking->emailUrl($this->orderId, 'other@example.test');
    }

    public function testPrivateInvoicesRequireAuthorizedOrderAndExistingNonRevokedPdf(): void
    {
        $directory = $this->root . '/storage/private/invoices';
        if (!is_dir($directory)) { mkdir($directory, 0770, true); }
        file_put_contents($directory . '/' . $this->invoiceFile, "%PDF-1.4\n%%EOF");
        $id = $this->tracking->registerInvoice($this->orderId, 'LM-123', $this->invoiceFile);
        $path = '/urmarire-comanda/factura/' . $id . '/';
        self::assertSame(404, $this->request('GET', $path)->getStatusCode());
        $_SESSION['tracked_order'] = ['id'=>$this->orderId + 1, 'expires'=>time()+1800];
        self::assertSame(404, $this->request('GET', $path)->getStatusCode());
        $_SESSION['tracked_order'] = ['id'=>$this->orderId, 'expires'=>time()+1800];
        self::assertStringContainsString('Descarcă factura LM-123', (string) $this->request('GET')->getBody());
        $pdf = $this->request('GET', $path);
        self::assertSame(200, $pdf->getStatusCode());
        self::assertSame('application/pdf', $pdf->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('attachment;', $pdf->getHeaderLine('Content-Disposition'));
        self::assertStringStartsWith('%PDF-', (string) $pdf->getBody());
        $pdf->getBody()->close();
        $this->pdo->prepare('UPDATE order_documents SET revoked_at=:now WHERE id=:id')->execute(['now'=>gmdate('c'), 'id'=>$id]);
        self::assertSame(404, $this->request('GET', $path)->getStatusCode());
        self::assertStringNotContainsString('Descarcă factura', (string) $this->request('GET')->getBody());
        $this->expectException(\RuntimeException::class);
        $this->tracking->registerInvoice($this->orderId, 'BAD', '../../../.env');
    }

    public function testTimelineDatesStatusExceptionsAndDiscountsUsePersistedValues(): void
    {
        $this->pdo->prepare("UPDATE orders SET status='preparing',discount_bani=1000,shipping_discount_bani=500,internal_notes='PRIVATE' WHERE id=:id")->execute(['id'=>$this->orderId]);
        $details = $this->tracking->details($this->orderId);
        self::assertNotNull($details);
        self::assertArrayNotHasKey('internal_notes', $details);
        self::assertTrue($details['timeline'][2]['current']);
        self::assertNull($details['timeline'][2]['date']);
        self::assertFalse($details['timeline'][4]['done']);
        $_SESSION['tracked_order'] = ['id'=>$this->orderId, 'expires'=>time()+1800];
        self::assertStringContainsString('Ai economisit 15,00 Lei', (string) $this->request('GET')->getBody());
        foreach (['cancelled','returned','manual_review'] as $status) {
            $this->pdo->prepare('UPDATE orders SET status=:status WHERE id=:id')->execute(['status'=>$status, 'id'=>$this->orderId]);
            $html = (string) $this->request('GET')->getBody();
            self::assertStringContainsString('tracking-exception', $html);
            self::assertStringNotContainsString('aria-current="step"', $html);
        }
    }

    public function testLookupIsRateLimitedAndResetClearsAccess(): void
    {
        $this->request('GET');
        for ($i=0; $i<12; $i++) {
            self::assertSame(422, $this->request('POST', data:['_csrf'=>$_SESSION['_csrf'], 'order_number'=>'BAD','email'=>'tracking@example.test'])->getStatusCode());
        }
        self::assertSame(429, $this->request('POST', data:['_csrf'=>$_SESSION['_csrf']])->getStatusCode());
        $_SESSION['tracked_order'] = ['id'=>$this->orderId, 'expires'=>time()+1800];
        self::assertSame(303, $this->request('POST', '/urmarire-comanda/alta/', ['_csrf'=>$_SESSION['_csrf']])->getStatusCode());
        self::assertArrayNotHasKey('tracked_order', $_SESSION);
    }

    public function testEachStatusKeepsItsEntryTimestampAndRendersRomanianTime(): void
    {
        $now = gmdate('c');
        $this->pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES ('tracking.admin@example.test','unused','Admin','Test','administrator','active',:now,:now)")->execute(['now'=>$now]);
        $actor = (int) $this->pdo->lastInsertId();
        $orders = new OrderService($this->pdo);
        foreach (['confirm'=>'confirmed', 'prepare'=>'preparing', 'ship'=>'shipped', 'deliver'=>'delivered'] as $action=>$status) {
            $orders->transition($this->orderId, $action, null, $actor);
            $detail = $this->tracking->details($this->orderId);
            self::assertNotEmpty($detail['status_date']);
            $entry = array_values(array_filter($detail['timeline'], static fn(array $step): bool=>$step['key'] === $status))[0];
            self::assertSame($detail['status_date'], $entry['date']);
        }
        foreach ($this->tracking->details($this->orderId)['timeline'] as $entry) {
            self::assertNotNull($entry['date']);
        }
        $this->pdo->prepare("UPDATE audit_log SET created_at='2026-11-07T10:45:00+00:00' WHERE entity_type='order' AND entity_id=:id AND action='order.deliver'")->execute(['id'=>$this->orderId]);
        $_SESSION['tracked_order'] = ['id'=>$this->orderId, 'expires'=>time()+1800];
        $html = (string) $this->request('GET')->getBody();
        self::assertStringContainsString('07.11.2026 · 12:45', $html);
        self::assertStringContainsString('Din 07.11.2026, 12:45', $html);
        $item = (int) $this->pdo->query('SELECT id FROM order_items WHERE order_id=' . $this->orderId)->fetchColumn();
        $orders->registerReturn($this->orderId, [$item=>2], 'Retur de test', '', false, 'none', 0, $actor);
        self::assertSame('returned', $this->tracking->details($this->orderId)['status']);
        self::assertNotEmpty($this->tracking->details($this->orderId)['status_date']);
    }

    public function testRepeatedPaymentFailureDoesNotMoveStatusEntryTime(): void
    {
        $this->pdo->prepare("UPDATE orders SET payment_method='stripe' WHERE id=:id")->execute(['id'=>$this->orderId]);
        $stripe = new \App\Modules\Payments\StripeCheckoutService($this->pdo, Config::load($this->root));
        $stripe->markSessionFailure($this->orderId, 'Synthetic failure');
        $this->pdo->prepare("UPDATE audit_log SET created_at='2026-11-07T10:00:00+00:00' WHERE entity_type='order' AND entity_id=:id AND action='stripe.session_failed'")->execute(['id'=>$this->orderId]);
        $stripe->markSessionFailure($this->orderId, 'Retry, same status');
        self::assertSame('2026-11-07T10:00:00+00:00', $this->tracking->details($this->orderId)['status_date']);
        $this->pdo->prepare("UPDATE orders SET status='cancelled',cancelled_at='2026-11-07T11:00:00+00:00' WHERE id=:id")->execute(['id'=>$this->orderId]);
        self::assertSame('2026-11-07T11:00:00+00:00', $this->tracking->details($this->orderId)['status_date']);
    }

    /** @param array<string,string> $data */
    private function request(string $method, string $path = '/urmarire-comanda/', array $data = []): ResponseInterface
    {
        $app = AppFactory::create($this->root);
        $response = $app->handle((new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR'=>'127.0.0.1'])->withParsedBody($data));
        unset($app);
        gc_collect_cycles();
        return $response;
    }
}
