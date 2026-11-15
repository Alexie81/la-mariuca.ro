<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Cart\CartService;
use App\Modules\Suppliers\SupplierAdminService;
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
        $productPage = (string) $this->get('/produs/' . $row['slug'] . '/')->getBody();
        self::assertStringContainsString('Disponibil pentru precomandă', $productPage);
        self::assertStringContainsString('/pagina/livrare-si-retur/', $productPage);
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

    public function testCartLimitsAreConfigurableAndCountDistinctProducts(): void
    {
        $this->seed();
        $variants = $this->pdo->query('SELECT v.id,v.product_id FROM product_variants v JOIN inventory i ON i.variant_id=v.id WHERE v.status=\'active\' AND i.physical_quantity-i.reserved_quantity>=5 ORDER BY v.product_id,v.id')->fetchAll();
        $byProduct = [];
        foreach ($variants as $variant) {
            $byProduct[(int) $variant['product_id']] ??= (int) $variant['id'];
        }
        self::assertGreaterThanOrEqual(2, count($byProduct));
        $variantIds = array_values($byProduct);
        $cart = new CartService($this->pdo, 3, 1);

        try {
            $cart->add($variantIds[0], 4);
            self::fail('Limita configurată de cantitate trebuie aplicată.');
        } catch (RuntimeException $exception) {
            self::assertSame('Cantitatea trebuie să fie între 1 și 3.', $exception->getMessage());
        }

        $cart->add($variantIds[0], 1);
        try {
            $cart->add($variantIds[1], 1);
            self::fail('Limita configurată de produse distincte trebuie aplicată.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('maximum 1 produse distincte', $exception->getMessage());
        }
        $cart->add($variantIds[0], 1);
        self::assertSame(2, $cart->summary()['count']);
    }

    public function testInactiveSupplierBlocksDirectOrderingAndIsExplainedOnProductPage(): void
    {
        $this->seed();
        $row = $this->pdo->query('SELECT v.id AS variant_id,p.id AS product_id,p.slug FROM product_variants v JOIN products p ON p.id=v.product_id ORDER BY v.id LIMIT 1')->fetch();
        self::assertIsArray($row);
        $supplierId = (new SupplierAdminService($this->pdo))->save(['alias' => 'Sursă temporar oprită', 'company_name' => 'Sursă temporar oprită SRL']);
        $this->pdo->exec("UPDATE suppliers SET status='inactive' WHERE id=" . $supplierId);
        $this->pdo->exec('UPDATE products SET supplier_id=' . $supplierId . ' WHERE id=' . (int) $row['product_id']);

        try {
            (new CartService($this->pdo))->add((int) $row['variant_id'], 1);
            self::fail('Produsul unei surse inactive nu trebuie adăugat în coș.');
        } catch (RuntimeException $exception) {
            self::assertSame('Varianta nu este disponibilă.', $exception->getMessage());
        }
        $page = (string) $this->get('/produs/' . $row['slug'] . '/')->getBody();
        self::assertStringContainsString('Sursa acestui produs este temporar inactivă', $page);
        self::assertStringNotContainsString('data-cart-form', $page);
    }

    public function testHiddenSupplierBlocksAddingAndUpdatingCartItems(): void
    {
        $this->seed();
        $row=$this->pdo->query("SELECT v.id AS variant_id,p.id AS product_id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND v.status='active' AND i.physical_quantity-i.reserved_quantity>=2 LIMIT 1")->fetch();
        $service=new SupplierAdminService($this->pdo);
        $supplier=$service->save(['alias'=>'Furnizor ascuns QA']);
        $this->pdo->exec('UPDATE products SET supplier_id='.$supplier.' WHERE id='.$row['product_id']);
        $cart=new CartService($this->pdo); $cart->add((int)$row['variant_id'],1);
        $item=(int)$cart->summary()['items'][0]['id'];
        $service->save(['alias'=>'Furnizor ascuns QA','products_visible_online'=>'0'],$supplier);
        foreach([fn()=>$cart->add((int)$row['variant_id'],1),fn()=>$cart->update($item,2)] as $action) {
            try { $action(); self::fail('Hidden products must not be ordered'); }
            catch(RuntimeException $e) { self::assertNotSame('',$e->getMessage()); }
        }
        self::assertSame(1,$cart->summary()['count']);
        $cart->update($item,0); self::assertSame(0,$cart->summary()['count']);
    }

    public function testArchivedProductReturnsGoneWhileDraftRemainsNotFound(): void
    {
        $this->seed();
        $slug = 'costumas-micul-gentleman';
        $this->pdo->exec("UPDATE products SET status='archived' WHERE slug='" . $slug . "'");
        self::assertSame(410, $this->get('/produs/' . $slug . '/')->getStatusCode());

        $this->pdo->exec("UPDATE products SET status='draft' WHERE slug='" . $slug . "'");
        self::assertSame(404, $this->get('/produs/' . $slug . '/')->getStatusCode());
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
