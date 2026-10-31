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
        if ($name === '') {
            throw new RuntimeException('Numele produsului este obligatoriu.');
        }
        if (!$this->isProductNameAvailable($name, $id)) {
            throw new RuntimeException('Acest titlu există deja. Alege o denumire unică pentru produs.');
        }
        $shortDescription = trim((string) ($data['short_description'] ?? ''));
        if (mb_strlen($shortDescription) > 200) {
            throw new RuntimeException('Descrierea scurtă poate avea maximum 200 de caractere.');
        }
        $slugInput = trim((string) ($data['slug'] ?? ''));
        $slug = $this->normalizeProductSlug($slugInput !== '' ? $slugInput : $name);
        if ($slug === '') {
            throw new RuntimeException('Numele linkului nu a putut fi generat. Introdu manual un slug valid.');
        }
        if (!$this->isProductSlugAvailable($slug, $id)) {
            throw new RuntimeException('Acest nume de link există deja. Alege un slug unic pentru produs.');
        }
        $categoryIds = $this->productCategoryIds($data);
        if ($categoryIds === []) {
            throw new RuntimeException('Selectează cel puțin o categorie.');
        }
        $this->assertProductCategoriesExist($categoryIds);
        $categoryId = $this->primaryProductCategoryId($id, $categoryIds);
        $supplierId = ($data['supplier_id'] ?? '') === '' ? null : (int) $data['supplier_id'];
        if ($supplierId !== null) {
            $supplier = $this->pdo->prepare("SELECT COUNT(*) FROM suppliers WHERE id=:id AND status='active'");
            $supplier->execute(['id' => $supplierId]);
            if ((int) $supplier->fetchColumn() !== 1) {
                throw new RuntimeException('Furnizorul selectat nu mai este disponibil.');
            }
        }
        $status = in_array(($data['status'] ?? ''), ['draft', 'published', 'archived'], true) ? (string) $data['status'] : 'draft';
        $primarySku = trim(mb_substr((string) ($data['primary_sku'] ?? $data['sku'] ?? ''), 0, 100));
        if ($primarySku === '') {
            $primarySku = $this->generateProductSku();
        }
        if (!$this->isProductPrimarySkuAvailable($primarySku, $id)) {
            throw new RuntimeException('Acest SKU există deja ca SKU principal. Alege alt cod pentru produs.');
        }
        $trackInConta = (string) ($data['track_in_conta'] ?? '1') === '1';
        $variants = $this->submittedProductVariants($data, $id, $primarySku, $trackInConta);
        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            if ($id === null) {
                $statement = $this->pdo->prepare('INSERT INTO products (name,slug,primary_sku,track_in_conta,short_description,description,brand,supplier_id,status,primary_category_id,seo_title,meta_description,composition,care_instructions,size_guide,package_contents,excluded_accessories,featured,indexable,published_at,created_at,updated_at) VALUES (:name,:slug,:primary_sku,:track_in_conta,:short_description,:description,:brand,:supplier_id,:status,:category,:seo_title,:meta_description,:composition,:care,:size_guide,:package_contents,:excluded_accessories,:featured,:indexable,:published_at,:now,:now)');
                $statement->execute($this->productParams($data, $name, $slug, $primarySku, $trackInConta, $status, $categoryId, $supplierId, $now));
                $id = (int) $this->pdo->lastInsertId();
                $this->saveProductCategories($id, $categoryIds);
            } else {
                $old = $this->pdo->prepare('SELECT slug FROM products WHERE id=:id');
                $old->execute(['id' => $id]);
                $oldSlug = $old->fetchColumn();
                if ($oldSlug === false) {
                    throw new RuntimeException('Produsul nu există.');
                }
                $statement = $this->pdo->prepare('UPDATE products SET name=:name,slug=:slug,primary_sku=:primary_sku,track_in_conta=:track_in_conta,short_description=:short_description,description=:description,brand=:brand,supplier_id=:supplier_id,status=:status,primary_category_id=:category,seo_title=:seo_title,meta_description=:meta_description,composition=:composition,care_instructions=:care,size_guide=:size_guide,package_contents=:package_contents,excluded_accessories=:excluded_accessories,featured=:featured,indexable=:indexable,published_at=:published_at,updated_at=:now WHERE id=:id');
                $statement->execute($this->productParams($data, $name, $slug, $primarySku, $trackInConta, $status, $categoryId, $supplierId, $now) + ['id' => $id]);
                $this->pdo->prepare('DELETE FROM product_categories WHERE product_id=:id')->execute(['id' => $id]);
                $this->saveProductCategories($id, $categoryIds);
                if ($oldSlug !== $slug) {
                    $this->saveRedirect('product', (string) $oldSlug, $slug);
                }
            }
            $this->syncProductVariants($id, $variants, $now);
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
        return $this->pdo->query("SELECT p.*, c.name AS category_name, COALESCE(p.primary_sku,v.sku) AS sku, v.price_bani, i.physical_quantity, i.reserved_quantity FROM products p LEFT JOIN categories c ON c.id=p.primary_category_id LEFT JOIN product_variants v ON v.product_id=p.id AND v.is_default=1 LEFT JOIN inventory i ON i.variant_id=v.id ORDER BY p.id DESC")->fetchAll();
    }

    public function isProductNameAvailable(string $name, ?int $excludeId = null): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }

        $statement = $excludeId !== null && $excludeId > 0
            ? $this->pdo->prepare('SELECT name FROM products WHERE id <> :exclude_id')
            : $this->pdo->prepare('SELECT name FROM products');
        $statement->execute($excludeId !== null && $excludeId > 0 ? ['exclude_id' => $excludeId] : []);
        $nameKey = Slugger::slug($name);

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $existingName) {
            if (Slugger::slug((string) $existingName) === $nameKey) {
                return false;
            }
        }

        return true;
    }

    public function isProductSkuAvailable(string $sku, ?int $excludeVariantId = null): bool
    {
        $sku = trim($sku);
        if ($sku === '') {
            return false;
        }

        $sql = 'SELECT COUNT(*) FROM product_variants WHERE LOWER(sku)=LOWER(:sku)';
        $parameters = ['sku' => $sku];
        if ($excludeVariantId !== null && $excludeVariantId > 0) {
            $sql .= ' AND id<>:exclude_variant_id';
            $parameters['exclude_variant_id'] = $excludeVariantId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn() === 0;
    }

    public function isProductPrimarySkuAvailable(string $sku, ?int $excludeProductId = null): bool
    {
        $sku = trim($sku);
        if ($sku === '') {
            return false;
        }

        $productSql = 'SELECT COUNT(*) FROM products WHERE LOWER(primary_sku)=LOWER(:sku)';
        $productParameters = ['sku' => $sku];
        if ($excludeProductId !== null && $excludeProductId > 0) {
            $productSql .= ' AND id<>:exclude_product_id';
            $productParameters['exclude_product_id'] = $excludeProductId;
        }
        $product = $this->pdo->prepare($productSql);
        $product->execute($productParameters);
        if ((int) $product->fetchColumn() > 0) {
            return false;
        }

        $variantSql = 'SELECT COUNT(*) FROM product_variants WHERE LOWER(sku)=LOWER(:sku)';
        $variantParameters = ['sku' => $sku];
        if ($excludeProductId !== null && $excludeProductId > 0) {
            $variantSql .= ' AND product_id<>:exclude_product_id';
            $variantParameters['exclude_product_id'] = $excludeProductId;
        }
        $variant = $this->pdo->prepare($variantSql);
        $variant->execute($variantParameters);

        return (int) $variant->fetchColumn() === 0;
    }

    public function normalizeProductSlug(string $slug): string
    {
        return Slugger::slug(trim($slug));
    }

    public function isProductSlugAvailable(string $slug, ?int $excludeId = null): bool
    {
        $slug = $this->normalizeProductSlug($slug);
        if ($slug === '') {
            return false;
        }

        $sql = 'SELECT COUNT(*) FROM products WHERE LOWER(slug)=LOWER(:slug)';
        $parameters = ['slug' => $slug];
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id<>:exclude_id';
            $parameters['exclude_id'] = $excludeId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn() === 0;
    }

    /** @param list<string> $reserved */
    public function generateProductSku(array $reserved = []): string
    {
        $year = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Bucharest')))->format('y');
        $prefix = 'LM-' . $year . '-';
        $statement = $this->pdo->prepare("SELECT sku FROM product_variants WHERE sku LIKE :variant_prefix UNION SELECT primary_sku AS sku FROM products WHERE primary_sku LIKE :product_prefix");
        $statement->execute(['variant_prefix' => $prefix . '%', 'product_prefix' => $prefix . '%']);
        $maximum = 0;
        $pattern = '/^' . preg_quote($prefix, '/') . '(\d+)$/i';
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $existingSku) {
            if (preg_match($pattern, (string) $existingSku, $matches) === 1) {
                $maximum = max($maximum, (int) $matches[1]);
            }
        }

        $reserved = array_fill_keys(array_map('mb_strtolower', $reserved), true);
        do {
            ++$maximum;
            $candidate = $prefix . str_pad((string) $maximum, 3, '0', STR_PAD_LEFT);
        } while (!$this->isProductPrimarySkuAvailable($candidate) || isset($reserved[mb_strtolower($candidate)]));

        return $candidate;
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
                OR LOWER(COALESCE(p.primary_sku, '')) LIKE :primary_sku_search
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
                ':primary_sku_search' => $needle,
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
                COALESCE(p.primary_sku, (
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
                    SELECT default_variant.compare_price_bani FROM product_variants default_variant
                    WHERE default_variant.product_id = p.id
                    ORDER BY default_variant.is_default DESC, default_variant.id ASC LIMIT 1
                ), 0) AS compare_price_bani,
                (SELECT default_variant.discount_type FROM product_variants default_variant
                    WHERE default_variant.product_id = p.id
                    ORDER BY default_variant.is_default DESC, default_variant.id ASC LIMIT 1
                ) AS discount_type,
                COALESCE((
                    SELECT default_variant.discount_value FROM product_variants default_variant
                    WHERE default_variant.product_id = p.id
                    ORDER BY default_variant.is_default DESC, default_variant.id ASC LIMIT 1
                ), 0) AS discount_value,
                COALESCE((
                    SELECT SUM(stock.physical_quantity - stock.reserved_quantity)
                    FROM product_variants stock_variant
                    LEFT JOIN inventory stock ON stock.variant_id = stock_variant.id
                    WHERE stock_variant.product_id = p.id AND stock_variant.status = 'active'
                ), 0) AS online_quantity,
                CASE WHEN EXISTS (
                    SELECT 1 FROM product_variants unlimited_variant
                    WHERE unlimited_variant.product_id=p.id
                      AND unlimited_variant.status='active'
                      AND unlimited_variant.stock_unlimited=1
                ) THEN 1 ELSE 0 END AS has_unlimited_stock,
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
        $statement = $this->pdo->prepare('SELECT p.* FROM products p WHERE p.id=:id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $categories = $this->pdo->prepare('SELECT category_id FROM product_categories WHERE product_id=:product_id ORDER BY category_id');
        $categories->execute(['product_id' => $id]);
        $row['category_ids'] = array_map('intval', $categories->fetchAll(PDO::FETCH_COLUMN));
        if ($row['category_ids'] === [] && (int) ($row['primary_category_id'] ?? 0) > 0) {
            $row['category_ids'] = [(int) $row['primary_category_id']];
        }

        $variants = $this->pdo->prepare("SELECT
                variant.id,variant.sku,variant.ean,variant.option_summary,variant.options_json,
                variant.price_bani,variant.compare_price_bani,variant.stock_unlimited,
                variant.track_in_conta,variant.discount_type,variant.discount_value,
                variant.is_default,COALESCE(inventory.physical_quantity, 0) AS stock,
                COALESCE(inventory.reserved_quantity, 0) AS reserved_quantity
            FROM product_variants variant
            LEFT JOIN inventory ON inventory.variant_id=variant.id
            WHERE variant.product_id=:product_id AND variant.status='active'
            ORDER BY variant.is_default DESC,variant.id");
        $variants->execute(['product_id' => $id]);
        $row['variants'] = [];
        $row['option_groups'] = [];
        $groupPositions = [];
        foreach ($variants->fetchAll() as $variant) {
            $decoded = json_decode((string) ($variant['options_json'] ?? '{}'), true);
            $options = is_array($decoded) ? $decoded : [];
            foreach ($options as $groupName => $value) {
                $groupName = trim((string) $groupName);
                $value = trim((string) $value);
                if ($groupName === '' || $value === '') {
                    continue;
                }
                $groupKey = mb_strtolower($groupName);
                if (!isset($groupPositions[$groupKey])) {
                    $groupPositions[$groupKey] = count($row['option_groups']);
                    $row['option_groups'][] = ['name' => $groupName, 'values' => []];
                }
                $position = $groupPositions[$groupKey];
                if (!in_array($value, $row['option_groups'][$position]['values'], true)) {
                    $row['option_groups'][$position]['values'][] = $value;
                }
            }

            $fullPrice = $variant['compare_price_bani'] !== null
                ? (int) $variant['compare_price_bani']
                : (int) $variant['price_bani'];
            $discountType = in_array($variant['discount_type'], ['percent', 'fixed'], true)
                ? (string) $variant['discount_type']
                : null;
            $discountValue = $variant['discount_value'] !== null ? (int) $variant['discount_value'] : null;
            if ($discountType === null && $fullPrice > (int) $variant['price_bani']) {
                // Produsele create înainte de introducerea tipului de reducere își păstrează
                // prețul final exact. O reducere fixă evită diferențe de un ban la resalvare.
                $discountType = 'fixed';
                $discountValue = $fullPrice - (int) $variant['price_bani'];
            }
            $variant['options'] = $options;
            $variant['full_price_bani'] = $fullPrice;
            $variant['discount_type'] = $discountType ?? 'percent';
            $variant['discount_value'] = $discountValue ?? 0;
            $variant['discount_enabled'] = $fullPrice > (int) $variant['price_bani'];
            $row['variants'][] = $variant;
        }

        if ($row['variants'] !== []) {
            $default = $row['variants'][0];
            $row['variant_id'] = (int) $default['id'];
            if (trim((string) ($row['primary_sku'] ?? '')) === '') {
                $row['primary_sku'] = (string) $default['sku'];
            }
            $row['price_bani'] = (int) $default['price_bani'];
            $row['stock'] = (int) $default['stock'];
        }

        return $row;
    }

    /** @param array<string,mixed> $data @return list<int> */
    private function productCategoryIds(array $data): array
    {
        $values = $data['category_ids'] ?? [];
        if (!is_array($values)) {
            $values = [$values];
        }
        if ($values === [] && isset($data['category_id'])) {
            $values = [$data['category_id']];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn(int $categoryId): bool => $categoryId > 0
        )));
    }

    /** @param list<int> $categoryIds */
    private function assertProductCategoriesExist(array $categoryIds): void
    {
        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM categories WHERE id IN (' . $placeholders . ')');
        $statement->execute($categoryIds);
        if ((int) $statement->fetchColumn() !== count($categoryIds)) {
            throw new RuntimeException('Una dintre categoriile selectate nu mai există. Reîncarcă formularul.');
        }
    }

    /** @param list<int> $categoryIds */
    private function primaryProductCategoryId(?int $productId, array $categoryIds): int
    {
        if ($productId !== null && $productId > 0) {
            $statement = $this->pdo->prepare('SELECT primary_category_id FROM products WHERE id=:id');
            $statement->execute(['id' => $productId]);
            $current = (int) $statement->fetchColumn();
            if ($current > 0 && in_array($current, $categoryIds, true)) {
                return $current;
            }
        }

        return $categoryIds[0];
    }

    /** @param list<int> $categoryIds */
    private function saveProductCategories(int $productId, array $categoryIds): void
    {
        $statement = $this->pdo->prepare('INSERT INTO product_categories (product_id, category_id) VALUES (:product, :category)');
        foreach ($categoryIds as $categoryId) {
            $statement->execute(['product' => $productId, 'category' => $categoryId]);
        }
    }

    private function defaultVariantId(?int $productId): ?int
    {
        if ($productId === null || $productId <= 0) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT id FROM product_variants WHERE product_id=:product_id ORDER BY is_default DESC,id LIMIT 1');
        $statement->execute(['product_id' => $productId]);
        $variantId = $statement->fetchColumn();

        return $variantId === false ? null : (int) $variantId;
    }

    /**
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    private function submittedProductVariants(array $data, ?int $productId, string $primarySku, bool $parentTrackInConta): array
    {
        $groups = $this->normalizeOptionGroups($data['option_groups'] ?? []);
        $combinations = $this->optionCombinations($groups);
        $submitted = $data['variants'] ?? [];
        if (!is_array($submitted) || $submitted === []) {
            $submitted = [[
                'id' => $this->defaultVariantId($productId),
                'options_json' => '{}',
                'sku' => $primarySku,
                'ean' => $data['ean'] ?? '',
                'full_price' => $data['price'] ?? '',
                'stock' => $data['stock'] ?? 0,
                'stock_unlimited' => $data['stock_unlimited'] ?? 0,
                'track_in_conta' => $parentTrackInConta ? 1 : 0,
                'discount_enabled' => $data['discount_enabled'] ?? 0,
                'discount_type' => $data['discount_type'] ?? 'percent',
                'discount_value' => $data['discount_value'] ?? '',
            ]];
        }

        $existingIds = [];
        $existingByOptions = [];
        if ($productId !== null && $productId > 0) {
            $existing = $this->pdo->prepare('SELECT id,options_json FROM product_variants WHERE product_id=:product_id');
            $existing->execute(['product_id' => $productId]);
            foreach ($existing->fetchAll() as $row) {
                $variantId = (int) $row['id'];
                $existingIds[$variantId] = true;
                $decoded = json_decode((string) $row['options_json'], true);
                $existingByOptions[$this->canonicalOptionsKey(is_array($decoded) ? $decoded : [])] = $variantId;
            }
        }

        $submittedByOptions = [];
        foreach ($submitted as $rawVariant) {
            if (!is_array($rawVariant)) {
                continue;
            }
            $decoded = json_decode((string) ($rawVariant['options_json'] ?? '{}'), true);
            $options = $this->normalizeSubmittedOptions(is_array($decoded) ? $decoded : [], $groups);
            $optionsKey = $this->canonicalOptionsKey($options);
            if (isset($submittedByOptions[$optionsKey])) {
                throw new RuntimeException('Aceeași combinație de opțiuni apare de două ori. Reîncarcă formularul.');
            }
            $submittedByOptions[$optionsKey] = $rawVariant + ['normalized_options' => $options];
        }

        $result = [];
        $usedIds = [];
        $reservedSkus = $groups === [] ? [] : [$primarySku];
        $reservedEans = [];
        foreach ($combinations as $position => $options) {
            $optionsKey = $this->canonicalOptionsKey($options);
            $rawVariant = $submittedByOptions[$optionsKey] ?? null;
            if (!is_array($rawVariant)) {
                throw new RuntimeException('Completează datele comerciale pentru toate combinațiile de opțiuni.');
            }

            $variantId = (int) ($rawVariant['id'] ?? 0);
            if ($variantId <= 0 || !isset($existingIds[$variantId]) || isset($usedIds[$variantId])) {
                $variantId = (int) ($existingByOptions[$optionsKey] ?? 0);
            }
            if ($variantId > 0) {
                if (isset($usedIds[$variantId])) {
                    throw new RuntimeException('O variantă existentă a fost trimisă de două ori. Reîncarcă formularul.');
                }
                $usedIds[$variantId] = true;
            }

            $sku = $groups === []
                ? $primarySku
                : trim(mb_substr((string) ($rawVariant['sku'] ?? ''), 0, 100));
            // Older products used the default variant SKU as the product SKU. Once the
            // parent identity is separated, migrate that inherited value transparently
            // instead of blocking the first save with a duplicate-SKU error.
            if ($groups !== [] && mb_strtolower($sku) === mb_strtolower($primarySku)) {
                $sku = '';
            }
            if ($sku === '') {
                $sku = $this->generateProductSku($reservedSkus);
            }
            $skuKey = mb_strtolower($sku);
            if (in_array($skuKey, array_map('mb_strtolower', $reservedSkus), true)) {
                throw new RuntimeException('Fiecare variantă trebuie să aibă un SKU unic.');
            }
            $skuOwner = $this->pdo->prepare('SELECT id FROM product_variants WHERE LOWER(sku)=LOWER(:sku) LIMIT 1');
            $skuOwner->execute(['sku' => $sku]);
            $ownerId = $skuOwner->fetchColumn();
            if ($ownerId !== false && (int) $ownerId !== $variantId) {
                throw new RuntimeException('Acest SKU există deja. Alege alt cod pentru varianta „' . ((string) ($rawVariant['option_summary'] ?? '') ?: $sku) . '”.');
            }
            if ($groups !== []) {
                $primaryOwner = $this->pdo->prepare('SELECT id FROM products WHERE LOWER(primary_sku)=LOWER(:sku) LIMIT 1');
                $primaryOwner->execute(['sku' => $sku]);
                if ($primaryOwner->fetchColumn() !== false) {
                    throw new RuntimeException('Acest SKU este rezervat unui produs principal. Alege alt cod pentru combinație.');
                }
            }
            $reservedSkus[] = $sku;

            $ean = trim((string) ($rawVariant['ean'] ?? ''));
            $ean = $ean === '' ? null : $ean;
            if ($ean !== null) {
                if (isset($reservedEans[$ean])) {
                    throw new RuntimeException('Fiecare variantă trebuie să aibă un cod EAN unic.');
                }
                $eanOwner = $this->pdo->prepare('SELECT id FROM product_variants WHERE ean=:ean LIMIT 1');
                $eanOwner->execute(['ean' => $ean]);
                $ownerId = $eanOwner->fetchColumn();
                if ($ownerId !== false && (int) $ownerId !== $variantId) {
                    throw new RuntimeException('Codul EAN „' . $ean . '” este deja folosit.');
                }
                $reservedEans[$ean] = true;
            }

            $fullPrice = Money::parse((string) ($rawVariant['full_price'] ?? $rawVariant['price'] ?? ''));
            $stock = filter_var($rawVariant['stock'] ?? null, FILTER_VALIDATE_INT);
            if ($stock === false || $stock < 0) {
                throw new RuntimeException('Stocul trebuie să fie un număr întreg pozitiv sau zero.');
            }
            $stockUnlimited = (string) ($rawVariant['stock_unlimited'] ?? '0') === '1';
            $trackInConta = $groups === []
                ? $parentTrackInConta
                : (string) ($rawVariant['track_in_conta'] ?? '0') === '1';
            $discountEnabled = (string) ($rawVariant['discount_enabled'] ?? '0') === '1';
            $discountType = null;
            $discountValue = null;
            $sellingPrice = $fullPrice;
            if ($discountEnabled) {
                if ($fullPrice <= 0) {
                    throw new RuntimeException('Adaugă un preț întreg mai mare decât zero înainte de reducere.');
                }
                $discountType = ($rawVariant['discount_type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
                if ($discountType === 'percent') {
                    $normalized = str_replace(',', '.', trim((string) ($rawVariant['discount_value'] ?? '')));
                    if (preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized) !== 1) {
                        throw new RuntimeException('Procentul reducerii trebuie să fie un număr valid.');
                    }
                    $discountValue = (int) round((float) $normalized * 100);
                    if ($discountValue <= 0 || $discountValue >= 10000) {
                        throw new RuntimeException('Reducerea procentuală trebuie să fie mai mare decât 0% și mai mică decât 100%.');
                    }
                    $sellingPrice = $fullPrice - (int) round($fullPrice * ($discountValue / 10000));
                } else {
                    $discountValue = Money::parse((string) ($rawVariant['discount_value'] ?? ''));
                    if ($discountValue <= 0 || $discountValue >= $fullPrice) {
                        throw new RuntimeException('Reducerea fixă trebuie să fie mai mare decât zero și mai mică decât prețul întreg.');
                    }
                    $sellingPrice = $fullPrice - $discountValue;
                }
            }

            $summaryParts = [];
            foreach ($options as $groupName => $value) {
                $summaryParts[] = $groupName . ': ' . $value;
            }
            $optionsJson = $this->canonicalOptionsJson($options);
            $result[] = [
                'id' => $variantId,
                'sku' => $sku,
                'ean' => $ean,
                'option_summary' => implode(' · ', $summaryParts),
                'options_json' => $optionsJson,
                'options_key' => hash('sha256', $optionsJson),
                'price_bani' => $sellingPrice,
                'compare_price_bani' => $discountEnabled ? $fullPrice : null,
                'stock' => (int) $stock,
                'stock_unlimited' => $stockUnlimited ? 1 : 0,
                'track_in_conta' => $trackInConta ? 1 : 0,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'is_default' => $position === 0 ? 1 : 0,
            ];
        }

        if (count($submittedByOptions) !== count($combinations)) {
            throw new RuntimeException('Lista variantelor nu mai corespunde opțiunilor. Verifică grupurile și încearcă din nou.');
        }

        return $result;
    }

    /** @return list<array{name:string,values:list<string>}> */
    private function normalizeOptionGroups(mixed $rawGroups): array
    {
        if (!is_array($rawGroups)) {
            return [];
        }
        $groups = [];
        $seenNames = [];
        $combinationCount = 1;
        foreach ($rawGroups as $rawGroup) {
            if (!is_array($rawGroup)) {
                continue;
            }
            $name = trim(mb_substr((string) ($rawGroup['name'] ?? ''), 0, 80));
            $rawValues = $rawGroup['values'] ?? [];
            $rawValues = is_array($rawValues) ? $rawValues : [$rawValues];
            $values = [];
            $seenValues = [];
            foreach ($rawValues as $rawValue) {
                $value = trim(mb_substr((string) $rawValue, 0, 100));
                if ($value === '') {
                    continue;
                }
                $valueKey = mb_strtolower($value);
                if (isset($seenValues[$valueKey])) {
                    throw new RuntimeException('În același grup, fiecare opțiune trebuie să fie unică.');
                }
                $seenValues[$valueKey] = true;
                $values[] = $value;
            }
            if ($name === '' && $values === []) {
                continue;
            }
            if ($name === '') {
                throw new RuntimeException('Scrie ce alege clientul pentru fiecare grup, de exemplu „Mărime”.');
            }
            if ($values === []) {
                throw new RuntimeException('Adaugă cel puțin o alegere în grupul „' . $name . '”.');
            }
            $nameKey = mb_strtolower($name);
            if (isset($seenNames[$nameKey])) {
                throw new RuntimeException('Fiecare grup de opțiuni trebuie să aibă un nume diferit.');
            }
            $seenNames[$nameKey] = true;
            $combinationCount *= count($values);
            if ($combinationCount > 100) {
                throw new RuntimeException('Ai creat peste 100 de combinații. Redu numărul de alegeri pentru a păstra produsul ușor de administrat.');
            }
            $groups[] = ['name' => $name, 'values' => $values];
        }
        if (count($groups) > 4) {
            throw new RuntimeException('Un produs poate avea cel mult 4 tipuri de alegeri.');
        }

        return $groups;
    }

    /**
     * @param list<array{name:string,values:list<string>}> $groups
     * @return list<array<string,string>>
     */
    private function optionCombinations(array $groups): array
    {
        $combinations = [[]];
        foreach ($groups as $group) {
            $next = [];
            foreach ($combinations as $combination) {
                foreach ($group['values'] as $value) {
                    $next[] = $combination + [$group['name'] => $value];
                }
            }
            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * @param array<string,mixed> $options
     * @param list<array{name:string,values:list<string>}> $groups
     * @return array<string,string>
     */
    private function normalizeSubmittedOptions(array $options, array $groups): array
    {
        if ($groups === []) {
            return [];
        }
        $normalized = [];
        foreach ($groups as $group) {
            $value = trim((string) ($options[$group['name']] ?? ''));
            if (!in_array($value, $group['values'], true)) {
                throw new RuntimeException('O combinație conține o alegere care nu mai există. Reîncarcă formularul.');
            }
            $normalized[$group['name']] = $value;
        }
        if (count($options) !== count($normalized)) {
            throw new RuntimeException('O combinație conține opțiuni necunoscute. Reîncarcă formularul.');
        }

        return $normalized;
    }

    /** @param array<string,mixed> $options */
    private function canonicalOptionsJson(array $options): string
    {
        if ($options === []) {
            return '{}';
        }
        $encoded = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('Opțiunile produsului nu au putut fi salvate.');
        }

        return $encoded;
    }

    /** @param array<string,mixed> $options */
    private function canonicalOptionsKey(array $options): string
    {
        return hash('sha256', $this->canonicalOptionsJson($options));
    }

    /** @param list<array<string,mixed>> $variants */
    private function syncProductVariants(int $productId, array $variants, string $now): void
    {
        $existing = $this->pdo->prepare('SELECT id FROM product_variants WHERE product_id=:product_id');
        $existing->execute(['product_id' => $productId]);
        $existingIds = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));
        $temporaryKey = $this->pdo->prepare('UPDATE product_variants SET options_key=:options_key,status=\'inactive\',is_default=0,updated_at=:now WHERE id=:id');
        foreach ($existingIds as $existingId) {
            $temporaryKey->execute([
                'options_key' => hash('sha256', 'pending:' . $productId . ':' . $existingId . ':' . $now),
                'now' => $now,
                'id' => $existingId,
            ]);
        }

        $activeIds = [];
        foreach ($variants as $variant) {
            $variantId = (int) $variant['id'];
            $parameters = [
                'product_id' => $productId,
                'sku' => $variant['sku'],
                'ean' => $variant['ean'],
                'option_summary' => $variant['option_summary'],
                'options_json' => $variant['options_json'],
                'options_key' => $variant['options_key'],
                'price_bani' => $variant['price_bani'],
                'compare_price_bani' => $variant['compare_price_bani'],
                'stock_unlimited' => $variant['stock_unlimited'],
                'track_in_conta' => $variant['track_in_conta'],
                'discount_type' => $variant['discount_type'],
                'discount_value' => $variant['discount_value'],
                'is_default' => $variant['is_default'],
                'now' => $now,
            ];
            if ($variantId > 0 && in_array($variantId, $existingIds, true)) {
                $this->pdo->prepare("UPDATE product_variants SET
                        sku=:sku,ean=:ean,option_summary=:option_summary,options_json=:options_json,
                        options_key=:options_key,price_bani=:price_bani,compare_price_bani=:compare_price_bani,
                        stock_unlimited=:stock_unlimited,track_in_conta=:track_in_conta,
                        discount_type=:discount_type,discount_value=:discount_value,status='active',
                        is_default=:is_default,updated_at=:now
                    WHERE id=:id AND product_id=:product_id")
                    ->execute($parameters + ['id' => $variantId]);
            } else {
                $this->pdo->prepare("INSERT INTO product_variants (
                        product_id,sku,ean,option_summary,options_json,options_key,price_bani,compare_price_bani,
                        stock_unlimited,track_in_conta,discount_type,discount_value,status,is_default,created_at,updated_at
                    ) VALUES (
                        :product_id,:sku,:ean,:option_summary,:options_json,:options_key,:price_bani,:compare_price_bani,
                        :stock_unlimited,:track_in_conta,:discount_type,:discount_value,'active',:is_default,:now,:now
                    )")->execute($parameters);
                $variantId = (int) $this->pdo->lastInsertId();
            }
            $activeIds[] = $variantId;

            $inventory = $this->pdo->prepare('SELECT physical_quantity,reserved_quantity FROM inventory WHERE variant_id=:variant_id');
            $inventory->execute(['variant_id' => $variantId]);
            $current = $inventory->fetch();
            $stock = (int) $variant['stock'];
            $reserved = (int) ($current['reserved_quantity'] ?? 0);
            if (!(bool) $variant['stock_unlimited'] && $stock < $reserved) {
                throw new RuntimeException('Stocul fizic pentru „' . ((string) $variant['option_summary'] ?: 'produs') . '” nu poate fi mai mic decât rezervările active.');
            }
            if (is_array($current)) {
                $delta = $stock - (int) $current['physical_quantity'];
                $this->pdo->prepare('UPDATE inventory SET physical_quantity=:stock,updated_at=:now WHERE variant_id=:variant_id')
                    ->execute(['stock' => $stock, 'now' => $now, 'variant_id' => $variantId]);
                if ($delta !== 0) {
                    $this->pdo->prepare("INSERT INTO inventory_movements (variant_id,type,physical_delta,reserved_delta,reason,created_at) VALUES (:variant_id,'manual_adjustment',:delta,0,'Editare produs în admin',:now)")
                        ->execute(['variant_id' => $variantId, 'delta' => $delta, 'now' => $now]);
                }
            } else {
                $this->pdo->prepare('INSERT INTO inventory (variant_id,physical_quantity,reserved_quantity,updated_at) VALUES (:variant_id,:stock,0,:now)')
                    ->execute(['variant_id' => $variantId, 'stock' => $stock, 'now' => $now]);
                if ($stock !== 0) {
                    $this->pdo->prepare("INSERT INTO inventory_movements (variant_id,type,physical_delta,reserved_delta,reason,created_at) VALUES (:variant_id,'initial',:stock,0,'Stoc inițial',:now)")
                        ->execute(['variant_id' => $variantId, 'stock' => $stock, 'now' => $now]);
                }
            }
        }

        if ($activeIds !== []) {
            $placeholders = implode(',', array_fill(0, count($activeIds), '?'));
            $statement = $this->pdo->prepare('DELETE FROM cart_items WHERE variant_id IN (SELECT id FROM product_variants WHERE product_id=? AND status=\'inactive\' AND id NOT IN (' . $placeholders . '))');
            $statement->execute(array_merge([$productId], $activeIds));
        }
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
    private function productParams(array $data, string $name, string $slug, string $primarySku, bool $trackInConta, string $status, int $categoryId, ?int $supplierId, string $now): array
    {
        return [
            'name' => $name, 'slug' => $slug, 'primary_sku' => $primarySku, 'track_in_conta' => $trackInConta ? 1 : 0,
            'short_description' => trim((string) ($data['short_description'] ?? '')), 'description' => trim((string) ($data['description'] ?? '')),
            'brand' => ($brand = trim(mb_substr((string) ($data['brand'] ?? ''), 0, 190))) === '' ? null : $brand, 'supplier_id' => $supplierId,
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
