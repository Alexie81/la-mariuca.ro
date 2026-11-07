<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Payments\PaymentMethodSettingsService;
use App\Shared\Config\Config;
use App\Shared\Security\Csrf;
use DOMDocument;
use DOMXPath;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class CheckoutCategoryPaymentTest extends DatabaseTestCase
{
    /** @var array<string,mixed> */
    private array $environment = [];
    private int $sequence = 0;

    protected function setUp(): void
    {
        $overrides = [
            'STRIPE_ENABLED' => 'true', 'STRIPE_SECRET_KEY' => 'sk_test_category_checkout',
            'COD_ENABLED' => 'true', 'MAIL_DELIVERY_MODE' => 'spool',
            'MAILER_DSN' => 'null://null', 'WEB_PUSH_ENABLED' => 'false',
        ];
        foreach ($overrides as $key => $value) {
            $this->environment[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = $value;
        }
        parent::setUp();
        $this->seed();
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        parent::tearDown();
    }

    public function testTamperedCashPostIsRejectedWithoutOrderStockEmailOrCouponSideEffects(): void
    {
        $item = $this->addProduct(1, 0);
        $this->pdo->prepare("INSERT INTO coupons (code,type,value,minimum_bani,usage_limit,per_customer_limit,status,created_at) VALUES ('PAYMENT10','percent',1000,0,1,1,'active',?)")->execute([gmdate('c')]);
        (new CartService($this->pdo))->applyCoupon('PAYMENT10');
        $page = $this->document();
        self::assertSame(1, $page->query('//input[@name="payment_method" and @value="cod" and @disabled]')->length);
        $before = $this->businessSnapshot();

        $response = $this->post('cod');
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/finalizare-comanda/', $response->getHeaderLine('Location'));
        self::assertSame($before, $this->businessSnapshot());
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame('active', $this->pdo->query('SELECT status FROM carts ORDER BY id DESC LIMIT 1')->fetchColumn());
        self::assertSame('PAYMENT10', $this->pdo->query('SELECT coupon_code FROM carts ORDER BY id DESC LIMIT 1')->fetchColumn());
        self::assertStringContainsString('nu acceptă plata ramburs', (string) ($_SESSION['_flash'][0]['message'] ?? ''));
        $after = $this->document();
        self::assertSame(1, $after->query('//input[@name="payment_method" and @value="stripe" and @checked and not(@disabled)]')->length);
        self::assertStringContainsString($item['name'], (string) $after->evaluate('string(//*[@id="payment-cod-description"])'));
    }

    public function testAncestorRuleChangedAfterGetIsRecheckedAtPost(): void
    {
        $root = $this->category(1, 1);
        $item = $this->addProduct(1, 1);
        $this->pdo->prepare('UPDATE categories SET parent_id=? WHERE id=?')->execute([$root, $item['category_id']]);
        $page = $this->document();
        self::assertSame(2, $page->query('//input[@name="payment_method" and not(@disabled)]')->length);

        $this->pdo->prepare('UPDATE categories SET allow_cod=0 WHERE id=?')->execute([$root]);
        $before = $this->businessSnapshot();
        $response = $this->post('cod');
        self::assertSame('/finalizare-comanda/', $response->getHeaderLine('Location'));
        self::assertSame($before, $this->businessSnapshot());
        $updated = $this->document();
        self::assertSame(1, $updated->query('//input[@name="payment_method" and @value="cod" and @disabled]')->length);
        self::assertSame(1, $updated->query('//input[@name="payment_method" and @value="stripe" and @checked]')->length);
    }

    public function testMixedCardOnlyAndCashOnlyCartSelectsAndAcceptsCardBeforeProviderRedirect(): void
    {
        $card = $this->addProduct(1, 0);
        $cash = $this->addProduct(0, 1, (int) $card['product_id']);
        $page = $this->document();
        self::assertSame(1, $page->query('//input[@name="payment_method" and @value="stripe" and @checked and not(@disabled)]')->length);
        self::assertSame(1, $page->query('//input[@name="payment_method" and @value="cod" and @disabled]')->length);
        self::assertSame(1, $page->query('//*[@id="payment-cod-description"]//li')->length);
        $explanation = (string) $page->evaluate('string(//*[@id="payment-cod-description"])');
        self::assertStringContainsString('Unele produse din coș nu acceptă plata ramburs.', $explanation);
        self::assertStringContainsString($card['name'], $explanation);
        self::assertStringNotContainsString($cash['name'], $explanation);

        // Test the transaction directly: never invoke the real Stripe HTTP client.
        $service = new CheckoutService($this->pdo, new CartService($this->pdo), Config::load($this->root));
        $result = $service->place($this->checkoutData('stripe'));
        self::assertSame('stripe', $result['payment_method']);
        $order = $this->pdo->query('SELECT payment_method,payment_status FROM orders WHERE id=' . (int) $result['id'])->fetch();
        self::assertSame('stripe', $order['payment_method']);
        self::assertSame('pending', $order['payment_status']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
    }

    public function testPersistedCardSwitchOffMovesTheSameMixedCartFromCardPriorityToCash(): void
    {
        $card = $this->addProduct(1, 0);
        $cash = $this->addProduct(0, 1, (int) $card['product_id']);
        $categoryFlags = $this->pdo->query('SELECT id,allow_card,allow_cod FROM categories ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES ('mixed-payment-admin@example.test','unused','Ana','Admin','administrator','active',?,?)")
            ->execute([gmdate('c'), gmdate('c')]);
        $adminId = (int) $this->pdo->lastInsertId();
        $settings = new PaymentMethodSettingsService($this->pdo, Config::load($this->root));
        $settings->save(true, true, $adminId);

        $page = $this->document();
        self::assertSame(1, $page->query('//input[@name="payment_method" and @value="stripe" and @checked and not(@disabled)]')->length);
        self::assertSame(1, $page->query('//input[@name="payment_method" and @value="cod" and @disabled]')->length);
        $explanation = (string) $page->evaluate('string(//*[@id="payment-cod-description"])');
        self::assertStringContainsString($card['name'], $explanation);
        self::assertStringNotContainsString($cash['name'], $explanation);
        $before = $this->businessSnapshot();
        self::assertSame('/finalizare-comanda/', $this->post('cod')->getHeaderLine('Location'));
        self::assertSame($before, $this->businessSnapshot());

        // Persist the same change as the CRM switch; the ENV default deliberately stays enabled.
        $settings->save(true, false, $adminId);
        self::assertTrue(Config::load($this->root)->bool('STRIPE_ENABLED'));
        $_SESSION['checkout_old_input'] = ['payment_method' => 'stripe'];
        $page = $this->document();
        self::assertSame(0, $page->query('//input[@name="payment_method" and @value="stripe"]')->length);
        self::assertSame(0, $page->query('//*[@id="payment-stripe-description"]')->length);
        self::assertSame(1, $page->query('//input[@name="payment_method" and @value="cod" and @checked and not(@disabled)]')->length);
        self::assertSame(0, $page->query('//input[@name="payment_method" and @checked and @disabled]')->length);
        self::assertStringContainsString('Poți continua cu plata la livrare', (string) $page->evaluate('string(//body)'));
        self::assertSame(1, $page->query('//button[@form="checkout-form" and @type="submit" and not(@name) and not(@disabled)]')->length);
        self::assertSame($categoryFlags, $this->pdo->query('SELECT id,allow_card,allow_cod FROM categories ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertCount(2, (new CartService($this->pdo))->summary()['items']);

        self::assertSame('/finalizare-comanda/', $this->post('stripe')->getHeaderLine('Location'));
        self::assertSame($before, $this->businessSnapshot());
        $response = $this->post('cod');
        self::assertSame(303, $response->getStatusCode());
        self::assertMatchesRegularExpression('~^/comanda/[a-f0-9]{64}/$~', $response->getHeaderLine('Location'));
        self::assertSame('cod', $this->pdo->query('SELECT payment_method FROM orders')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM inventory_reservations')->fetchColumn());
        self::assertSame($categoryFlags, $this->pdo->query('SELECT id,allow_card,allow_cod FROM categories ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        $outbox = $this->pdo->query('SELECT message_type,status,attempts FROM outbox_messages')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([['message_type' => 'order_confirmation', 'status' => 'pending', 'attempts' => 0]], $outbox);
    }

    public function testGlobalCardOffAllowsCashForCardOnlyProductsAndQueuesOnlyOneEmail(): void
    {
        $this->addProduct(1, 0);
        $_ENV['STRIPE_ENABLED'] = 'false';
        $page = $this->document();
        self::assertSame(0, $page->query('//input[@name="payment_method" and @value="stripe"]')->length);
        self::assertSame(0, $page->query('//*[@id="payment-stripe-description"]')->length);
        self::assertStringNotContainsString('Card online', (string) $page->evaluate('string(//body)'));
        self::assertSame(1, $page->query('//input[@name="payment_method" and @value="cod" and @checked and not(@disabled)]')->length);
        self::assertStringContainsString('Poți continua cu plata la livrare', (string) $page->evaluate('string(//body)'));
        self::assertSame(1, $page->query('//button[@form="checkout-form" and @type="submit" and not(@name) and not(@disabled)]')->length);

        $response = $this->post('cod');
        self::assertSame(303, $response->getStatusCode());
        self::assertMatchesRegularExpression('~^/comanda/[a-f0-9]{64}/$~', $response->getHeaderLine('Location'));
        self::assertSame('cod', $this->pdo->query('SELECT payment_method FROM orders')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM inventory_reservations')->fetchColumn());
        $outbox = $this->pdo->query('SELECT message_type,status,attempts FROM outbox_messages')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $outbox);
        self::assertSame('order_confirmation', $outbox[0]['message_type']);
        self::assertSame('pending', $outbox[0]['status']);
        self::assertSame(0, (int) $outbox[0]['attempts']);
    }

    public function testGloballyEnabledCardRemainsVisibleWithTheCashOnlyProductExplanation(): void
    {
        $item = $this->addProduct(0, 1);
        $page = $this->document();
        self::assertSame(1, $page->query('//input[@name="payment_method" and @value="stripe" and @disabled]')->length);
        self::assertSame(1, $page->query('//input[@name="payment_method" and @value="cod" and @checked and not(@disabled)]')->length);
        self::assertSame(1, $page->query('//*[@id="payment-stripe-description"]//li')->length);
        $explanation = (string) $page->evaluate('string(//*[@id="payment-stripe-description"])');
        self::assertStringContainsString('nu acceptă plata cu cardul', $explanation);
        self::assertStringContainsString($item['name'], $explanation);
    }

    public function testCardWithoutProviderConfigurationIsNotDisplayed(): void
    {
        $this->addProduct(1, 1);
        $_ENV['STRIPE_SECRET_KEY'] = '';
        $page = $this->document();
        self::assertSame(0, $page->query('//input[@name="payment_method" and @value="stripe"]')->length);
        self::assertSame(0, $page->query('//*[@id="payment-stripe-description"]')->length);
        self::assertSame(1, $page->query('//input[@name="payment_method" and @value="cod" and @checked and not(@disabled)]')->length);
    }

    public function testGlobalCashOffCannotBeBypassedButCashOnlyCartCanUseCardFallback(): void
    {
        $this->addProduct(0, 1);
        $_ENV['COD_ENABLED'] = 'false';
        $_SESSION['checkout_old_input'] = ['payment_method' => 'cod'];
        $page = $this->document();
        self::assertSame(0, $page->query('//input[@name="payment_method" and @value="cod"]')->length);
        self::assertSame(0, $page->query('//*[@id="payment-cod-description"]')->length);
        self::assertSame(1, $page->query('//input[@name="payment_method" and @value="stripe" and @checked and not(@disabled)]')->length);
        self::assertSame(0, $page->query('//input[@name="payment_method" and @checked and @disabled]')->length);
        self::assertSame(1, $page->query('//button[@form="checkout-form" and @type="submit" and not(@name) and not(@disabled)]')->length);
        self::assertStringContainsString('Poți continua cu plata online', (string) $page->evaluate('string(//body)'));
        self::assertSame(0, $page->query('//*[@id="payment-stripe-description"]//li')->length);
        self::assertStringNotContainsString('nu acceptă plata cu cardul', (string) $page->evaluate('string(//*[@id="payment-stripe-description"])'));
        $before = $this->businessSnapshot();

        $response = $this->post('cod');
        self::assertSame('/finalizare-comanda/', $response->getHeaderLine('Location'));
        self::assertSame($before, $this->businessSnapshot());
        self::assertStringContainsString('Plata ramburs nu este disponibilă', (string) ($_SESSION['_flash'][0]['message'] ?? ''));

        // The checkout transaction accepts Stripe; its provider redirect is deliberately not invoked.
        $service = new CheckoutService($this->pdo, new CartService($this->pdo), Config::load($this->root));
        $result = $service->place($this->checkoutData('stripe'));
        self::assertSame('stripe', $result['payment_method']);
        self::assertSame('stripe', $this->pdo->query('SELECT payment_method FROM orders')->fetchColumn());
        self::assertSame('pending', $this->pdo->query('SELECT payment_status FROM orders')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM inventory_reservations')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
    }

    public function testBothGlobalMethodsOffDisableSubmissionAndRejectEitherForgedChoice(): void
    {
        $this->addProduct(0, 1);
        $_ENV['COD_ENABLED'] = 'false';
        $_ENV['STRIPE_ENABLED'] = 'false';
        $_SESSION['checkout_old_input'] = ['payment_method' => 'stripe'];
        $page = $this->document();
        self::assertSame(0, $page->query('//input[@name="payment_method"]')->length);
        self::assertSame(0, $page->query('//*[contains(concat(" ", normalize-space(@class), " "), " checkout-payment-card ")]')->length);
        self::assertSame(0, $page->query('//input[@name="payment_method" and @checked]')->length);
        self::assertSame(1, $page->query('//button[@form="checkout-form" and @type="submit" and not(@name) and @disabled]')->length);
        self::assertStringContainsString('Nicio metodă de plată disponibilă', (string) $page->evaluate('string(//body)'));
        self::assertStringNotContainsString('Poți continua cu plata', (string) $page->evaluate('string(//body)'));
        $before = $this->businessSnapshot();

        foreach (['cod', 'stripe'] as $method) {
            self::assertSame('/finalizare-comanda/', $this->post($method)->getHeaderLine('Location'));
            self::assertSame($before, $this->businessSnapshot());
        }
    }

    public function testGlobalMethodsChangedAfterGetAreAppliedAtPost(): void
    {
        $this->addProduct(1, 1);
        $page = $this->document();
        self::assertSame(2, $page->query('//input[@name="payment_method" and not(@disabled)]')->length);
        $_ENV['COD_ENABLED'] = 'false';
        $before = $this->businessSnapshot();
        self::assertSame('/finalizare-comanda/', $this->post('cod')->getHeaderLine('Location'));
        self::assertSame($before, $this->businessSnapshot());
    }

    public function testAnAvailablePreviousSelectionIsPreservedButAnUnavailableOneIsReplaced(): void
    {
        $item = $this->addProduct(1, 1);
        $_SESSION['checkout_old_input'] = ['payment_method' => 'cod'];
        $page = $this->document();
        self::assertSame(1, $page->query('//input[@name="payment_method" and @value="cod" and @checked and not(@disabled)]')->length);

        $this->pdo->prepare('UPDATE categories SET allow_cod=0 WHERE id=?')->execute([$item['category_id']]);
        $_SESSION['checkout_old_input'] = ['payment_method' => 'cod'];
        $page = $this->document();
        self::assertSame(1, $page->query('//input[@name="payment_method" and @checked]')->length);
        self::assertSame(1, $page->query('//input[@name="payment_method" and @value="stripe" and @checked and not(@disabled)]')->length);
        self::assertSame(0, $page->query('//input[@name="payment_method" and @disabled and @checked]')->length);
    }

    /** @return array<string,mixed> */
    private function addProduct(int $card, int $cod, int $excludeProduct = 0): array
    {
        $statement = $this->pdo->prepare("SELECT v.id AS variant_id,p.id AS product_id,p.name FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND v.status='active' AND v.stock_unlimited=0 AND i.physical_quantity-i.reserved_quantity>1 AND p.id<>? ORDER BY v.id LIMIT 1");
        $statement->execute([$excludeProduct]);
        $item = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($item);
        $categoryId = $this->category($card, $cod);
        $this->pdo->prepare('DELETE FROM product_categories WHERE product_id=?')->execute([$item['product_id']]);
        $this->pdo->prepare('INSERT INTO product_categories (product_id,category_id) VALUES (?,?)')->execute([$item['product_id'], $categoryId]);
        $this->pdo->prepare('UPDATE products SET primary_category_id=? WHERE id=?')->execute([$categoryId, $item['product_id']]);
        (new CartService($this->pdo))->add((int) $item['variant_id'], 1);
        return $item + ['category_id' => $categoryId];
    }

    private function category(int $card, int $cod): int
    {
        $name = 'Checkout category ' . ++$this->sequence;
        $statement = $this->pdo->prepare("INSERT INTO categories (name,slug,status,allow_card,allow_cod,created_at,updated_at) VALUES (?,?,'published',?,?,?,?)");
        $statement->execute([$name, 'checkout-category-' . $this->sequence, $card, $cod, gmdate('c'), gmdate('c')]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function businessSnapshot(): array
    {
        $snapshot = [];
        foreach (['orders', 'order_items', 'payments', 'inventory', 'inventory_reservations', 'inventory_movements', 'outbox_messages', 'coupons', 'coupon_redemptions'] as $table) {
            $snapshot[$table] = $this->pdo->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        }
        return $snapshot;
    }

    private function document(): DOMXPath
    {
        $response = $this->request('GET');
        self::assertSame(200, $response->getStatusCode());
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . (string) $response->getBody(), LIBXML_NOERROR | LIBXML_NOWARNING);
        return new DOMXPath($document);
    }

    private function post(string $method): ResponseInterface
    {
        return $this->request('POST', $this->checkoutData($method) + ['_csrf' => (new Csrf())->token()]);
    }

    /** @param array<string,string> $data */
    private function request(string $method, array $data = []): ResponseInterface
    {
        $app = AppFactory::create($this->root);
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/finalizare-comanda/');
        if ($method === 'POST') {
            $request = $request->withParsedBody($data);
        }
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();
        return $response;
    }

    /** @return array<string,string> */
    private function checkoutData(string $method): array
    {
        $shippingMethodId = (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        return [
            'email' => 'category-checkout@example.test', 'first_name' => 'Ana', 'last_name' => 'Pop',
            'phone' => '0700000000', 'address' => 'Strada Florilor 10', 'county' => 'Cluj', 'city' => 'Cluj-Napoca',
            'shipping_method_id' => (string) $shippingMethodId, 'payment_method' => $method,
            'idempotency_key' => 'category-checkout-' . ++$this->sequence, 'terms' => '1',
        ];
    }
}
