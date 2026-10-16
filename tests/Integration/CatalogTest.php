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
}
