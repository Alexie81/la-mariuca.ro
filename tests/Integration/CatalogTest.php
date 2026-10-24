<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Catalog\CatalogAdminService;
use App\Modules\Catalog\CatalogRepository;
use App\Modules\Catalog\CatalogSearchService;
use RuntimeException;
use Tests\DatabaseTestCase;

final class CatalogTest extends DatabaseTestCase
{
    public function testCategoryCycleIsRejected(): void
    {
        $service = new CatalogAdminService($this->pdo);
        $parent = $service->saveCategory(['name' => 'Părinte', 'status' => 'draft', 'indexable' => '1']);
        $child = $service->saveCategory(['name' => 'Copil', 'parent_id' => (string) $parent, 'status' => 'draft', 'indexable' => '1']);
        $this->expectException(RuntimeException::class);
        $service->saveCategory(['name' => 'Părinte', 'parent_id' => (string) $child, 'status' => 'draft', 'indexable' => '1'], $parent);
    }

    public function testPublishedEntitiesHaveOwnContentAndSitemapData(): void
    {
        $this->seed();
        $repository = new CatalogRepository($this->pdo);
        $category = $repository->categoryBySlug('rochite-botez');
        $product = $repository->productBySlug('rochie-de-botez-raisa');
        self::assertSame('Rochițe de botez', $category['name']);
        self::assertSame('Rochie de botez „Raisa”', $product['name']);
        self::assertContains('rochite-botez', array_column($repository->sitemapEntities('category'), 'slug'));
        self::assertContains('rochie-de-botez-raisa', array_column($repository->sitemapEntities('product'), 'slug'));
    }

    public function testChangingSlugCreatesPermanentRedirectRecord(): void
    {
        $service = new CatalogAdminService($this->pdo);
        $id = $service->saveCategory(['name' => 'Veche', 'slug' => 'veche', 'status' => 'draft', 'indexable' => '1']);
        $service->saveCategory(['name' => 'Nouă', 'slug' => 'noua', 'status' => 'draft', 'indexable' => '1'], $id);
        $repository = new CatalogRepository($this->pdo);
        self::assertSame('noua', $repository->redirectFor('category', 'veche')['new_slug']);
    }

    public function testSearchSuggestionsIncludePrimaryCategory(): void
    {
        $this->seed();
        $repository = new CatalogRepository($this->pdo);
        $results = $repository->search('roch', 6);
        $categories = $repository->searchCategories('roch', 4);

        self::assertNotEmpty($results);
        self::assertSame('Rochițe de botez', $results[0]['category_name']);
        self::assertArrayHasKey('image_path', $results[0]);
        self::assertArrayHasKey('image_alt', $results[0]);
        self::assertNotEmpty($results[0]['image_path']);
        self::assertLessThanOrEqual(6, count($results));
        self::assertNotEmpty($categories);
        self::assertStringContainsString('Rochițe', $categories[0]['name']);
        self::assertArrayHasKey('image_path', $categories[0]);
        self::assertArrayHasKey('image_alt', $categories[0]);
        self::assertLessThanOrEqual(4, count($categories));
    }

    public function testSearchResultsCanBeCountedAndPaginated(): void
    {
        $this->seed();
        $repository = new CatalogRepository($this->pdo);
        $total = $repository->searchCount('roch');
        $allResults = $repository->search('roch', 50);
        $firstPage = $repository->search('roch', 1);
        $secondPage = $repository->search('roch', 1, 1);

        self::assertSame($total, count($allResults));
        self::assertNotEmpty($firstPage);
        if ($total > 1) {
            self::assertNotEmpty($secondPage);
            self::assertNotSame($firstPage[0]['id'], $secondPage[0]['id']);
        }
    }

    public function testSemanticSearchHandlesSkuGrammarDiacriticsAndSmallTypos(): void
    {
        $this->seed();
        $search = new CatalogSearchService(new CatalogRepository($this->pdo));

        $sku = $search->products('lm-b-001-2a');
        self::assertTrue($sku['exact']);
        self::assertSame(['Costumaș „Micul gentleman”'], array_column($sku['products'], 'name'));

        $misspelledName = $search->products('costumas micul gentelman');
        self::assertTrue($misspelledName['exact']);
        self::assertSame(['Costumaș „Micul gentleman”'], array_column($misspelledName['products'], 'name'));

        $grammaticalVariant = $search->products('rochii botez');
        self::assertNotEmpty($grammaticalVariant['products']);
        self::assertSame('Rochie de botez „Raisa”', $grammaticalVariant['products'][0]['name']);

        $misspelledWords = $search->products('rocie botez');
        self::assertNotEmpty($misspelledWords['products']);
        self::assertSame('Rochie de botez „Raisa”', $misspelledWords['products'][0]['name']);
    }

