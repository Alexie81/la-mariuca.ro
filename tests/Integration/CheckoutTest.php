<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Orders\OrderService;
use App\Modules\Payments\StripeWebhookController;
use App\Shared\Config\Config;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\DatabaseTestCase;

final class CheckoutTest extends DatabaseTestCase
{
    private const STRIPE_WEBHOOK_SECRET = 'whsec_checkout_test';

    public function testShippingQuotesUseTheSameDiscountsAndTotalsAsThePlacedOrder(): void
    {
        $this->seed();
        $variant = $this->availableVariant();
        $cart = new CartService($this->pdo);
        $cart->add((int) $variant['id'], 1);
        $this->insertPromotion('Reducere comandă', 'order', 'percent', 1000);
        $this->insertPromotion('Reducere transport', 'shipping', 'fixed', 1500);
        $methodId = $this->insertShippingMethod('Transport cotat', 'transport-cotat', 2490);
        $service = new CheckoutService($this->pdo, $cart, Config::load($this->root));
        $summary = $cart->summary();
        $quote = $service->quoteShipping($summary, ['price_bani' => 2490]);

        self::assertSame(990, $quote['shipping_bani']);
        self::assertSame(1500, $quote['shipping_discount_bani']);
        self::assertSame($summary['total_bani'] + 990, $quote['total_bani']);
        $lowerPrice = $service->quoteShipping($summary, ['price_bani' => 1000]);
        self::assertSame(0, $lowerPrice['shipping_bani']);
        self::assertSame(1000, $lowerPrice['shipping_discount_bani']);
        self::assertSame($summary['total_bani'], $lowerPrice['total_bani']);

        $result = $service->place(array_replace($this->checkoutData('quoted-order'), ['shipping_method_id' => (string) $methodId]));
        $order = $this->pdo->query('SELECT shipping_bani,shipping_discount_bani,total_bani FROM orders WHERE id=' . (int) $result['id'])->fetch();
        self::assertIsArray($order);
        foreach (['shipping_bani', 'shipping_discount_bani', 'total_bani'] as $field) {
            self::assertSame($quote[$field], (int) $order[$field]);
        }
    }

    public function testFailedCheckoutRetainsOnlyAllowedFormFieldsForTheNextRequest(): void
    {
        $this->seed();
        $app = AppFactory::create($this->root);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/finalizare-comanda/');
        self::assertSame(200, $app->handle($request)->getStatusCode());
        $data = array_replace($this->checkoutData('failed-checkout'), [
            '_csrf' => $_SESSION['_csrf'],
            'email' => 'invalid-email',
            'saved_address_id' => '',
            'marketing' => '1',
            'unrelated_secret' => 'must-not-persist',
            'phone' => str_repeat('1', 2100),
        ]);
        $response = $app->handle((new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/finalizare-comanda/')
            ->withParsedBody($data));
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/finalizare-comanda/', $response->getHeaderLine('Location'));
        $remembered = $_SESSION['checkout_old_input'];
        self::assertSame('invalid-email', $remembered['email']);
        self::assertSame('Strada Test 1', $remembered['address']);
        self::assertSame('', $remembered['saved_address_id']);
        self::assertSame('1', $remembered['marketing']);
        self::assertSame(2000, mb_strlen($remembered['phone']));
        self::assertArrayNotHasKey('_csrf', $remembered);
        self::assertArrayNotHasKey('idempotency_key', $remembered);
        self::assertArrayNotHasKey('unrelated_secret', $remembered);
        self::assertSame(200, $app->handle($request)->getStatusCode());
        self::assertArrayNotHasKey('checkout_old_input', $_SESSION);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        unset($app);
        gc_collect_cycles();
    }

    public function testNoScriptRecalculationKeepsShippingWithoutPlacingOrValidatingOrder(): void
    {
        $this->seed();
        $variant = $this->availableVariant();
        (new CartService($this->pdo))->add((int) $variant['id'], 1);
        $methodId = $this->insertShippingMethod('Livrare alternativă', 'recalculare', 2990);
        $app = AppFactory::create($this->root);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/finalizare-comanda/')
            ->withParsedBody([
                '_csrf' => (new \App\Shared\Security\Csrf())->token(),
                'checkout_action' => 'recalculate',
                'shipping_method_id' => (string) $methodId,
                'first_name' => 'Ana',
            ]);
        $response = $app->handle($request);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/finalizare-comanda/', $response->getHeaderLine('Location'));
        self::assertSame((string) $methodId, $_SESSION['checkout_old_input']['shipping_method_id']);
        self::assertSame('Ana', $_SESSION['checkout_old_input']['first_name']);
        self::assertSame('', $_SESSION['checkout_old_input']['terms']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM inventory_reservations')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM rate_limits')->fetchColumn());
        self::assertArrayNotHasKey('_flash', $_SESSION);
        unset($app);
        gc_collect_cycles();
    }

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

