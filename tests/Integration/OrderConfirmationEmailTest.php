<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Orders\OrderConfirmationEmail;
use App\Modules\Orders\OrderEmailImages;
use App\Modules\Orders\OrderService;
use App\Modules\Orders\OrderTrackingRepository;
use App\Modules\Orders\OrderTrackingService;
use App\Shared\Config\Config;
use DomainException;
use Tests\DatabaseTestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class OrderConfirmationEmailTest extends DatabaseTestCase
{
    private int $orderId;
    private OrderTrackingService $tracking;
    private OrderConfirmationEmail $email;
    private Config $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $_ENV['APP_URL'] = 'https://shop.example.test';
        $_ENV['APP_BASE_PATH'] = '/boutique';
        $this->config = Config::load($this->root);
        $variant = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND i.physical_quantity>=2 ORDER BY v.id LIMIT 1")->fetchColumn();
        $shipping = (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($variant, 2);
        $order = (new CheckoutService($this->pdo, $cart, $this->config))->place([
            'email'=>'ana.email@example.test', 'first_name'=>'Ana', 'last_name'=>'Pop', 'phone'=>'0700000000',
            'address'=>'Strada Florilor 10, apartament 2', 'city'=>'București — Sector 1', 'county'=>'București', 'postal_code'=>'010101',
            'shipping_method_id'=>(string) $shipping, 'payment_method'=>'cod', 'terms'=>'1', 'idempotency_key'=>bin2hex(random_bytes(32)),
        ]);
        $this->orderId = $order['id'];
        $this->pdo->exec("UPDATE orders SET subtotal_bani=20000,discount_bani=2500,shipping_bani=500,shipping_discount_bani=1400,total_bani=18000,shipping_method_name='Curier la adresă — Standard',created_at='2026-11-07T10:30:00+00:00'");
        $this->pdo->exec('UPDATE order_items SET quantity=2,unit_price_bani=10000,line_total_bani=20000');
        $this->tracking = new OrderTrackingService(new OrderTrackingRepository($this->pdo), new OrderService($this->pdo), $this->config, $this->root);
        $this->email = new OrderConfirmationEmail($this->tracking, $this->config,
            new Environment(new FilesystemLoader($this->root . '/resources/views'), ['autoescape'=>'html', 'strict_variables'=>true]),
            new OrderEmailImages($this->config, $this->root));
    }

    protected function tearDown(): void
    {
        unset($this->email, $this->tracking);
        parent::tearDown();
    }

    public function testCashConfirmationContainsSnapshotAmountsDeliveryPaymentAndPrivateTracking(): void
    {
        $mail = $this->email->render($this->orderId, 'ana.email@example.test');
        self::assertStringStartsWith('Comanda LM-', $mail['subject']);
        self::assertStringEndsWith('a fost primită cu succes', $mail['subject']);
        foreach (['Bună, Ana!', 'Comanda a fost plasată cu succes.', 'Ramburs', 'De achitat la livrare',
            'Ana Pop', 'Strada Florilor 10, apartament 2', 'București', '010101', '0700000000',
            'Curier la adresă — Standard', '07.11.2026 · 12:30', '200,00 Lei', '−25,00 Lei',
            '19,00 Lei', '−14,00 Lei', '180,00 Lei', '39,00 Lei', 'SKU:', '100,00 Lei'] as $value) {
            self::assertStringContainsString($value, $mail['html']);
            self::assertStringContainsString($value, $mail['text']);
        }
        self::assertStringContainsString('https://shop.example.test/boutique/assets/images/logo-la-mariuca-transparent.png', $mail['html']);
        self::assertMatchesRegularExpression('~https://shop.example.test/boutique/media/email/[a-f0-9]+\.jpg~', $mail['html']);
        $url = $this->tracking->emailUrl($this->orderId, 'ana.email@example.test');
        self::assertStringContainsString('href="' . $url . '"', $mail['html']);
        self::assertStringContainsString($url, $mail['text']);
        self::assertStringContainsString('<v:roundrect', $mail['html']);
        self::assertStringContainsString('role="presentation"', $mail['html']);
        self::assertStringNotContainsString('<script', $mail['html']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='order_confirmation'")->fetchColumn());
    }

    public function testPaidCardUsesPaidLabelAndConfirmationSubject(): void
    {
        $this->pdo->exec("UPDATE orders SET payment_method='stripe',payment_status='paid',status='confirmed'");
        $mail = $this->email->render($this->orderId, 'ana.email@example.test');
        self::assertStringEndsWith('a fost confirmată cu succes', $mail['subject']);
        foreach (['Comanda a fost confirmată cu succes.', 'Card online', 'Plătit', 'Achitat integral.'] as $text) {
            self::assertStringContainsString($text, $mail['html']);
        }
        self::assertStringContainsString('Card online — Plătit', $mail['text']);
        self::assertStringNotContainsString('De achitat la livrare', $mail['html']);
    }

    public function testUnpaidCardCannotGeneratePaidConfirmation(): void
    {
        $this->pdo->exec("UPDATE orders SET payment_method='stripe',payment_status='pending'");
        $this->expectException(DomainException::class);
        $this->email->render($this->orderId, 'ana.email@example.test');
    }

    public function testLatePaidCardInReviewDoesNotPromiseConfirmedOrder(): void
    {
        $this->pdo->exec("UPDATE orders SET payment_method='stripe',payment_status='paid',status='manual_review'");
        $mail = $this->email->render($this->orderId, 'ana.email@example.test');
        self::assertStringContainsString('Verificăm comanda.', $mail['html']);
        self::assertStringNotContainsString('Comanda a fost confirmată', $mail['html']);
        self::assertStringContainsString('Card online — Plătit', $mail['text']);
    }

    public function testCustomerAndProductValuesAreEscapedAndWrongRecipientIsRejected(): void
    {
        $this->pdo->exec("UPDATE orders SET first_name='<script>nume</script>',last_name='A & B'");
        $this->pdo->exec("UPDATE order_items SET product_name='<img src=x onerror=alert(1)>'");
        $mail = $this->email->render($this->orderId, 'ana.email@example.test');
        self::assertStringNotContainsString('<script>', $mail['html']);
        self::assertStringNotContainsString('<img src=x', $mail['html']);
        self::assertStringContainsString('&lt;script&gt;nume&lt;/script&gt;', $mail['html']);
        self::assertStringContainsString('A &amp; B', $mail['html']);
        self::assertStringContainsString('A & B', $mail['text']);
        $this->expectException(\RuntimeException::class);
        $this->email->render($this->orderId, 'someoneelse@example.test');
    }

    public function testNoDiscountAndMissingImagesDoNotProduceBrokenImageOrEmptySavings(): void
    {
        $this->pdo->exec('UPDATE orders SET discount_bani=0,shipping_discount_bani=0,shipping_bani=0,subtotal_bani=20000,total_bani=20000');
        $this->pdo->exec('UPDATE media SET public_path=NULL');
        $mail = $this->email->render($this->orderId, 'ana.email@example.test');
        self::assertStringNotContainsString('Reducere produse', $mail['html']);
        self::assertStringNotContainsString('Ai economisit', $mail['html']);
        self::assertStringNotContainsString('src=""', $mail['html']);
        self::assertStringContainsString('Gratuită', $mail['html']);
        $images = new OrderEmailImages($this->config, $this->root);
        self::assertNull($images->productUrl('../.env'));
        self::assertNull($images->productUrl('https://external.example.test/image.jpg'));
        self::assertNull($images->productUrl('/missing-image.webp'));
    }

    public function testDeliveryEstimateRetainsCheckoutSettingsAndSupportsADynamicProvider(): void
    {
        $before = $this->email->render($this->orderId, 'ana.email@example.test');
        self::assertStringContainsString('1–3 zile lucrătoare', $before['html']);
        self::assertStringContainsString('Estimare de la predarea coletului către curier.', $before['text']);
        $this->pdo->exec('UPDATE shipping_methods SET estimated_min_days=5,estimated_max_days=8');
        self::assertStringContainsString('1–3 zile lucrătoare', $this->email->render($this->orderId, 'ana.email@example.test')['html']);
        $dynamic = new class implements \App\Modules\Shipping\DeliveryEstimateProvider {
            public function forOrder(array $order): ?array
            {
                return ['label'=>'10–12 noiembrie', 'detail'=>'Estimare actualizată pentru destinația comenzii.'];
            }
        };
        $renderer = new OrderConfirmationEmail($this->tracking, $this->config,
            new Environment(new FilesystemLoader($this->root . '/resources/views'), ['autoescape'=>'html']),
            new OrderEmailImages($this->config, $this->root), $dynamic);
        self::assertStringContainsString('10–12 noiembrie', $renderer->render($this->orderId, 'ana.email@example.test')['html']);
        $this->pdo->exec('UPDATE orders SET shipping_estimate_json=NULL,shipping_method_id=NULL');
        self::assertStringNotContainsString('Termen estimat de livrare', $this->email->render($this->orderId, 'ana.email@example.test')['html']);
    }
}