    public function testSemanticSearchKeepsEveryResultRelevantToAllImportantTerms(): void
    {
        $this->seed();
        $search = new CatalogSearchService(new CatalogRepository($this->pdo));

        $costumes = $search->products('costume');
        self::assertNotEmpty($costumes['products']);
        foreach ($costumes['products'] as $product) {
            self::assertStringContainsStringIgnoringCase('costum', $product['name']);
        }

        self::assertSame([], $search->products('rochie baieti')['products']);
        self::assertSame([], $search->products('rochie astronaut')['products']);
    }

    public function testSemanticSearchDiscoversProductsChangedAfterDeployment(): void
    {
        $this->seed();
        $this->pdo->exec("UPDATE products SET name = 'Salopetă pufoasă Aurora' WHERE slug = 'body-petrecut-liniste'");

        $search = new CatalogSearchService(new CatalogRepository($this->pdo));
        $result = $search->products('salopete pufoase aurora');

        self::assertTrue($result['exact']);
        self::assertSame(['body-petrecut-liniste'], array_column($result['products'], 'slug'));
    }

    public function testHomepageCollectionsContainOnlyPublishedMainCategories(): void
    {
        $this->seed();
        $repository = new CatalogRepository($this->pdo);
        $categories = $repository->homepageCategories();

        self::assertNotEmpty($categories);
        self::assertSame(
            [
                'nou-nascuti',
                'fetite',
                'baieti',
                'costume-traditionale',
                'botez',
                'incaltaminte',
                'cadouri',
                'jucarii',
            ],
            array_column($categories, 'slug')
        );
        self::assertContains('nou-nascuti', array_column($categories, 'slug'));
        self::assertNotContains('rochite-botez', array_column($categories, 'slug'));
        self::assertSame(
            [
                'assets/images/categories/nou-nascuti-editorial-2026.webp',
                'assets/images/categories/fetite-editorial-2026.webp',
                'assets/images/categories/baieti-editorial-2026.webp',
                'assets/images/categories/costume-traditionale-editorial-2026.webp',
                'assets/images/categories/botez-editorial-2026.webp',
                'assets/images/categories/incaltaminte-editorial-2026.webp',
                'assets/images/categories/cadouri-editorial-2026.webp',
                null,
            ],
            array_column($categories, 'image_path')
        );

        foreach ($categories as $category) {
            self::assertNull($category['parent_id']);
        }
    }

    public function testNavigationIncludesThreeLevelGiftTreeWithoutInventedChildImages(): void
    {
        $this->seed();
        $repository = new CatalogRepository($this->pdo);
        $navigation = $repository->navigationCategories();
        $gifts = current(array_filter($navigation, static fn(array $category): bool => $category['slug'] === 'cadouri'));

        self::assertIsArray($gifts);
        self::assertSame('assets/images/categories/cadouri-editorial-2026.webp', $gifts['image_path']);
        self::assertCount(1, $gifts['children']);
        self::assertSame('cadouri-pentru-bebelusi', $gifts['children'][0]['slug']);
        self::assertNull($gifts['children'][0]['image_path']);
        self::assertCount(1, $gifts['children'][0]['children']);
        self::assertSame('seturi-cadou', $gifts['children'][0]['children'][0]['slug']);
        self::assertNull($gifts['children'][0]['children'][0]['image_path']);
    }

