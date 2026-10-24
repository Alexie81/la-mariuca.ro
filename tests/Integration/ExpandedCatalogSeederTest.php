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
        self::assertSame(71, (int) $this->pdo->query("SELECT COUNT(*) FROM product_variants WHERE sku LIKE 'LM-26-%'")->fetchColumn());
        self::assertSame(32, (int) $this->pdo->query("SELECT COUNT(*) FROM product_images pi JOIN products p ON p.id = pi.product_id WHERE EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.id AND pv.sku LIKE 'LM-26-%')")->fetchColumn());
        self::assertSame(8, (int) $this->pdo->query("SELECT COUNT(DISTINCT c.id) FROM categories c JOIN product_categories pc ON pc.category_id = c.id JOIN product_variants pv ON pv.product_id = pc.product_id WHERE c.parent_id IS NULL AND c.status = 'published' AND c.visibility = 'public' AND pv.sku LIKE 'LM-26-%'")->fetchColumn());

        $repository = new CatalogRepository($this->pdo);
        $product = $repository->productBySlug('cuburi-moi-poveste');
        self::assertSame('assets/images/products/collection-2026/cuburi-moi-poveste.webp', $product['image_path']);
        self::assertSame('Cuburi senzoriale moi în roz, bleu, ivoire și bej', $product['image_alt']);
        self::assertCount(3, $product['images']);
        self::assertCount(42, $product['variants']);
        self::assertSame([
            ['name' => 'Mărime', 'values' => ['0-6 luni', '0-7 luni', '0-8 luni', '0-9 luni', '1 an–1,5 ani', '0-3 luni', '0-2 luni']],
            ['name' => 'Culoare', 'values' => ['Roșu', 'Negru', 'Verde', 'Galben', 'Bej', 'Alb']],
            ['name' => 'Dimensiune', 'values' => ['1 cm']],
        ], $product['variant_groups']);

        $pricesByCombination = [];
        foreach ($product['variants'] as $variant) {
            $options = json_decode((string) $variant['options_json'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('1 cm', $options['Dimensiune']);
            $pricesByCombination[$options['Mărime'] . '|' . $options['Culoare']] = (int) $variant['price_bani'];
        }
        self::assertSame(15900, $pricesByCombination['0-6 luni|Roșu']);
        self::assertSame(14900, $pricesByCombination['0-6 luni|Negru']);
        self::assertSame(15500, $pricesByCombination['0-9 luni|Galben']);
        self::assertSame(17100, $pricesByCombination['0-2 luni|Bej']);

        $shopProduct = current(array_filter(
            $repository->shopProducts(1, 50),
            static fn(array $item): bool => $item['slug'] === 'cuburi-moi-poveste'
        ));
        self::assertIsArray($shopProduct);
        self::assertSame(15900, (int) $shopProduct['price_bani']);
        self::assertSame(18900, (int) $shopProduct['compare_price_bani']);
        self::assertSame(16, (int) round(
            ((int) $shopProduct['compare_price_bani'] - (int) $shopProduct['price_bani'])
            * 100
            / (int) $shopProduct['compare_price_bani']
        ));

        $searchProduct = current(array_filter(
            $repository->searchableProducts(),
            static fn(array $item): bool => $item['slug'] === 'cuburi-moi-poveste'
        ));
        self::assertIsArray($searchProduct);
        self::assertSame(15900, (int) $searchProduct['price_bani']);
        self::assertSame(18900, (int) $searchProduct['compare_price_bani']);
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