    public function testPersonalDiscountAndFreeShippingApplyAutomaticallyAndAreAudited(): void
    {
        $this->seed();
        $now = gmdate('c');
        $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES ('personal@example.test',:password,'Ana','Personal','customer','active',:now,:now,:now)")->execute(['password' => password_hash('Personal-2026!', PASSWORD_DEFAULT), 'now' => $now]);
        $customerId = (int) $this->pdo->lastInsertId();
        $discount = $this->pdo->prepare("INSERT INTO customer_discounts (user_id,scope,value_type,value,target_id,starts_at,ends_at,status,created_by_user_id,created_at,updated_at,deleted_at) VALUES (:user,:scope,:type,:value,NULL,NULL,NULL,'active',NULL,:now,:now,NULL)");
        $discount->execute(['user' => $customerId, 'scope' => 'order', 'type' => 'percent', 'value' => 1500, 'now' => $now]);
        $orderDiscountId = (int) $this->pdo->lastInsertId();
        $discount->execute(['user' => $customerId, 'scope' => 'shipping', 'type' => 'free', 'value' => 0, 'now' => $now]);
        $shippingDiscountId = (int) $this->pdo->lastInsertId();
        $_SESSION['user_id'] = $customerId;
        $variant = $this->pdo->query("SELECT v.id,v.price_bani FROM product_variants v JOIN inventory i ON i.variant_id=v.id WHERE i.physical_quantity>1 ORDER BY v.price_bani ASC LIMIT 1")->fetch();
        $cart = new CartService($this->pdo);
        $cart->add((int) $variant['id'], 1);
        $summary = $cart->summary();
        self::assertSame(intdiv(((int) $variant['price_bani'] * 1500) + 5000, 10000), $summary['discount_bani']);
        self::assertSame($orderDiscountId, (int) $summary['customer_discount']['id']);
        self::assertCount(1, $summary['customer_shipping_discounts']);

        $result = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place($this->checkoutData('personal-discount-key'));
        $order = $this->pdo->query('SELECT * FROM orders WHERE id=' . (int) $result['id'])->fetch();
        self::assertSame($orderDiscountId, (int) $order['customer_discount_id']);
        self::assertSame($shippingDiscountId, (int) $order['customer_shipping_discount_id']);
        self::assertSame(0, (int) $order['shipping_bani']);
        self::assertGreaterThan(0, (int) $order['shipping_discount_bani']);
    }

