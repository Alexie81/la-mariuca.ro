<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Cart\CartService;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class CartTest extends DatabaseTestCase
{
    public function testProductCardToggleAddsThenRemovesVariant(): void
    {
        $this->seed();
        $variantId = (int) $this->pdo->query(
            'SELECT v.id FROM product_variants v JOIN inventory i ON i.variant_id = v.id JOIN product_images pi ON pi.product_id = v.product_id WHERE i.physical_quantity > 0 LIMIT 1'
        )->fetchColumn();
        $cart = new CartService($this->pdo);

        self::assertSame('added', $cart->toggle($variantId));
        $summary = $cart->summary();
        self::assertSame(1, $summary['count']);
        self::assertNotEmpty($summary['items'][0]['image_path']);
        self::assertNotEmpty($summary['items'][0]['image_alt']);

        self::assertSame('removed', $cart->toggle($variantId));
        self::assertSame(0, $cart->summary()['count']);
    }

    public function testEmptyCartPageUsesEditorialResponsiveExperience(): void
    {
        $this->seed();

        $body = (string) $this->get('/cos/')->getBody();

        self::assertStringContainsString('<body class="cart-page-body">', $body);
        self::assertStringContainsString('class="cart-empty-experience"', $body);
        self::assertStringContainsString('Povestea abia începe.', $body);
        self::assertStringContainsString('href="/magazin/"', $body);
        self::assertStringContainsString('Descoperă colecțiile', $body);
        self::assertStringContainsString('class="cart-discovery"', $body);
    }

    public function testFilledCartPageShowsImagesControlsAndOrderSummary(): void
    {
        $this->seed();
        $variantId = (int) $this->pdo->query(
            'SELECT v.id FROM product_variants v JOIN inventory i ON i.variant_id = v.id JOIN product_images pi ON pi.product_id = v.product_id WHERE i.physical_quantity > 0 LIMIT 1'
        )->fetchColumn();
        (new CartService($this->pdo))->add($variantId, 1);

        $body = (string) $this->get('/cos/')->getBody();

        self::assertStringContainsString('class="cart-filled-layout"', $body);
        self::assertStringContainsString('class="cart-item__media"', $body);
        self::assertStringContainsString('class="cart-quantity-form"', $body);
        self::assertStringContainsString('class="cart-remove-form"', $body);
        self::assertStringContainsString('class="cart-order-card"', $body);
        self::assertStringContainsString('Continuă spre finalizare', $body);
    }

    private function get(string $path): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . $path);
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();

        return $response;
    }
}
