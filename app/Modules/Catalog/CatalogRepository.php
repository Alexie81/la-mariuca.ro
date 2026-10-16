<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use PDO;

final class CatalogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function navigationCategories(): array
    {
        $rows = $this->pdo->query("SELECT c.id, c.parent_id, c.name, c.slug
            FROM categories c
            WHERE c.status = 'published' AND c.visibility = 'public'
              AND EXISTS (
                SELECT 1 FROM product_categories pc
                JOIN products p ON p.id = pc.product_id AND p.status = 'published'
                WHERE pc.category_id = c.id
              )
            ORDER BY c.position, c.name")->fetchAll();
        return $this->tree($rows);
    }

    /** @return list<array<string, mixed>> */
    public function allCategories(bool $includeDrafts = false): array
    {
        $sql = 'SELECT c.*, p.name AS parent_name FROM categories c LEFT JOIN categories p ON p.id = c.parent_id';
        if (!$includeDrafts) {
            $sql .= " WHERE c.status = 'published'";
        }
        $sql .= ' ORDER BY c.position, c.name';
        return $this->pdo->query($sql)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function categoryBySlug(string $slug, bool $publicOnly = true): ?array
    {
        $sql = 'SELECT * FROM categories WHERE slug = :slug';
        if ($publicOnly) {
            $sql .= " AND status = 'published' AND visibility = 'public'";
        }
        $statement = $this->pdo->prepare($sql . ' LIMIT 1');
        $statement->execute(['slug' => $slug]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function categoryById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM categories WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function redirectFor(string $type, string $oldSlug): ?array
    {
        $statement = $this->pdo->prepare('SELECT new_slug FROM slug_redirects WHERE entity_type = :type AND old_slug = :slug');
        $statement->execute(['type' => $type, 'slug' => $oldSlug]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function productsForCategory(int $categoryId, int $page = 1, int $perPage = 12, string $sort = 'new'): array
    {
        $sorts = [
            'new' => 'p.published_at DESC, p.id DESC',
            'price-asc' => 'price_bani ASC, p.id DESC',
            'price-desc' => 'price_bani DESC, p.id DESC',
            'name' => 'p.name ASC, p.id DESC',
        ];
        $order = $sorts[$sort] ?? $sorts['new'];
        $offset = max(0, ($page - 1) * $perPage);
        $limit = max(1, min(48, $perPage));
        $sql = "WITH RECURSIVE descendants(id) AS (
                    SELECT id FROM categories WHERE id = :category_id
                    UNION ALL SELECT c.id FROM categories c JOIN descendants d ON c.parent_id = d.id WHERE c.status = 'published'
                )
                SELECT p.*, MIN(v.price_bani) AS price_bani,
                       MIN(v.compare_price_bani) AS compare_price_bani,
                       COALESCE(SUM(i.physical_quantity - i.reserved_quantity), 0) AS available_quantity
                FROM products p
                JOIN product_categories pc ON pc.product_id = p.id
                JOIN descendants d ON d.id = pc.category_id
                JOIN product_variants v ON v.product_id = p.id AND v.status = 'active'
                LEFT JOIN inventory i ON i.variant_id = v.id
                WHERE p.status = 'published'
                GROUP BY p.id
                ORDER BY {$order}
                LIMIT {$limit} OFFSET {$offset}";
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['category_id' => $categoryId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function featuredProducts(int $limit = 8): array
    {
        $limit = max(1, min(24, $limit));
        return $this->pdo->query("SELECT p.*, MIN(v.price_bani) AS price_bani,
                MIN(v.compare_price_bani) AS compare_price_bani,
                COALESCE(SUM(i.physical_quantity - i.reserved_quantity), 0) AS available_quantity
            FROM products p
            JOIN product_variants v ON v.product_id = p.id AND v.status = 'active'
            LEFT JOIN inventory i ON i.variant_id = v.id
            WHERE p.status = 'published' AND p.featured = 1
            GROUP BY p.id ORDER BY p.published_at DESC, p.id DESC LIMIT {$limit}")->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function productBySlug(string $slug, bool $publicOnly = true): ?array
    {
        $sql = 'SELECT p.*, c.name AS category_name, c.slug AS category_slug FROM products p LEFT JOIN categories c ON c.id = p.primary_category_id WHERE p.slug = :slug';
        if ($publicOnly) {
            $sql .= " AND p.status = 'published'";
        }
        $statement = $this->pdo->prepare($sql . ' LIMIT 1');
        $statement->execute(['slug' => $slug]);
        $product = $statement->fetch();
        if (!is_array($product)) {
            return null;
        }
        $variantStatement = $this->pdo->prepare("SELECT v.*, COALESCE(i.physical_quantity, 0) AS physical_quantity,
                COALESCE(i.reserved_quantity, 0) AS reserved_quantity,
                COALESCE(i.physical_quantity - i.reserved_quantity, 0) AS available_quantity
            FROM product_variants v LEFT JOIN inventory i ON i.variant_id = v.id
            WHERE v.product_id = :id AND v.status = 'active' ORDER BY v.is_default DESC, v.id");
        $variantStatement->execute(['id' => $product['id']]);
        $product['variants'] = $variantStatement->fetchAll();
        $optionStatement = $this->pdo->prepare('SELECT * FROM personalization_options WHERE product_id = :id ORDER BY position, id');
        $optionStatement->execute(['id' => $product['id']]);
        $product['personalization_options'] = $optionStatement->fetchAll();
        return $product;
    }

    /** @return array<string, mixed>|null */
    public function productById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM products WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function search(string $query, int $limit = 24): array
    {
        $limit = max(1, min(48, $limit));
        $statement = $this->pdo->prepare("SELECT p.*, MIN(v.price_bani) AS price_bani,
                COALESCE(SUM(i.physical_quantity - i.reserved_quantity), 0) AS available_quantity
            FROM products p
            JOIN product_variants v ON v.product_id = p.id AND v.status = 'active'
            LEFT JOIN inventory i ON i.variant_id = v.id
            LEFT JOIN product_categories pc ON pc.product_id = p.id
            LEFT JOIN categories c ON c.id = pc.category_id
            WHERE p.status = 'published' AND (p.name LIKE :query OR v.sku LIKE :query OR c.name LIKE :query)
            GROUP BY p.id ORDER BY p.name LIMIT {$limit}");
        $statement->execute(['query' => '%' . trim($query) . '%']);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function collectionProducts(string $slug): array
    {
        $statement = $this->pdo->prepare("SELECT p.*, MIN(v.price_bani) AS price_bani,
                COALESCE(SUM(i.physical_quantity - i.reserved_quantity), 0) AS available_quantity
            FROM collections col
            JOIN collection_products cp ON cp.collection_id = col.id
            JOIN products p ON p.id = cp.product_id AND p.status = 'published'
            JOIN product_variants v ON v.product_id = p.id AND v.status = 'active'
            LEFT JOIN inventory i ON i.variant_id = v.id
            WHERE col.slug = :slug AND col.status = 'published'
            GROUP BY p.id, cp.position ORDER BY cp.position, p.name");
        $statement->execute(['slug' => $slug]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function collectionBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare("SELECT * FROM collections WHERE slug = :slug AND status = 'published'");
        $statement->execute(['slug' => $slug]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function sitemapEntities(string $type): array
    {
        $tables = ['category' => 'categories', 'product' => 'products', 'collection' => 'collections', 'page' => 'content_pages'];
        $table = $tables[$type] ?? null;
        if ($table === null) {
            return [];
        }
        $extra = $type === 'category' ? " AND visibility = 'public' AND EXISTS (SELECT 1 FROM product_categories pc JOIN products p ON p.id = pc.product_id AND p.status = 'published' WHERE pc.category_id = categories.id)" : '';
        return $this->pdo->query("SELECT slug, updated_at FROM {$table} WHERE status = 'published' AND indexable = 1{$extra} ORDER BY id")->fetchAll();
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function tree(array $rows, ?int $parent = null): array
    {
        $result = [];
        foreach ($rows as $row) {
            $rowParent = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            if ($rowParent !== $parent) {
                continue;
            }
            $row['children'] = $this->tree($rows, (int) $row['id']);
            $result[] = $row;
        }
        return $result;
    }
}
