<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use Database\Seeders\ExpandedCatalogSeeder;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class CategoryRouteTest extends DatabaseTestCase
{
    public function testFlatSubcategoryUrlRedirectsPermanentlyToItsCanonicalHierarchy(): void
    {
        $this->seed();

        $response = $this->get('/categorie/rochite-ocazie/?sortare=price-asc');

        self::assertSame(301, $response->getStatusCode());
        self::assertSame(
            '/categorie/fetite/rochite/rochite-ocazie/?sortare=price-asc',
            $response->getHeaderLine('Location')
        );
    }

    public function testCanonicalCategoryUrlContainsFullBreadcrumbAndCanonicalMetadata(): void
    {
        $this->seed();

        $response = $this->get('/categorie/fetite/rochite/rochite-ocazie/');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            '<link rel="canonical" href="http://localhost/categorie/fetite/rochite/rochite-ocazie/">',
            $body
        );
        self::assertStringContainsString('href="/categorie/fetite/">Fetițe</a>', $body);
        self::assertStringContainsString('href="/categorie/fetite/rochite/">Rochițe</a>', $body);
        self::assertStringContainsString('<span aria-current="page">Rochițe de ocazie</span>', $body);
        self::assertStringContainsString('"@type":"BreadcrumbList"', $body);
    }

    public function testInventedCategoryAncestryReturnsNotFound(): void
    {
        $this->seed();

        $response = $this->get('/categorie/baieti/rochite/rochite-ocazie/');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testFormerSlugInsideAValidHierarchyRedirectsToTheNewCanonicalPath(): void
    {
        $this->seed();
        $now = gmdate('c');
        $this->pdo->exec("UPDATE categories SET slug = 'rochite-eveniment' WHERE slug = 'rochite-ocazie'");
        $statement = $this->pdo->prepare('INSERT INTO slug_redirects (entity_type, old_slug, new_slug, created_at) VALUES (:type, :old, :new, :created)');
        $statement->execute([
            'type' => 'category',
            'old' => 'rochite-ocazie',
            'new' => 'rochite-eveniment',
            'created' => $now,
        ]);

        $response = $this->get('/categorie/fetite/rochite/rochite-ocazie/');

        self::assertSame(301, $response->getStatusCode());
        self::assertSame(
            '/categorie/fetite/rochite/rochite-eveniment/',
            $response->getHeaderLine('Location')
        );
    }

    public function testSitemapUsesHierarchicalCategoryUrls(): void
    {
        $this->seed();

        $body = (string) $this->get('/sitemap.xml')->getBody();

        self::assertStringContainsString(
            '<loc>http://localhost/categorie/fetite/rochite/rochite-ocazie/</loc>',
            $body
        );
        self::assertStringNotContainsString(
            '<loc>http://localhost/categorie/rochite-ocazie/</loc>',
            $body
        );
    }

    public function testCategoryArtworkReplacesTheShopMascotOnlyWhenItExists(): void
    {
        $this->seed();

        $categoryBody = (string) $this->get('/categorie/fetite/')->getBody();
        $subcategoryBody = (string) $this->get('/categorie/fetite/rochite/rochite-ocazie/')->getBody();

        self::assertStringContainsString('category-editorial-portrait', $categoryBody);
        self::assertStringContainsString('assets/images/categories/fetite-editorial-2026.webp', $categoryBody);
        self::assertStringNotContainsString('la-mariuca-hero-v3-cat-head-bow.png', $categoryBody);
        self::assertStringContainsString('boutique-product-card', $categoryBody);
        self::assertStringNotContainsString('category-editorial-portrait', $subcategoryBody);
        self::assertStringNotContainsString('la-mariuca-hero-v3-cat-head-bow.png', $subcategoryBody);
    }

    public function testCategoryCatalogUsesSharedControlsAndOnlyRelevantDynamicFilters(): void
    {
        $this->seed();

        $categoryBody = (string) $this->get('/categorie/fetite/')->getBody();
        $leafBody = (string) $this->get('/categorie/fetite/rochite/rochite-ocazie/')->getBody();

        self::assertStringContainsString('class="shop-catalog-layout has-mobile-product-count"', $categoryBody);
        self::assertStringContainsString('class="shop-toolbar"', $categoryBody);
        self::assertStringContainsString('data-shop-filters', $categoryBody);
        self::assertStringContainsString('class="shop-pagination"', $categoryBody);
        self::assertStringContainsString('<legend>Subcategorii</legend>', $categoryBody);
        self::assertStringContainsString('Toate din Fetițe', $categoryBody);
        self::assertStringContainsString('value="rochite"', $categoryBody);
        self::assertStringNotContainsString('value="baieti"', $categoryBody);
        self::assertStringNotContainsString('data-shop-category-carousel', $categoryBody);

        self::assertStringContainsString('data-shop-filters', $leafBody);
        self::assertStringNotContainsString('shop-category-filter', $leafBody);
        self::assertStringNotContainsString('data-shop-category-carousel', $leafBody);
    }

    public function testCategoryFilterPreviewStaysInsideItsOwnBranch(): void
    {
        $this->seed();

        $validPreview = $this->get('/categorie/fetite/?preview=1&categorie%5B%5D=rochite-ocazie');
        $foreignPreview = $this->get('/categorie/fetite/?preview=1&categorie%5B%5D=baieti');

        self::assertStringStartsWith('application/json', $validPreview->getHeaderLine('Content-Type'));
        self::assertMatchesRegularExpression('/^\{"count":\d+\}$/', (string) $validPreview->getBody());
        self::assertSame(
            (string) $this->get('/categorie/fetite/?preview=1')->getBody(),
            (string) $foreignPreview->getBody()
        );
    }

    public function testProductPageRendersDynamicEditorialNavigationAndSpecifications(): void
    {
        $this->seed();

        $body = (string) $this->get('/produs/costumas-micul-gentleman/')->getBody();

        self::assertStringContainsString('data-product-editorial', $body);
        self::assertStringContainsString('href="#descriere-produs"', $body);
        self::assertStringContainsString('href="#informatii-produs"', $body);
        self::assertStringContainsString('>Informații produs</a>', $body);
        self::assertStringContainsString('href="#recenzii-produs"', $body);
        self::assertStringContainsString('<dt>Mărime</dt><dd>2 ani · 4 ani</dd>', $body);
        self::assertStringContainsString('data-product-description-toggle', $body);
        self::assertStringContainsString('product-editorial.js', $body);
        self::assertStringContainsString('id="formular-recenzie"', $body);
        self::assertStringContainsString('name="rating"', $body);
        self::assertStringContainsString('product-similar-carousel', $body);
        self::assertStringContainsString('data-product-carousel', $body);
    }

    public function testVariantGroupsPreselectFirstAvailableValue(): void
    {
        $this->seed();
        (new ExpandedCatalogSeeder($this->pdo))->run();
        $slug = (string) $this->pdo->query("SELECT p.slug FROM products p JOIN product_variants v ON v.product_id = p.id WHERE v.sku = 'LM-26-030' LIMIT 1")->fetchColumn();

        $body = (string) $this->get('/produs/' . $slug . '/')->getBody();

        self::assertMatchesRegularExpression(
            '/data-option-name="Dimensiune"[^>]*>.*?<option value="1 cm" selected>1 cm<\/option>/s',
            $body
        );
        self::assertMatchesRegularExpression(
            '/data-option-name="Mărime"[^>]*>.*?<option value="0-6 luni" selected>0-6 luni<\/option>/s',
            $body
        );
        self::assertMatchesRegularExpression(
            '/data-option-name="Culoare"[^>]*>.*?<option value="Roșu" selected>Roșu<\/option>/s',
            $body
        );
        self::assertStringContainsString('selectFirstAvailableOptions', file_get_contents($this->root . '/public/assets/js/storefront/variant-selector.js'));
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
