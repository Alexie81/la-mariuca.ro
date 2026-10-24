<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Seeders\ExpandedCatalogSeeder;
use App\Modules\Catalog\CatalogRepository;
use Tests\DatabaseTestCase;

final class ExpandedCatalogSeederTest extends DatabaseTestCase
{
    public function testExpandedCatalogAddsThirtyProductsAcrossEveryPublicMainCategoryAndIsIdempotent(): void
    {
        $this->seed();
        $seeder = new ExpandedCatalogSeeder($this->pdo);

        self::assertSame(30, $seeder->run());
        self::assertSame(0, $seeder->run());
        self::assertSame(30, (int) $this->pdo->query("SELECT COUNT(*) FROM product_variants WHERE sku LIKE 'LM-26-%'")->fetchColumn());
        self::assertSame(32, (int) $this->pdo->query("SELECT COUNT(*) FROM product_images pi JOIN products p ON p.id = pi.product_id JOIN product_variants pv ON pv.product_id = p.id WHERE pv.sku LIKE 'LM-26-%'")->fetchColumn());
        self::assertSame(8, (int) $this->pdo->query("SELECT COUNT(DISTINCT c.id) FROM categories c JOIN product_categories pc ON pc.category_id = c.id JOIN product_variants pv ON pv.product_id = pc.product_id WHERE c.parent_id IS NULL AND c.status = 'published' AND c.visibility = 'public' AND pv.sku LIKE 'LM-26-%'")->fetchColumn());

        $product = (new CatalogRepository($this->pdo))->productBySlug('cuburi-moi-poveste');
        self::assertSame('assets/images/products/collection-2026/cuburi-moi-poveste.webp', $product['image_path']);
        self::assertSame('Cuburi senzoriale moi în roz, bleu, ivoire și bej', $product['image_alt']);
        self::assertCount(3, $product['images']);
        self::assertSame(
            [
                'assets/images/products/collection-2026/cuburi-moi-poveste.webp',
                'assets/images/products/collection-2026/cuburi-moi-poveste-02.webp',
                'assets/images/products/collection-2026/cuburi-moi-poveste-03.webp',
            ],
            array_column($product['images'], 'image_path')
        );
    }
}
