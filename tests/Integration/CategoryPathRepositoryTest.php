<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Catalog\CategoryPathRepository;
use Tests\DatabaseTestCase;

final class CategoryPathRepositoryTest extends DatabaseTestCase
{
    public function testResolvesAnArbitrarilyDeepCanonicalCategoryPath(): void
    {
        $this->seed();
        $paths = new CategoryPathRepository($this->pdo);

        $resolved = $paths->resolve('fetite/rochite/rochite-ocazie');

        self::assertNotNull($resolved);
        self::assertSame('rochite-ocazie', $resolved['category']['slug']);
        self::assertSame('fetite/rochite/rochite-ocazie', $resolved['path']);
        self::assertSame(
            ['fetite', 'rochite', 'rochite-ocazie'],
            array_column($resolved['trail'], 'slug')
        );
        self::assertSame(
            ['fetite', 'fetite/rochite', 'fetite/rochite/rochite-ocazie'],
            array_column($resolved['trail'], 'path')
        );
    }

    public function testCanonicalTrailRejectsDraftOrHiddenAncestors(): void
    {
        $this->seed();
        $this->pdo->exec("UPDATE categories SET visibility = 'hidden' WHERE slug = 'rochite'");
        $paths = new CategoryPathRepository($this->pdo);

        self::assertNull($paths->resolve('fetite/rochite/rochite-ocazie'));
        self::assertNull($paths->pathForSlug('rochite-ocazie'));
    }

    public function testAddsPathsToEveryLevelOfAnExistingTree(): void
    {
        $paths = new CategoryPathRepository($this->pdo);
        $tree = $paths->addPathsToTree([[
            'slug' => 'parinte',
            'children' => [[
                'slug' => 'copil',
                'children' => [['slug' => 'nepot']],
            ]],
        ]]);

        self::assertSame('parinte', $tree[0]['path']);
        self::assertSame('parinte/copil', $tree[0]['children'][0]['path']);
        self::assertSame('parinte/copil/nepot', $tree[0]['children'][0]['children'][0]['path']);
    }
}
