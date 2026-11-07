<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Orders\InternalOrderEmail;
use App\Modules\Orders\OrderEmailImages;
use App\Modules\Orders\OrderService;
use App\Modules\Orders\OrderTrackingRepository;
use App\Modules\Orders\OrderTrackingService;
use App\Shared\Config\Config;
use DomainException;
use Tests\DatabaseTestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class InternalOrderEmailTest extends DatabaseTestCase
{
    private int $orderId;
    private InternalOrderEmail $email;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $_ENV['APP_URL'] = 'https://shop.example.test';
        $_ENV['APP_BASE_PATH'] = '/boutique';
        $config = Config::load($this->root);
        $variant = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND i.physical_quantity>=2 ORDER BY v.id LIMIT 1")->fetchColumn();
        $shipping = (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($variant, 2);
        $order = (new CheckoutService($this->pdo, $cart, $config))->place([
            'email'=>'ana.internal-fixture@example.test', 'first_name'=>'Ana', 'last_name'=>'Pop', 'phone'=>'0700000000',
            'address'=>'Strada Florilor 10, apartament 2', 'city'=>'București — Sector 1', 'county'=>'București', 'postal_code'=>'010101',
            'shipping_method_id'=>(string) $shipping, 'payment_method'=>'cod', 'terms'=>'1', 'idempotency_key'=>bin2hex(random_bytes(32)),
        ]);
        $this->orderId = $order['id'];
        $this->pdo->exec("UPDATE orders SET subtotal_bani=20000,discount_bani=2500,shipping_bani=500,shipping_discount_bani=1400,total_bani=18000,shipping_method_name='Curier la adresă — Standard',created_at='2026-11-07T10:30:00+00:00'");
        $this->pdo->exec('UPDATE order_items SET quantity=2,unit_price_bani=10000,line_total_bani=20000');
        $tracking = new OrderTrackingService(new OrderTrackingRepository($this->pdo), new OrderService($this->pdo), $config, $this->root);
        $this->email = new InternalOrderEmail($tracking, $config,
            new Environment(new FilesystemLoader($this->root . '/resources/views'), ['autoescape'=>'html', 'strict_variables'=>true]),
            new OrderEmailImages($config, $this->root));
    }

    protected function tearDown(): void
    {
        unset($this->email);
        parent::tearDown();
    }

    public function testInternalEmailHasTheRequestedSnapshotAndOnlyAnAuthenticatedAdminLink(): void
    {
        $mail = $this->email->render($this->orderId, 'orders@example.test');
        self::assertStringStartsWith('Ai primit o nouă comandă! LM-', $mail['subject']);
        foreach (['Ai primit o nouă comandă în valoare de', '180,00 Lei', 'Deschide comanda', 'Produsele comandate',
            'Ana Pop', 'Strada Florilor 10, apartament 2', 'București', '010101', '0700000000', 'ana.internal-fixture@example.test',
            'Curier la adresă — Standard', '07.11.2026 · 12:30', '200,00 Lei', '−25,00 Lei', '19,00 Lei', '−14,00 Lei',
            'SKU:', '100,00 Lei', 'Ramburs', 'De achitat la livrare', '1–3 zile lucrătoare', 'Reduceri aplicate:', '39,00 Lei'] as $text) {
            self::assertStringContainsString($text, $mail['html']);
            if ($text !== 'Produsele comandate') { self::assertStringContainsString($text, $mail['text']); }
        }
        $url = 'https://shop.example.test/boutique/admin/comenzi/' . $this->orderId . '/';
        self::assertStringContainsString('href="' . $url . '"', $mail['html']);
        self::assertStringContainsString($url, $mail['text']);
        self::assertStringContainsString('/assets/images/logo-la-mariuca-transparent.png', $mail['html']);
        self::assertMatchesRegularExpression('~https://shop.example.test/boutique/media/email/[a-f0-9]+\.jpg~', $mail['html']);
        self::assertStringContainsString('<v:roundrect', $mail['html']);
        self::assertStringContainsString('&copy;', $mail['html']);
        foreach (['urmarire-comanda', 'fără autentificare', 'Bună, Ana', 'Ai economisit', 'Cu tine, și după comandă.', 'CAB IT', 'ANPC', 'email-customer-care.png', 'Linkul de urmărire'] as $absent) {
            self::assertStringNotContainsString($absent, $mail['html']);
            self::assertStringNotContainsString($absent, $mail['text']);
        }
        self::assertLessThan(strpos($mail['html'], 'Detalii de livrare'), strpos($mail['html'], 'Metoda de plată'));
        self::assertLessThan(strpos($mail['html'], 'Metoda de plată'), strpos($mail['html'], 'Rezumatul comenzii'));
    }

    public function testPaidCardIsClearlyPaidAndManualReviewDoesNotPromiseFulfilment(): void
    {
        $this->pdo->exec("UPDATE orders SET payment_method='stripe',payment_status='paid',status='confirmed'");
        $paid = $this->email->render($this->orderId, 'orders@example.test');
        self::assertStringContainsString('Card online', $paid['html']);
        self::assertStringContainsString('Plătit', $paid['html']);
        self::assertStringContainsString('Achitat integral.', $paid['html']);
        self::assertStringNotContainsString('necesită verificare', $paid['html']);
        $this->pdo->exec("UPDATE orders SET status='manual_review'");
        $review = $this->email->render($this->orderId, 'orders@example.test');
        self::assertStringContainsString('necesită verificare înainte de pregătire', $review['html']);
        self::assertStringContainsString('email-status-review.png', $review['html']);
        self::assertStringContainsString('Plătit', $review['html']);
    }

    public function testNaivePersistedUtcDateIsDisplayedInBucharestEvenWithAnotherDefaultTimezone(): void
    {
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Europe/Bucharest');
            $this->pdo->exec("UPDATE orders SET created_at='2026-11-07 10:30:00'");
            $mail = $this->email->render($this->orderId, 'orders@example.test');
            self::assertStringContainsString('07.11.2026 · 12:30', $mail['html']);
            self::assertStringContainsString('07.11.2026 · 12:30', $mail['text']);
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function testUnpaidCardCannotBeAnnouncedAsANewInternalOrder(): void
    {
        $this->pdo->exec("UPDATE orders SET payment_method='stripe',payment_status='pending'");
        $this->expectException(DomainException::class);
        $this->email->render($this->orderId, 'orders@example.test');
    }

    public function testCancelledOrderCannotBeAnnouncedAsNew(): void
    {
        $this->pdo->exec("UPDATE orders SET status='cancelled'");
        $this->expectException(DomainException::class);
        $this->email->render($this->orderId, 'orders@example.test');
    }

    public function testRecipientHeaderInjectionIsRejectedBeforeRendering(): void
    {
        $this->expectException(DomainException::class);
        $this->email->render($this->orderId, "orders@example.test\r\nBcc:other@example.test");
    }

    public function testCustomerTextIsEscapedAndZeroSavingsHaveNoCelebration(): void
    {
        $this->pdo->prepare('UPDATE orders SET first_name=:name,discount_bani=0,shipping_discount_bani=0,shipping_bani=0,total_bani=subtotal_bani')->execute(['name'=>'<script>alert(1)</script>']);
        $this->pdo->prepare('UPDATE order_items SET product_name=:name,sku=:sku')->execute(['name'=>'<img src=x onerror=alert(1)>', 'sku'=>'<b>SKU</b>']);
        $mail = $this->email->render($this->orderId, 'orders@example.test');
        self::assertStringNotContainsString('<script>', $mail['html']);
        self::assertStringNotContainsString('<img src=x', $mail['html']);
        self::assertStringContainsString('&lt;script&gt;', $mail['html']);
        self::assertStringContainsString('&lt;b&gt;SKU&lt;/b&gt;', $mail['html']);
        self::assertStringNotContainsString('Reduceri aplicate:', $mail['html']);
        self::assertStringNotContainsString('email-savings-confetti.gif', $mail['html']);
    }
}
