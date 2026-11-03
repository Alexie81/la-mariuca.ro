<?php

declare(strict_types=1);

namespace App\Modules\Promotions;

use App\Shared\Support\Money;
use PDO;
use PDOException;

final class PromotionAdminService
{
    private const SCOPES = ['order', 'category', 'product', 'shipping'];
    private const VALUE_TYPES = ['percent', 'fixed', 'free'];
    private const MAX_UNSIGNED_INTEGER = 4_294_967_295;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{total:int,active:int,scheduled:int,coupons:int,promotions:int,topbar:int} */
    public function summary(): array
    {
        $now = gmdate('c');
        $statement = $this->pdo->prepare("SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN status='active' AND (starts_at IS NULL OR starts_at<=:active_starts) AND (ends_at IS NULL OR ends_at>:active_ends) THEN 1 ELSE 0 END),0) AS active,
                COALESCE(SUM(CASE WHEN status='active' AND starts_at>:scheduled_at THEN 1 ELSE 0 END),0) AS scheduled,
                COALESCE(SUM(CASE WHEN offer_type='coupon' THEN 1 ELSE 0 END),0) AS coupons,
                COALESCE(SUM(CASE WHEN offer_type='promotion' THEN 1 ELSE 0 END),0) AS promotions,
                COALESCE(SUM(CASE WHEN status='active' AND topbar_enabled=1 AND (starts_at IS NULL OR starts_at<=:topbar_starts) AND (ends_at IS NULL OR ends_at>:topbar_ends) THEN 1 ELSE 0 END),0) AS topbar
            FROM (
                SELECT 'coupon' AS offer_type,status,starts_at,ends_at,topbar_enabled FROM coupons WHERE deleted_at IS NULL
                UNION ALL
                SELECT 'promotion' AS offer_type,status,starts_at,ends_at,topbar_enabled FROM promotions WHERE deleted_at IS NULL
            ) offer_registry");
        $statement->execute([
            'active_starts' => $now,
            'active_ends' => $now,
            'scheduled_at' => $now,
            'topbar_starts' => $now,
            'topbar_ends' => $now,
        ]);
        $row = $statement->fetch();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'scheduled' => (int) ($row['scheduled'] ?? 0),
            'coupons' => (int) ($row['coupons'] ?? 0),
            'promotions' => (int) ($row['promotions'] ?? 0),
            'topbar' => (int) ($row['topbar'] ?? 0),
        ];
    }

    /**
     * @return array{items:list<array<string,mixed>>,page:int,per_page:int,total:int,total_pages:int,from:int,to:int}
     */
    public function paginate(int $page, int $perPage = 15, string $search = ''): array
    {
        $perPage = max(1, min(50, $perPage));
        $now = gmdate('c');
        [$where, $parameters] = $this->searchWhere($search, $now);
        $registrySql = $this->registrySql();

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM (' . $registrySql . ') offer_registry' . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        $statement = $this->pdo->prepare('SELECT * FROM (' . $registrySql . ') offer_registry' . $where . '
            ORDER BY CASE
                WHEN status=\'inactive\' THEN 3
                WHEN ends_at IS NOT NULL AND ends_at<=:order_expired THEN 2
                WHEN starts_at IS NOT NULL AND starts_at>:order_scheduled THEN 1
                ELSE 0
            END,topbar_priority DESC,updated_at DESC,id DESC
            LIMIT :limit OFFSET :offset');
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue(':order_expired', $now);
        $statement->bindValue(':order_scheduled', $now);
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $items = $statement->fetchAll();
        foreach ($items as &$item) {
            $item['lifecycle_status'] = $this->lifecycleStatus($item, $now);
        }
        unset($item);

        return [
            'items' => $items,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => min($offset + $perPage, $total),
        ];
    }

