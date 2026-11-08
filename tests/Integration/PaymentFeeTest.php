<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Checkout\CheckoutAttemptService;
use App\Modules\Payments\PaymentFeeService;
use App\Modules\Payments\StripeCheckoutPayload;
use App\Shared\Config\Config;
use DomainException;
use PDO;
use Tests\DatabaseTestCase;

final class PaymentFeeTest extends DatabaseTestCase
{
    private array $environment;
    private int $adminId;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        parent::setUp();
        $this->seed();
        $_ENV['COD_ENABLED'] = 'true';
        $_ENV['STRIPE_ENABLED'] = 'true';
        $_ENV['STRIPE_MODE'] = 'test';
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_fee_checkout';
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_fee_checkout';
        $_ENV['WEB_PUSH_ENABLED'] = 'false';
        $_ENV['MAIL_DELIVERY_MODE'] = 'preview';
        $this->pdo->exec("INSERT INTO users (email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES ('fee-admin@example.test','unused','Ana','Admin','administrator','active','2026-11-08T10:00:00+00:00','2026-11-08T10:00:00+00:00')");
        $this->adminId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_ENV = $this->environment;
    }

    public function testCrudPerMethodUsesExactDecimalPricesAndAudit(): void
    {
        $service = new PaymentFeeService($this->pdo);
        self::assertSame(['items' => [], 'total_bani' => 0], $service->quote('cod'));
        $cod = $service->save(['payment_method' => 'cod', 'name' => 'Ambalare', 'price' => '3,45'], $this->adminId);
        $card = $service->save(['payment_method' => 'stripe', 'name' => 'Cutie cadou', 'price' => '10.50'], $this->adminId);
        self::assertSame(345, $service->quote('cod')['total_bani']);
        self::assertSame(1050, $service->quote('stripe')['total_bani']);
        $service->save(['payment_method' => 'stripe', 'name' => 'Ambalare specială', 'price' => '12'], $this->adminId, $card);
        self::assertSame(1200, $service->quote('stripe')['total_bani']);
        $service->delete($cod, $this->adminId);
        self::assertSame([], $service->quote('cod')['items']);
        self::assertCount(1, $service->all());
        self::assertSame(4, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity_type='payment_operational_fee'")->fetchColumn());
    }

    public function testInvalidPricesMethodsAndActorsCannotChangeFees(): void
    {
        $service = new PaymentFeeService($this->pdo);
        $cases = [
            ['payment_method' => 'stripe', 'name' => 'Ambalare', 'price' => '-1'],
            ['payment_method' => 'stripe', 'name' => 'Ambalare', 'price' => '1e3'],
            ['payment_method' => 'stripe', 'name' => 'Ambalare', 'price' => '1,234'],
            ['payment_method' => 'stripe', 'name' => 'Ambalare', 'price' => '0'],
            ['payment_method' => 'stripe', 'name' => 'Ambalare', 'price' => '10000,01'],
            ['payment_method' => 'other', 'name' => 'Ambalare', 'price' => '2'],
            ['payment_method' => 'cod', 'name' => '', 'price' => '2'],
        ];
        foreach ($cases as $data) {
            try { $service->save($data, $this->adminId); self::fail('Invalid fee accepted.'); }
            catch (DomainException) { self::assertSame([], $service->all()); }
        }
        $this->expectException(DomainException::class);
        $service->save(['payment_method' => 'cod', 'name' => 'Ambalare', 'price' => '2'], 0);
    }

    public function testDuplicateFeeNameWithinMethodIsRejected(): void
    {
        $service = new PaymentFeeService($this->pdo);
        $service->save(['payment_method' => 'cod', 'name' => 'Ambalare', 'price' => '2'], $this->adminId);
        $service->save(['payment_method' => 'stripe', 'name' => 'Ambalare', 'price' => '3'], $this->adminId);
        $this->expectException(DomainException::class);
        $service->save(['payment_method' => 'cod', 'name' => 'AMBALARE', 'price' => '4'], $this->adminId);
    }

    public function testOrderFreezesServerFeeSnapshotIgnoresSubmittedAmountsAndUsesOnlyChosenMethod(): void
    {
        $service = new PaymentFeeService($this->pdo);
        $id = $service->save(['payment_method' => 'cod', 'name' => 'Ambalare', 'price' => '3,45'], $this->adminId);
        $service->save(['payment_method' => 'stripe', 'name' => 'Serviciu special', 'price' => '7'], $this->adminId);
        $result = $this->place('cod', ['payment_fee_bani' => '-999999', 'payment_fees_json' => '[]']);
        $order = $this->pdo->query('SELECT * FROM orders WHERE id=' . $result['id'])->fetch(PDO::FETCH_ASSOC);
        self::assertSame(345, (int) $order['payment_fee_bani']);
        self::assertSame((int) $order['subtotal_bani'] - (int) $order['discount_bani'] + (int) $order['shipping_bani'] + 345, (int) $order['total_bani']);
        self::assertSame([['id' => $id, 'name' => 'Ambalare', 'amount_bani' => 345]], PaymentFeeService::snapshot($order));
        $service->save(['payment_method' => 'cod', 'name' => 'Altă denumire', 'price' => '8'], $this->adminId, $id);
        $service->delete($id, $this->adminId);
        $after = $this->pdo->query('SELECT * FROM orders WHERE id=' . $result['id'])->fetch(PDO::FETCH_ASSOC);
        self::assertSame($order['payment_fees_json'], $after['payment_fees_json']);
        self::assertSame($order['total_bani'], $after['total_bani']);
        self::assertSame($order['total_bani'], $this->pdo->query('SELECT amount_bani FROM payments WHERE order_id=' . $result['id'])->fetchColumn());
    }

    public function testStripeFeeLinesAndDiscountedSummaryReconcileWithoutIncreasingDiscount(): void
    {
        $service = new PaymentFeeService($this->pdo);
        $service->save(['payment_method' => 'stripe', 'name' => 'Ambalare premium', 'price' => '10,50'], $this->adminId);
        $result = $this->place('stripe');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        $attempts = new CheckoutAttemptService($this->pdo, Config::load($this->root));
        $attempt = $attempts->byId($result['id']);
        self::assertIsArray($attempt);
        $order = $attempts->snapshot($attempt);
        $order['checkout_attempt_id'] = $result['id'];
        $order['discount_bani'] = 101;
        $order['total_bani'] -= 101;
        $items = $order['items'];
        $builder = new StripeCheckoutPayload(Config::load($this->root));
        $payload = $builder->build($order, $items, $result['token']);
        $last = $payload['line_items'][array_key_last($payload['line_items'])];
        self::assertSame('Ambalare premium', $last['price_data']['product_data']['name']);
        self::assertSame(1050, $last['price_data']['unit_amount']);
        $coupon = $builder->coupon($order, ['prod_merchandise']);
        self::assertSame(101, $coupon['amount_off']);
        self::assertSame(['products' => ['prod_merchandise']], $coupon['applies_to']);
        $gross = array_sum(array_map(static fn (array $line): int => $line['quantity'] * $line['price_data']['unit_amount'], $payload['line_items']));
        self::assertSame((int) $order['total_bani'], $gross - $builder->coupon($order)['amount_off']);
        $order['payment_fee_bani']++;
        $this->expectException(\RuntimeException::class);
        $builder->build($order, $items, $result['token']);
    }

    private function place(string $method, array $additional = []): array
    {
        $variant = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND v.status='active' AND i.physical_quantity-i.reserved_quantity>4 ORDER BY v.id LIMIT 1")->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($variant, 2);
        $checkout = new CheckoutService($this->pdo, $cart, Config::load($this->root));
        $options = $checkout->paymentOptions($cart->summary());
        self::assertArrayHasKey('fee_bani', $options['methods'][$method]);
        return $checkout->place($additional + [
            'idempotency_key' => bin2hex(random_bytes(32)), 'email' => 'fees@example.test', 'first_name' => 'Ana', 'last_name' => 'Test',
            'phone' => '0712345678', 'address' => 'Strada Test nr. 1', 'city' => 'Cluj-Napoca', 'county' => 'Cluj', 'postal_code' => '', 'terms' => '1', 'payment_method' => $method,
            'shipping_method_id' => (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn(),
        ]);
    }
}
