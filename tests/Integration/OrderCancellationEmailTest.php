<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Orders\OrderCancellationEmail;
use App\Modules\Orders\OrderEmailImages;
use App\Modules\Orders\OrderService;
use App\Modules\Orders\OrderTrackingRepository;
use App\Modules\Orders\OrderTrackingService;
use App\Shared\Config\Config;
use App\Shared\Mail\OutboxProcessor;
use DomainException;
use PDO;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Tests\DatabaseTestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class OrderCancellationEmailTest extends DatabaseTestCase
{
    /** @var array<string,mixed> */
    private array $environment;
    private int $orderId;
    private int $requestId;
    private OrderCancellationEmail $renderer;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        $_ENV['WEB_PUSH_ENABLED'] = 'false';
        $_ENV['COD_ENABLED'] = 'true';
        parent::setUp();
        $this->seed();
        $_ENV['APP_URL'] = 'https://shop.example.test';
        $_ENV['APP_BASE_PATH'] = '/boutique';
        $_ENV['MAIL_DELIVERY_MODE'] = 'preview';
        $_ENV['MAIL_SEND_AFTER_ID'] = '0';
        $_ENV['MAIL_MESSAGE_TYPES'] = implode(',', OrderCancellationEmail::TYPES);
        $_ENV['MAIL_FROM_ADDRESS'] = 'sender@example.test';
        $_ENV['CONTACT_EMAIL'] = 'support@example.test';
        $config = Config::load($this->root);
        $variant = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND i.physical_quantity>=2 ORDER BY v.id LIMIT 1")->fetchColumn();
        $shipping = (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($variant, 2);
        $order = (new CheckoutService($this->pdo, $cart, $config))->place([
            'email'=>'ana.cancel@example.test', 'first_name'=>'Ana', 'last_name'=>'Pop', 'phone'=>'0700000000',
            'address'=>'Strada Florilor 10', 'city'=>'București — Sector 1', 'county'=>'București', 'postal_code'=>'010101',
            'shipping_method_id'=>(string) $shipping, 'payment_method'=>'cod', 'terms'=>'1', 'idempotency_key'=>bin2hex(random_bytes(32)),
        ]);
        $this->orderId = (int) $order['id'];
        $this->pdo->prepare("INSERT INTO order_cancellation_requests(order_id,reason,order_status_at_request,created_at) VALUES (?,'Am ales o mărime nepotrivită.','new','2026-11-08 10:45:00')")->execute([$this->orderId]);
        $this->requestId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("UPDATE orders SET subtotal_bani=20000,discount_bani=2500,shipping_bani=500,shipping_discount_bani=1400,total_bani=18000,shipping_method_name='Curier standard' WHERE id=?")->execute([$this->orderId]);
        $this->pdo->prepare('UPDATE order_items SET quantity=2,unit_price_bani=10000,line_total_bani=20000 WHERE order_id=?')->execute([$this->orderId]);
        $tracking = new OrderTrackingService(new OrderTrackingRepository($this->pdo), new OrderService($this->pdo), $config, $this->root);
        $this->renderer = new OrderCancellationEmail($tracking, $config,
            new Environment(new FilesystemLoader($this->root . '/resources/views'), ['autoescape'=>'html', 'strict_variables'=>true]),
            new OrderEmailImages($config, $this->root));
    }

    protected function tearDown(): void
    {
        unset($this->renderer);
        parent::tearDown();
        $_ENV = $this->environment;
    }

    public function testCustomerReceiptReusesBrandedDesignWithOrderSnapshotPrivateCtaAndFullFooter(): void
    {
        $mail = $this->render('order_cancellation_received');
        self::assertStringContainsString('am primit solicitarea de anulare', $mail['subject']);
        foreach (['Bună, Ana!', 'Am primit solicitarea ta de anulare.', 'Am ales o mărime nepotrivită.', '08.11.2026 · 12:45',
            '180,00 Lei', '200,00 Lei', '−25,00 Lei', '−14,00 Lei', 'SKU:', '100,00 Lei', 'Ramburs', 'Curier standard',
            'Strada Florilor 10', '0700000000', 'CAB IT', 'ANPC', 'support@example.test'] as $text) {
            self::assertStringContainsString($text, $mail['html']);
            self::assertStringContainsString($text, $mail['text']);
        }
        foreach (['Cu tine, și după comandă.', 'logo-la-mariuca-transparent.png', '<v:roundrect', 'email-customer-care.png', 'email-status-review.png'] as $text) {
            self::assertStringContainsString($text, $mail['html']);
        }
        self::assertMatchesRegularExpression('~https://shop.example.test/boutique/urmarire-comanda/acces/' . $this->orderId . '/[a-f0-9]{64}/~', $mail['html']);
        self::assertMatchesRegularExpression('~https://shop.example.test/boutique/media/email/[a-f0-9]+\.jpg~', $mail['html']);
        self::assertStringNotContainsString('/admin/comenzi/', $mail['html']);
        self::assertStringNotContainsString('Termen estimat de livrare', $mail['html']);
    }

    public function testInternalRequestHasCustomerNameExactCtaAndNoCustomerFooterOrPrivateTrackingLink(): void
    {
        $mail = $this->render('internal_order_cancellation', 'orders@example.test');
        self::assertStringStartsWith('Ana Pop solicită anularea comenzii LM-', $mail['subject']);
        foreach (['Ana Pop', 'Vezi solicitarea de anulare', 'Am ales o mărime nepotrivită.', '180,00 Lei', 'SKU:', '100,00 Lei'] as $text) {
            self::assertStringContainsString($text, $mail['html']);
            self::assertStringContainsString($text, $mail['text']);
        }
        self::assertStringContainsString('https://shop.example.test/boutique/admin/comenzi/' . $this->orderId . '/#cerere-anulare', $mail['html']);
        self::assertStringContainsString('&copy;', $mail['html']);
        foreach (['urmarire-comanda', 'Cu tine, și după comandă.', 'CAB IT', 'ANPC', 'email-customer-care.png', 'Linkul de urmărire'] as $text) {
            self::assertStringNotContainsString($text, $mail['html']);
            self::assertStringNotContainsString($text, $mail['text']);
        }
        // The server-created outbox recipient remains valid even if settings changed before delivery.
        $this->pdo->exec("UPDATE order_notification_settings SET recipients_json='[]'");
        self::assertSame($mail['subject'], $this->render('internal_order_cancellation', 'orders@example.test')['subject']);
    }

    public function testAcceptedCashCancellationDoesNotAskForPaymentAndContainsTheDecision(): void
    {
        $this->decide('accepted', 'Am anulat comanda la solicitarea ta.');
        $mail = $this->render('order_cancellation_decision');
        self::assertStringContainsString('solicitarea de anulare a fost acceptată', $mail['subject']);
        foreach (['Solicitarea ta de anulare a fost acceptată.', 'Am anulat comanda la solicitarea ta.', 'Comandă anulată · fără plată la livrare', '08.11.2026 · 13:15'] as $text) {
            self::assertStringContainsString($text, $mail['html']);
            self::assertStringContainsString($text, $mail['text']);
        }
        self::assertStringNotContainsString('De achitat la livrare', $mail['html']);
        self::assertStringNotContainsString('Achitat integral.', $mail['html']);
        self::assertStringNotContainsString('Termen estimat', $mail['html']);
        self::assertStringNotContainsString('email-savings-confetti.gif', $mail['html']);
        self::assertStringContainsString('Cu tine, și după comandă.', $mail['html']);
        // A delayed receipt is still a receipt, not a second decision or a claim that cancellation was already granted.
        $receipt = $this->render('order_cancellation_received');
        self::assertStringContainsString('Am primit solicitarea ta de anulare.', $receipt['html']);
        self::assertStringNotContainsString('Solicitarea ta de anulare a fost acceptată.', $receipt['html']);
    }

    public function testCardCancellationUsesActualPaymentStateAndNeverInventsARefund(): void
    {
        $this->pdo->exec("UPDATE orders SET payment_method='stripe',payment_status='pending' WHERE id=" . $this->orderId);
        $pending = $this->render('order_cancellation_received');
        self::assertStringContainsString('Plată în așteptare', $pending['html']);
        self::assertStringNotContainsString('Achitat integral.', $pending['html']);
        $this->decide('accepted');
        $cancelledPending = $this->render('order_cancellation_decision');
        self::assertStringContainsString('Card online — Plată în așteptare', $cancelledPending['text']);
        self::assertStringNotContainsString('Fără plată încasată', $cancelledPending['html']);
        $this->pdo->exec("UPDATE orders SET payment_status='paid' WHERE id=" . $this->orderId);
        $this->decide('accepted');
        $paid = $this->render('order_cancellation_decision');
        self::assertStringContainsString('Card online — Plătit', $paid['text']);
        self::assertStringContainsString('nu restituirea banilor', $paid['html']);
        self::assertSame('paid', $this->pdo->query('SELECT payment_status FROM orders WHERE id=' . $this->orderId)->fetchColumn());
        $this->pdo->exec("UPDATE orders SET payment_status='refunded' WHERE id=" . $this->orderId);
        $refunded = $this->render('order_cancellation_decision');
        self::assertStringContainsString('Card online — Rambursat', $refunded['text']);
        self::assertStringNotContainsString('nu restituirea banilor', $refunded['html']);
    }

    public function testRejectedReasonAndCustomerTextAreEscapedAndEmailSubjectRemainsOneLine(): void
    {
        $this->decide('rejected', '<script>Motiv</script> & verificare.');
        $this->pdo->prepare('UPDATE order_cancellation_requests SET reason=? WHERE id=?')->execute(['<img src=x onerror=alert(1)>', $this->requestId]);
        $this->pdo->prepare('UPDATE orders SET first_name=? WHERE id=?')->execute(["Ana\r\nBcc:secret", $this->orderId]);
        $mail = $this->render('order_cancellation_decision');
        self::assertStringContainsString('Solicitarea ta de anulare nu a fost acceptată.', $mail['html']);
        self::assertStringContainsString('&lt;script&gt;Motiv&lt;/script&gt; &amp; verificare.', $mail['html']);
        self::assertStringNotContainsString('<script>', $mail['html']);
        self::assertStringNotContainsString('<img src=x', $mail['html']);
        self::assertStringContainsString('<script>Motiv</script> & verificare.', $mail['text']);
        self::assertStringNotContainsString("\r", $this->render('internal_order_cancellation', 'orders@example.test')['subject']);
        self::assertStringNotContainsString("\n", $this->render('internal_order_cancellation', 'orders@example.test')['subject']);
    }

    public function testWrongCustomerCannotReceiveOrderDetails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->render('order_cancellation_received', 'someone-else@example.test');
    }

    public function testMismatchedRequestCannotBeRenderedForAnOrder(): void
    {
        $this->expectException(DomainException::class);
        $this->renderer->render($this->orderId, $this->requestId + 100, 'ana.cancel@example.test', 'order_cancellation_received');
    }

    public function testPendingRequestCannotGenerateADecisionEmail(): void
    {
        $this->expectException(DomainException::class);
        $this->render('order_cancellation_decision');
    }

    public function testCancellationMessagesReachTheTransportIndependentlyWithInlineImagesAndSafeRetries(): void
    {
        $this->decide('accepted', 'Anulare confirmată.');
        $this->queue('order_cancellation_received', 'ana.cancel@example.test');
        $failed = $this->queue('internal_order_cancellation', 'first@example.test');
        $this->queue('internal_order_cancellation', 'second@example.test');
        $this->queue('order_cancellation_decision', 'ana.cancel@example.test');
        $_ENV['APP_ENV'] = 'development';
        $_ENV['MAIL_DELIVERY_MODE'] = 'smtp';
        $_ENV['MAILER_DSN'] = 'smtp://127.0.0.1:2525';
        $transport = new CancellationRecordingTransport($this->pdo);
        $processor = new OutboxProcessor($this->pdo, Config::load($this->root), $this->root, $transport);
        self::assertSame(['processed'=>4, 'sent'=>3, 'previewed'=>0, 'failed'=>1], $processor->process());
        self::assertCount(3, $transport->messages);
        foreach ($transport->messages as $sent) {
            $mail = $sent->getOriginalMessage();
            self::assertInstanceOf(Email::class, $mail);
            self::assertCount(1, $mail->getTo());
            self::assertSame([], $mail->getCc());
            self::assertSame([], $mail->getBcc());
            self::assertStringContainsString('src="cid:', (string) $mail->getHtmlBody());
            self::assertStringContainsString('Content-ID:', $sent->toString());
            self::assertNotEmpty($mail->getAttachments());
        }
        self::assertSame([false,false,false,false], $transport->transactionStates);
        $transport->failedRecipient = null;
        $this->pdo->prepare('UPDATE outbox_messages SET available_at=? WHERE id=?')->execute([gmdate('c', time() - 1), $failed]);
        self::assertSame(['processed'=>1, 'sent'=>1, 'previewed'=>0, 'failed'=>0], $processor->process());
        self::assertSame(0, $processor->process()['processed']);
        self::assertCount(4, $transport->messages);
        self::assertSame('sent', $this->pdo->query('SELECT status FROM outbox_messages WHERE id=' . $failed)->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='order_confirmation' AND status='pending'")->fetchColumn());
    }

    /** @return array{subject:string,text:string,html:string} */
    private function render(string $type, string $recipient = 'ana.cancel@example.test'): array
    {
        return $this->renderer->render($this->orderId, $this->requestId, $recipient, $type);
    }

    private function decide(string $status, string $reason = ''): void
    {
        $this->pdo->prepare("UPDATE order_cancellation_requests SET status=?,decision_reason=?,resolved_at='2026-11-08 11:15:00' WHERE id=?")->execute([$status, $reason, $this->requestId]);
        if ($status === 'accepted') { $this->pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$this->orderId]); }
    }

    private function queue(string $type, string $recipient): int
    {
        $this->pdo->prepare("INSERT INTO outbox_messages(message_type,recipient,payload_json,status,attempts,available_at,created_at) VALUES(?,?,?,'pending',0,?,?)")
            ->execute([$type, $recipient, json_encode(['order_id'=>$this->orderId, 'request_id'=>$this->requestId], JSON_THROW_ON_ERROR), gmdate('c'), gmdate('c')]);
        return (int) $this->pdo->lastInsertId();
    }
}

final class CancellationRecordingTransport extends AbstractTransport
{
    /** @var list<SentMessage> */
    public array $messages = [];
    /** @var list<bool> */
    public array $transactionStates = [];
    public ?string $failedRecipient = 'first@example.test';

    public function __construct(private readonly PDO $pdo) { parent::__construct(); }

    protected function doSend(SentMessage $message): void
    {
        $this->transactionStates[] = $this->pdo->inTransaction();
        if ($message->getEnvelope()->getRecipients()[0]->getAddress() === $this->failedRecipient) {
            throw new TransportException('Test transport failure; no external network.');
        }
        $this->messages[] = $message;
    }

    public function __toString(): string { return 'test-cancellation://no-network'; }
}