    /** @return array{products:list<array<string,mixed>>,categories:list<array<string,mixed>>} */
    public function discountCatalog(): array
    {
        $products = $this->pdo->query("SELECT p.id,p.name,p.slug,p.primary_sku,p.short_description,p.brand,p.seo_title,p.meta_description,c.name AS category_name,
                COALESCE((SELECT m.public_path FROM product_images pi JOIN media m ON m.id=pi.media_id WHERE pi.product_id=p.id AND m.public_path IS NOT NULL ORDER BY pi.position,pi.media_id LIMIT 1),'') AS image_path
            FROM products p LEFT JOIN categories c ON c.id=p.primary_category_id
            WHERE p.status='published' ORDER BY p.name,p.id")->fetchAll();
        $variants = $this->pdo->query("SELECT product_id,sku,option_summary FROM product_variants WHERE status='active' ORDER BY product_id,is_default DESC,id")->fetchAll();
        $variantSkus = [];
        $variantTerms = [];
        foreach ($variants as $variant) {
            $productId = (int) $variant['product_id'];
            $sku = trim((string) $variant['sku']);
            if ($sku !== '') {
                $variantSkus[$productId][] = $sku;
            }
            $summary = trim((string) $variant['option_summary']);
            if ($summary !== '') {
                $variantTerms[$productId][] = $summary;
            }
        }
        foreach ($products as &$product) {
            $productId = (int) $product['id'];
            $skus = array_values(array_unique(array_filter([
                trim((string) ($product['primary_sku'] ?? '')),
                ...($variantSkus[$productId] ?? []),
            ], static fn(string $sku): bool => $sku !== '')));
            $product['search_skus'] = implode('|', $skus);
            $product['search_terms'] = trim(implode(' ', [
                (string) ($product['slug'] ?? ''),
                (string) ($product['short_description'] ?? ''),
                (string) ($product['brand'] ?? ''),
                (string) ($product['seo_title'] ?? ''),
                (string) ($product['meta_description'] ?? ''),
                implode(' ', array_values(array_unique($variantTerms[$productId] ?? []))),
            ]));
        }
        unset($product);

        $categories = $this->pdo->query("SELECT id,parent_id,name FROM categories WHERE status='published' ORDER BY position,name,id")->fetchAll();
        $byId = [];
        foreach ($categories as $category) {
            $byId[(int) $category['id']] = $category;
        }
        foreach ($categories as &$category) {
            $path = [(string) $category['name']];
            $parentId = (int) ($category['parent_id'] ?? 0);
            $guard = 0;
            while ($parentId > 0 && isset($byId[$parentId]) && $guard++ < 20) {
                array_unshift($path, (string) $byId[$parentId]['name']);
                $parentId = (int) ($byId[$parentId]['parent_id'] ?? 0);
            }
            $category['path'] = implode(' › ', $path);
            $category['depth'] = max(0, count($path) - 1);
        }
        unset($category);

        return ['products' => $products, 'categories' => $categories];
    }

    /** @param array<string,mixed> $data */
    public function createCoupon(array $data, int $adminUserId): string
    {
        return $this->writeOffer(fn(): string => $this->persistCoupon($data, $adminUserId));
    }

    /** @param array<string,mixed> $data */
    public function createPromotion(array $data, int $adminUserId): string
    {
        return $this->writeOffer(fn(): string => $this->persistPromotion($data, $adminUserId));
    }

    /** @return array<string,mixed>|null */
    public function findOffer(string $offerType, int $offerId): ?array
    {
        $this->offerTable($offerType);
        $statement = $this->pdo->prepare('SELECT * FROM (' . $this->registrySql() . ') offers WHERE offer_type=:type AND id=:id');
        $statement->execute(['type' => $offerType, 'id' => $offerId]);
        $offer = $statement->fetch();
        return is_array($offer) ? $offer : null;
    }

    /** @param array<string,mixed> $data */
    public function updateOffer(string $offerType, int $offerId, array $data): string
    {
        $table = $this->offerTable($offerType);
        return $this->writeOffer(function () use ($table, $offerType, $offerId, $data): string {
            $lock = $this->pdo->prepare("SELECT id FROM {$table} WHERE id=:id AND deleted_at IS NULL" . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $lock->execute(['id' => $offerId]);
            if ($lock->fetchColumn() === false) {
                throw new \RuntimeException('Oferta nu mai există în registru.');
            }
            return $offerType === 'coupon'
                ? $this->persistCoupon($data, 0, $offerId)
                : $this->persistPromotion($data, 0, $offerId);
        });
    }

    public function deleteOffer(string $offerType, int $offerId): string
    {
        $table = $this->offerTable($offerType);
        return $this->writeOffer(function () use ($table, $offerType, $offerId): string {
            $now = gmdate('c');
            $statement = $this->pdo->prepare("UPDATE {$table} SET status='inactive',topbar_enabled=0,deleted_at=:deleted,updated_at=:updated WHERE id=:id AND deleted_at IS NULL");
            $statement->execute(['deleted' => $now, 'updated' => $now, 'id' => $offerId]);
            if ($statement->rowCount() !== 1) {
                throw new \RuntimeException('Oferta nu mai există în registru.');
            }
            return $offerType === 'coupon' ? 'Cuponul a fost șters din registru.' : 'Reducerea a fost ștearsă din registru.';
        });
    }

    private function offerTable(string $offerType): string
    {
        return match ($offerType) {
            'coupon' => 'coupons',
            'promotion' => 'promotions',
            default => throw new \RuntimeException('Tipul ofertei nu este valid.'),
        };
    }

    /** @param callable():string $operation */
    private function writeOffer(callable $operation): string
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $message = $operation();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $message;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $data */
    private function persistCoupon(array $data, int $adminUserId, ?int $offerId = null): string
    {
        $name = $this->validatedName($data);
        $code = mb_strtoupper(trim((string) ($data['code'] ?? '')), 'UTF-8');
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{2,39}$/', $code) !== 1) {
            throw new \RuntimeException('Codul trebuie să aibă între 3 și 40 de caractere și poate conține litere, cifre, cratimă sau underscore.');
        }
        $duplicate = $this->pdo->prepare('SELECT id FROM coupons WHERE UPPER(code)=:code AND id<>:id LIMIT 1');
        $duplicate->execute(['code' => $code, 'id' => $offerId ?? 0]);
        if ($duplicate->fetchColumn() !== false) {
            throw new \RuntimeException('Există deja un cupon cu acest cod.');
        }

        $rule = $this->validatedRule($data);
        [$startsAt, $endsAt] = $this->validatedPeriod($data);
        [$topbarEnabled, $topbarMessage, $topbarPriority] = $this->validatedTopbar($data);
        $minimumBani = $this->optionalMoney($data, 'minimum', 'minimum_bani');
        $usageLimit = $this->optionalPositiveInteger($data, 'usage_limit', 'Limita totală de utilizări');
        $customerLimit = $this->optionalPositiveInteger($data, 'per_customer_limit', 'Limita de utilizări per client');
        $status = $this->validatedStatus($data);
        $now = gmdate('c');

        $legacyType = $rule['value_type'] === 'fixed' ? 'fixed' : 'percent';
        $legacyValue = $rule['value_type'] === 'free' ? 10000 : $rule['value'];
        try {
            if ($offerId !== null) {
                $statement = $this->pdo->prepare('UPDATE coupons SET code=:code,type=:type,value=:value,minimum_bani=:minimum,usage_limit=:usage_limit,per_customer_limit=:customer_limit,starts_at=:starts,ends_at=:ends,status=:status,name=:name,scope=:scope,target_id=:target,value_type=:value_type,topbar_enabled=:topbar,topbar_message=:message,topbar_priority=:priority,updated_at=:updated WHERE id=:id AND deleted_at IS NULL');
                $statement->execute([
                    'id' => $offerId, 'code' => $code, 'type' => $legacyType, 'value' => $legacyValue,
                    'minimum' => $minimumBani, 'usage_limit' => $usageLimit, 'customer_limit' => $customerLimit,
                    'starts' => $startsAt, 'ends' => $endsAt, 'status' => $status, 'name' => $name,
                    'scope' => $rule['scope'], 'target' => $rule['target_id'], 'value_type' => $rule['value_type'],
                    'topbar' => $topbarEnabled ? 1 : 0, 'message' => $topbarMessage, 'priority' => $topbarPriority, 'updated' => $now,
                ]);
                return 'Cuponul „' . $name . '” a fost actualizat.';
            }
            $statement = $this->pdo->prepare("INSERT INTO coupons
                (code,type,value,minimum_bani,usage_limit,per_customer_limit,starts_at,ends_at,status,created_at,name,scope,target_id,value_type,topbar_enabled,topbar_message,topbar_priority,created_by_user_id,updated_at,deleted_at)
                VALUES (:code,:legacy_type,:value,:minimum,:usage_limit,:customer_limit,:starts,:ends,:status,:created,:name,:scope,:target,:value_type,:topbar_enabled,:topbar_message,:topbar_priority,:admin,:updated,NULL)");
            $statement->execute([
                'code' => $code,
                'legacy_type' => $legacyType,
                'value' => $legacyValue,
                'minimum' => $minimumBani,
                'usage_limit' => $usageLimit,
                'customer_limit' => $customerLimit,
                'starts' => $startsAt,
                'ends' => $endsAt,
                'status' => $status,
                'created' => $now,
                'name' => $name,
                'scope' => $rule['scope'],
                'target' => $rule['target_id'],
                'value_type' => $rule['value_type'],
                'topbar_enabled' => $topbarEnabled ? 1 : 0,
                'topbar_message' => $topbarMessage,
                'topbar_priority' => $topbarPriority,
                'admin' => $adminUserId > 0 ? $adminUserId : null,
                'updated' => $now,
            ]);
        } catch (PDOException $exception) {
            if ($this->isConstraintViolation($exception)) {
                throw new \RuntimeException('Există deja un cupon cu acest cod.', 0, $exception);
            }
            throw $exception;
        }

        return 'Cuponul „' . $name . '” a fost creat.';
    }

    /** @param array<string,mixed> $data */
    private function persistPromotion(array $data, int $adminUserId, ?int $offerId = null): string
    {
        $name = $this->validatedName($data);
        $rule = $this->validatedRule($data);
        [$startsAt, $endsAt] = $this->validatedPeriod($data);
        [$topbarEnabled, $topbarMessage, $topbarPriority] = $this->validatedTopbar($data);
        $minimumMode = trim((string) ($data['minimum_mode'] ?? ''));
        if ($minimumMode !== '' && !in_array($minimumMode, ['none', 'amount'], true)) {
            throw new \RuntimeException('Alege o condiție de activare validă.');
        }
        $minimumBani = $minimumMode === 'none'
            ? 0
            : $this->optionalMoney($data, 'minimum', 'minimum_bani');
        if ($minimumMode === 'amount' && $minimumBani < 1) {
            throw new \RuntimeException('Introdu valoarea minimă de la care se activează reducerea.');
        }
        $status = $this->validatedStatus($data);
        $now = gmdate('c');

        if ($offerId !== null) {
            $statement = $this->pdo->prepare('UPDATE promotions SET name=:name,scope=:scope,value_type=:value_type,value=:value,target_id=:target,minimum_bani=:minimum,starts_at=:starts,ends_at=:ends,status=:status,topbar_enabled=:topbar,topbar_message=:message,topbar_priority=:priority,updated_at=:updated WHERE id=:id AND deleted_at IS NULL');
            $statement->execute([
                'id' => $offerId, 'name' => $name, 'scope' => $rule['scope'], 'value_type' => $rule['value_type'],
                'value' => $rule['value'], 'target' => $rule['target_id'], 'minimum' => $minimumBani,
                'starts' => $startsAt, 'ends' => $endsAt, 'status' => $status,
                'topbar' => $topbarEnabled ? 1 : 0, 'message' => $topbarMessage, 'priority' => $topbarPriority, 'updated' => $now,
            ]);
            return 'Reducerea „' . $name . '” a fost actualizată.';
        }
        $statement = $this->pdo->prepare("INSERT INTO promotions
            (name,scope,value_type,value,target_id,minimum_bani,starts_at,ends_at,status,topbar_enabled,topbar_message,topbar_priority,created_by_user_id,created_at,updated_at,deleted_at)
            VALUES (:name,:scope,:value_type,:value,:target,:minimum,:starts,:ends,:status,:topbar_enabled,:topbar_message,:topbar_priority,:admin,:created,:updated,NULL)");
        $statement->execute([
            'name' => $name,
            'scope' => $rule['scope'],
            'value_type' => $rule['value_type'],
            'value' => $rule['value'],
            'target' => $rule['target_id'],
            'minimum' => $minimumBani,
            'starts' => $startsAt,
            'ends' => $endsAt,
            'status' => $status,
            'topbar_enabled' => $topbarEnabled ? 1 : 0,
            'topbar_message' => $topbarMessage,
            'topbar_priority' => $topbarPriority,
            'admin' => $adminUserId > 0 ? $adminUserId : null,
            'created' => $now,
            'updated' => $now,
        ]);

        return 'Reducerea „' . $name . '” a fost creată.';
    }

    public function deactivateOffer(string $offerType, int $offerId): string
    {
        return $this->writeOffer(fn(): string => $this->deactivateExistingOffer($offerType, $offerId));
    }

    private function deactivateExistingOffer(string $offerType, int $offerId): string
    {
        $table = match ($offerType) {
            'coupon' => 'coupons',
            'promotion' => 'promotions',
            default => throw new \RuntimeException('Tipul avantajului nu este valid.'),
        };
        if ($offerId < 1) {
            throw new \RuntimeException('Avantajul solicitat nu este valid.');
        }

        $statement = $this->pdo->prepare("UPDATE {$table} SET status='inactive',updated_at=:updated WHERE id=:id AND deleted_at IS NULL AND status='active'");
        $statement->execute(['updated' => gmdate('c'), 'id' => $offerId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Avantajul nu mai există sau este deja inactiv.');
        }

        return $offerType === 'coupon' ? 'Cuponul a fost dezactivat.' : 'Reducerea a fost dezactivată.';
    }

    /** @return array<string,mixed>|null */
    public function activeTopbarOffer(): ?array
    {
        $now = gmdate('c');
        $statement = $this->pdo->prepare("SELECT * FROM (
                SELECT c.id,'coupon' AS offer_type,COALESCE(NULLIF(c.name,''),c.code) AS name,c.code,c.scope,COALESCE(c.value_type,c.type) AS value_type,
                    CASE WHEN COALESCE(c.value_type,c.type)='free' THEN 0 ELSE c.value END AS value,c.topbar_message,c.topbar_priority,c.updated_at
                FROM coupons c
                WHERE c.deleted_at IS NULL AND c.status='active' AND c.topbar_enabled=1
                    AND (c.starts_at IS NULL OR c.starts_at<=:coupon_starts)
                    AND (c.ends_at IS NULL OR c.ends_at>:coupon_ends)
                    AND (c.usage_limit IS NULL OR (SELECT COUNT(*) FROM coupon_redemptions redemption WHERE redemption.coupon_id=c.id AND redemption.status IN ('reserved','confirmed'))<c.usage_limit)
                UNION ALL
                SELECT p.id,'promotion' AS offer_type,p.name,NULL AS code,p.scope,p.value_type,p.value,p.topbar_message,p.topbar_priority,p.updated_at
                FROM promotions p
                WHERE p.deleted_at IS NULL AND p.status='active' AND p.topbar_enabled=1
                    AND (p.starts_at IS NULL OR p.starts_at<=:promotion_starts)
                    AND (p.ends_at IS NULL OR p.ends_at>:promotion_ends)
            ) active_offer
            ORDER BY topbar_priority DESC,updated_at DESC,id DESC
            LIMIT 1");
        $statement->execute([
            'coupon_starts' => $now,
            'coupon_ends' => $now,
            'promotion_starts' => $now,
            'promotion_ends' => $now,
        ]);
        $offer = $statement->fetch();
        if (!is_array($offer)) {
            return null;
        }
        $offer['message'] = (string) $offer['topbar_message'];
        $offer['coupon_code'] = (string) ($offer['code'] ?? '');
        $offer['link_path'] = 'magazin/';

        return $offer;
    }

    private function registrySql(): string
    {
        return "SELECT c.id,'coupon' AS offer_type,COALESCE(NULLIF(c.name,''),c.code) AS name,c.code,c.scope,COALESCE(c.value_type,c.type) AS value_type,
                CASE WHEN COALESCE(c.value_type,c.type)='free' THEN 0 ELSE c.value END AS value,c.target_id,
                CASE WHEN c.scope='product' THEN (SELECT p.name FROM products p WHERE p.id=c.target_id)
                     WHEN c.scope='category' THEN (SELECT category.name FROM categories category WHERE category.id=c.target_id)
                     ELSE NULL END AS target_name,
                c.minimum_bani,c.usage_limit,c.per_customer_limit,c.starts_at,c.ends_at,c.status,c.topbar_enabled,c.topbar_message,c.topbar_priority,c.created_by_user_id,c.created_at,c.updated_at,
                (SELECT COUNT(*) FROM coupon_redemptions redemption WHERE redemption.coupon_id=c.id AND redemption.status IN ('reserved','confirmed')) AS usage_count
            FROM coupons c WHERE c.deleted_at IS NULL
            UNION ALL
            SELECT promotion.id,'promotion' AS offer_type,promotion.name,NULL AS code,promotion.scope,promotion.value_type,promotion.value,promotion.target_id,
                CASE WHEN promotion.scope='product' THEN (SELECT p.name FROM products p WHERE p.id=promotion.target_id)
                     WHEN promotion.scope='category' THEN (SELECT category.name FROM categories category WHERE category.id=promotion.target_id)
                     ELSE NULL END AS target_name,
                promotion.minimum_bani,NULL AS usage_limit,NULL AS per_customer_limit,promotion.starts_at,promotion.ends_at,promotion.status,promotion.topbar_enabled,promotion.topbar_message,promotion.topbar_priority,promotion.created_by_user_id,promotion.created_at,promotion.updated_at,
                (SELECT COUNT(*) FROM orders customer_order WHERE customer_order.promotion_id=promotion.id OR customer_order.shipping_promotion_id=promotion.id) AS usage_count
            FROM promotions promotion WHERE promotion.deleted_at IS NULL";
    }

    /** @param array<string,mixed> $data */
    private function validatedName(array $data): string
    {
        $name = trim((string) ($data['name'] ?? ''));
        $length = mb_strlen($name, 'UTF-8');
        if ($length < 3 || $length > 190 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new \RuntimeException('Numele trebuie să aibă între 3 și 190 de caractere.');
        }
        return $name;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{scope:string,value_type:string,value:int,target_id:int|null}
     */
    private function validatedRule(array $data): array
    {
        $scope = trim((string) ($data['scope'] ?? ''));
        $valueType = trim((string) ($data['value_type'] ?? ''));
        if (!in_array($scope, self::SCOPES, true)) {
            throw new \RuntimeException('Alege unde se aplică avantajul.');
        }
        if (!in_array($valueType, self::VALUE_TYPES, true) || ($valueType === 'free' && $scope !== 'shipping')) {
            throw new \RuntimeException('Alege un tip de reducere potrivit. Livrarea gratuită este disponibilă doar pentru livrare.');
        }

        $targetId = null;
        if ($scope === 'product' || $scope === 'category') {
            $targetId = filter_var($data['target_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_int($targetId)) {
                throw new \RuntimeException($scope === 'product' ? 'Alege produsul care primește avantajul.' : 'Alege categoria care primește avantajul.');
            }
            $table = $scope === 'product' ? 'products' : 'categories';
            $target = $this->pdo->prepare("SELECT id FROM {$table} WHERE id=:id AND status='published' LIMIT 1");
            $target->execute(['id' => $targetId]);
            if ($target->fetchColumn() === false) {
                throw new \RuntimeException($scope === 'product' ? 'Produsul ales nu mai este publicat.' : 'Categoria aleasă nu mai este publicată.');
            }
        }

        if ($valueType === 'free') {
            $value = 0;
        } elseif ($valueType === 'percent') {
            $normalized = str_replace(',', '.', trim((string) ($data['value'] ?? '')));
            if (preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $normalized) !== 1 || (float) $normalized <= 0 || (float) $normalized > 100) {
                throw new \RuntimeException('Procentul trebuie să fie între 0,01 și 100.');
            }
            $value = (int) round((float) $normalized * 100);
        } else {
            try {
                $value = Money::parse((string) ($data['value'] ?? ''));
            } catch (\InvalidArgumentException $exception) {
                throw new \RuntimeException('Valoarea fixă trebuie introdusă în lei, de exemplu 25,00.', 0, $exception);
            }
            if ($value < 1 || $value > self::MAX_UNSIGNED_INTEGER) {
                throw new \RuntimeException('Valoarea reducerii trebuie să fie mai mare decât zero.');
            }
        }

        return ['scope' => $scope, 'value_type' => $valueType, 'value' => $value, 'target_id' => $targetId];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{0:string|null,1:string|null}
     */
    private function validatedPeriod(array $data): array
    {
        $rawStarts = trim((string) ($data['starts_at'] ?? ''));
        $rawEnds = trim((string) ($data['ends_at'] ?? ''));
        $validity = (string) ($data['validity'] ?? (($rawStarts !== '' || $rawEnds !== '') ? 'period' : 'always'));
        if ($validity === 'always') {
            return [null, null];
        }
        if ($validity !== 'period') {
            throw new \RuntimeException('Alege o valabilitate corectă.');
        }

        $startsAt = $this->localInputToUtc($rawStarts);
        $endsAt = $this->localInputToUtc($rawEnds);
        if ($startsAt === null || $endsAt === null) {
            throw new \RuntimeException('Completează data de început și data de sfârșit.');
        }
        if (strtotime($endsAt) <= strtotime($startsAt)) {
            throw new \RuntimeException('Data de final trebuie să fie după data de început.');
        }
        return [$startsAt, $endsAt];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{0:bool,1:string,2:int}
     */
    private function validatedTopbar(array $data): array
    {
        $enabled = $this->booleanValue($data['topbar_enabled'] ?? $data['show_in_topbar'] ?? false);
        $message = preg_replace('/\s+/u', ' ', trim((string) ($data['topbar_message'] ?? ''))) ?? '';
        if (mb_strlen($message, 'UTF-8') > 140 || preg_match('/[\x00-\x1F\x7F]/u', $message) === 1) {
            throw new \RuntimeException('Mesajul din bara de sus poate avea maximum 140 de caractere.');
        }
        if ($enabled && $message === '') {
            throw new \RuntimeException('Scrie mesajul care va apărea în bara de sus.');
        }

        $rawPriority = trim((string) ($data['topbar_priority'] ?? '0'));
        $rawPriority = $rawPriority === '' ? '0' : $rawPriority;
        if (preg_match('/^\d{1,4}$/', $rawPriority) !== 1 || (int) $rawPriority > 1000) {
            throw new \RuntimeException('Prioritatea barei trebuie să fie un număr între 0 și 1000.');
        }
        return [$enabled, $message, (int) $rawPriority];
    }

    /** @param array<string,mixed> $data */
    private function validatedStatus(array $data): string
    {
        $status = trim((string) ($data['status'] ?? 'active'));
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new \RuntimeException('Starea avantajului nu este validă.');
        }
        return $status;
    }

    /** @param array<string,mixed> $data */
    private function optionalMoney(array $data, string $leiKey, string $baniKey): int
    {
        $rawLei = trim((string) ($data[$leiKey] ?? ''));
        if ($rawLei !== '') {
            try {
                $value = Money::parse($rawLei);
                if ($value > self::MAX_UNSIGNED_INTEGER) {
                    throw new \RuntimeException('Pragul minim depășește valoarea maximă acceptată.');
                }
                return $value;
            } catch (\InvalidArgumentException $exception) {
                throw new \RuntimeException('Pragul minim trebuie introdus în lei, de exemplu 150,00.', 0, $exception);
            }
        }
        $rawBani = $data[$baniKey] ?? 0;
        $validatedBani = filter_var($rawBani, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_UNSIGNED_INTEGER]]);
        if (!is_int($validatedBani)) {
            throw new \RuntimeException('Pragul minim nu este valid.');
        }
        return $validatedBani;
    }

    /** @param array<string,mixed> $data */
    private function optionalPositiveInteger(array $data, string $key, string $label): ?int
    {
        $raw = trim((string) ($data[$key] ?? ''));
        if ($raw === '') {
            return null;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_UNSIGNED_INTEGER]]);
        if (!is_int($value)) {
            throw new \RuntimeException($label . ' trebuie să fie un număr întreg mai mare decât zero.');
        }
        return $value;
    }

    private function localInputToUtc(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $timezone = new \DateTimeZone('Europe/Bucharest');
        foreach (['!Y-m-d\\TH:i', '!Y-m-d\\TH:i:s', '!Y-m-d H:i', '!Y-m-d H:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, $timezone);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->setTimezone(new \DateTimeZone('UTC'))->format('c');
            }
        }
        return null;
    }

    private function booleanValue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    private function isConstraintViolation(PDOException $exception): bool
    {
        return $exception->getCode() === '23000' || str_contains(mb_strtolower($exception->getMessage(), 'UTF-8'), 'unique');
    }

    /** @param array<string,mixed> $item */
    private function lifecycleStatus(array $item, string $now): string
    {
        if ((string) ($item['status'] ?? '') !== 'active') {
            return 'inactive';
        }
        $startsAt = (string) ($item['starts_at'] ?? '');
        if ($startsAt !== '' && $startsAt > $now) {
            return 'scheduled';
        }
        $endsAt = (string) ($item['ends_at'] ?? '');
        if ($endsAt !== '' && $endsAt <= $now) {
            return 'expired';
        }
        return 'active';
    }

    /** @return array{0:string,1:array<string,string>} */
    private function searchWhere(string $search, string $now): array
    {
        $normalized = $this->normalize($search);
        if ($normalized === '') {
            return ['', []];
        }
        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopWords = ['a', 'al', 'ale', 'cu', 'de', 'din', 'in', 'la', 'si', 'un', 'o', 'registru'];
        $tokens = array_slice(array_values(array_unique(array_filter(
            $tokens,
            static fn(string $token): bool => strlen($token) > 1 && !in_array($token, $stopWords, true),
        ))), 0, 8);
        if ($tokens === []) {
            return ['', []];
        }

        $where = [];
        $parameters = [];
        foreach ($tokens as $index => $token) {
            if (in_array($token, ['cupon', 'cupoane', 'cod'], true)) {
                $where[] = "offer_type='coupon'";
                continue;
            }
            if (in_array($token, ['promotie', 'promotii', 'reducere', 'reduceri', 'automat', 'automata'], true)) {
                $where[] = "offer_type='promotion'";
                continue;
            }
            $scope = match ($token) {
                'comanda', 'cos' => 'order',
                'produs', 'produse' => 'product',
                'categorie', 'categorii' => 'category',
                'livrare', 'transport' => 'shipping',
                default => null,
            };
            if ($scope !== null) {
                $placeholder = ':search_scope_' . $index;
                $where[] = 'scope=' . $placeholder;
                $parameters[$placeholder] = $scope;
                continue;
            }
            if (in_array($token, ['bara', 'anunt', 'anunturi'], true)) {
                $where[] = 'topbar_enabled=1';
                continue;
            }
            if (in_array($token, ['inactiv', 'inactiva', 'inactive'], true)) {
                $where[] = "status='inactive'";
                continue;
            }
            if (in_array($token, ['activ', 'activa', 'active'], true)) {
                $starts = ':search_active_starts_' . $index;
                $ends = ':search_active_ends_' . $index;
                $where[] = "status='active' AND (starts_at IS NULL OR starts_at<={$starts}) AND (ends_at IS NULL OR ends_at>{$ends})";
                $parameters[$starts] = $now;
                $parameters[$ends] = $now;
                continue;
            }
            if (in_array($token, ['programat', 'programata', 'viitor'], true)) {
                $placeholder = ':search_scheduled_' . $index;
                $where[] = "status='active' AND starts_at>" . $placeholder;
                $parameters[$placeholder] = $now;
                continue;
            }
            if (in_array($token, ['expirat', 'expirata'], true)) {
                $placeholder = ':search_expired_' . $index;
                $where[] = 'ends_at IS NOT NULL AND ends_at<=' . $placeholder;
                $parameters[$placeholder] = $now;
                continue;
            }

            $clauses = [];
            foreach (['name', 'code', 'scope', 'value_type', 'target_name', 'topbar_message'] as $columnIndex => $column) {
                $placeholder = ':search_offer_' . $index . '_' . $columnIndex;
                $clauses[] = $this->normalizedColumn($column) . ' LIKE ' . $placeholder;
                $parameters[$placeholder] = '%' . $token . '%';
            }
            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }

        return [' WHERE ' . implode(' AND ', $where), $parameters];
    }

    private function normalizedColumn(string $column): string
    {
        $expression = "LOWER(COALESCE({$column},''))";
        foreach ([
            'Ă' => 'a', 'Â' => 'a', 'Î' => 'i', 'Ș' => 's', 'Ş' => 's', 'Ț' => 't', 'Ţ' => 't',
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ] as $from => $to) {
            $expression = "REPLACE({$expression},'{$from}','{$to}')";
        }
        return $expression;
    }

    private function normalize(string $value): string
    {
        return strtr(mb_strtolower(trim($value), 'UTF-8'), [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
    }
}