    public function testHomepageProductSelectionUsesManualChoicesAndFallsBackToLatestProducts(): void
    {
        $this->seed();
        $repository = new CatalogRepository($this->pdo);

        $selected = $repository->featuredProducts(4);
        self::assertCount(4, $selected);
        self::assertSame(
            [
                'trusou-botez-inceput',
                'costumas-micul-gentleman',
                'rochita-de-ocazie-amalia',
                'set-nou-nascut-primul-dar',
            ],
            array_column($selected, 'slug')
        );
        self::assertNotContains(null, array_column($selected, 'image_path'));
        self::assertSame('assets/images/products/trusou-botez-inceput.webp', $selected[0]['image_path']);
        self::assertSame(0, (int) $selected[0]['required_personalization_count']);
        self::assertSame(2, (int) $selected[1]['variant_count']);

        $expandedSelection = $repository->featuredProducts(8);
        self::assertCount(8, $expandedSelection);
        self::assertSame(array_column($selected, 'id'), array_slice(array_column($expandedSelection, 'id'), 0, 4));
        self::assertCount(8, array_unique(array_column($expandedSelection, 'id')));

        $gentleman = $repository->productBySlug('costumas-micul-gentleman');
        self::assertSame(['2 ani', '4 ani'], array_column($gentleman['variants'], 'option_summary'));
        self::assertSame([
            ['name' => 'Mărime', 'values' => ['2 ani', '4 ani']],
        ], $gentleman['variant_groups']);
        self::assertNotEmpty($gentleman['image_path']);

        $this->pdo->exec("UPDATE personalization_options SET required = 1 WHERE product_id = " . (int) $selected[0]['id']);
        $selectedWithRequiredOption = $repository->featuredProducts(4);
        self::assertSame(1, (int) $selectedWithRequiredOption[0]['required_personalization_count']);

        $selectedIds = array_map(static fn(array $product): int => (int) $product['id'], $selected);
        $latest = $repository->latestProducts(4, $selectedIds);
        self::assertCount(4, $latest);
        self::assertSame([], array_intersect($selectedIds, array_map(static fn(array $product): int => (int) $product['id'], $latest)));

        $latestHomepageProducts = $repository->latestProducts(15);
        self::assertLessThanOrEqual(15, count($latestHomepageProducts));
        $latestHomepageIds = array_map('intval', array_column($latestHomepageProducts, 'id'));
        $descendingHomepageIds = $latestHomepageIds;
        rsort($descendingHomepageIds, SORT_NUMERIC);
        self::assertSame($descendingHomepageIds, $latestHomepageIds);

        $this->pdo->exec('UPDATE products SET featured = 0');
        $fallback = $repository->featuredProducts(4);
        self::assertCount(4, $fallback);
        self::assertSame(array_column($repository->latestProducts(4), 'id'), array_column($fallback, 'id'));
    }

