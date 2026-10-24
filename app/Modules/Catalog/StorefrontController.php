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
        private readonly CategoryPathRepository $categoryPaths,
        private readonly ContentRepository $content,
        private readonly RateLimiter $limiter,
        private readonly View $view,
        private readonly Config $config,
    ) {
    }

    public function home(ServerRequestInterface $request): ResponseInterface
    {
        $categories = $this->categoryPaths->addRootPaths($this->catalog->homepageCategories());
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
        $requestedPath = trim((string) ($args['path'] ?? ''), '/');
        $segments = $requestedPath === '' ? [] : explode('/', $requestedPath);
        $resolved = $this->categoryPaths->resolve($requestedPath);

        if ($resolved === null) {
            if ($segments === []) {
                return $this->notFound();
            }

            $redirect = $this->catalog->redirectFor('category', (string) end($segments));
            $redirectPath = $redirect === null
                ? null
                : $this->categoryPaths->pathForSlug((string) $redirect['new_slug']);
            return $redirectPath === null || !$this->isRedirectableCategoryPath($segments, $redirectPath)
                ? $this->notFound()
                : $this->categoryRedirect($request, $redirectPath);
        }

        if ($requestedPath !== $resolved['path']) {
            return $this->isRedirectableCategoryPath($segments, $resolved['path'])
                ? $this->categoryRedirect($request, $resolved['path'])
                : $this->notFound();
        }

        $category = $resolved['category'];
        $categoryTrail = $resolved['trail'];
        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['pagina'] ?? 1));
        $sort = (string) ($query['sortare'] ?? 'new');
        if ($sort === 'name') {
            $sort = 'name-asc';
        }
        if (!in_array($sort, ['new', 'price-asc', 'price-desc', 'name-asc', 'name-desc'], true)) {
            $sort = 'new';
        }

        $filterCategories = $this->categoryPaths->addPathsToTree(
            $this->catalog->shopFilterCategories((int) $category['id']),
            $resolved['path']
        );
        $allowedCategorySlugs = $this->categoryTreeSlugs($filterCategories);
        $requestedCategorySlugs = $query['categorie'] ?? [];
        if (!is_array($requestedCategorySlugs)) {
            $requestedCategorySlugs = [$requestedCategorySlugs];
        }
        $categorySlugs = [];
        $categoryIds = [];
        $categories = [];
        foreach (array_slice($requestedCategorySlugs, 0, 50) as $requestedCategorySlug) {
            if (!is_string($requestedCategorySlug) && !is_int($requestedCategorySlug)) {
                continue;
            }
            $requestedCategorySlug = trim((string) $requestedCategorySlug);
            if (
                $requestedCategorySlug === ''
                || !in_array($requestedCategorySlug, $allowedCategorySlugs, true)
                || in_array($requestedCategorySlug, $categorySlugs, true)
            ) {
                continue;
            }
            $selectedCategory = $this->catalog->categoryBySlug($requestedCategorySlug);
            if (!is_array($selectedCategory)) {
                continue;
            }
            $categorySlugs[] = (string) $selectedCategory['slug'];
            $categoryIds[] = (int) $selectedCategory['id'];
            $categories[] = $selectedCategory;
        }

        $baseCategoryIds = [(int) $category['id']];
        $scopedCategoryIds = $categoryIds === [] ? $baseCategoryIds : $categoryIds;
        $inStock = (string) ($query['stoc'] ?? '') === '1';
        $priceRange = $this->catalog->shopPriceRange($baseCategoryIds);
        $priceMinLei = max(0, (int) floor($priceRange['min_bani'] / 100));
        $priceMaxLei = max($priceMinLei + 1, (int) ceil($priceRange['max_bani'] / 100));
        $requestedMaxPrice = filter_var($query['pret_max'] ?? null, FILTER_VALIDATE_INT);
        $selectedMaxPriceLei = $requestedMaxPrice === false
            ? $priceMaxLei
            : max($priceMinLei, min($priceMaxLei, (int) $requestedMaxPrice));
        $maxPriceBani = $selectedMaxPriceLei < $priceMaxLei ? $selectedMaxPriceLei * 100 : null;

        $requestedPerPage = filter_var($query['pe_pagina'] ?? null, FILTER_VALIDATE_INT);
        $perPage = in_array($requestedPerPage, [12, 24, 50], true) ? (int) $requestedPerPage : 12;
        $productTotal = $this->catalog->publishedProductCount($scopedCategoryIds, $inStock, $maxPriceBani);

        if ((string) ($query['preview'] ?? '') === '1') {
            return $this->json(['count' => $productTotal])
                ->withHeader('Cache-Control', 'no-store');
        }

        $lastPage = max(1, (int) ceil($productTotal / $perPage));
        $page = min($page, $lastPage);
        $products = $this->catalog->shopProducts($page, $perPage, $sort, $scopedCategoryIds, $inStock, $maxPriceBani);
        $isFiltered = $categoryIds !== [] || $inStock || $maxPriceBani !== null;
        $indexable = (int) $category['indexable'] === 1
            && $products !== []
            && $page === 1
            && $sort === 'new'
            && $perPage === 12
            && !$isFiltered;

        $displayQuery = ['sortare' => $sort];
        if ($perPage !== 12) {
            $displayQuery['pe_pagina'] = (string) $perPage;
        }
        $activeQuery = $displayQuery;
        if ($categorySlugs !== []) {
            $activeQuery['categorie'] = $categorySlugs;
        }
        if ($inStock) {
            $activeQuery['stoc'] = '1';
        }
        if ($maxPriceBani !== null) {
            $activeQuery['pret_max'] = (string) $selectedMaxPriceLei;
        }

        $expandedCategoryIds = [];
        foreach ($categories as $selectedCategory) {
            $categoryCursor = $selectedCategory;
            $visitedCategoryIds = [];
            while (is_array($categoryCursor)) {
                $cursorId = (int) $categoryCursor['id'];
                if (in_array($cursorId, $visitedCategoryIds, true)) {
                    break;
                }
                $visitedCategoryIds[] = $cursorId;
                if (!in_array($cursorId, $expandedCategoryIds, true)) {
                    $expandedCategoryIds[] = $cursorId;
                }
                $parentId = $categoryCursor['parent_id'] === null ? null : (int) $categoryCursor['parent_id'];
                $categoryCursor = $parentId === null ? null : $this->catalog->categoryById($parentId);
            }
        }

        $categoryResetQuery = $activeQuery;
        unset($categoryResetQuery['categorie']);

        return $this->view->render('storefront/pages/category.twig', [
            'category' => $category,
            'category_trail' => $categoryTrail,
            'products' => $products,
            'page' => $page,
            'last_page' => $lastPage,
            'pagination_items' => $this->paginationItems($page, $lastPage),
            'page_start' => $productTotal === 0 ? 0 : (($page - 1) * $perPage) + 1,
            'page_end' => min($productTotal, $page * $perPage),
            'per_page' => $perPage,
            'per_page_options' => [12, 24, 50],
            'sort' => $sort,
            'product_total' => $productTotal,
            'all_product_total' => $this->catalog->publishedProductCount($baseCategoryIds),
            'has_next_page' => $page < $lastPage,
            'filter_categories' => $filterCategories,
            'show_mobile_category_carousel' => false,
            'expanded_category_ids' => $expandedCategoryIds,
            'active_category_slugs' => $categorySlugs,
            'filter_in_stock' => $inStock,
            'filter_price_min' => $priceMinLei,
            'filter_price_max' => $priceMaxLei,
            'filter_price_value' => $selectedMaxPriceLei,
            'filter_price_active' => $maxPriceBani !== null,
            'filters_active' => $isFiltered,
            'shop_query' => http_build_query($activeQuery),
            'shop_reset_query' => http_build_query($displayQuery),
            'shop_category_reset_query' => http_build_query($categoryResetQuery),
            'catalog_filter_legend' => 'Subcategorii',
            'catalog_all_label' => 'Toate din ' . $category['name'],
            'catalog_pagination_label' => 'Paginile categoriei ' . $category['name'],
            'catalog_empty_title' => 'Nu am găsit combinația potrivită',
            'catalog_empty_text' => 'Încearcă un buget mai mare sau resetează filtrele.',
            'structured_data' => json_encode(
                $this->breadcrumbStructuredData($categoryTrail),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            'meta' => $this->meta($category['seo_title'] ?: $category['name'], $category['meta_description'] ?: $category['description'], '/categorie/' . $resolved['path'] . '/', !$indexable),
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
        $categoryTrail = empty($product['category_slug'])
            ? []
            : $this->categoryPaths->trailForSlug((string) $product['category_slug']);
        $offers = [];
        foreach ($variants as $variant) {
            $offers[] = [
                '@type' => 'Offer', 'priceCurrency' => 'RON', 'price' => number_format(((int) $variant['price_bani']) / 100, 2, '.', ''),
                'availability' => (int) $variant['available_quantity'] > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'sku' => $variant['sku'], 'url' => $this->config->rootUrl() . '/produs/' . $product['slug'] . '/',
            ];
        }
        $structured = [
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'Product', 'name' => $product['name'], 'description' => $product['short_description'], 'sku' => $variants[0]['sku'] ?? '', 'offers' => $offers],
                $this->breadcrumbStructuredData($categoryTrail, (string) $product['name'], '/produs/' . $product['slug'] . '/', false),
            ],
        ];
        return $this->view->render('storefront/pages/product.twig', [
            'product' => $product,
            'product_category_trail' => $categoryTrail,
            'structured_data' => json_encode($structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
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
        if ($sort === 'name') {
            $sort = 'name-asc';
        }
        if (!in_array($sort, ['new', 'price-asc', 'price-desc', 'name-asc', 'name-desc'], true)) {
            $sort = 'new';
        }

        $requestedCategorySlugs = $query['categorie'] ?? [];
        if (!is_array($requestedCategorySlugs)) {
            $requestedCategorySlugs = [$requestedCategorySlugs];
        }
        $categorySlugs = [];
        $categoryIds = [];
        $categories = [];
        foreach (array_slice($requestedCategorySlugs, 0, 50) as $requestedCategorySlug) {
            if (!is_string($requestedCategorySlug) && !is_int($requestedCategorySlug)) {
                continue;
            }
            $requestedCategorySlug = trim((string) $requestedCategorySlug);
            if ($requestedCategorySlug === '' || in_array($requestedCategorySlug, $categorySlugs, true)) {
                continue;
            }
            $category = $this->catalog->categoryBySlug($requestedCategorySlug);
            if (!is_array($category)) {
                continue;
            }
            $categorySlugs[] = (string) $category['slug'];
            $categoryIds[] = (int) $category['id'];
            $categories[] = $category;
        }

        $inStock = (string) ($query['stoc'] ?? '') === '1';
        $priceRange = $this->catalog->shopPriceRange();
        $priceMinLei = max(0, (int) floor($priceRange['min_bani'] / 100));
        $priceMaxLei = max($priceMinLei + 1, (int) ceil($priceRange['max_bani'] / 100));
        $requestedMaxPrice = filter_var($query['pret_max'] ?? null, FILTER_VALIDATE_INT);
        $selectedMaxPriceLei = $requestedMaxPrice === false
            ? $priceMaxLei
            : max($priceMinLei, min($priceMaxLei, (int) $requestedMaxPrice));
        $maxPriceBani = $selectedMaxPriceLei < $priceMaxLei ? $selectedMaxPriceLei * 100 : null;

        $requestedPerPage = filter_var($query['pe_pagina'] ?? null, FILTER_VALIDATE_INT);
        $perPage = in_array($requestedPerPage, [12, 24, 50], true) ? (int) $requestedPerPage : 12;
        $productTotal = $this->catalog->publishedProductCount($categoryIds, $inStock, $maxPriceBani);

        if ((string) ($query['preview'] ?? '') === '1') {
            return $this->json(['count' => $productTotal])
                ->withHeader('Cache-Control', 'no-store');
        }

        $lastPage = max(1, (int) ceil($productTotal / $perPage));
        $page = min($page, $lastPage);
        $products = $this->catalog->shopProducts($page, $perPage, $sort, $categoryIds, $inStock, $maxPriceBani);
        $hasNextPage = $page * $perPage < $productTotal;
        $isFiltered = $categoryIds !== [] || $inStock || $maxPriceBani !== null;

        $displayQuery = ['sortare' => $sort];
        if ($perPage !== 12) {
            $displayQuery['pe_pagina'] = (string) $perPage;
        }
        $activeQuery = $displayQuery;
        if ($categorySlugs !== []) {
            $activeQuery['categorie'] = $categorySlugs;
        }
        if ($inStock) {
            $activeQuery['stoc'] = '1';
        }
        if ($maxPriceBani !== null) {
            $activeQuery['pret_max'] = (string) $selectedMaxPriceLei;
        }

        $expandedCategoryIds = [];
        foreach ($categories as $selectedCategory) {
            $categoryCursor = $selectedCategory;
            $visitedCategoryIds = [];
            while (is_array($categoryCursor)) {
                $cursorId = (int) $categoryCursor['id'];
                if (in_array($cursorId, $visitedCategoryIds, true)) {
                    break;
                }
                $visitedCategoryIds[] = $cursorId;
                if (!in_array($cursorId, $expandedCategoryIds, true)) {
                    $expandedCategoryIds[] = $cursorId;
                }
                $parentId = $categoryCursor['parent_id'] === null ? null : (int) $categoryCursor['parent_id'];
                $categoryCursor = $parentId === null ? null : $this->catalog->categoryById($parentId);
            }
        }

        $categoryResetQuery = $activeQuery;
        unset($categoryResetQuery['categorie']);

        return $this->view->render('storefront/pages/shop.twig', [
            'products' => $products,
            'page' => $page,
            'last_page' => $lastPage,
            'pagination_items' => $this->paginationItems($page, $lastPage),
            'page_start' => $productTotal === 0 ? 0 : (($page - 1) * $perPage) + 1,
            'page_end' => min($productTotal, $page * $perPage),
            'per_page' => $perPage,
            'per_page_options' => [12, 24, 50],
            'sort' => $sort,
            'product_total' => $productTotal,
            'all_product_total' => $this->catalog->publishedProductCount(),
            'has_next_page' => $hasNextPage,
            'filter_categories' => $this->categoryPaths->addPathsToTree($this->catalog->shopFilterCategories()),
            'expanded_category_ids' => $expandedCategoryIds,
            'active_category_slugs' => $categorySlugs,
            'filter_in_stock' => $inStock,
            'filter_price_min' => $priceMinLei,
            'filter_price_max' => $priceMaxLei,
            'filter_price_value' => $selectedMaxPriceLei,
            'filter_price_active' => $maxPriceBani !== null,
            'filters_active' => $isFiltered,
            'shop_query' => http_build_query($activeQuery),
            'shop_reset_query' => http_build_query($displayQuery),
            'shop_category_reset_query' => http_build_query($categoryResetQuery),
            'meta' => $this->meta(
                'Magazin',
                'Descoperă toate hăinuțele și accesoriile pentru copii disponibile la La Măriuca Boutique.',
                '/magazin/',
                $page !== 1 || $sort !== 'new' || $perPage !== 12 || $isFiltered
            ),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $categories
     * @return list<string>
     */
    private function categoryTreeSlugs(array $categories): array
    {
        $slugs = [];
        foreach ($categories as $category) {
            $slugs[] = (string) $category['slug'];
            $slugs = array_merge($slugs, $this->categoryTreeSlugs((array) ($category['children'] ?? [])));
        }

        return $slugs;
    }

    /** @return list<int|string> */
    private function paginationItems(int $page, int $lastPage): array
    {
        if ($lastPage <= 7) {
            return range(1, $lastPage);
        }

        $visiblePages = [1, $lastPage, $page - 1, $page, $page + 1];
        if ($page <= 4) {
            $visiblePages = array_merge($visiblePages, [2, 3, 4, 5]);
        }
        if ($page >= $lastPage - 3) {
            $visiblePages = array_merge($visiblePages, [$lastPage - 4, $lastPage - 3, $lastPage - 2, $lastPage - 1]);
        }

        $visiblePages = array_values(array_unique(array_filter(
            $visiblePages,
            static fn(int $candidate): bool => $candidate >= 1 && $candidate <= $lastPage
        )));
        sort($visiblePages, SORT_NUMERIC);

        $items = [];
        $previous = 0;
        foreach ($visiblePages as $visiblePage) {
            if ($previous !== 0 && $visiblePage - $previous > 1) {
                $items[] = 'ellipsis';
            }
            $items[] = $visiblePage;
            $previous = $visiblePage;
        }

        return $items;
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
                $categoryPath = $this->categoryPaths->pathForSlug((string) $category['slug']);
                if ($categoryPath === null) {
                    continue;
                }
                $categories[] = [
                    'name' => (string) $category['name'],
                    'slug' => (string) $category['slug'],
                    'url' => $this->config->path('categorie/' . $categoryPath . '/'),
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
                $entityPath = $type === 'category'
                    ? $this->categoryPaths->pathForSlug((string) $entity['slug'])
                    : (string) $entity['slug'];
                if ($entityPath === null) {
                    continue;
                }
                $urls[] = ['loc' => $this->config->rootUrl() . '/' . $prefix . '/' . $entityPath . '/', 'lastmod' => substr((string) $entity['updated_at'], 0, 10)];
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

    private function categoryRedirect(ServerRequestInterface $request, string $path): ResponseInterface
    {
        $location = $this->config->path('categorie/' . $path . '/');
        $query = $request->getUri()->getQuery();
        if ($query !== '') {
            $location .= '?' . $query;
        }

        return $this->view->redirect($location, 301);
    }

    /** @param list<string> $requestedSegments */
    private function isRedirectableCategoryPath(array $requestedSegments, string $canonicalPath): bool
    {
        if (count($requestedSegments) === 1) {
            return true;
        }

        $canonicalSegments = explode('/', $canonicalPath);
        if (count($requestedSegments) !== count($canonicalSegments)) {
            return false;
        }

        foreach ($requestedSegments as $index => $segment) {
            if ($segment === $canonicalSegments[$index]) {
                continue;
            }

            $redirect = $this->catalog->redirectFor('category', $segment);
            if ($redirect === null || $redirect['new_slug'] !== $canonicalSegments[$index]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $categoryTrail
     * @return array<string, mixed>
     */
    private function breadcrumbStructuredData(
        array $categoryTrail,
        ?string $currentName = null,
        ?string $currentPath = null,
        bool $includeContext = true,
    ): array {
        $items = [[
            '@type' => 'ListItem',
            'position' => 1,
            'name' => 'Acasă',
            'item' => $this->config->rootUrl() . '/',
        ]];

        foreach ($categoryTrail as $category) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => count($items) + 1,
                'name' => (string) $category['name'],
                'item' => $this->config->rootUrl() . '/categorie/' . $category['path'] . '/',
            ];
        }

        if ($currentName !== null && $currentPath !== null) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => count($items) + 1,
                'name' => $currentName,
                'item' => $this->config->rootUrl() . '/' . ltrim($currentPath, '/'),
            ];
        }

        $data = [
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
        if ($includeContext) {
            $data = ['@context' => 'https://schema.org'] + $data;
        }

        return $data;
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
