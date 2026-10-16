<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Orders\OrderService;
use App\Shared\Config\Config;
use RuntimeException;
use Tests\DatabaseTestCase;

final class CheckoutTest extends DatabaseTestCase
{
    public function testServerPriceAndSingleReservationAreAuthoritative(): void
    {
        $this->seed();
        $variant = $this->pdo->query("SELECT v.id,v.price_bani,i.physical_quantity FROM product_variants v JOIN inventory i ON i.variant_id=v.id WHERE i.physical_quantity>1 LIMIT 1")->fetch();
        $cart = new CartService($this->pdo);
        $cart->add((int) $variant['id'], 2);
        $service = new CheckoutService($this->pdo, $cart, Config::load($this->root));
        $result = $service->place($this->checkoutData('idem-1') + ['price' => 1]);
        $order = $this->pdo->query('SELECT * FROM orders WHERE id=' . (int) $result['id'])->fetch();
        self::assertSame((int) $variant['price_bani'] * 2, (int) $order['subtotal_bani']);
        $inventory = $this->pdo->query('SELECT * FROM inventory WHERE variant_id=' . (int) $variant['id'])->fetch();
        self::assertSame(2, (int) $inventory['reserved_quantity']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM inventory_reservations WHERE order_id=' . (int) $result['id'])->fetchColumn());
    }

    public function testDuplicateIdempotencyKeyDoesNotCreateSecondOrder(): void
    {
        $this->seed();
        $variantId = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN inventory i ON i.variant_id=v.id WHERE i.physical_quantity>1 LIMIT 1")->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($variantId, 1);
        $service = new CheckoutService($this->pdo, $cart, Config::load($this->root));
        $service->place($this->checkoutData('same-key'));
        $_SESSION['cart_token'] = bin2hex(random_bytes(32));
        $cart->add($variantId, 1);
        $this->expectException(RuntimeException::class);
        try {
            $service->place($this->checkoutData('same-key'));
        } finally {
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        }
    }

    public function testRepeatedCancellationDoesNotReleaseTwice(): void
    {
        $this->seed();
        $variantId = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN inventory i ON i.variant_id=v.id WHERE i.physical_quantity>1 LIMIT 1")->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($variantId, 1);
        $result = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place($this->checkoutData('cancel-key'));
        $userNow = gmdate('c');
        $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES ('admin@test.local',:password,'Admin','Test','administrator','active',:now,:now,:now)")->execute(['password' => password_hash('long-password-for-test', PASSWORD_DEFAULT), 'now' => $userNow]);
        $actor = (int) $this->pdo->lastInsertId();
        $orders = new OrderService($this->pdo);
        $orders->transition($result['id'], 'cancel', null, $actor);
        self::assertSame(0, (int) $this->pdo->query('SELECT reserved_quantity FROM inventory WHERE variant_id=' . $variantId)->fetchColumn());
        $this->expectException(RuntimeException::class);
        $orders->transition($result['id'], 'cancel', null, $actor);
    }

    public function testCouponIsCalculatedOnServerAndUsageIsReserved(): void
    {
        $this->seed();
        $now = gmdate('c');
        $this->pdo->prepare("INSERT INTO coupons (code,type,value,minimum_bani,usage_limit,per_customer_limit,status,created_at) VALUES ('TEST10','percent',1000,0,1,1,'active',:now)")->execute(['now' => $now]);
        $variant = $this->pdo->query("SELECT v.id,v.price_bani FROM product_variants v JOIN inventory i ON i.variant_id=v.id WHERE i.physical_quantity>1 ORDER BY v.price_bani ASC LIMIT 1")->fetch();
        $cart = new CartService($this->pdo);
        $cart->add((int) $variant['id'], 1);
        $cart->applyCoupon('test10');
        $summary = $cart->summary();
        self::assertSame(intdiv(((int) $variant['price_bani'] * 1000) + 5000, 10000), $summary['discount_bani']);
        $result = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place($this->checkoutData('coupon-key'));
        $order = $this->pdo->query('SELECT * FROM orders WHERE id=' . (int) $result['id'])->fetch();
        self::assertSame($summary['discount_bani'], (int) $order['discount_bani']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM coupon_redemptions WHERE status='reserved'")->fetchColumn());

        $_SESSION['cart_token'] = bin2hex(random_bytes(32));
        $cart->add((int) $variant['id'], 1);
        $this->expectException(RuntimeException::class);
        $cart->applyCoupon('TEST10');
    }

    /** @return array<string,mixed> */
    private function checkoutData(string $key): array
    {
        return ['email'=>'client@example.test','first_name'=>'Ana','last_name'=>'Pop','phone'=>'0700000000','address'=>'Strada Test 1','city'=>'București','county'=>'București','postal_code'=>'010101','payment_method'=>'cod','idempotency_key'=>$key,'terms'=>'1'];
    }
}