    public function testProductVariantGroupsAreBuiltDynamicallyFromEveryOptionKey(): void
    {
        $this->seed();
        $repository = new CatalogRepository($this->pdo);
        $product = $repository->productBySlug('costumas-micul-gentleman');
        self::assertNotNull($product);

        $variants = $product['variants'];
        $firstOptions = json_encode(['Mărime' => '2 ani', 'Culoare' => 'Bleu'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $secondOptions = json_encode(['Mărime' => '4 ani', 'Culoare' => 'Roz'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare('UPDATE product_variants SET options_json = :options, options_key = :key WHERE id = :id');
        $statement->execute(['options' => $firstOptions, 'key' => hash('sha256', $firstOptions), 'id' => $variants[0]['id']]);
        $statement->execute(['options' => $secondOptions, 'key' => hash('sha256', $secondOptions), 'id' => $variants[1]['id']]);

        $updated = $repository->productBySlug('costumas-micul-gentleman');
        self::assertSame([
            ['name' => 'Mărime', 'values' => ['2 ani', '4 ani']],
            ['name' => 'Culoare', 'values' => ['Bleu', 'Roz']],
        ], $updated['variant_groups']);
    }

    public function testShopListsEveryPublishedProductWithSharedCardData(): void
    {
        $this->seed();
        $repository = new CatalogRepository($this->pdo);

        $products = $repository->shopProducts(1, 50);

        self::assertCount($repository->publishedProductCount(), $products);
        self::assertNotEmpty($products);
        self::assertNotContains(null, array_column($products, 'default_variant_id'));
        self::assertNotContains(null, array_column($products, 'variant_count'));
        self::assertNotContains(null, array_column($products, 'available_quantity'));

        $productsByPrice = $repository->shopProducts(1, 50, 'price-asc');
        $prices = array_map('intval', array_column($productsByPrice, 'price_bani'));
        $sortedPrices = $prices;
        sort($sortedPrices, SORT_NUMERIC);
        self::assertSame($sortedPrices, $prices);

        $productsByNameAscending = $repository->shopProducts(1, 50, 'name-asc');
        $productsByNameDescending = $repository->shopProducts(1, 50, 'name-desc');
        self::assertSame(
            array_reverse(array_column($productsByNameAscending, 'name')),
            array_column($productsByNameDescending, 'name')
        );

        $filterCategories = $repository->shopFilterCategories();
        self::assertNotEmpty($filterCategories);
        $girlsCategory = current(array_filter(
            $filterCategories,
            static fn(array $item): bool => $item['slug'] === 'fetite'
        ));
        self::assertIsArray($girlsCategory);
        self::assertContains('rochite', array_column($girlsCategory['children'], 'slug'));
        $dressesCategory = current(array_filter(
            $girlsCategory['children'],
            static fn(array $item): bool => $item['slug'] === 'rochite'
        ));
        self::assertIsArray($dressesCategory);
        self::assertSame(
            ['rochite-zi', 'rochite-ocazie'],
            array_values(array_intersect(
                ['rochite-zi', 'rochite-ocazie'],
                array_column($dressesCategory['children'], 'slug')
            ))
        );

        $girlsScopedCategories = $repository->shopFilterCategories((int) $girlsCategory['id']);
        self::assertContains('rochite', array_column($girlsScopedCategories, 'slug'));
        self::assertNotContains('fetite', array_column($girlsScopedCategories, 'slug'));
        self::assertNotContains('baieti', array_column($girlsScopedCategories, 'slug'));
        self::assertSame([], $repository->shopFilterCategories((int) $dressesCategory['children'][1]['id']));

        $category = current(array_filter(
            $filterCategories,
            static fn(array $item): bool => (int) $item['product_count'] > 0
        ));
        self::assertIsArray($category);
        $categoryProducts = $repository->shopProducts(1, 50, 'new', (int) $category['id']);
        self::assertCount((int) $category['product_count'], $categoryProducts);
        self::assertSame(
            (int) $category['product_count'],
            $repository->publishedProductCount((int) $category['id'])
        );

        $boysCategory = current(array_filter(
            $filterCategories,
            static fn(array $item): bool => $item['slug'] === 'baieti'
        ));
        self::assertIsArray($boysCategory);
        $girlsProducts = $repository->shopProducts(1, 50, 'new', [(int) $girlsCategory['id']]);
        $boysProducts = $repository->shopProducts(1, 50, 'new', [(int) $boysCategory['id']]);
        $multiCategoryProducts = $repository->shopProducts(
            1,
            50,
            'new',
            [(int) $girlsCategory['id'], (int) $boysCategory['id']]
        );
        $expectedMultiCategoryIds = array_values(array_unique(array_merge(
            array_map('intval', array_column($girlsProducts, 'id')),
            array_map('intval', array_column($boysProducts, 'id'))
        )));
        $actualMultiCategoryIds = array_map('intval', array_column($multiCategoryProducts, 'id'));
        sort($expectedMultiCategoryIds, SORT_NUMERIC);
        sort($actualMultiCategoryIds, SORT_NUMERIC);
        self::assertSame($expectedMultiCategoryIds, $actualMultiCategoryIds);
        self::assertSame(
            count($expectedMultiCategoryIds),
            $repository->publishedProductCount([(int) $girlsCategory['id'], (int) $boysCategory['id']])
        );

        $availableProducts = $repository->shopProducts(1, 50, 'new', null, true);
        self::assertNotEmpty($availableProducts);
        foreach ($availableProducts as $availableProduct) {
            self::assertGreaterThan(0, (int) $availableProduct['available_quantity']);
        }

        $range = $repository->shopPriceRange();
        self::assertLessThanOrEqual($range['max_bani'], $range['min_bani']);
        $girlsRange = $repository->shopPriceRange([(int) $girlsCategory['id']]);
        self::assertLessThanOrEqual($girlsRange['max_bani'], $girlsRange['min_bani']);
        self::assertGreaterThanOrEqual($range['min_bani'], $girlsRange['min_bani']);
        self::assertLessThanOrEqual($range['max_bani'], $girlsRange['max_bani']);
        $budgetProducts = $repository->shopProducts(1, 50, 'new', null, false, $range['min_bani']);
        self::assertNotEmpty($budgetProducts);
        foreach ($budgetProducts as $budgetProduct) {
            self::assertLessThanOrEqual($range['min_bani'], (int) $budgetProduct['price_bani']);
        }
    }
}
