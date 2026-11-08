<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutAttemptService;
use App\Modules\Checkout\CheckoutService;
use App\Shared\Config\Config;
use App\Shared\Security\Csrf;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class StripeCheckoutUiTest extends DatabaseTestCase
{
    public function testPendingPaymentUsesExistingResultPagesWithoutCreatingAnOrder(): void
    {
        $environment = $_ENV;
        try {
            $result = $this->createAttempt();
            $token = $_SESSION['cart_token'];
            $inventory = $this->pdo->query('SELECT * FROM inventory ORDER BY variant_id')->fetchAll();
            foreach (['' => 'card_pending', 'verificare' => 'card_pending', 'paid' => 'card_pending', 'anulata' => 'card_cancelled'] as $hint => $state) {
                $response = $this->request('GET', '/comanda/' . $result['token'] . '/', [], ['plata' => $hint]);
                $html = (string) $response->getBody();
                self::assertSame(200, $response->getStatusCode());
                self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
                self::assertStringContainsString('data-order-result="' . $state . '"', $html);
                self::assertStringContainsString('data-payment-attempt="true"', $html);
                self::assertStringContainsString('Reia plata', $html);
                self::assertStringContainsString('/plata/' . $result['token'] . '/reincearca/', $html);
                self::assertStringContainsString('Strada Florilor 10', $html);
                self::assertStringNotContainsString('Numărul comenzii', $html);
                self::assertStringNotContainsString('Comanda este înregistrată', $html);
            }
            self::assertSame($token, $_SESSION['cart_token']);
            self::assertSame($inventory, $this->pdo->query('SELECT * FROM inventory ORDER BY variant_id')->fetchAll());
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
            $html = $this->checkoutPage();
            self::assertStringContainsString('Ai o plată în curs', $html);
            self::assertStringContainsString('Reia plata', $html);
            self::assertStringNotContainsString('id="checkout-form"', $html);
            foreach (['reincearca', 'anuleaza'] as $action) {
                self::assertSame(419, $this->request('POST', '/plata/' . $result['token'] . '/' . $action . '/')->getStatusCode());
            }
            self::assertSame('pending', $this->pdo->query('SELECT status FROM checkout_attempts')->fetchColumn());
        } finally { $_ENV = $environment; }
    }

    public function testTerminalAttemptReopensCheckoutWithSameProductsAndContactDetails(): void
    {
        $environment = $_ENV;
        try {
            $result = $this->createAttempt();
            $cartToken = $_SESSION['cart_token'];
            $items = (new CartService($this->pdo))->summary()['items'];
            $attempts = new CheckoutAttemptService($this->pdo, Config::load($this->root));
            $this->pdo->beginTransaction();
            $attempts->release($result['attempt_id'], 'expired');
            $this->pdo->commit();
            $page = (string) $this->request('GET', '/comanda/' . $result['token'] . '/', [], ['plata' => 'anulata'])->getBody();
            self::assertStringContainsString('data-order-result="card_failed"', $page);
            self::assertStringContainsString('Reia plata', $page);
            self::assertStringNotContainsString('/anuleaza/', $page);
            $_SESSION['checkout_idempotency'] = 'old-idempotency';
            $response = $this->request('POST', '/plata/' . $result['token'] . '/reincearca/', ['_csrf' => (new Csrf())->token()]);
            self::assertSame(303, $response->getStatusCode());
            self::assertSame('/finalizare-comanda/', $response->getHeaderLine('Location'));
            self::assertSame($cartToken, $_SESSION['cart_token']);
            self::assertSame('ui-attempt@example.test', $_SESSION['checkout_old_input']['email']);
            self::assertSame('Strada Florilor 10', $_SESSION['checkout_old_input']['address']);
            self::assertArrayNotHasKey('checkout_idempotency', $_SESSION);
            self::assertSame(array_column($items, 'variant_id'), array_column((new CartService($this->pdo))->summary()['items'], 'variant_id'));
            $page = $this->checkoutPage();
            self::assertStringContainsString('id="checkout-form"', $page);
            self::assertStringContainsString('value="ui-attempt@example.test"', $page);
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        } finally { $_ENV = $environment; }
    }

    public function testPaidAttemptPromotesTheSamePrivateUrlToTheExistingSuccessPage(): void
    {
        $environment = $_ENV;
        try {
            $result = $this->createAttempt();
            $this->pdo->prepare('UPDATE checkout_attempts SET checkout_session_id=:session WHERE id=:id')->execute(['session' => 'cs_ui_paid', 'id' => $result['attempt_id']]);
            $this->pdo->beginTransaction();
            $orderId = (new CheckoutAttemptService($this->pdo, Config::load($this->root)))->complete($result['attempt_id'], 'pi_ui_paid', 'cs_ui_paid');
            $this->pdo->commit();
            self::assertGreaterThan(0, $orderId);
            $html = (string) $this->request('GET', '/comanda/' . $result['token'] . '/', [], ['plata' => 'anulata'])->getBody();
            self::assertStringContainsString('data-order-result="card_paid"', $html);
            self::assertStringContainsString('data-payment-attempt="false"', $html);
            self::assertStringContainsString('Numărul comenzii', $html);
            self::assertStringNotContainsString('Reia plata', $html);
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
            self::assertSame([], (new CartService($this->pdo))->summary()['items']);
        } finally { $_ENV = $environment; }
    }

    public function testTestNoticeBelongsOnlyToAnAvailableCardOption(): void
    {
        $environment = $_ENV;
        try {
            $this->seed();
            $_ENV['STRIPE_MODE'] = 'test';
            $_ENV['STRIPE_ENABLED'] = 'true';
            $_ENV['COD_ENABLED'] = 'true';
            $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_ui_placeholder';
            $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_ui_placeholder';
            $variant = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND i.physical_quantity>0 ORDER BY v.id LIMIT 1")->fetchColumn();
            (new CartService($this->pdo))->add($variant, 1);
            $html = $this->checkoutPage();
            self::assertStringContainsString('checkout-test-badge', $html);
            self::assertStringContainsString('Nu se debitează bani.', $html);
            self::assertStringContainsString('Continuă la plată', $html);
            self::assertSame(1, substr_count($html, 'class="checkout-test-notice"'));
            self::assertStringNotContainsString('sk_test_ui_placeholder', $html);
            self::assertStringNotContainsString('whsec_ui_placeholder', $html);

            $this->pdo->exec('UPDATE payment_method_settings SET stripe_enabled=0,cod_enabled=1 WHERE id=1');
            $html = $this->checkoutPage();
            self::assertStringNotContainsString('checkout-test-badge', $html);
            self::assertStringNotContainsString('Nu se debitează bani.', $html);
            self::assertStringContainsString('Plasează comanda', $html);
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        } finally {
            $_ENV = $environment;
        }
    }

    private function checkoutPage(): string
    {
        $app = AppFactory::create($this->root);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/finalizare-comanda/');
        $response = $app->handle($request);
        self::assertSame(200, $response->getStatusCode());
        unset($app);
        gc_collect_cycles();
        return (string) $response->getBody();
    }

    /** @return array{order_number:string,token:string,id:int,payment_method:string,idempotency_key:string,attempt_id:int} */
    private function createAttempt(): array
    {
        $this->seed();
        $_ENV['STRIPE_MODE'] = 'test';
        $_ENV['STRIPE_ENABLED'] = 'true';
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_ui_placeholder';
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_ui_placeholder';
        $this->pdo->exec('UPDATE payment_method_settings SET stripe_enabled=1,cod_enabled=1 WHERE id=1');
        $variant = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND i.physical_quantity>=2 ORDER BY v.id LIMIT 1")->fetchColumn();
        $shipping = (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($variant, 2);
        $result = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place([
            'email' => 'ui-attempt@example.test', 'first_name' => 'Ana', 'last_name' => 'Pop',
            'phone' => '0700000000', 'address' => 'Strada Florilor 10', 'city' => 'București — Sector 1',
            'county' => 'București', 'postal_code' => '010101', 'shipping_method_id' => (string) $shipping,
            'payment_method' => 'stripe', 'idempotency_key' => bin2hex(random_bytes(32)), 'terms' => '1',
        ]);
        self::assertArrayHasKey('attempt_id', $result);
        return $result;
    }

    /** @param array<string,mixed> $body @param array<string,mixed> $query */
    private function request(string $method, string $path, array $body = [], array $query = []): ResponseInterface
    {
        $app = AppFactory::create($this->root);
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path)->withParsedBody($body)->withQueryParams($query);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();
        return $response;
    }
}