    public function testAutomaticOrderPromotionBeatsPersonalDiscountAndCouponWithoutReservingCoupon(): void
    {
        $this->seed();
        $customerId = $this->insertCustomer('promotion-winner@example.test');
        $personalId = $this->insertCustomerDiscount($customerId, 'order', 'percent', 2000);
        $promotionId = $this->insertPromotion('Promoție automată 30%', 'order', 'percent', 3000);
        $couponId = $this->insertCoupon('PROMO10', 1000);
        $_SESSION['user_id'] = $customerId;

        $variant = $this->availableVariant();
        $cart = new CartService($this->pdo);
        $cart->add((int) $variant['id'], 1);
        $cart->applyCoupon('promo10');
        $summary = $cart->summary();
        $expected = intdiv(((int) $variant['price_bani'] * 3000) + 5000, 10000);
        self::assertSame($expected, $summary['discount_bani']);
        self::assertSame('promotion', $summary['merchandise_discount']['offer_source']);
        self::assertSame($promotionId, (int) $summary['promotion']['id']);
        self::assertNull($summary['customer_discount']);
        self::assertNull($summary['coupon']);
        self::assertSame($couponId, (int) $summary['coupon_candidate']['id']);

        $result = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place($this->checkoutData('automatic-promotion-winner'));
        $order = $this->pdo->query('SELECT * FROM orders WHERE id=' . (int) $result['id'])->fetch();
        self::assertSame($promotionId, (int) $order['promotion_id']);
        self::assertNull($order['customer_discount_id']);
        self::assertSame($expected, (int) $order['discount_bani']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id=' . $couponId)->fetchColumn());
        self::assertSame($personalId, (int) $this->pdo->query('SELECT id FROM customer_discounts WHERE id=' . $personalId)->fetchColumn());
    }

    public function testCouponWinsAndRedemptionFollowsConfirmationAndCancellationLifecycle(): void
    {
        $this->seed();
        $customerId = $this->insertCustomer('coupon-winner@example.test');
        $this->insertCustomerDiscount($customerId, 'order', 'percent', 2000);
        $this->insertPromotion('Promoție automată 30%', 'order', 'percent', 3000);
        $couponId = $this->insertCoupon('MAX40', 4000);
        $adminId = $this->insertAdministrator();
        $_SESSION['user_id'] = $customerId;
        $variant = $this->availableVariant();

        $cart = new CartService($this->pdo);
        $cart->add((int) $variant['id'], 1);
        $cart->applyCoupon('MAX40');
        $summary = $cart->summary();
        $expected = intdiv(((int) $variant['price_bani'] * 4000) + 5000, 10000);
        self::assertSame($expected, $summary['discount_bani']);
        self::assertSame('coupon', $summary['merchandise_discount']['offer_source']);

        $checkout = new CheckoutService($this->pdo, $cart, Config::load($this->root));
        $confirmedOrder = $checkout->place($this->checkoutData('coupon-confirmed-order'));
        $confirmedRedemptionId = (int) $this->pdo->query('SELECT id FROM coupon_redemptions WHERE order_id=' . (int) $confirmedOrder['id'])->fetchColumn();
        $confirmedRow = $this->pdo->query('SELECT promotion_id,customer_discount_id,discount_bani FROM orders WHERE id=' . (int) $confirmedOrder['id'])->fetch();
        self::assertNull($confirmedRow['promotion_id']);
        self::assertNull($confirmedRow['customer_discount_id']);
        self::assertSame($expected, (int) $confirmedRow['discount_bani']);
        self::assertSame('reserved', $this->pdo->query('SELECT status FROM coupon_redemptions WHERE id=' . $confirmedRedemptionId)->fetchColumn());

        $orders = new OrderService($this->pdo);
        $orders->transition((int) $confirmedOrder['id'], 'confirm', null, $adminId);
        self::assertSame('confirmed', $this->pdo->query('SELECT status FROM coupon_redemptions WHERE id=' . $confirmedRedemptionId)->fetchColumn());

        $secondCart = new CartService($this->pdo);
        $secondCart->add((int) $variant['id'], 1);
        $secondCart->applyCoupon('MAX40');
        $cancelledOrder = (new CheckoutService($this->pdo, $secondCart, Config::load($this->root)))->place($this->checkoutData('coupon-cancelled-order'));
        $cancelledRedemptionId = (int) $this->pdo->query('SELECT id FROM coupon_redemptions WHERE order_id=' . (int) $cancelledOrder['id'])->fetchColumn();
        $orders->transition((int) $cancelledOrder['id'], 'cancel', null, $adminId);
        self::assertSame('released', $this->pdo->query('SELECT status FROM coupon_redemptions WHERE id=' . $cancelledRedemptionId)->fetchColumn());

        $orders->transition((int) $confirmedOrder['id'], 'cancel', null, $adminId);
        self::assertSame('released', $this->pdo->query('SELECT status FROM coupon_redemptions WHERE id=' . $confirmedRedemptionId)->fetchColumn(), 'Anularea unei comenzi confirmate trebuie să elibereze utilizarea cuponului.');
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id=' . $couponId)->fetchColumn());
    }

    public function testProductAndCategoryPromotionsDiscountOnlyEligibleLinesAndPersistWinner(): void
    {
        $this->seed();
        $target = $this->pdo->query("SELECT v.id,v.price_bani,p.id AS product_id,pc.category_id,c.parent_id
            FROM product_variants v
            JOIN products p ON p.id=v.product_id
            JOIN inventory i ON i.variant_id=v.id
            JOIN product_categories pc ON pc.product_id=p.id
            JOIN categories c ON c.id=pc.category_id
            WHERE p.status='published' AND v.status='active' AND i.physical_quantity-i.reserved_quantity>2
            ORDER BY v.price_bani ASC,v.id LIMIT 1")->fetch();
        self::assertIsArray($target);
        $other = $this->pdo->query("SELECT v.id,v.price_bani,p.id AS product_id
            FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id
            WHERE p.status='published' AND v.status='active' AND p.id<>" . (int) $target['product_id'] . " AND i.physical_quantity-i.reserved_quantity>1
            ORDER BY v.price_bani ASC,v.id LIMIT 1")->fetch();
        self::assertIsArray($other);

        $productPromotionId = $this->insertPromotion('Produs țintă 25%', 'product', 'percent', 2500, (int) $target['product_id']);
        $cart = new CartService($this->pdo);
        $cart->add((int) $target['id'], 1);
        $cart->add((int) $other['id'], 1);
        $summary = $cart->summary();
        $expectedProductDiscount = intdiv(((int) $target['price_bani'] * 2500) + 5000, 10000);
        self::assertSame($expectedProductDiscount, $summary['discount_bani']);
        self::assertSame((int) $target['price_bani'], (int) $summary['merchandise_discount']['eligible_bani']);
        $productOrder = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place($this->checkoutData('product-promotion-order'));
        self::assertSame($productPromotionId, (int) $this->pdo->query('SELECT promotion_id FROM orders WHERE id=' . (int) $productOrder['id'])->fetchColumn());

        $this->pdo->prepare("UPDATE promotions SET status='inactive',updated_at=:now WHERE id=:id")->execute(['now' => gmdate('c'), 'id' => $productPromotionId]);
        $categoryTargetId = (int) ($target['parent_id'] ?: $target['category_id']);
        $categoryPromotionId = $this->insertPromotion('Ramură categorie 15%', 'category', 'percent', 1500, $categoryTargetId);
        $categoryCart = new CartService($this->pdo);
        $categoryCart->add((int) $target['id'], 1);
        $categorySummary = $categoryCart->summary();
        $expectedCategoryDiscount = intdiv(((int) $target['price_bani'] * 1500) + 5000, 10000);
        self::assertSame($expectedCategoryDiscount, $categorySummary['discount_bani']);
        self::assertSame($categoryPromotionId, (int) $categorySummary['promotion']['id']);
        $categoryOrder = (new CheckoutService($this->pdo, $categoryCart, Config::load($this->root)))->place($this->checkoutData('category-promotion-order'));
        self::assertSame($categoryPromotionId, (int) $this->pdo->query('SELECT promotion_id FROM orders WHERE id=' . (int) $categoryOrder['id'])->fetchColumn());
    }

    public function testFreeShippingPromotionHonorsMinimumBeatsPersonalDiscountAndIsPersisted(): void
    {
        $previousFreeThreshold = $_ENV['SHIPPING_FREE_THRESHOLD_BANI'] ?? null;
        $_ENV['SHIPPING_FREE_THRESHOLD_BANI'] = '30000';

        try {
            $this->seed();
            $customerId = $this->insertCustomer('shipping-promotion@example.test');
            $personalShippingId = $this->insertCustomerDiscount($customerId, 'shipping', 'percent', 5000);
            $shippingPromotionId = $this->insertPromotion('Livrare globală gratuită de la 500 Lei', 'shipping', 'free', 0, null, 50000);
            $_SESSION['user_id'] = $customerId;

            $variant = $this->availableVariant();
            $this->pdo->prepare('UPDATE product_variants SET price_bani=20000,compare_price_bani=NULL,stock_unlimited=1,updated_at=:now WHERE id=:id')
                ->execute(['now' => gmdate('c'), 'id' => $variant['id']]);
            $cart = new CartService($this->pdo);
            $cart->add((int) $variant['id'], 2);

            $underThreshold = $cart->summary();
            self::assertSame(40000, (int) $underThreshold['subtotal_bani']);
            self::assertCount(1, $underThreshold['shipping_discounts']);
            $underBest = $cart->bestShippingDiscount(1900, $underThreshold['shipping_discounts']);
            self::assertSame(950, $underBest['discount_bani']);
            self::assertSame('customer', $underBest['discount']['offer_source']);

            $underResult = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place($this->checkoutData('shipping-promotion-under-threshold'));
            $underOrder = $this->pdo->query('SELECT * FROM orders WHERE id=' . (int) $underResult['id'])->fetch();
            self::assertNull($underOrder['shipping_promotion_id']);
            self::assertSame($personalShippingId, (int) $underOrder['customer_shipping_discount_id']);
            self::assertSame(950, (int) $underOrder['shipping_bani'], 'Campania configurată trebuie să înlocuiască pragul global vechi de 300 Lei.');
            self::assertSame(950, (int) $underOrder['shipping_discount_bani']);

            $cart = new CartService($this->pdo);
            $cart->add((int) $variant['id'], 3);
            $aboveThreshold = $cart->summary();
            self::assertSame(60000, (int) $aboveThreshold['subtotal_bani']);
            self::assertCount(2, $aboveThreshold['shipping_discounts']);
            $best = $cart->bestShippingDiscount(1900, $aboveThreshold['shipping_discounts']);
            self::assertSame(1900, $best['discount_bani']);
            self::assertSame('promotion', $best['discount']['offer_source']);
            self::assertSame($shippingPromotionId, (int) $best['discount']['id']);

            $result = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place($this->checkoutData('shipping-promotion-order'));
            $order = $this->pdo->query('SELECT * FROM orders WHERE id=' . (int) $result['id'])->fetch();
            self::assertSame($shippingPromotionId, (int) $order['shipping_promotion_id']);
            self::assertNull($order['customer_shipping_discount_id']);
            self::assertSame(0, (int) $order['shipping_bani']);
            self::assertSame(1900, (int) $order['shipping_discount_bani']);
            self::assertSame($personalShippingId, (int) $this->pdo->query('SELECT id FROM customer_discounts WHERE id=' . $personalShippingId)->fetchColumn());

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $this->pdo->prepare("UPDATE promotions SET status='inactive',updated_at=:now WHERE id=:id")
                ->execute(['now' => $now->format('c'), 'id' => $shippingPromotionId]);
            $this->pdo->prepare("UPDATE customer_discounts SET status='inactive',updated_at=:now WHERE id=:id")
                ->execute(['now' => $now->format('c'), 'id' => $personalShippingId]);
            $fallbackCart = new CartService($this->pdo);
            $fallbackCart->add((int) $variant['id'], 2);
            self::assertCount(0, $fallbackCart->summary()['shipping_discounts']);
            $fallbackResult = (new CheckoutService($this->pdo, $fallbackCart, Config::load($this->root)))->place($this->checkoutData('shipping-promotion-inactive-fallback'));
            $fallbackOrder = $this->pdo->query('SELECT * FROM orders WHERE id=' . (int) $fallbackResult['id'])->fetch();
            self::assertNull($fallbackOrder['shipping_promotion_id']);
            self::assertNull($fallbackOrder['customer_shipping_discount_id']);
            self::assertSame(1900, (int) $fallbackOrder['shipping_bani'], 'Fără o promoție activă, costul metodei de livrare trebuie păstrat integral.');
            self::assertSame(0, (int) $fallbackOrder['shipping_discount_bani']);

            $this->pdo->prepare("UPDATE promotions SET status='active',starts_at=:starts,ends_at=:ends,updated_at=:now WHERE id=:id")
                ->execute(['starts' => $now->modify('+1 day')->format('c'), 'ends' => $now->modify('+2 days')->format('c'), 'now' => $now->format('c'), 'id' => $shippingPromotionId]);
            $futureCart = new CartService($this->pdo);
            $futureCart->add((int) $variant['id'], 1);
            self::assertCount(0, $futureCart->summary()['shipping_discounts'], 'O campanie viitoare nu trebuie să schimbe politica prezentă.');
            $this->pdo->prepare('UPDATE promotions SET starts_at=:starts,ends_at=:ends,updated_at=:now WHERE id=:id')
                ->execute(['starts' => $now->modify('-2 days')->format('c'), 'ends' => $now->modify('-1 day')->format('c'), 'now' => $now->format('c'), 'id' => $shippingPromotionId]);
            self::assertCount(0, $futureCart->summary()['shipping_discounts'], 'O campanie expirată nu trebuie să schimbe politica prezentă.');
        } finally {
            $this->restoreEnvironment('SHIPPING_FREE_THRESHOLD_BANI', $previousFreeThreshold);
        }
    }

    public function testSelectedShippingMethodCostIsAuthoritativeAndLegacyFreeThresholdIsIgnored(): void
    {
        $previousFreeThreshold = $_ENV['SHIPPING_FREE_THRESHOLD_BANI'] ?? null;
        $_ENV['SHIPPING_FREE_THRESHOLD_BANI'] = '30000';

        try {
            $this->seed();
            $this->pdo->exec('DELETE FROM shipping_methods');
            $shippingMethodId = $this->insertShippingMethod('Curier rapid', 'curier-rapid', 2490, 'courier', 1, 2, 10, true);
            $variant = $this->availableVariant();
            $this->pdo->prepare('UPDATE product_variants SET price_bani=20000,compare_price_bani=NULL,stock_unlimited=1,updated_at=:now WHERE id=:id')
                ->execute(['now' => gmdate('c'), 'id' => $variant['id']]);
            $cart = new CartService($this->pdo);
            $cart->add((int) $variant['id'], 2);

            $result = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place(array_replace(
                $this->checkoutData('configured-shipping-authoritative'),
                [
                    'shipping_method_id' => (string) $shippingMethodId,
                    'shipping_price' => '0',
                    'shipping_method' => 'falsificat',
                    'shipping_method_name' => 'Metodă falsificată',
                ],
            ));
            $order = $this->pdo->query('SELECT * FROM orders WHERE id=' . (int) $result['id'])->fetch();
            self::assertIsArray($order);
            self::assertSame(40000, (int) $order['subtotal_bani']);
            self::assertSame(2490, (int) $order['shipping_bani'], 'Pragul vechi de 300 Lei nu mai poate acorda livrare gratuită în afara promoțiilor.');
            self::assertSame(0, (int) $order['shipping_discount_bani']);
            self::assertSame(42490, (int) $order['total_bani']);
            self::assertSame($shippingMethodId, (int) $order['shipping_method_id']);
            self::assertSame('curier-rapid', $order['shipping_method']);
            self::assertSame('Curier rapid', $order['shipping_method_name']);
        } finally {
            $this->restoreEnvironment('SHIPPING_FREE_THRESHOLD_BANI', $previousFreeThreshold);
        }
    }

    public function testCheckoutRejectsMissingUnknownAndInactiveShippingMethods(): void
    {
        $this->seed();
        $this->pdo->exec('DELETE FROM shipping_methods');
        $inactiveId = $this->insertShippingMethod('Curier oprit', 'curier-oprit', 1990, 'courier', 1, 3, 10, false, 'inactive');
        $variant = $this->availableVariant();
        $this->pdo->prepare('UPDATE product_variants SET stock_unlimited=1,updated_at=:now WHERE id=:id')
            ->execute(['now' => gmdate('c'), 'id' => $variant['id']]);
        $cart = new CartService($this->pdo);
        $cart->add((int) $variant['id'], 1);
        $checkout = new CheckoutService($this->pdo, $cart, Config::load($this->root));

        $missing = $this->checkoutData('shipping-method-missing');
        unset($missing['shipping_method_id']);
        $this->assertShippingMethodRejected(static fn () => $checkout->place($missing));
        $this->assertShippingMethodRejected(fn () => $checkout->place(array_replace(
            $this->checkoutData('shipping-method-unknown'),
            ['shipping_method_id' => '999999'],
        )));
        $this->assertShippingMethodRejected(fn () => $checkout->place(array_replace(
            $this->checkoutData('shipping-method-inactive'),
            ['shipping_method_id' => (string) $inactiveId],
        )));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    }

    public function testShippingPromotionsUseTheSelectedMethodCost(): void
    {
        $this->seed();
        $this->pdo->exec('DELETE FROM shipping_methods');
        $shippingMethodId = $this->insertShippingMethod('Curier premium', 'curier-premium', 3290, 'courier', 1, 1, 10, true);
        $variant = $this->availableVariant();
        $this->pdo->prepare('UPDATE product_variants SET price_bani=20000,compare_price_bani=NULL,stock_unlimited=1,updated_at=:now WHERE id=:id')
            ->execute(['now' => gmdate('c'), 'id' => $variant['id']]);

        $percentPromotionId = $this->insertPromotion('Jumătate din transport', 'shipping', 'percent', 5000);
        $percentCart = new CartService($this->pdo);
        $percentCart->add((int) $variant['id'], 1);
        $percentResult = (new CheckoutService($this->pdo, $percentCart, Config::load($this->root)))->place(array_replace(
            $this->checkoutData('shipping-method-percent-promotion'),
            ['shipping_method_id' => (string) $shippingMethodId],
        ));
        $percentOrder = $this->pdo->query('SELECT * FROM orders WHERE id=' . (int) $percentResult['id'])->fetch();
        self::assertIsArray($percentOrder);
        self::assertSame(1645, (int) $percentOrder['shipping_bani']);
        self::assertSame(1645, (int) $percentOrder['shipping_discount_bani']);
        self::assertSame($percentPromotionId, (int) $percentOrder['shipping_promotion_id']);
        self::assertSame($shippingMethodId, (int) $percentOrder['shipping_method_id']);

        $this->pdo->prepare("UPDATE promotions SET status='inactive',updated_at=:now WHERE id=:id")
            ->execute(['now' => gmdate('c'), 'id' => $percentPromotionId]);
        $freePromotionId = $this->insertPromotion('Transport din partea noastră', 'shipping', 'free', 0);
        $freeCart = new CartService($this->pdo);
        $freeCart->add((int) $variant['id'], 1);
        $freeResult = (new CheckoutService($this->pdo, $freeCart, Config::load($this->root)))->place(array_replace(
            $this->checkoutData('shipping-method-free-promotion'),
            ['shipping_method_id' => (string) $shippingMethodId],
        ));
        $freeOrder = $this->pdo->query('SELECT * FROM orders WHERE id=' . (int) $freeResult['id'])->fetch();
        self::assertIsArray($freeOrder);
        self::assertSame(0, (int) $freeOrder['shipping_bani']);
        self::assertSame(3290, (int) $freeOrder['shipping_discount_bani']);
        self::assertSame($freePromotionId, (int) $freeOrder['shipping_promotion_id']);
        self::assertSame('curier-premium', $freeOrder['shipping_method']);
        self::assertSame('Curier premium', $freeOrder['shipping_method_name']);
    }

    public function testStripeWebhookConfirmsAndTerminalSessionEventsReleaseCouponRedemptions(): void
    {
        $previousStripeEnabled = $_ENV['STRIPE_ENABLED'] ?? null;
        $previousStripeKey = $_ENV['STRIPE_SECRET_KEY'] ?? null;
        $previousWebhookSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? null;
        $_ENV['STRIPE_ENABLED'] = 'true';
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_checkout_webhook';
        $_ENV['STRIPE_WEBHOOK_SECRET'] = self::STRIPE_WEBHOOK_SECRET;

        try {
            $this->seed();
            $couponId = $this->insertCoupon('STRIPE35', 3500);
            $variant = $this->availableVariant();
            $config = Config::load($this->root);

            $paidCart = new CartService($this->pdo);
            $paidCart->add((int) $variant['id'], 1);
            $paidCart->applyCoupon('STRIPE35');
            $paidOrder = (new CheckoutService($this->pdo, $paidCart, $config))->place(array_replace(
                $this->checkoutData('stripe-paid-order'),
                ['payment_method' => 'stripe'],
            ));
            self::assertSame('reserved', $this->redemptionStatus((int) $paidOrder['id']));

            $completedUnpaid = $this->stripeWebhook('evt_checkout_completed_unpaid', 'checkout.session.completed', (int) $paidOrder['id'], 'unpaid');
            self::assertSame(200, $completedUnpaid->getStatusCode());
            self::assertSame('reserved', $this->redemptionStatus((int) $paidOrder['id']));
            $unpaidState = $this->pdo->query('SELECT status,payment_status FROM orders WHERE id=' . (int) $paidOrder['id'])->fetch();
            self::assertSame('new', $unpaidState['status']);
            self::assertSame('pending', $unpaidState['payment_status']);

            $completed = $this->stripeWebhook('evt_checkout_async_succeeded', 'checkout.session.async_payment_succeeded', (int) $paidOrder['id']);
            self::assertSame(200, $completed->getStatusCode());
            self::assertSame('confirmed', $this->redemptionStatus((int) $paidOrder['id']));
            $paidState = $this->pdo->query('SELECT status,payment_status FROM orders WHERE id=' . (int) $paidOrder['id'])->fetch();
            self::assertSame('confirmed', $paidState['status']);
            self::assertSame('paid', $paidState['payment_status']);
            $statusEntry = $this->pdo->query("SELECT created_at FROM audit_log WHERE entity_type='order' AND entity_id=" . (int) $paidOrder['id'] . " AND action='order.confirm'")->fetchColumn();
            self::assertNotFalse($statusEntry, 'Confirmarea automată trebuie să înregistreze data intrării în status.');
            $payment = $this->pdo->query('SELECT status,provider_payment_id FROM payments WHERE order_id=' . (int) $paidOrder['id'])->fetch();
            self::assertSame('paid', $payment['status']);
            self::assertSame('pi_evt_checkout_async_succeeded', $payment['provider_payment_id']);
            self::assertSame('processed', $this->pdo->query("SELECT status FROM payment_events WHERE provider_event_id='evt_checkout_completed_unpaid'")->fetchColumn());
            self::assertSame('processed', $this->pdo->query("SELECT status FROM payment_events WHERE provider_event_id='evt_checkout_async_succeeded'")->fetchColumn());

            $duplicate = $this->stripeWebhook('evt_checkout_async_succeeded', 'checkout.session.async_payment_succeeded', (int) $paidOrder['id']);
            self::assertSame(200, $duplicate->getStatusCode());
            self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM payment_events WHERE provider_event_id='evt_checkout_async_succeeded'")->fetchColumn());
            self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity_type='order' AND entity_id=" . (int) $paidOrder['id'] . " AND action='order.confirm'")->fetchColumn());
            self::assertSame('confirmed', $this->redemptionStatus((int) $paidOrder['id']));
            self::assertSame(200, $this->stripeWebhook('evt_paid_order_expired_late', 'checkout.session.expired', (int) $paidOrder['id'])->getStatusCode());
            self::assertSame('confirmed', $this->redemptionStatus((int) $paidOrder['id']));
            self::assertSame('confirmed', $this->pdo->query('SELECT status FROM orders WHERE id=' . (int) $paidOrder['id'])->fetchColumn());
            self::assertSame('active', $this->pdo->query('SELECT status FROM inventory_reservations WHERE order_id=' . (int) $paidOrder['id'])->fetchColumn());

            $expiredCart = new CartService($this->pdo);
            $expiredCart->add((int) $variant['id'], 1);
            $expiredCart->applyCoupon('STRIPE35');
            $expiredOrder = (new CheckoutService($this->pdo, $expiredCart, $config))->place(array_replace(
                $this->checkoutData('stripe-expired-order'),
                ['payment_method' => 'stripe'],
            ));
            self::assertSame('reserved', $this->redemptionStatus((int) $expiredOrder['id']));
            $reservedBeforeExpiry = (int) $this->pdo->query('SELECT reserved_quantity FROM inventory WHERE variant_id=' . (int) $variant['id'])->fetchColumn();
            self::assertSame(200, $this->stripeWebhook('evt_checkout_expired', 'checkout.session.expired', (int) $expiredOrder['id'])->getStatusCode());
            self::assertSame('released', $this->redemptionStatus((int) $expiredOrder['id']));
            self::assertSame('processed', $this->pdo->query("SELECT status FROM payment_events WHERE provider_event_id='evt_checkout_expired'")->fetchColumn());
            $expiredState = $this->pdo->query('SELECT status,payment_status,cancelled_at FROM orders WHERE id=' . (int) $expiredOrder['id'])->fetch();
            self::assertSame('cancelled', $expiredState['status']);
            self::assertSame('failed', $expiredState['payment_status']);
            self::assertNotEmpty($expiredState['cancelled_at']);
            self::assertSame('failed', $this->pdo->query('SELECT status FROM payments WHERE order_id=' . (int) $expiredOrder['id'])->fetchColumn());
            self::assertSame('released', $this->pdo->query('SELECT status FROM inventory_reservations WHERE order_id=' . (int) $expiredOrder['id'])->fetchColumn());
            self::assertSame($reservedBeforeExpiry - 1, (int) $this->pdo->query('SELECT reserved_quantity FROM inventory WHERE variant_id=' . (int) $variant['id'])->fetchColumn());

            $failedCart = new CartService($this->pdo);
            $failedCart->add((int) $variant['id'], 1);
            $failedCart->applyCoupon('STRIPE35');
            $failedOrder = (new CheckoutService($this->pdo, $failedCart, $config))->place(array_replace(
                $this->checkoutData('stripe-async-failed-order'),
                ['payment_method' => 'stripe'],
            ));
            self::assertSame(200, $this->stripeWebhook('evt_checkout_async_failed', 'checkout.session.async_payment_failed', (int) $failedOrder['id'])->getStatusCode());
            self::assertSame('released', $this->redemptionStatus((int) $failedOrder['id']));
            self::assertSame('cancelled', $this->pdo->query('SELECT status FROM orders WHERE id=' . (int) $failedOrder['id'])->fetchColumn());
            self::assertSame('released', $this->pdo->query('SELECT status FROM inventory_reservations WHERE order_id=' . (int) $failedOrder['id'])->fetchColumn());

            $retryableCart = new CartService($this->pdo);
            $retryableCart->add((int) $variant['id'], 1);
            $retryableCart->applyCoupon('STRIPE35');
            $retryableOrder = (new CheckoutService($this->pdo, $retryableCart, $config))->place(array_replace(
                $this->checkoutData('stripe-retryable-failure-order'),
                ['payment_method' => 'stripe'],
            ));
            self::assertSame(200, $this->stripeWebhook('evt_payment_retryable_failed', 'payment_intent.payment_failed', (int) $retryableOrder['id'])->getStatusCode());
            self::assertSame('reserved', $this->redemptionStatus((int) $retryableOrder['id']), 'Un eșec Stripe reîncercabil nu trebuie să elibereze cuponul înainte de expirarea rezervării.');
            $retryableState = $this->pdo->query('SELECT status,payment_status FROM orders WHERE id=' . (int) $retryableOrder['id'])->fetch();
            self::assertSame('new', $retryableState['status']);
            self::assertSame('pending', $retryableState['payment_status']);
            self::assertSame('active', $this->pdo->query('SELECT status FROM inventory_reservations WHERE order_id=' . (int) $retryableOrder['id'])->fetchColumn());

            $retryCart = new CartService($this->pdo);
            $retryCart->add((int) $variant['id'], 1);
            $retryCart->applyCoupon('STRIPE35');
            $retryOrder = (new CheckoutService($this->pdo, $retryCart, $config))->place(array_replace(
                $this->checkoutData('stripe-event-transaction-retry'),
                ['payment_method' => 'stripe'],
            ));
            $retryReservation = $this->pdo->query('SELECT id,quantity FROM inventory_reservations WHERE order_id=' . (int) $retryOrder['id'])->fetch();
            self::assertIsArray($retryReservation);
            $this->pdo->prepare('UPDATE inventory_reservations SET quantity=999999 WHERE id=:id')->execute(['id' => $retryReservation['id']]);
            self::assertSame(500, $this->stripeWebhook('evt_checkout_retry_after_rollback', 'checkout.session.expired', (int) $retryOrder['id'])->getStatusCode());
            self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM payment_events WHERE provider_event_id='evt_checkout_retry_after_rollback'")->fetchColumn(), 'Evenimentul trebuie să fie anulat odată cu tranzacția eșuată, ca Stripe să-l poată reîncerca.');
            self::assertSame('new', $this->pdo->query('SELECT status FROM orders WHERE id=' . (int) $retryOrder['id'])->fetchColumn());
            self::assertSame('active', $this->pdo->query('SELECT status FROM inventory_reservations WHERE order_id=' . (int) $retryOrder['id'])->fetchColumn());
            self::assertSame('reserved', $this->redemptionStatus((int) $retryOrder['id']));
            $this->pdo->prepare('UPDATE inventory_reservations SET quantity=:quantity WHERE id=:id')->execute(['quantity' => $retryReservation['quantity'], 'id' => $retryReservation['id']]);
            self::assertSame(200, $this->stripeWebhook('evt_checkout_retry_after_rollback', 'checkout.session.expired', (int) $retryOrder['id'])->getStatusCode());
            self::assertSame('processed', $this->pdo->query("SELECT status FROM payment_events WHERE provider_event_id='evt_checkout_retry_after_rollback'")->fetchColumn());
            self::assertSame('cancelled', $this->pdo->query('SELECT status FROM orders WHERE id=' . (int) $retryOrder['id'])->fetchColumn());
            self::assertSame('released', $this->redemptionStatus((int) $retryOrder['id']));

            self::assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id=' . $couponId)->fetchColumn());
            self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id={$couponId} AND status='confirmed'")->fetchColumn());
            self::assertSame(3, (int) $this->pdo->query("SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id={$couponId} AND status='released'")->fetchColumn());
            self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id={$couponId} AND status='reserved'")->fetchColumn());
        } finally {
            $this->restoreEnvironment('STRIPE_ENABLED', $previousStripeEnabled);
            $this->restoreEnvironment('STRIPE_SECRET_KEY', $previousStripeKey);
            $this->restoreEnvironment('STRIPE_WEBHOOK_SECRET', $previousWebhookSecret);
        }
    }

    /** @return array<string,mixed> */
    private function availableVariant(?int $maximumPriceBani = null): array
    {
        $price = $maximumPriceBani === null ? '' : ' AND v.price_bani<=' . $maximumPriceBani;
        $variant = $this->pdo->query("SELECT v.id,v.price_bani,p.id AS product_id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND v.status='active' AND i.physical_quantity-i.reserved_quantity>4{$price} ORDER BY v.price_bani ASC,v.id LIMIT 1")->fetch();
        if (!is_array($variant)) {
            self::fail('Catalogul demonstrativ trebuie să conțină o variantă disponibilă pentru test.');
        }
        return $variant;
    }

    private function insertCustomer(string $email): int
    {
        $now = gmdate('c');
        $statement = $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES (:email,:password,'Ana','Promo','customer','active',:now,:now,:now)");
        $statement->execute(['email' => $email, 'password' => password_hash('Unused-promotion-2026!', PASSWORD_DEFAULT), 'now' => $now]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertAdministrator(): int
    {
        $now = gmdate('c');
        $statement = $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES (:email,:password,'Admin','Promo','administrator','active',:now,:now,:now)");
        $statement->execute(['email' => 'promotion-orders-admin@example.test', 'password' => password_hash('Unused-admin-2026!', PASSWORD_DEFAULT), 'now' => $now]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertCustomerDiscount(int $customerId, string $scope, string $valueType, int $value): int
    {
        $now = gmdate('c');
        $statement = $this->pdo->prepare("INSERT INTO customer_discounts (user_id,scope,value_type,value,target_id,starts_at,ends_at,status,created_by_user_id,created_at,updated_at,deleted_at) VALUES (:user,:scope,:value_type,:value,NULL,NULL,NULL,'active',NULL,:now,:now,NULL)");
        $statement->execute(['user' => $customerId, 'scope' => $scope, 'value_type' => $valueType, 'value' => $value, 'now' => $now]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertPromotion(string $name, string $scope, string $valueType, int $value, ?int $targetId = null, int $minimumBani = 0): int
    {
        $now = gmdate('c');
        $statement = $this->pdo->prepare("INSERT INTO promotions (name,scope,value_type,value,target_id,minimum_bani,starts_at,ends_at,status,topbar_enabled,topbar_message,topbar_priority,created_by_user_id,created_at,updated_at,deleted_at) VALUES (:name,:scope,:value_type,:value,:target,:minimum,NULL,NULL,'active',0,'',0,NULL,:now,:now,NULL)");
        $statement->execute(['name' => $name, 'scope' => $scope, 'value_type' => $valueType, 'value' => $value, 'target' => $targetId, 'minimum' => $minimumBani, 'now' => $now]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertCoupon(string $code, int $basisPoints): int
    {
        $now = gmdate('c');
        $statement = $this->pdo->prepare("INSERT INTO coupons (code,type,value,minimum_bani,usage_limit,per_customer_limit,starts_at,ends_at,status,created_at,name,scope,target_id,value_type,topbar_enabled,topbar_message,topbar_priority,created_by_user_id,updated_at,deleted_at) VALUES (:code,'percent',:value,0,NULL,NULL,NULL,NULL,'active',:now,:name,'order',NULL,'percent',0,'',0,NULL,:now,NULL)");
        $statement->execute(['code' => $code, 'value' => $basisPoints, 'now' => $now, 'name' => 'Cupon ' . $code]);
        return (int) $this->pdo->lastInsertId();
    }

    private function stripeWebhook(string $eventId, string $eventType, int $orderId, ?string $paymentStatus = null): ResponseInterface
    {
        $order = $this->pdo->query('SELECT total_bani,currency FROM orders WHERE id=' . $orderId)->fetch();
        if (!is_array($order)) {
            self::fail('Comanda Stripe pentru webhook nu există.');
        }
        $payload = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'api_version' => '2025-09-30.clover',
            'created' => time(),
            'data' => ['object' => [
                'id' => 'cs_' . $eventId,
                'object' => 'checkout.session',
                'amount_total' => (int) $order['total_bani'],
                'currency' => strtolower((string) $order['currency']),
                'payment_status' => $paymentStatus ?? (in_array($eventType, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true) ? 'paid' : 'unpaid'),
                'payment_intent' => 'pi_' . $eventId,
                'metadata' => ['order_id' => (string) $orderId],
            ]],
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => ['id' => null, 'idempotency_key' => null],
            'type' => $eventType,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, self::STRIPE_WEBHOOK_SECRET);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/stripe/webhook')
            ->withHeader('Stripe-Signature', 't=' . $timestamp . ',v1=' . $signature)
            ->withBody((new StreamFactory())->createStream($payload));

        return (new StripeWebhookController($this->pdo, Config::load($this->root)))($request);
    }

    private function redemptionStatus(int $orderId): string
    {
        return (string) $this->pdo->query('SELECT status FROM coupon_redemptions WHERE order_id=' . $orderId)->fetchColumn();
    }

    private function restoreEnvironment(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$name]);
            return;
        }
        $_ENV[$name] = $value;
    }

    private function insertShippingMethod(
        string $name,
        string $code,
        int $priceBani,
        string $type = 'courier',
        int $estimatedMinDays = 1,
        int $estimatedMaxDays = 3,
        int $position = 10,
        bool $isDefault = false,
        string $status = 'active',
    ): int {
        $now = gmdate('c');
        $statement = $this->pdo->prepare('INSERT INTO shipping_methods (name,code,type,description,price_bani,estimated_min_days,estimated_max_days,position,is_default,status,created_at,updated_at,deleted_at) VALUES (:name,:code,:type,:description,:price,:min_days,:max_days,:position,:is_default,:status,:now,:now,NULL)');
        $statement->execute([
            'name' => $name,
            'code' => $code,
            'type' => $type,
            'description' => 'Metodă de livrare pentru test.',
            'price' => $priceBani,
            'min_days' => $estimatedMinDays,
            'max_days' => $estimatedMaxDays,
            'position' => $position,
            'is_default' => $isDefault ? 1 : 0,
            'status' => $status,
            'now' => $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param callable():mixed $callback */
    private function assertShippingMethodRejected(callable $callback): void
    {
        try {
            $callback();
            self::fail('Checkoutul trebuia să refuze metoda de livrare indisponibilă.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('livrare', mb_strtolower($exception->getMessage()));
        }
    }

    /** @return array<string,mixed> */
    private function checkoutData(string $key): array
    {
        $shippingMethodId = (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        return ['email'=>'client@example.test','first_name'=>'Ana','last_name'=>'Pop','phone'=>'0700000000','address'=>'Strada Test 1','city'=>'București — Sector 1','county'=>'București','postal_code'=>'010101','shipping_method_id'=>(string) $shippingMethodId,'payment_method'=>'cod','idempotency_key'=>$key,'terms'=>'1'];
    }
}
