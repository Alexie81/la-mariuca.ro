<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Modules\Content\ContentRepository;
use App\Shared\Config\Config;
use App\Shared\Http\RateLimiter;
use App\Shared\Http\View;
use App\Shared\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

final class StorefrontController
{
    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly ContentRepository $content,
        private readonly RateLimiter $limiter,
        private readonly View $view,
        private readonly Config $config,
    ) {
    }

    public function home(ServerRequestInterface $request): ResponseInterface
    {
        $categories = $this->catalog->homepageCategories();
        $baptismCategory = null;
        foreach ($categories as $category) {
            if (($category['slug'] ?? '') === 'botez') {
                $baptismCategory = $category;
                break;
            }
        }
        $products = $this->catalog->featuredProducts(8);

        return $this->view->render('storefront/pages/home.twig', [
            'meta' => $this->meta('La Măriuca Boutique', 'Îmbrăcăminte și accesorii pentru copii de la naștere la 8 ani.', '/'),
            'sections' => $this->content->homepageSections(),
            'categories' => $categories,
            'products' => $products,
            'new_products' => $this->catalog->latestProducts(15),
            'baptism_category' => $baptismCategory,
        ]);
    }

    public function category(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = (string) $args['slug'];
        $category = $this->catalog->categoryBySlug($slug);
        if ($category === null) {
            $redirect = $this->catalog->redirectFor('category', $slug);
            return $redirect !== null ? $this->view->redirect($this->config->path('categorie/' . $redirect['new_slug'] . '/'), 301) : $this->notFound();
        }
        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['pagina'] ?? 1));
        $sort = (string) ($query['sortare'] ?? 'new');
        $products = $this->catalog->productsForCategory((int) $category['id'], $page, 12, $sort);
        $indexable = (int) $category['indexable'] === 1 && $products !== [] && $page === 1 && $sort === 'new';
        return $this->view->render('storefront/pages/category.twig', [
            'category' => $category, 'products' => $products, 'page' => $page, 'sort' => $sort,
            'meta' => $this->meta($category['seo_title'] ?: $category['name'], $category['meta_description'] ?: $category['description'], '/categorie/' . $category['slug'] . '/', !$indexable),
        ]);
    }

    public function product(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = (string) $args['slug'];
        $product = $this->catalog->productBySlug($slug);
        if ($product === null) {
            $redirect = $this->catalog->redirectFor('product', $slug);
            return $redirect !== null ? $this->view->redirect($this->config->path('produs/' . $redirect['new_slug'] . '/'), 301) : $this->notFound();
        }
        $variants = (array) $product['variants'];
        $offers = [];
        foreach ($variants as $variant) {
            $offers[] = [
                '@type' => 'Offer', 'priceCurrency' => 'RON', 'price' => number_format(((int) $variant['price_bani']) / 100, 2, '.', ''),
                'availability' => (int) $variant['available_quantity'] > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'sku' => $variant['sku'], 'url' => $this->config->rootUrl() . '/produs/' . $product['slug'] . '/',
            ];
        }
        $structured = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $product['name'], 'description' => $product['short_description'], 'sku' => $variants[0]['sku'] ?? '', 'offers' => $offers];
        return $this->view->render('storefront/pages/product.twig', [
            'product' => $product, 'structured_data' => json_encode($structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'meta' => $this->meta($product['seo_title'] ?: $product['name'], $product['meta_description'] ?: $product['short_description'], '/produs/' . $product['slug'] . '/', (int) $product['indexable'] !== 1),
        ]);
    }

    public function collection(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $collection = $this->catalog->collectionBySlug((string) $args['slug']);
        if ($collection === null) {
            return $this->notFound();
        }
        $products = $this->catalog->collectionProducts((string) $args['slug']);
        return $this->view->render('storefront/pages/collection.twig', [
            'collection' => $collection, 'products' => $products,
            'meta' => $this->meta($collection['seo_title'] ?: $collection['name'], $collection['meta_description'] ?: $collection['description'], '/colectie/' . $collection['slug'] . '/', (int) $collection['indexable'] !== 1 || $products === []),
        ]);
    }

    public function shop(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['pagina'] ?? 1));
        $sort = (string) ($query['sortare'] ?? 'new');
        if (!in_array($sort, ['new', 'price-asc', 'price-desc', 'name'], true)) {
            $sort = 'new';
        }
        $perPage = 12;
        $products = $this->catalog->shopProducts($page, $perPage, $sort);
        $productTotal = $this->catalog->publishedProductCount();
        $hasNextPage = $page * $perPage < $productTotal;

        return $this->view->render('storefront/pages/shop.twig', [
            'products' => $products,
            'page' => $page,
            'sort' => $sort,
            'product_total' => $productTotal,
            'has_next_page' => $hasNextPage,
            'meta' => $this->meta(
                'Magazin',
                'Descoperă toate hăinuțele și accesoriile pentru copii disponibile la La Măriuca Boutique.',
                '/magazin/',
                $page !== 1 || $sort !== 'new'
            ),
        ]);
    }

    public function search(ServerRequestInterface $request): ResponseInterface
    {
        $query = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        if (!$this->limiter->allow('search', (string) $ip, 60, 60)) {
            return $this->view->render('storefront/pages/search.twig', ['query' => $query, 'products' => [], 'error' => 'Prea multe căutări. Încearcă din nou peste un minut.', 'meta' => $this->meta('Căutare', '', '/cautare/', true)], 429);
        }
        $products = mb_strlen($query) >= 2 ? $this->catalog->search($query) : [];
        return $this->view->render('storefront/pages/search.twig', ['query' => $query, 'products' => $products, 'meta' => $this->meta('Căutare', '', '/cautare/', true)]);
    }

    public function searchSuggestions(ServerRequestInterface $request): ResponseInterface
    {
        $query = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        $items = [];
        $categories = [];
        $status = 200;

        if (!$this->limiter->allow('search-suggestions', $ip, 120, 60)) {
            $status = 429;
        } elseif (mb_strlen($query) >= 2) {
            foreach ($this->catalog->searchCategories($query, 4) as $category) {
                $categories[] = [
                    'name' => (string) $category['name'],
                    'slug' => (string) $category['slug'],
                    'url' => $this->config->path('categorie/' . $category['slug'] . '/'),
                ];
            }
            foreach ($this->catalog->search($query, 6) as $product) {
                $items[] = [
                    'name' => (string) $product['name'],
                    'category' => (string) ($product['category_name'] ?? ''),
                    'price_bani' => (int) ($product['price_bani'] ?? 0),
                    'url' => $this->config->path('produs/' . $product['slug'] . '/'),
                ];
            }
        }

        $response = new Response($status);
        $response->getBody()->write(json_encode([
            'query' => $query,
            'categories' => $categories,
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function favorites(): ResponseInterface
    {
        $ids = array_values(array_unique(array_map('intval', (array) ($_SESSION['favorites'] ?? []))));
        $products = [];
        $validIds = [];
        foreach ($ids as $id) {
            $row = $this->catalog->productById($id);
            if ($row !== null && $row['status'] === 'published') {
                $full = $this->catalog->productBySlug((string) $row['slug']);
                if ($full !== null) {
                    $validIds[] = $id;
                    $products[] = $this->favoriteProductCard($full);
                }
            }
        }

        // Remove products that are no longer publicly available so the navbar count stays accurate.
        $_SESSION['favorites'] = $validIds;

        return $this->view->render('storefront/pages/favorites.twig', ['products' => $products, 'meta' => $this->meta('Favorite', '', '/favorite/', true)]);
    }

    /**
     * Hydrate the shared storefront product-card fields from the full product payload.
     *
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function favoriteProductCard(array $product): array
    {
        $variants = array_values(array_filter((array) ($product['variants'] ?? []), 'is_array'));
        $prices = [];
        $comparePrices = [];
        $availableQuantity = 0;
        $defaultVariantId = null;

        foreach ($variants as $variant) {
            $prices[] = (int) ($variant['price_bani'] ?? 0);
            $comparePrice = (int) ($variant['compare_price_bani'] ?? 0);
            if ($comparePrice > 0) {
                $comparePrices[] = $comparePrice;
            }
            $availableQuantity += (int) ($variant['available_quantity'] ?? 0);
            if ((int) ($variant['is_default'] ?? 0) === 1) {
                $defaultVariantId = (int) ($variant['id'] ?? 0);
            }
        }

        if ($defaultVariantId === null && isset($variants[0]['id'])) {
            $defaultVariantId = (int) $variants[0]['id'];
        }

        $requiredPersonalizationCount = 0;
        foreach ((array) ($product['personalization_options'] ?? []) as $option) {
            if (is_array($option) && (int) ($option['required'] ?? 0) === 1) {
                ++$requiredPersonalizationCount;
            }
        }

        $product['price_bani'] = $prices === [] ? 0 : min($prices);
        $product['compare_price_bani'] = $comparePrices === [] ? null : min($comparePrices);
        $product['available_quantity'] = $availableQuantity;
        $product['variant_count'] = count($variants);
        $product['default_variant_id'] = $defaultVariantId;
        $product['required_personalization_count'] = $requiredPersonalizationCount;

        return $product;
    }

    public function toggleFavorite(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) ($request->getParsedBody() ?? []);
        $id = (int) ($data['product_id'] ?? 0);
        $wantsJson = str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
        $product = $this->catalog->productById($id);
        if ($product === null || ($product['status'] ?? '') !== 'published') {
            $message = 'Produsul nu mai este disponibil.';
            if ($wantsJson) {
                return $this->json(['ok' => false, 'message' => $message], 422);
            }
            Flash::set('error', $message);
            return $this->view->redirect($request->getHeaderLine('Referer') ?: $this->config->path('favorite/'));
        }

        $favorites = array_values(array_unique(array_map('intval', (array) ($_SESSION['favorites'] ?? []))));
        if (in_array($id, $favorites, true)) {
            $favorites = array_values(array_filter($favorites, static fn(int $current): bool => $current !== $id));
            $state = 'removed';
            $message = 'Produsul a fost eliminat din favorite.';
        } else {
            $favorites[] = $id;
            $state = 'added';
            $message = 'Produsul a fost adăugat la favorite.';
        }
        $_SESSION['favorites'] = $favorites;

        if ($wantsJson) {
            return $this->json([
                'ok' => true,
                'message' => $message,
                'state' => $state,
                'favorite_count' => count($favorites),
            ]);
        }

        Flash::set('success', $message);
        return $this->view->redirect($request->getHeaderLine('Referer') ?: $this->config->path('favorite/'));
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function page(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $page = $this->content->page((string) $args['slug']);
        if ($page === null) {
            return $this->notFound();
        }
        return $this->view->render('storefront/pages/content.twig', ['page' => $page, 'meta' => $this->meta($page['seo_title'] ?: $page['title'], $page['meta_description'], '/pagina/' . $page['slug'] . '/', (int) $page['indexable'] !== 1)]);
    }

    public function robots(): ResponseInterface
    {
        $body = $this->config->bool('APP_INDEXING')
            ? "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /cont/\nDisallow: /cos/\nDisallow: /finalizare-comanda/\nDisallow: /cautare/\nSitemap: " . $this->config->rootUrl() . "/sitemap.xml\n"
            : "User-agent: *\nDisallow: /\n";
        $response = new Response();
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    public function sitemap(): ResponseInterface
    {
        $urls = [['loc' => $this->config->rootUrl() . '/', 'lastmod' => null]];
        foreach (['category' => 'categorie', 'product' => 'produs', 'collection' => 'colectie', 'page' => 'pagina'] as $type => $prefix) {
            foreach ($this->catalog->sitemapEntities($type) as $entity) {
                $urls[] = ['loc' => $this->config->rootUrl() . '/' . $prefix . '/' . $entity['slug'] . '/', 'lastmod' => substr((string) $entity['updated_at'], 0, 10)];
            }
        }
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>' . htmlspecialchars((string) $url['loc'], ENT_XML1) . '</loc>' . ($url['lastmod'] ? '<lastmod>' . $url['lastmod'] . '</lastmod>' : '') . "</url>\n";
        }
        $xml .= "</urlset>\n";
        $response = new Response();
        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    /** @return array<string,mixed> */
    private function meta(string $title, string $description, string $path, bool $noindex = false): array
    {
        return ['title' => $title, 'description' => $description, 'canonical' => $this->config->rootUrl() . '/' . ltrim($path, '/'), 'noindex' => $noindex || !$this->config->bool('APP_INDEXING')];
    }

    private function notFound(): ResponseInterface
    {
        return $this->view->render('storefront/pages/error.twig', ['title' => 'Pagina nu a fost găsită', 'message' => 'Adresa nu există sau conținutul nu este publicat.', 'meta' => $this->meta('Pagina nu a fost găsită', '', '/404', true)], 404);
    }
}
