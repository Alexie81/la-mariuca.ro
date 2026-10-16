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
        return $this->view->render('storefront/pages/home.twig', [
            'meta' => $this->meta('La Măriuca Boutique', 'Îmbrăcăminte și accesorii pentru copii de la naștere la 8 ani.', '/'),
            'sections' => $this->content->homepageSections(),
            'categories' => $this->catalog->allCategories(),
            'products' => $this->catalog->featuredProducts(),
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

    public function favorites(): ResponseInterface
    {
        $ids = array_values(array_unique(array_map('intval', (array) ($_SESSION['favorites'] ?? []))));
        $products = [];
        foreach ($ids as $id) {
            $row = $this->catalog->productById($id);
            if ($row !== null && $row['status'] === 'published') {
                $full = $this->catalog->productBySlug((string) $row['slug']);
                if ($full !== null) {
                    $full['price_bani'] = $full['variants'][0]['price_bani'] ?? 0;
                    $full['available_quantity'] = array_sum(array_map(static fn(array $v): int => (int) $v['available_quantity'], $full['variants']));
                    $products[] = $full;
                }
            }
        }
        return $this->view->render('storefront/pages/favorites.twig', ['products' => $products, 'meta' => $this->meta('Favorite', '', '/favorite/', true)]);
    }

    public function toggleFavorite(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) ($request->getParsedBody() ?? []);
        $id = (int) ($data['product_id'] ?? 0);
        $favorites = array_values(array_unique(array_map('intval', (array) ($_SESSION['favorites'] ?? []))));
        if (in_array($id, $favorites, true)) {
            $favorites = array_values(array_filter($favorites, static fn(int $current): bool => $current !== $id));
            Flash::set('success', 'Produsul a fost eliminat din favorite.');
        } else {
            $favorites[] = $id;
            Flash::set('success', 'Produsul a fost adăugat la favorite.');
        }
        $_SESSION['favorites'] = $favorites;
        return $this->view->redirect($request->getHeaderLine('Referer') ?: $this->config->path('favorite/'));
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
