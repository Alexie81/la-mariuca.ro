<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Shared\Config\Config;
use App\Shared\Mail\OutboxProcessor;
use PDO;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Tests\DatabaseTestCase;

final class OutboxProcessorTest extends DatabaseTestCase
{
    /** @var array<string,mixed> */
    private array $originalEnv;
    private int $orderId;
    private int $originalMessageId;
    private ?string $previewRoot = null;

    protected function setUp(): void
    {
        $this->originalEnv = $_ENV;
        parent::setUp();
        $this->seed();
        $_ENV['APP_URL'] = 'https://shop.example.test';
        $_ENV['APP_BASE_PATH'] = '/boutique';
        $_ENV['MAIL_DELIVERY_MODE'] = 'preview';
        $_ENV['MAIL_SEND_AFTER_ID'] = '0';
        $_ENV['MAIL_MESSAGE_TYPES'] = 'order_confirmation';
        $_ENV['MAILER_DSN'] = 'smtp://127.0.0.1:2525';
        $_ENV['MAIL_FROM_ADDRESS'] = 'sender@example.test';
        $_ENV['MAIL_FROM_NAME'] = 'La Măriuca Boutique';
        $_ENV['CONTACT_EMAIL'] = 'support@example.test';

        $variant = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND i.physical_quantity>=1 ORDER BY v.id LIMIT 1")->fetchColumn();
        $shipping = (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($variant, 1);
        $order = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place([
            'email'=>'ana.outbox@example.test', 'first_name'=>'Ana', 'last_name'=>'Pop', 'phone'=>'0700000000',
            'address'=>'Strada Florilor 10', 'city'=>'București — Sector 1', 'county'=>'București', 'postal_code'=>'010101',
            'shipping_method_id'=>(string) $shipping, 'payment_method'=>'cod', 'terms'=>'1',
            'idempotency_key'=>bin2hex(random_bytes(32)),
        ]);
        $this->orderId = $order['id'];
        $this->originalMessageId = (int) $this->pdo->query('SELECT MAX(id) FROM outbox_messages')->fetchColumn();
        // SMTP behavior is tested exclusively through an injected transport with no sockets.
        $_ENV['APP_ENV'] = 'development';
        $_ENV['MAIL_DELIVERY_MODE'] = 'smtp';
    }

    protected function tearDown(): void
    {
        if ($this->previewRoot !== null) {
            foreach (['storage/private/mail/' . $this->originalMessageId . '.txt', 'storage/private/mail/' . $this->originalMessageId . '.html',
                'resources/views/emails/order-confirmation.twig', 'resources/views/emails/order-confirmation-text.twig',
                'resources/views/emails/partials/order-delivery.twig', 'resources/views/emails/partials/order-payment.twig'] as $path) {
                $file = $this->previewRoot . '/' . $path;
                if (is_file($file)) { unlink($file); }
            }
            foreach (['storage/private/mail', 'storage/private', 'storage', 'resources/views/emails/partials', 'resources/views/emails', 'resources/views', 'resources', 'public', ''] as $directory) {
                $path = $this->previewRoot . ($directory === '' ? '' : '/' . $directory);
                if (is_dir($path)) { rmdir($path); }
            }
        }
        parent::tearDown();
        $_ENV = $this->originalEnv;
    }

    public function testOnlyNewConfirmationsAreSentAndRepeatedProcessingDoesNotDuplicateThem(): void
    {
        $_ENV['MAIL_SEND_AFTER_ID'] = (string) $this->originalMessageId;
        $unrelated = $this->queue('verify_email');
        $statusUpdate = $this->queue('order_status_update');
        $new = $this->queue('order_confirmation');
        $transport = new RecordingOutboxTransport($this->pdo);
        $processor = $this->processor($transport);

        self::assertSame(['processed'=>1, 'sent'=>1, 'previewed'=>0, 'failed'=>0], $processor->process());
        self::assertCount(1, $transport->messages);
        self::assertSame([false], $transport->transactionStates);
        $message = $transport->messages[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('ana.outbox@example.test', $message->getTo()[0]->getAddress());
        self::assertSame('sender@example.test', $message->getFrom()[0]->getAddress());
        self::assertSame('La Măriuca Boutique', $message->getFrom()[0]->getName());
        self::assertSame('support@example.test', $message->getReplyTo()[0]->getAddress());
        self::assertStringContainsString('a fost primită cu succes', (string) $message->getSubject());
        self::assertStringContainsString('Urmărește comanda', (string) $message->getHtmlBody());
        self::assertStringContainsString('Ramburs', (string) $message->getTextBody());
        self::assertStringContainsString('src="cid:', (string) $message->getHtmlBody());
        self::assertStringNotContainsString('src="https://shop.example.test/boutique/', (string) $message->getHtmlBody());
        self::assertNotEmpty($message->getAttachments());
        foreach ($message->getAttachments() as $image) {
            self::assertSame('image', $image->getMediaType());
            self::assertSame('inline', $image->getDisposition());
        }
        self::assertStringContainsString('Content-ID:', $transport->messages[0]->toString());
        self::assertSame('sent', $this->message($new)['status']);
        self::assertNotNull($this->message($new)['sent_at']);
        foreach ([$this->originalMessageId, $unrelated, $statusUpdate] as $untouched) {
            self::assertSame('pending', $this->message($untouched)['status']);
            self::assertSame(0, (int) $this->message($untouched)['attempts']);
        }
        self::assertSame(['processed'=>0, 'sent'=>0, 'previewed'=>0, 'failed'=>0], $processor->process());
        self::assertCount(1, $transport->messages);
    }

    public function testTransportFailureIsRedactedAndRetryKeepsTheActivationFloor(): void
    {
        $_ENV['MAIL_SEND_AFTER_ID'] = (string) $this->originalMessageId;
        $new = $this->queue('order_confirmation');
        $transport = new RecordingOutboxTransport($this->pdo);
        $transport->fail = true;
        $processor = $this->processor($transport);
        $before = time();

        self::assertSame(['processed'=>1, 'sent'=>0, 'previewed'=>0, 'failed'=>1], $processor->process());
        $failed = $this->message($new);
        self::assertSame('failed', $failed['status']);
        self::assertSame(1, (int) $failed['attempts']);
        self::assertNull($failed['sent_at']);
        self::assertGreaterThanOrEqual($before + 300, strtotime((string) $failed['available_at']));
        self::assertNotEmpty($failed['last_error']);
        self::assertStringNotContainsString('fixture-secret', (string) $failed['last_error']);
        self::assertStringNotContainsString('AUTH', (string) $failed['last_error']);
        self::assertStringNotContainsString('smtp://', (string) $failed['last_error']);
        self::assertSame(0, $processor->process()['processed']);
        self::assertSame(1, $transport->attempts);

        $this->pdo->prepare('UPDATE outbox_messages SET available_at=:past WHERE id=:id')->execute(['past'=>gmdate('c', time() - 1), 'id'=>$new]);
        $transport->fail = false;
        self::assertSame(1, $this->processor($transport)->process()['sent']);
        self::assertSame(2, (int) $this->message($new)['attempts']);
        self::assertNull($this->message($new)['last_error']);
        self::assertSame('pending', $this->message($this->originalMessageId)['status']);
        self::assertCount(1, $transport->messages);
    }

    public function testSavingsAnimationRemainsAnUnmodifiedInlineGifInTheSentEmail(): void
    {
        $asset = $this->root . '/public/assets/images/email-savings-confetti.gif';
        self::assertFileExists($asset);
        $originalGif = file_get_contents($asset);
        self::assertIsString($originalGif);
        self::assertStringStartsWith('GIF8', $originalGif);
        $this->pdo->prepare('UPDATE orders SET discount_bani=1000,total_bani=total_bani-1000 WHERE id=?')->execute([$this->orderId]);
        $transport = new RecordingOutboxTransport($this->pdo);

        self::assertSame(['processed'=>1, 'sent'=>1, 'previewed'=>0, 'failed'=>0], $this->processor($transport)->process());
        self::assertCount(1, $transport->messages);
        $message = $transport->messages[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $message);
        $animations = array_values(array_filter($message->getAttachments(), static fn ($part): bool => $part->getContentType() === 'image/gif'));
        self::assertCount(1, $animations);
        $animation = $animations[0];
        self::assertSame('inline', $animation->getDisposition());
        // Byte equality protects every animation frame; no flattening or image conversion is allowed.
        self::assertSame($originalGif, $animation->getBody());
        self::assertStringContainsString('src="cid:' . $animation->getName() . '"', (string) $message->getHtmlBody());
        self::assertStringNotContainsString('/assets/images/email-savings-confetti.gif', (string) $message->getHtmlBody());

        $wire = $transport->messages[0]->toString();
        self::assertStringContainsString('Content-Type: image/gif', $wire);
        self::assertStringContainsString('Content-ID: <' . $animation->getContentId() . '>', $wire);
        self::assertStringContainsString('src="cid:' . $animation->getContentId() . '"', quoted_printable_decode($wire));
        self::assertSame([false], $transport->transactionStates);
    }

    public function testUnpaidCardConfirmationDoesNotReachTransport(): void
    {
        $this->pdo->prepare("UPDATE orders SET payment_method='stripe',payment_status='pending',status='new' WHERE id=:id")->execute(['id'=>$this->orderId]);
        $transport = new RecordingOutboxTransport($this->pdo);
        self::assertSame(['processed'=>1, 'sent'=>0, 'previewed'=>0, 'failed'=>1], $this->processor($transport)->process());
        self::assertSame(0, $transport->attempts);
        self::assertSame('failed', $this->message($this->originalMessageId)['status']);
        self::assertNull($this->message($this->originalMessageId)['sent_at']);
    }

    public function testInternalRecipientsAreIndependentAndCustomerConfirmationStillSends(): void
    {
        $_ENV['MAIL_MESSAGE_TYPES'] = 'order_confirmation,internal_order_notification';
        $first = $this->queue('internal_order_notification', 'orders-first@example.test');
        $second = $this->queue('internal_order_notification', 'orders-second@example.test');
        $transport = new RecordingOutboxTransport($this->pdo);
        $transport->failRecipients = ['orders-first@example.test'];

        self::assertSame(['processed'=>3, 'sent'=>2, 'previewed'=>0, 'failed'=>1], $this->processor($transport)->process());
        self::assertCount(2, $transport->messages);
        $customer = $transport->messages[0]->getOriginalMessage();
        $internal = $transport->messages[1]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $customer);
        self::assertInstanceOf(Email::class, $internal);
        self::assertSame('ana.outbox@example.test', $customer->getTo()[0]->getAddress());
        self::assertStringContainsString('Urmărește comanda', (string) $customer->getHtmlBody());
        self::assertCount(1, $internal->getTo());
        self::assertSame([], $internal->getCc());
        self::assertSame([], $internal->getBcc());
        self::assertSame('orders-second@example.test', $internal->getTo()[0]->getAddress());
        self::assertStringStartsWith('Ai primit o nouă comandă!', (string) $internal->getSubject());
        self::assertStringContainsString('Deschide comanda', (string) $internal->getHtmlBody());
        self::assertStringContainsString('src="cid:', (string) $internal->getHtmlBody());
        self::assertStringNotContainsString('urmarire-comanda', (string) $internal->getHtmlBody());
        self::assertStringNotContainsString('orders-first@example.test', (string) $internal->getHtmlBody());
        self::assertSame('failed', $this->message($first)['status']);
        self::assertSame('sent', $this->message($second)['status']);
        self::assertSame([false, false, false], $transport->transactionStates);

        $transport->failRecipients = [];
        $this->pdo->prepare('UPDATE outbox_messages SET available_at=:now WHERE id=:id')->execute(['now'=>gmdate('c', time()-1), 'id'=>$first]);
        self::assertSame(['processed'=>1, 'sent'=>1, 'previewed'=>0, 'failed'=>0], $this->processor($transport)->process());
        self::assertCount(3, $transport->messages);
        self::assertSame('sent', $this->message($first)['status']);
        self::assertSame(1, (int) $this->message($second)['attempts']);
        self::assertSame(1, (int) $this->message($this->originalMessageId)['attempts']);
    }

    public function testAcceptedSmtpIsNotRetriedWhenFinalDatabaseUpdateFails(): void
    {
        $this->pdo->exec("CREATE TRIGGER fail_outbox_completion BEFORE UPDATE ON outbox_messages WHEN NEW.status='sent' BEGIN SELECT RAISE(ABORT, 'fixture completion failure'); END");
        $transport = new RecordingOutboxTransport($this->pdo);
        $processor = $this->processor($transport);

        self::assertSame(['processed'=>1, 'sent'=>1, 'previewed'=>0, 'failed'=>1], $processor->process());
        self::assertCount(1, $transport->messages);
        $message = $this->message($this->originalMessageId);
        self::assertSame('processing', $message['status']);
        self::assertSame(1, (int) $message['attempts']);
        self::assertNull($message['sent_at']);
        self::assertStringContainsString('SMTP a acceptat mesajul', (string) $message['last_error']);
        self::assertStringContainsString('verificare manuală', (string) $message['last_error']);
        self::assertStringNotContainsString('fixture completion failure', (string) $message['last_error']);

        // Even after the database is healthy again, an accepted message must not be resent.
        $this->pdo->exec('DROP TRIGGER fail_outbox_completion');
        self::assertSame(['processed'=>0, 'sent'=>0, 'previewed'=>0, 'failed'=>0], $this->processor($transport)->process());
        self::assertSame(1, $transport->attempts);
        self::assertCount(1, $transport->messages);
    }

    public function testPreviewWritesPrivateFilesWithoutCallingSmtpOrCountingDelivery(): void
    {
        $_ENV['MAIL_DELIVERY_MODE'] = 'preview';
        $this->previewRoot = sys_get_temp_dir() . '/la-mariuca-outbox-preview-' . bin2hex(random_bytes(8));
        mkdir($this->previewRoot . '/resources/views/emails', 0770, true);
        mkdir($this->previewRoot . '/resources/views/emails/partials', 0770, true);
        mkdir($this->previewRoot . '/public', 0770, true);
        foreach (['order-confirmation.twig', 'order-confirmation-text.twig', 'partials/order-delivery.twig', 'partials/order-payment.twig'] as $template) {
            copy($this->root . '/resources/views/emails/' . $template, $this->previewRoot . '/resources/views/emails/' . $template);
        }
        $transport = new RecordingOutboxTransport($this->pdo);
        $transport->fail = true;
        $processor = new OutboxProcessor($this->pdo, Config::load($this->root), $this->previewRoot, $transport);
        self::assertSame(['processed'=>1, 'sent'=>0, 'previewed'=>1, 'failed'=>0], $processor->process());
        self::assertSame(0, $transport->attempts);
        $base = $this->previewRoot . '/storage/private/mail/' . $this->originalMessageId;
        self::assertFileExists($base . '.txt');
        self::assertFileExists($base . '.html');
        self::assertStringContainsString('Comanda a fost plasată cu succes.', (string) file_get_contents($base . '.html'));
        self::assertStringContainsString('https://shop.example.test/boutique/urmarire-comanda/acces/', (string) file_get_contents($base . '.txt'));
    }

    public function testFutureAndExhaustedRetriesAreNotClaimed(): void
    {
        $this->pdo->prepare("UPDATE outbox_messages SET status='failed',attempts=5 WHERE id=:id")->execute(['id'=>$this->originalMessageId]);
        $future = $this->queue('order_confirmation');
        $this->pdo->prepare('UPDATE outbox_messages SET available_at=:future WHERE id=:id')->execute(['future'=>gmdate('c', time() + 3600), 'id'=>$future]);
        $due = $this->queue('order_confirmation');
        $transport = new RecordingOutboxTransport($this->pdo);
        self::assertSame(['processed'=>1, 'sent'=>1, 'previewed'=>0, 'failed'=>0], $this->processor($transport)->process());
        self::assertSame(5, (int) $this->message($this->originalMessageId)['attempts']);
        self::assertSame(0, (int) $this->message($future)['attempts']);
        self::assertSame('sent', $this->message($due)['status']);
    }

    private function processor(RecordingOutboxTransport $transport): OutboxProcessor
    {
        return new OutboxProcessor($this->pdo, Config::load($this->root), $this->root, $transport);
    }

    private function queue(string $type, string $recipient = 'ana.outbox@example.test'): int
    {
        $this->pdo->prepare("INSERT INTO outbox_messages(message_type,recipient,payload_json,status,attempts,available_at,created_at) VALUES(:type,:recipient,:payload,'pending',0,:now,:now)")
            ->execute(['type'=>$type, 'recipient'=>$recipient, 'payload'=>json_encode(['order_id'=>$this->orderId], JSON_THROW_ON_ERROR), 'now'=>gmdate('c')]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function message(int $id): array
    {
        $query = $this->pdo->prepare('SELECT * FROM outbox_messages WHERE id=:id');
        $query->execute(['id'=>$id]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }
}

final class RecordingOutboxTransport extends AbstractTransport
{
    /** @var list<SentMessage> */
    public array $messages = [];
    /** @var list<bool> */
    public array $transactionStates = [];
    public int $attempts = 0;
    public bool $fail = false;
    /** @var list<string> */
    public array $failRecipients = [];

    public function __construct(private readonly PDO $pdo)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $this->attempts++;
        $this->transactionStates[] = $this->pdo->inTransaction();
        if ($this->fail || in_array($message->getEnvelope()->getRecipients()[0]->getAddress(), $this->failRecipients, true)) {
            throw new TransportException('AUTH fixture-secret at smtp://fixture-user:fixture-secret@127.0.0.1:2525');
        }
        $this->messages[] = $message;
    }

    public function __toString(): string
    {
        return 'test-recording://no-network';
    }
}
