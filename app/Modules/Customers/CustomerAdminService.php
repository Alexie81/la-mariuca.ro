<?php

declare(strict_types=1);

namespace App\Modules\Customers;

use App\Shared\Support\Money;
use PDO;

final class CustomerAdminService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{items:list<array<string,mixed>>,page:int,per_page:int,total:int,total_pages:int,from:int,to:int}
     */
    public function paginate(int $page, int $perPage = 15, string $search = ''): array
    {
        $perPage = max(1, min(50, $perPage));
        [$where, $parameters] = $this->searchWhere($search);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM users u WHERE u.role='customer'" . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        $latestConsent = $this->latestConsentSql();
        $statement = $this->pdo->prepare("SELECT u.id,u.email,u.first_name,u.last_name,u.phone,u.status,u.email_verified_at,u.created_at,u.last_login_at,
                CASE WHEN EXISTS(SELECT 1 FROM oauth_identities identity WHERE identity.user_id=u.id AND identity.provider='google') THEN 1 ELSE 0 END AS uses_google,
                COALESCE({$latestConsent},0) AS is_subscribed,
                (SELECT COUNT(*) FROM orders customer_order WHERE customer_order.user_id=u.id) AS order_count,
                COALESCE((SELECT SUM(customer_order.total_bani) FROM orders customer_order WHERE customer_order.user_id=u.id AND customer_order.status NOT IN ('cancelled','returned')),0) AS total_spent_bani,
                (SELECT MAX(customer_order.created_at) FROM orders customer_order WHERE customer_order.user_id=u.id) AS last_order_at
            FROM users u
            WHERE u.role='customer'" . $where . "
            ORDER BY CASE WHEN last_order_at IS NULL THEN 1 ELSE 0 END,last_order_at DESC,u.created_at DESC,u.id DESC
            LIMIT :limit OFFSET :offset");
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

    /** @return array{total:int,subscribed:int,google:int,with_orders:int} */
    public function summary(): array
    {
        $latestConsent = $this->latestConsentSql();
        $row = $this->pdo->query("SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN COALESCE({$latestConsent},0)=1 THEN 1 ELSE 0 END),0) AS subscribed,
                COALESCE(SUM(CASE WHEN EXISTS(SELECT 1 FROM oauth_identities identity WHERE identity.user_id=u.id AND identity.provider='google') THEN 1 ELSE 0 END),0) AS google,
                COALESCE(SUM(CASE WHEN EXISTS(SELECT 1 FROM orders customer_order WHERE customer_order.user_id=u.id) THEN 1 ELSE 0 END),0) AS with_orders
            FROM users u WHERE u.role='customer'")->fetch();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'subscribed' => (int) ($row['subscribed'] ?? 0),
            'google' => (int) ($row['google'] ?? 0),
            'with_orders' => (int) ($row['with_orders'] ?? 0),
        ];
    }

    /**
     * @return array{customer:array<string,mixed>,discounts:list<array<string,mixed>>,orders:array{items:list<array<string,mixed>>,page:int,per_page:int,total:int,total_pages:int,from:int,to:int}}|null
     */
    public function detail(int $customerId, int $orderPage = 1, int $ordersPerPage = 6): ?array
    {
        $latestConsent = $this->latestConsentSql();
        $statement = $this->pdo->prepare("SELECT u.id,u.email,u.first_name,u.last_name,u.phone,u.status,u.email_verified_at,u.created_at,u.updated_at,u.last_login_at,u.blocked_reason,u.blocked_at,u.blocked_by_user_id,
                CASE WHEN EXISTS(SELECT 1 FROM oauth_identities identity WHERE identity.user_id=u.id AND identity.provider='google') THEN 1 ELSE 0 END AS uses_google,
                COALESCE({$latestConsent},0) AS is_subscribed,
                (SELECT COUNT(*) FROM orders customer_order WHERE customer_order.user_id=u.id) AS order_count,
                COALESCE((SELECT SUM(customer_order.total_bani) FROM orders customer_order WHERE customer_order.user_id=u.id AND customer_order.status NOT IN ('cancelled','returned')),0) AS total_spent_bani,
                COALESCE((SELECT AVG(customer_order.total_bani) FROM orders customer_order WHERE customer_order.user_id=u.id AND customer_order.status NOT IN ('cancelled','returned')),0) AS average_order_bani,
                (SELECT MIN(customer_order.created_at) FROM orders customer_order WHERE customer_order.user_id=u.id) AS first_order_at,
                (SELECT MAX(customer_order.created_at) FROM orders customer_order WHERE customer_order.user_id=u.id) AS last_order_at,
                (SELECT customer_order.shipping_address_json FROM orders customer_order WHERE customer_order.user_id=u.id ORDER BY customer_order.created_at DESC,customer_order.id DESC LIMIT 1) AS last_shipping_address_json
            FROM users u WHERE u.id=:id AND u.role='customer' LIMIT 1");
        $statement->execute(['id' => $customerId]);
        $customer = $statement->fetch();
        if (!is_array($customer)) {
            return null;
        }

        $customer['blocked_by_name'] = '';
        if ((int) ($customer['blocked_by_user_id'] ?? 0) > 0) {
            $blockingAdmin = $this->pdo->prepare('SELECT first_name,last_name FROM users WHERE id=:id LIMIT 1');
            $blockingAdmin->execute(['id' => (int) $customer['blocked_by_user_id']]);
            $admin = $blockingAdmin->fetch();
            if (is_array($admin)) {
                $customer['blocked_by_name'] = trim((string) $admin['first_name'] . ' ' . (string) $admin['last_name']);
            }
        }

        $customer['last_shipping_address'] = $this->decodeJson((string) ($customer['last_shipping_address_json'] ?? ''));
        unset($customer['last_shipping_address_json']);
        $ordersPerPage = max(1, min(20, $ordersPerPage));
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id=:id');
        $count->execute(['id' => $customerId]);
        $total = (int) $count->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $ordersPerPage));
        $orderPage = max(1, min($orderPage, $totalPages));
        $offset = ($orderPage - 1) * $ordersPerPage;

        $orders = $this->pdo->prepare("SELECT o.id,o.order_number,o.total_bani,o.currency,o.status,o.payment_status,o.payment_method,o.created_at,
                (SELECT COUNT(*) FROM order_items item WHERE item.order_id=o.id) AS item_count,
                COALESCE((SELECT SUM(item.quantity) FROM order_items item WHERE item.order_id=o.id),0) AS product_quantity,
                COALESCE((SELECT GROUP_CONCAT(item.product_name) FROM order_items item WHERE item.order_id=o.id),'') AS product_names
            FROM orders o WHERE o.user_id=:id ORDER BY o.created_at DESC,o.id DESC LIMIT :limit OFFSET :offset");
        $orders->bindValue(':id', $customerId, PDO::PARAM_INT);
        $orders->bindValue(':limit', $ordersPerPage, PDO::PARAM_INT);
        $orders->bindValue(':offset', $offset, PDO::PARAM_INT);
        $orders->execute();

        $discounts = $this->pdo->prepare("SELECT d.*,
                CASE
                    WHEN d.scope='product' THEN (SELECT p.name FROM products p WHERE p.id=d.target_id)
                    WHEN d.scope='category' THEN (SELECT c.name FROM categories c WHERE c.id=d.target_id)
                    ELSE NULL
                END AS target_name
            FROM customer_discounts d
            WHERE d.user_id=:id AND d.status='active' AND d.deleted_at IS NULL
            ORDER BY d.created_at DESC,d.id DESC");
        $discounts->execute(['id' => $customerId]);

        return [
            'customer' => $customer,
            'discounts' => $discounts->fetchAll(),
            'orders' => [
                'items' => $orders->fetchAll(),
                'page' => $orderPage,
                'per_page' => $ordersPerPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => min($offset + $ordersPerPage, $total),
            ],
        ];
    }

    public function updateAccountStatus(int $customerId, string $action, string $reason, int $adminUserId): string
    {
        $statement = $this->pdo->prepare("SELECT id,status,first_name,last_name FROM users WHERE id=:id AND role='customer' LIMIT 1");
        $statement->execute(['id' => $customerId]);
        $customer = $statement->fetch();
        if (!is_array($customer)) {
            throw new \RuntimeException('Clientul solicitat nu a fost găsit.');
        }

        $name = trim((string) $customer['first_name'] . ' ' . (string) $customer['last_name']) ?: 'Clientul';
        if ($action === 'block') {
            $reason = trim($reason);
            if (mb_strlen($reason) < 3 || mb_strlen($reason) > 300) {
                throw new \RuntimeException('Scrie un motiv clar, între 3 și 300 de caractere.');
            }
            $update = $this->pdo->prepare("UPDATE users SET status='blocked',blocked_reason=:reason,blocked_at=:now,blocked_by_user_id=:admin WHERE id=:id AND role='customer'");
            $update->execute(['reason' => $reason, 'now' => gmdate('c'), 'admin' => $adminUserId, 'id' => $customerId]);
            return 'Contul lui ' . $name . ' a fost dezactivat.';
        }
        if ($action === 'activate') {
            $update = $this->pdo->prepare("UPDATE users SET status='active',blocked_reason=NULL,blocked_at=NULL,blocked_by_user_id=NULL WHERE id=:id AND role='customer'");
            $update->execute(['id' => $customerId]);
            return 'Contul lui ' . $name . ' a fost reactivat.';
        }

        throw new \RuntimeException('Acțiunea pentru cont nu este validă.');
    }

    /** @return array{products:list<array<string,mixed>>,categories:list<array<string,mixed>>} */
    public function discountCatalog(): array
    {
        $products = $this->pdo->query("SELECT p.id,p.name,p.slug,p.primary_sku,p.short_description,p.brand,p.seo_title,p.meta_description,c.name AS category_name,
                COALESCE((SELECT m.public_path FROM product_images pi JOIN media m ON m.id=pi.media_id WHERE pi.product_id=p.id AND m.public_path IS NOT NULL ORDER BY pi.position,pi.media_id LIMIT 1),'') AS image_path
            FROM products p LEFT JOIN categories c ON c.id=p.primary_category_id
            WHERE p.status='published' ORDER BY p.name,p.id")->fetchAll();
        $variantSearch = $this->pdo->query("SELECT product_id,sku,option_summary FROM product_variants WHERE status='active' ORDER BY product_id,is_default DESC,id")->fetchAll();
        $variantSkus = [];
        $variantTerms = [];
        foreach ($variantSearch as $variant) {
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
    public function createDiscount(int $customerId, array $data, int $adminUserId): string
    {
        $customer = $this->pdo->prepare("SELECT id FROM users WHERE id=:id AND role='customer' LIMIT 1");
        $customer->execute(['id' => $customerId]);
        if ($customer->fetchColumn() === false) {
            throw new \RuntimeException('Clientul solicitat nu a fost găsit.');
        }

        $scope = (string) ($data['scope'] ?? '');
        $valueType = (string) ($data['value_type'] ?? '');
        if (!in_array($scope, ['order', 'category', 'product', 'shipping'], true)) {
            throw new \RuntimeException('Alege unde se aplică reducerea.');
        }
        if (!in_array($valueType, ['percent', 'fixed', 'free'], true) || ($valueType === 'free' && $scope !== 'shipping')) {
            throw new \RuntimeException('Alege un tip de reducere potrivit.');
        }

        $targetId = null;
        if (in_array($scope, ['product', 'category'], true)) {
            $targetId = (int) ($data['target_id'] ?? 0);
            $table = $scope === 'product' ? 'products' : 'categories';
            $target = $this->pdo->prepare("SELECT id FROM {$table} WHERE id=:id AND status='published' LIMIT 1");
            $target->execute(['id' => $targetId]);
            if ($targetId < 1 || $target->fetchColumn() === false) {
                throw new \RuntimeException($scope === 'product' ? 'Alege produsul care primește reducerea.' : 'Alege categoria care primește reducerea.');
            }
        }

        if ($valueType === 'percent') {
            $normalized = str_replace(',', '.', trim((string) ($data['value'] ?? '')));
            if (!is_numeric($normalized) || (float) $normalized <= 0 || (float) $normalized > 100) {
                throw new \RuntimeException('Procentul trebuie să fie între 0,01 și 100.');
            }
            $value = (int) round((float) $normalized * 100);
        } elseif ($valueType === 'fixed') {
            $value = Money::parse((string) ($data['value'] ?? ''));
            if ($value < 1) {
                throw new \RuntimeException('Valoarea reducerii trebuie să fie mai mare decât zero.');
            }
        } else {
            $value = 0;
        }

        $startsAt = null;
        $endsAt = null;
        if (($data['validity'] ?? 'always') === 'period') {
            $startsAt = $this->localInputToUtc((string) ($data['starts_at'] ?? ''));
            $endsAt = $this->localInputToUtc((string) ($data['ends_at'] ?? ''));
            if ($startsAt === null || $endsAt === null) {
                throw new \RuntimeException('Completează începutul și sfârșitul perioadei.');
            }
            if (strtotime($endsAt) <= strtotime($startsAt)) {
                throw new \RuntimeException('Data de final trebuie să fie după data de început.');
            }
        }

        $now = gmdate('c');
        $statement = $this->pdo->prepare("INSERT INTO customer_discounts (user_id,scope,value_type,value,target_id,starts_at,ends_at,status,created_by_user_id,created_at,updated_at,deleted_at)
            VALUES (:user,:scope,:value_type,:value,:target,:starts,:ends,'active',:admin,:now,:now,NULL)");
        $statement->execute(['user' => $customerId, 'scope' => $scope, 'value_type' => $valueType, 'value' => $value, 'target' => $targetId, 'starts' => $startsAt, 'ends' => $endsAt, 'admin' => $adminUserId ?: null, 'now' => $now]);

        return 'Reducerea personalizată a fost activată pentru acest client.';
    }

    public function deleteDiscount(int $customerId, int $discountId): string
    {
        $statement = $this->pdo->prepare("UPDATE customer_discounts SET status='inactive',deleted_at=:now,updated_at=:now WHERE id=:id AND user_id=:user AND status='active'");
        $statement->execute(['now' => gmdate('c'), 'id' => $discountId, 'user' => $customerId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Reducerea nu mai există sau a fost deja ștearsă.');
        }
        return 'Reducerea personalizată a fost ștearsă.';
    }

    private function localInputToUtc(string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('Europe/Bucharest')))->setTimezone(new \DateTimeZone('UTC'))->format('c');
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{0:string,1:array<string,string>} */
    private function searchWhere(string $search): array
    {
        $normalized = $this->normalize(trim($search));
        if ($normalized === '') {
            return ['', []];
        }

        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopWords = ['a', 'al', 'ale', 'cu', 'de', 'din', 'in', 'la', 'si', 'un', 'client', 'clienta', 'clienti', 'cont', 'conturi'];
        $tokens = array_slice(array_values(array_unique(array_filter(
            $tokens,
            static fn (string $token): bool => strlen($token) > 1 && !in_array($token, $stopWords, true),
        ))), 0, 8);
        if ($tokens === []) {
            return ['', []];
        }

        $where = [];
        $parameters = [];
        foreach ($tokens as $index => $token) {
            if (in_array($token, ['google', 'gmail'], true)) {
                $where[] = "EXISTS(SELECT 1 FROM oauth_identities identity WHERE identity.user_id=u.id AND identity.provider='google')";
                continue;
            }
            if (in_array($token, ['lamariuca', 'mariuca', 'parola', 'local', 'clasic'], true)) {
                $where[] = "NOT EXISTS(SELECT 1 FROM oauth_identities identity WHERE identity.user_id=u.id AND identity.provider='google')";
                continue;
            }
            if (in_array($token, ['abonat', 'abonata', 'abonati', 'newsletter', 'noutati'], true)) {
                $where[] = 'COALESCE(' . $this->latestConsentSql() . ',0)=1';
                continue;
            }
            if (in_array($token, ['neabonat', 'neabonata', 'dezabonat', 'dezabonata'], true)) {
                $where[] = 'COALESCE(' . $this->latestConsentSql() . ',0)=0';
                continue;
            }
            $status = match ($token) {
                'activ', 'activa' => 'active',
                'blocat', 'blocata' => 'blocked',
                'asteptare', 'neconfirmat', 'neconfirmata' => 'pending',
                default => null,
            };
            if ($status !== null) {
                $placeholder = ':status_' . $index;
                $where[] = 'u.status=' . $placeholder;
                $parameters[$placeholder] = $status;
                continue;
            }

            $clauses = [];
            foreach (['u.first_name', 'u.last_name', 'u.email', 'u.phone', 'u.created_at'] as $columnIndex => $column) {
                $placeholder = ':customer_' . $index . '_' . $columnIndex;
                $clauses[] = $this->normalizedColumn($column) . ' LIKE ' . $placeholder;
                $parameters[$placeholder] = '%' . $token . '%';
            }
            $orderClauses = [];
            foreach (['search_order.order_number', 'search_order.email', 'search_order.phone', 'search_order.status', 'search_order.payment_status'] as $columnIndex => $column) {
                $orderPlaceholder = ':customer_order_' . $index . '_' . $columnIndex;
                $orderClauses[] = $this->normalizedColumn($column) . ' LIKE ' . $orderPlaceholder;
                $parameters[$orderPlaceholder] = '%' . $token . '%';
            }
            $orderExpression = implode(' OR ', $orderClauses);
            $clauses[] = 'EXISTS(SELECT 1 FROM orders search_order WHERE search_order.user_id=u.id AND (' . $orderExpression . '))';
            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }

        return [' AND ' . implode(' AND ', $where), $parameters];
    }

    private function latestConsentSql(): string
    {
        return "(SELECT consent_order.marketing_consent FROM orders consent_order WHERE consent_order.user_id=u.id ORDER BY consent_order.created_at DESC,consent_order.id DESC LIMIT 1)";
    }

    private function normalizedColumn(string $column): string
    {
        $expression = "LOWER(COALESCE({$column},''))";
        foreach (['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'Ă' => 'a', 'Â' => 'a', 'Î' => 'i', 'Ș' => 's', 'Ş' => 's', 'Ț' => 't', 'Ţ' => 't'] as $from => $to) {
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

    /** @return array<string,mixed> */
    private function decodeJson(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
