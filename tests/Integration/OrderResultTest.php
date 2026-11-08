<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Checkout\OrderResultPresenter;
use App\Modules\Orders\OrderService;
use App\Modules\Payments\StripeCheckoutService;
use App\Shared\Config\Config;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class OrderResultTest extends DatabaseTestCase
{
    public function testCashConfirmationKeepsOrderSnapshotsAndShowsDeliverySummary(): void
    {
        $result = $this->placeOrder();
        $before = $this->order($result['token']);
        $this->pdo->prepare("UPDATE products SET name='Nume modificat în catalog' WHERE id=:id")
            ->execute(['id' => $before['items'][0]['product_id']]);
        $this->pdo->prepare("UPDATE product_variants SET price_bani=99999,option_summary='Variantă nouă' WHERE id=:id")
            ->execute(['id' => $before['items'][0]['variant_id']]);
        $this->pdo->prepare("UPDATE orders SET created_at='2026-11-03T10:30:00+00:00' WHERE id=:id")
            ->execute(['id' => $result['id']]);

        $order = $this->order($result['token']);
        $presentation = $this->presentation($order, 'anulata', $result['token']);
        self::assertSame('cash_placed', $presentation['state']);
        self::assertSame('Ramburs la livrare', $presentation['payment_label']);
        self::assertSame('De achitat la livrare', $presentation['total_label']);
        self::assertSame('03.11.2026, 12:30', $presentation['placed_at_label']);
        self::assertSame(2, $presentation['item_count']);
        self::assertFalse($presentation['can_refresh']);
        self::assertSame('Strada Florilor 10', $order['shipping_address']['address']);
        self::assertSame($before['items'][0]['product_name'], $order['items'][0]['product_name']);
        self::assertSame($before['items'][0]['variant_summary'], $order['items'][0]['variant_summary']);
        self::assertSame($before['items'][0]['line_total_bani'], $order['items'][0]['line_total_bani']);
        self::assertArrayHasKey('image_path', $order['items'][0]);
        self::assertArrayHasKey('personalization', $order['items'][0]);

        $response = $this->getResult($result['token'], ['plata' => 'anulata']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertStringContainsString('data-order-result="cash_placed"', (string) $response->getBody());
        self::assertStringContainsString($result['order_number'], (string) $response->getBody());
        self::assertStringContainsString('Strada Florilor 10', (string) $response->getBody());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    }

    public function testCancelledCardReturnOnlyChangesPresentationAndNeverOrderOrStock(): void
    {
        $result = $this->placeOrder('stripe');
        $beforeOrder = $this->order($result['token']);
        $beforeReservations = $this->pdo->query('SELECT * FROM inventory_reservations ORDER BY id')->fetchAll();
        $beforeInventory = $this->pdo->query('SELECT * FROM inventory ORDER BY variant_id')->fetchAll();
        $beforePayments = $this->pdo->query('SELECT * FROM payments ORDER BY id')->fetchAll();
        $response = $this->getResult($result['token'], ['plata' => 'anulata']);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('data-order-result="card_cancelled"', (string) $response->getBody());
        self::assertSame($beforeOrder, $this->order($result['token']));
        self::assertSame($beforeReservations, $this->pdo->query('SELECT * FROM inventory_reservations ORDER BY id')->fetchAll());
        self::assertSame($beforeInventory, $this->pdo->query('SELECT * FROM inventory ORDER BY variant_id')->fetchAll());
        self::assertSame($beforePayments, $this->pdo->query('SELECT * FROM payments ORDER BY id')->fetchAll());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());

        foreach ([[], ['plata' => 'verificare'], ['plata' => 'paid'], ['plata' => 'esuata'], ['plata' => ['anulata']]] as $query) {
            self::assertStringContainsString('data-order-result="card_pending"', (string) $this->getResult($result['token'], $query)->getBody());
        }
    }

    public function testRecordedCardFailureOverridesReturnUrlAndSessionFailureIsVisible(): void
    {
        $result = $this->placeOrder('stripe');
        (new StripeCheckoutService($this->pdo, Config::load($this->root)))->markSessionFailure($result['id'], 'Synthetic session initialization failure');
        $order = $this->order($result['token']);
        self::assertSame('manual_review', $order['status']);
        self::assertSame('pending', $order['payment_status']);
        self::assertSame('failed', $order['latest_payment_status']);
        self::assertSame('card_failed', $this->presentation($order, 'anulata', $result['token'])['state']);
        self::assertStringContainsString('data-order-result="card_failed"', (string) $this->getResult($result['token'], ['plata' => 'verificare'])->getBody());

        $this->pdo->prepare("UPDATE orders SET status='cancelled',payment_status='failed' WHERE id=:id")->execute(['id' => $result['id']]);
        self::assertStringContainsString('data-order-result="card_failed"', (string) $this->getResult($result['token'], ['plata' => 'anulata'])->getBody());
    }

    public function testPendingReturnExposesOnlyStoredStatusToReadOnlyWatcher(): void
    {
        $result = $this->placeOrder('stripe');
        $before = $this->order($result['token']);
        $html = (string) $this->getResult($result['token'], ['plata' => 'verificare'])->getBody();
        self::assertStringContainsString('data-order-status="new"', $html);
        self::assertStringContainsString('data-payment-status="pending"', $html);
        self::assertStringContainsString('order-payment-status.js', $html);
        self::assertSame($before, $this->order($result['token']));

        $this->pdo->prepare("UPDATE orders SET status='confirmed',payment_status='paid' WHERE id=:id")
            ->execute(['id' => $result['id']]);
        $html = (string) $this->getResult($result['token'], ['plata' => 'verificare'])->getBody();
        self::assertStringContainsString('data-order-result="card_paid"', $html);
        self::assertStringContainsString('data-payment-status="paid"', $html);
        self::assertStringNotContainsString('order-payment-status.js', $html);
    }

    public function testPaidManualReviewRefundAndCancellationAlwaysTakePrecedence(): void
    {
        $result = $this->placeOrder('stripe');
        $cases = [
            ['confirmed', 'paid', 'card_paid'],
            ['manual_review', 'paid', 'payment_review'],
            ['cancelled', 'paid', 'order_cancelled'],
            ['delivered', 'partially_refunded', 'payment_refunded'],
            ['returned', 'refunded', 'payment_refunded'],
            ['returned', 'paid', 'order_returned'],
        ];
        foreach ($cases as [$status, $payment, $expected]) {
            $this->pdo->prepare('UPDATE orders SET status=:status,payment_status=:payment WHERE id=:id')
                ->execute(['status' => $status, 'payment' => $payment, 'id' => $result['id']]);
            $response = $this->getResult($result['token'], ['plata' => 'anulata']);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('data-order-result="' . $expected . '"', (string) $response->getBody());
        }
        $this->pdo->prepare("UPDATE orders SET payment_method='cod',status='cancelled',payment_status='pending' WHERE id=:id")
            ->execute(['id' => $result['id']]);
        self::assertSame('order_cancelled', $this->presentation($this->order($result['token']), '', $result['token'])['state']);
        $this->pdo->prepare("UPDATE orders SET status='delivered',payment_status='paid' WHERE id=:id")
            ->execute(['id' => $result['id']]);
        $cashPaid = $this->presentation($this->order($result['token']), '', $result['token']);
        self::assertSame('cash_paid', $cashPaid['state']);
        self::assertSame('Total achitat', $cashPaid['total_label']);
        self::assertSame('Plătită', $cashPaid['payment_label']);
    }

    public function testRefreshUsesConfiguredBasePathAndDoesNotRepeatCancellationHint(): void
    {
        $result = $this->placeOrder('stripe');
        $previousBasePath = $_ENV['APP_BASE_PATH'];
        try {
            $_ENV['APP_BASE_PATH'] = '/boutique';
            $presentation = $this->presentation($this->order($result['token']), 'anulata', $result['token']);
            self::assertTrue($presentation['can_refresh']);
            self::assertSame('/boutique/comanda/' . $result['token'] . '/', $presentation['refresh_path']);
        } finally {
            $_ENV['APP_BASE_PATH'] = $previousBasePath;
        }
    }

    public function testInvalidPublicTokensCannotExposeAnOrder(): void
    {
        $result = $this->placeOrder();
        foreach (['invalid', str_repeat('0', 64), (string) $result['id']] as $token) {
            $response = $this->getResult($token);
            self::assertSame(404, $response->getStatusCode());
            self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
            self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
            self::assertStringNotContainsString($result['order_number'], (string) $response->getBody());
            self::assertStringNotContainsString('Strada Florilor 10', (string) $response->getBody());
        }
    }

    /** @return array{order_number:string,token:string,id:int,payment_method:string,idempotency_key:string} */
    private function placeOrder(string $method = 'cod'): array
    {
        $this->seed();
        $variant = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND i.physical_quantity>=2 ORDER BY v.id LIMIT 1")->fetchColumn();
        $shipping = (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($variant, 2);
        $result = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place([
            'email' => 'order-result@example.test', 'first_name' => 'Ana', 'last_name' => 'Pop',
            'phone' => '0700000000', 'address' => 'Strada Florilor 10', 'city' => 'București — Sector 1',
            'county' => 'București', 'postal_code' => '010101', 'shipping_method_id' => (string) $shipping,
            'payment_method' => 'cod', 'idempotency_key' => bin2hex(random_bytes(32)), 'terms' => '1',
        ]);
        if ($method === 'stripe') {
            // Local database fixture only: no hosted payment session or network request is created.
            $this->pdo->prepare("UPDATE orders SET payment_method='stripe' WHERE id=:id")->execute(['id' => $result['id']]);
            $this->pdo->prepare("UPDATE payments SET provider='stripe' WHERE order_id=:id")->execute(['id' => $result['id']]);
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function order(string $token): array
    {
        $order = (new OrderService($this->pdo))->byPublicToken($token);
        self::assertNotNull($order);
        return $order;
    }

    /** @param array<string,mixed> $order @return array<string,mixed> */
    private function presentation(array $order, string $returnStatus, string $token): array
    {
        return (new OrderResultPresenter(Config::load($this->root)))->present($order, $returnStatus, $token);
    }

    /** @param array<string,mixed> $query */
    private function getResult(string $token, array $query = []): ResponseInterface
    {
        $app = AppFactory::create($this->root);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/comanda/' . $token . '/')
            ->withQueryParams($query);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();
        return $response;
    }
}
