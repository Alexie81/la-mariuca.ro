<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Shared\Support\Money;
use App\Shared\Support\Slugger;
use PDO;
use RuntimeException;

final class CatalogAdminService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $data */
    public function saveCategory(array $data, ?int $id = null): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Numele categoriei este obligatoriu.');
        }
        $slug = Slugger::slug((string) ($data['slug'] ?? $name));
        $parentId = ($data['parent_id'] ?? '') === '' ? null : (int) $data['parent_id'];
        if ($id !== null && ($parentId === $id || ($parentId !== null && $this->wouldCreateCycle($id, $parentId)))) {
            throw new RuntimeException('Categoria nu poate fi mutată sub ea însăși sau un descendent.');
        }
        $status = in_array(($data['status'] ?? ''), ['draft', 'published', 'archived'], true) ? (string) $data['status'] : 'draft';
        $now = gmdate('c');
        if ($id === null) {
            $statement = $this->pdo->prepare('INSERT INTO categories (parent_id, name, slug, description, position, status, visibility, seo_title, meta_description, indexable, published_at, created_at, updated_at) VALUES (:parent_id, :name, :slug, :description, :position, :status, :visibility, :seo_title, :meta_description, :indexable, :published_at, :created_at, :updated_at)');
            $statement->execute($this->categoryParams($data, $name, $slug, $parentId, $status, $now) + ['created_at' => $now]);
            return (int) $this->pdo->lastInsertId();
        }
        $old = $this->pdo->prepare('SELECT slug FROM categories WHERE id = :id');
        $old->execute(['id' => $id]);
        $oldSlug = $old->fetchColumn();
        if ($oldSlug === false) {
            throw new RuntimeException('Categoria nu există.');
        }
        $statement = $this->pdo->prepare('UPDATE categories SET parent_id=:parent_id,name=:name,slug=:slug,description=:description,position=:position,status=:status,visibility=:visibility,seo_title=:seo_title,meta_description=:meta_description,indexable=:indexable,published_at=:published_at,updated_at=:updated_at WHERE id=:id');
        $statement->execute($this->categoryParams($data, $name, $slug, $parentId, $status, $now) + ['id' => $id]);
        if ($oldSlug !== $slug) {
            $this->saveRedirect('category', (string) $oldSlug, $slug);
        }
        return $id;
    }

    /** @param array<string,mixed> $data */
    public function saveProduct(array $data, ?int $id = null): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        $sku = trim((string) ($data['sku'] ?? ''));
        if ($name === '' || $sku === '') {
            throw new RuntimeException('Numele și SKU sunt obligatorii.');
        }
        $price = Money::parse((string) ($data['price'] ?? ''));
        $stock = filter_var($data['stock'] ?? null, FILTER_VALIDATE_INT);
        if ($stock === false || $stock < 0) {
            throw new RuntimeException('Stocul trebuie să fie un număr întreg pozitiv sau zero.');
        }
        $categoryId = (int) ($data['category_id'] ?? 0);
        if ($categoryId <= 0) {
            throw new RuntimeException('Selectează categoria principală.');
        }
        $status = in_array(($data['status'] ?? ''), ['draft', 'published', 'archived'], true) ? (string) $data['status'] : 'draft';
        $slug = Slugger::slug((string) ($data['slug'] ?? $name));
        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            if ($id === null) {
                $statement = $this->pdo->prepare('INSERT INTO products (name,slug,short_description,description,status,primary_category_id,seo_title,meta_description,composition,care_instructions,size_guide,package_contents,excluded_accessories,featured,indexable,published_at,created_at,updated_at) VALUES (:name,:slug,:short_description,:description,:status,:category,:seo_title,:meta_description,:composition,:care,:size_guide,:package_contents,:excluded_accessories,:featured,:indexable,:published_at,:now,:now)');
                $statement->execute($this->productParams($data, $name, $slug, $status, $categoryId, $now));
                $id = (int) $this->pdo->lastInsertId();
                $this->pdo->prepare('INSERT INTO product_categories (product_id, category_id) VALUES (:product, :category)')->execute(['product' => $id, 'category' => $categoryId]);
                $this->pdo->prepare("INSERT INTO product_variants (product_id, sku, option_summary, options_json, options_key, price_bani, status, is_default, created_at, updated_at) VALUES (:product,:sku,'','{}',:options_key,:price,'active',1,:now,:now)")->execute(['product' => $id, 'sku' => $sku, 'options_key' => hash('sha256', '{}'), 'price' => $price, 'now' => $now]);
                $variantId = (int) $this->pdo->lastInsertId();
                $this->pdo->prepare('INSERT INTO inventory (variant_id, physical_quantity, reserved_quantity, updated_at) VALUES (:variant,:stock,0,:now)')->execute(['variant' => $variantId, 'stock' => $stock, 'now' => $now]);
                $this->pdo->prepare("INSERT INTO inventory_movements (variant_id,type,physical_delta,reserved_delta,reason,created_at) VALUES (:variant,'initial',:stock,0,'Stoc inițial',:now)")->execute(['variant' => $variantId, 'stock' => $stock, 'now' => $now]);
            } else {
                $old = $this->pdo->prepare('SELECT slug FROM products WHERE id=:id');
                $old->execute(['id' => $id]);
                $oldSlug = $old->fetchColumn();
                if ($oldSlug === false) {
                    throw new RuntimeException('Produsul nu există.');
                }
                $statement = $this->pdo->prepare('UPDATE products SET name=:name,slug=:slug,short_description=:short_description,description=:description,status=:status,primary_category_id=:category,seo_title=:seo_title,meta_description=:meta_description,composition=:composition,care_instructions=:care,size_guide=:size_guide,package_contents=:package_contents,excluded_accessories=:excluded_accessories,featured=:featured,indexable=:indexable,published_at=:published_at,updated_at=:now WHERE id=:id');
                $statement->execute($this->productParams($data, $name, $slug, $status, $categoryId, $now) + ['id' => $id]);
                $this->pdo->prepare('DELETE FROM product_categories WHERE product_id=:id')->execute(['id' => $id]);
                $this->pdo->prepare('INSERT INTO product_categories (product_id, category_id) VALUES (:product, :category)')->execute(['product' => $id, 'category' => $categoryId]);
                $variant = $this->pdo->prepare('SELECT id FROM product_variants WHERE product_id=:id ORDER BY is_default DESC,id LIMIT 1');
                $variant->execute(['id' => $id]);
                $variantId = (int) $variant->fetchColumn();
                $this->pdo->prepare('UPDATE product_variants SET sku=:sku,price_bani=:price,updated_at=:now WHERE id=:id')->execute(['sku' => $sku, 'price' => $price, 'now' => $now, 'id' => $variantId]);
                $inventory = $this->pdo->prepare('SELECT physical_quantity,reserved_quantity FROM inventory WHERE variant_id=:id');
                $inventory->execute(['id' => $variantId]);
                $existing = $inventory->fetch();
                if (is_array($existing) && $stock < (int) $existing['reserved_quantity']) {
                    throw new RuntimeException('Stocul fizic nu poate fi mai mic decât rezervările active.');
                }
                $delta = $stock - (int) ($existing['physical_quantity'] ?? 0);
                $this->pdo->prepare('UPDATE inventory SET physical_quantity=:stock,updated_at=:now WHERE variant_id=:id')->execute(['stock' => $stock, 'now' => $now, 'id' => $variantId]);
                if ($delta !== 0) {
                    $this->pdo->prepare("INSERT INTO inventory_movements (variant_id,type,physical_delta,reserved_delta,reason,created_at) VALUES (:variant,'manual_adjustment',:delta,0,'Editare produs în admin',:now)")->execute(['variant' => $variantId, 'delta' => $delta, 'now' => $now]);
                }
                if ($oldSlug !== $slug) {
                    $this->saveRedirect('product', (string) $oldSlug, $slug);
                }
            }
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string,mixed>> */
    public function products(): array
    {
        return $this->pdo->query("SELECT p.*, c.name AS category_name, v.sku, v.price_bani, i.physical_quantity, i.reserved_quantity FROM products p LEFT JOIN categories c ON c.id=p.primary_category_id LEFT JOIN product_variants v ON v.product_id=p.id AND v.is_default=1 LEFT JOIN inventory i ON i.variant_id=v.id ORDER BY p.id DESC")->fetchAll();
    }

    public function deleteProduct(int $id): string
    {
        if ($id <= 0) {
            throw new RuntimeException('Produsul solicitat nu este valid.');
        }

        $product = $this->pdo->prepare('SELECT name,status FROM products WHERE id=:id');
        $product->execute(['id' => $id]);
        $row = $product->fetch();
        if (!is_array($row) || (string) $row['status'] === 'archived') {
            throw new RuntimeException('Produsul nu există sau a fost deja șters.');
        }

        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM cart_items WHERE variant_id IN (SELECT id FROM product_variants WHERE product_id=:product_id)')
                ->execute(['product_id' => $id]);
            $this->pdo->prepare("UPDATE product_variants SET status='inactive',updated_at=:now WHERE product_id=:product_id")
                ->execute(['now' => $now, 'product_id' => $id]);
            $this->pdo->prepare("UPDATE products SET status='archived',featured=0,indexable=0,published_at=NULL,updated_at=:now WHERE id=:id")
                ->execute(['now' => $now, 'id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return (string) $row['name'];
    }

    /**
     * @return array{
     *     items:list<array<string,mixed>>,
     *     page:int,
     *     per_page:int,
     *     total:int,
     *     total_pages:int,
     *     from:int,
     *     to:int
     * }
     */
    public function paginateProductsForAdmin(int $page, int $perPage = 15, string $search = ''): array
    {
        $perPage = max(1, min(50, $perPage));
        $search = trim($search);
        $where = " WHERE p.status <> 'archived'";
        $parameters = [];

        if ($search !== '') {
            $needle = '%' . mb_strtolower($search) . '%';
            $where .= " AND (LOWER(p.name) LIKE :name_search
                OR LOWER(p.slug) LIKE :slug_search
                OR LOWER(COALESCE(c.name, '')) LIKE :category_search
                OR LOWER(p.status) LIKE :status_search
                OR EXISTS (
                    SELECT 1 FROM product_variants search_variant
                    WHERE search_variant.product_id = p.id
                    AND (LOWER(search_variant.sku) LIKE :sku_search
                        OR LOWER(search_variant.option_summary) LIKE :variant_search)
                ))";
            $parameters = [
                ':name_search' => $needle,
                ':slug_search' => $needle,
                ':category_search' => $needle,
                ':status_search' => $needle,
                ':sku_search' => $needle,
                ':variant_search' => $needle,
            ];
        }

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM products p LEFT JOIN categories c ON c.id = p.primary_category_id' . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        $statement = $this->pdo->prepare("SELECT
                p.id,p.name,p.slug,p.status,p.featured,p.updated_at,
                COALESCE(c.name, 'Fără categorie') AS category_name,
                COALESCE((
                    SELECT default_variant.sku FROM product_variants default_variant
                    WHERE default_variant.product_id = p.id
                    ORDER BY default_variant.is_default DESC, default_variant.id ASC LIMIT 1
                ), '') AS sku,
                COALESCE((
                    SELECT default_variant.price_bani FROM product_variants default_variant
                    WHERE default_variant.product_id = p.id
                    ORDER BY default_variant.is_default DESC, default_variant.id ASC LIMIT 1
                ), 0) AS price_bani,
                COALESCE((
                    SELECT SUM(stock.physical_quantity - stock.reserved_quantity)
                    FROM product_variants stock_variant
                    LEFT JOIN inventory stock ON stock.variant_id = stock_variant.id
                    WHERE stock_variant.product_id = p.id AND stock_variant.status = 'active'
                ), 0) AS online_quantity,
                COALESCE((
                    SELECT SUM(stock.physical_quantity)
                    FROM product_variants stock_variant
                    LEFT JOIN inventory stock ON stock.variant_id = stock_variant.id
                    WHERE stock_variant.product_id = p.id
                ), 0) AS physical_quantity,
                COALESCE((
                    SELECT SUM(stock.reserved_quantity)
                    FROM product_variants stock_variant
                    LEFT JOIN inventory stock ON stock.variant_id = stock_variant.id
                    WHERE stock_variant.product_id = p.id
                ), 0) AS reserved_quantity,
                (SELECT product_media.public_path
                    FROM product_images product_image
                    JOIN media product_media ON product_media.id = product_image.media_id
                    WHERE product_image.product_id = p.id AND product_media.public_path IS NOT NULL
                    ORDER BY product_image.position, product_image.media_id LIMIT 1
                ) AS image_path,
                COALESCE((SELECT product_media.alt_text
                    FROM product_images product_image
                    JOIN media product_media ON product_media.id = product_image.media_id
                    WHERE product_image.product_id = p.id AND product_media.public_path IS NOT NULL
                    ORDER BY product_image.position, product_image.media_id LIMIT 1
                ), p.name) AS image_alt
            FROM products p
            LEFT JOIN categories c ON c.id = p.primary_category_id"
            . $where
            . ' ORDER BY p.updated_at DESC,p.id DESC LIMIT :limit OFFSET :offset');
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => $statement->fetchAll(),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => min($offset + $perPage, $total),
        ];
    }

    /** @return array<string,mixed>|null */
    public function productForm(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT p.*,v.sku,v.price_bani,i.physical_quantity AS stock FROM products p JOIN product_variants v ON v.product_id=p.id AND v.is_default=1 LEFT JOIN inventory i ON i.variant_id=v.id WHERE p.id=:id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function categoryParams(array $data, string $name, string $slug, ?int $parentId, string $status, string $now): array
    {
        return [
            'parent_id' => $parentId, 'name' => $name, 'slug' => $slug, 'description' => trim((string) ($data['description'] ?? '')),
            'position' => (int) ($data['position'] ?? 0), 'status' => $status,
            'visibility' => ($data['visibility'] ?? 'public') === 'hidden' ? 'hidden' : 'public',
            'seo_title' => trim((string) ($data['seo_title'] ?? '')), 'meta_description' => trim((string) ($data['meta_description'] ?? '')),
            'indexable' => isset($data['indexable']) ? 1 : 0, 'published_at' => $status === 'published' ? $now : null, 'updated_at' => $now,
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function productParams(array $data, string $name, string $slug, string $status, int $categoryId, string $now): array
    {
        return [
            'name' => $name, 'slug' => $slug, 'short_description' => trim((string) ($data['short_description'] ?? '')), 'description' => trim((string) ($data['description'] ?? '')),
            'status' => $status, 'category' => $categoryId, 'seo_title' => trim((string) ($data['seo_title'] ?? '')), 'meta_description' => trim((string) ($data['meta_description'] ?? '')),
            'composition' => trim((string) ($data['composition'] ?? '')), 'care' => trim((string) ($data['care_instructions'] ?? '')), 'size_guide' => trim((string) ($data['size_guide'] ?? '')),
            'package_contents' => trim((string) ($data['package_contents'] ?? '')), 'excluded_accessories' => trim((string) ($data['excluded_accessories'] ?? '')),
            'featured' => isset($data['featured']) ? 1 : 0, 'indexable' => isset($data['indexable']) ? 1 : 0, 'published_at' => $status === 'published' ? $now : null, 'now' => $now,
        ];
    }

    private function wouldCreateCycle(int $id, int $parentId): bool
    {
        $current = $parentId;
        $seen = [];
        while ($current > 0 && !isset($seen[$current])) {
            if ($current === $id) {
                return true;
            }
            $seen[$current] = true;
            $statement = $this->pdo->prepare('SELECT parent_id FROM categories WHERE id=:id');
            $statement->execute(['id' => $current]);
            $value = $statement->fetchColumn();
            $current = $value === false || $value === null ? 0 : (int) $value;
        }
        return false;
    }

    private function saveRedirect(string $type, string $old, string $new): void
    {
        if ($old === $new) {
            return;
        }
        $statement = $this->pdo->prepare('SELECT id FROM slug_redirects WHERE entity_type=:type AND old_slug=:old');
        $statement->execute(['type' => $type, 'old' => $old]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            $this->pdo->prepare('INSERT INTO slug_redirects (entity_type,old_slug,new_slug,created_at) VALUES (:type,:old,:new,:now)')->execute(['type' => $type, 'old' => $old, 'new' => $new, 'now' => gmdate('c')]);
        } else {
            $this->pdo->prepare('UPDATE slug_redirects SET new_slug=:new WHERE id=:id')->execute(['new' => $new, 'id' => $id]);
        }
    }
}
