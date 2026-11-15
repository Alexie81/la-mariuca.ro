<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Cart\CartService;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
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
        self::assertStringContainsString('assets/images/icon-favorite-hearts.png', $body);
        self::assertStringNotContainsString('Cadouri cu poveste', $body);
        self::assertStringNotContainsString('Alegeri pentru botez', $body);
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
        self::assertStringContainsString('data-cart-quantity-decrease', $body);
        self::assertStringContainsString('data-cart-quantity-increase', $body);
        self::assertStringNotContainsString('<button type="submit">Actualizează</button>', $body);
        self::assertStringContainsString('aria-label="Înapoi la magazin"', $body);
        self::assertStringContainsString('Magazin', $body);
        self::assertStringNotContainsString('Continuă cumpărăturile', $body);
        self::assertStringNotContainsString('Mărime standard', $body);
        self::assertStringContainsString('class="cart-remove-form"', $body);
        self::assertStringContainsString('class="cart-order-card"', $body);
        self::assertStringContainsString('Continuă spre finalizare', $body);
    }

    public function testQuantityStepperUpdatesCartThroughJson(): void
    {
        $this->seed();
        $variantId = (int) $this->pdo->query(
            'SELECT v.id FROM product_variants v JOIN inventory i ON i.variant_id = v.id WHERE i.physical_quantity - i.reserved_quantity >= 2 LIMIT 1'
        )->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($variantId, 1);
        $itemId = (int) $cart->summary()['items'][0]['id'];
        $_SESSION['_csrf'] = 'cart-quantity-token';

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/cos/actualizeaza/')
            ->withHeader('Accept', 'application/json')
            ->withParsedBody([
                '_csrf' => 'cart-quantity-token',
                'item_id' => (string) $itemId,
                'quantity' => '2',
            ]);
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);
        self::assertSame(2, $payload['item_quantity']);
        self::assertSame(2, $payload['cart_count']);
        self::assertSame(2, (new CartService($this->pdo))->summary()['count']);
    }

    public function testPreorderWithBackordersCanBeAddedWithoutPhysicalStock(): void
    {
        $this->seed();
        $row = $this->pdo->query('SELECT v.id AS variant_id,p.id AS product_id,p.slug FROM product_variants v JOIN products p ON p.id=v.product_id ORDER BY v.id LIMIT 1')->fetch();
        self::assertIsArray($row);
        $this->pdo->exec('UPDATE inventory SET physical_quantity=0,reserved_quantity=0 WHERE variant_id=' . (int) $row['variant_id']);
        $this->pdo->exec("UPDATE products SET public_availability='preorder',backorder_policy='allow' WHERE id=" . (int) $row['product_id']);

        $cart = new CartService($this->pdo);
        $cart->add((int) $row['variant_id'], 2);
        $item = $cart->summary()['items'][0];

        self::assertSame(2, (int) $item['quantity']);
        self::assertSame(999999, (int) $item['available_quantity']);
        self::assertSame(1, (int) $item['stock_unlimited']);
        self::assertStringContainsString('Disponibil pentru precomandă', (string) $this->get('/produs/' . $row['slug'] . '/')->getBody());
    }

    public function testOnRequestAndUnavailableProductsCannotBeAddedDirectly(): void
    {
        $this->seed();
        $row = $this->pdo->query('SELECT v.id AS variant_id,p.id AS product_id FROM product_variants v JOIN products p ON p.id=v.product_id ORDER BY v.id LIMIT 1')->fetch();
        self::assertIsArray($row);
        $cart = new CartService($this->pdo);

        foreach (['on_request', 'unavailable'] as $availability) {
            $statement = $this->pdo->prepare("UPDATE products SET public_availability=:availability,backorder_policy='allow' WHERE id=:id");
            $statement->execute(['availability' => $availability, 'id' => $row['product_id']]);
            try {
                $cart->add((int) $row['variant_id'], 1);
                self::fail('Produsul marcat ' . $availability . ' nu trebuie adăugat direct în coș.');
            } catch (RuntimeException $exception) {
                self::assertSame('Varianta nu este disponibilă.', $exception->getMessage());
            }
        }
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
