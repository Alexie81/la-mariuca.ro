<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Cart\CartService;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class StripeCheckoutUiTest extends DatabaseTestCase
{
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
}
