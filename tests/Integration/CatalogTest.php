<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Catalog\CatalogAdminService;
use App\Modules\Catalog\CatalogRepository;
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
        self::assertLessThanOrEqual(6, count($results));
        self::assertNotEmpty($categories);
        self::assertStringContainsString('Rochițe', $categories[0]['name']);
        self::assertLessThanOrEqual(4, count($categories));
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
}
