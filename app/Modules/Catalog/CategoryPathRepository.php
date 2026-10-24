<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use PDO;

final class CategoryPathRepository
{
    /** @var array<int, array<string, mixed>|null> */
    private array $byId = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $bySlug = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{category: array<string, mixed>, trail: list<array<string, mixed>>, path: string}|null
     */
    public function resolve(string $path): ?array
    {
        $path = trim($path, '/');
        if ($path === '') {
            return null;
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $segment) !== 1) {
                return null;
            }
        }

        $category = $this->publishedBySlug((string) end($segments));
        if ($category === null) {
            return null;
        }

        $trail = $this->trailForId((int) $category['id']);
        if ($trail === []) {
            return null;
        }

        return [
            'category' => $category,
            'trail' => $trail,
            'path' => implode('/', array_column($trail, 'slug')),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function trailForSlug(string $slug): array
    {
        $category = $this->publishedBySlug($slug);
        return $category === null ? [] : $this->trailForId((int) $category['id']);
    }

    /** @return list<array<string, mixed>> */
    public function trailForId(int $categoryId): array
    {
        $trail = [];
        $visited = [];
        $cursorId = $categoryId;

        while ($cursorId > 0) {
            if (isset($visited[$cursorId])) {
                return [];
            }
            $visited[$cursorId] = true;

            $category = $this->publishedById($cursorId);
            if ($category === null) {
                return [];
            }

            array_unshift($trail, $category);
            $cursorId = $category['parent_id'] === null ? 0 : (int) $category['parent_id'];
        }

        $path = '';
        foreach ($trail as &$category) {
            $path = $path === '' ? (string) $category['slug'] : $path . '/' . $category['slug'];
            $category['path'] = $path;
        }
        unset($category);

        return $trail;
    }

    public function pathForSlug(string $slug): ?string
    {
        $trail = $this->trailForSlug($slug);
        return $trail === [] ? null : (string) $trail[array_key_last($trail)]['path'];
    }

    /**
     * Adds canonical paths to an already-loaded category tree without additional SQL queries.
     *
     * @param list<array<string, mixed>> $categories
     * @return list<array<string, mixed>>
     */
    public function addPathsToTree(array $categories, string $parentPath = ''): array
    {
        foreach ($categories as &$category) {
            $path = $parentPath === ''
                ? (string) $category['slug']
                : $parentPath . '/' . $category['slug'];
            $category['path'] = $path;
            $category['children'] = $this->addPathsToTree((array) ($category['children'] ?? []), $path);
        }
        unset($category);

        return $categories;
    }

    /**
     * @param list<array<string, mixed>> $categories
     * @return list<array<string, mixed>>
     */
    public function addRootPaths(array $categories): array
    {
        foreach ($categories as &$category) {
            $category['path'] = (string) $category['slug'];
        }
        unset($category);

        return $categories;
    }

    /** @return array<string, mixed>|null */
    private function publishedById(int $id): ?array
    {
        if (array_key_exists($id, $this->byId)) {
            return $this->byId[$id];
        }

        $statement = $this->pdo->prepare("SELECT * FROM categories
            WHERE id = :id AND status = 'published' AND visibility = 'public'
            LIMIT 1");
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        $category = is_array($row) ? $row : null;
        $this->byId[$id] = $category;
        if ($category !== null) {
            $this->bySlug[(string) $category['slug']] = $category;
        }

        return $category;
    }

    /** @return array<string, mixed>|null */
    private function publishedBySlug(string $slug): ?array
    {
        if (array_key_exists($slug, $this->bySlug)) {
            return $this->bySlug[$slug];
        }

        $statement = $this->pdo->prepare("SELECT * FROM categories
            WHERE slug = :slug AND status = 'published' AND visibility = 'public'
            LIMIT 1");
        $statement->execute(['slug' => $slug]);
        $row = $statement->fetch();
        $category = is_array($row) ? $row : null;
        $this->bySlug[$slug] = $category;
        if ($category !== null) {
            $this->byId[(int) $category['id']] = $category;
        }

        return $category;
    }
}
