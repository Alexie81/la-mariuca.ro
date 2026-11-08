<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use PDO;
use RuntimeException;

final class OrderService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        return $this->pdo->query("SELECT * FROM orders ORDER BY id DESC LIMIT {$limit}")->fetchAll();
    }

    /**
     * @return array{
     *     items:list<array<string, mixed>>,
     *     page:int,
     *     per_page:int,
     *     total:int,
     *     total_pages:int,
     *     from:int,
     *     to:int
     * }
     */
    public function paginateForAdmin(
        int $page,
        int $perPage = 15,
        string $search = '',
        ?string $status = null,
    ): array
    {
        $perPage = max(1, min(50, $perPage));
        [$whereSql, $parameters] = $this->adminSearchWhere($search, $status);

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM orders o' . $whereSql);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        $statement = $this->pdo->prepare(
            'SELECT o.id,o.order_number,o.email,o.first_name,o.last_name,o.phone,o.total_bani,o.currency,o.status,o.payment_status,o.payment_method,o.created_at,'
            . "EXISTS(SELECT 1 FROM order_cancellation_requests cr WHERE cr.order_id=o.id AND cr.status='pending') AS cancellation_requested "
            . 'FROM orders o' . $whereSql . ' ORDER BY o.created_at DESC,o.id DESC LIMIT :limit OFFSET :offset'
        );
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

    /**
     * The admin search deliberately uses a strict AND between meaningful words and
     * an OR only between fields/synonyms. This keeps results on-topic while still
     * understanding the Romanian labels displayed in the interface.
     *
     * @return array{0:string,1:array<string, string|int>}
     */
    private function adminSearchWhere(string $search, ?string $status): array
    {
        $where = [];
        $parameters = [];
        $allowedStatuses = ['new', 'confirmed', 'preparing', 'shipped', 'delivered', 'cancelled', 'returned', 'manual_review'];
        if ($status === 'cancellation_requested') {
            $where[] = "EXISTS(SELECT 1 FROM order_cancellation_requests cr WHERE cr.order_id=o.id AND cr.status='pending')";
        }
        if ($status !== null && in_array($status, $allowedStatuses, true)) {
            $where[] = 'o.status = :filter_status';
            $parameters[':filter_status'] = $status;
        }

        $search = trim($search);
        if ($search !== '') {
            $normalized = $this->normalizeAdminSearch($search);
            $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $stopWords = ['a', 'ai', 'ale', 'cu', 'de', 'din', 'dupa', 'in', 'la', 'o', 'pe', 'pentru', 'si', 'un'];
            $tokens = array_values(array_filter(
                $tokens,
                static fn (string $token): bool => strlen($token) > 1 && !in_array($token, $stopWords, true),
            ));
            if ($tokens === []) {
                $tokens = [$normalized];
            }
            $tokens = array_slice(array_values(array_unique($tokens)), 0, 8);
            if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $normalized, $dateParts) === 1) {
                $tokens = [sprintf('%04d-%02d-%02d', (int) $dateParts[3], (int) $dateParts[2], (int) $dateParts[1])];
            }

            $rawWords = preg_split('/\s+/u', mb_strtolower($search, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $rawByNormalized = [];
            foreach ($rawWords as $rawWord) {
                $rawByNormalized[$this->normalizeAdminSearch($rawWord)] = $rawWord;
            }
            foreach ($tokens as $tokenIndex => $token) {
                $aliases = $this->adminSearchAliases($token);
                if (isset($rawByNormalized[$token]) && $rawByNormalized[$token] !== $token) {
                    $aliases[] = $rawByNormalized[$token];
                }
                $aliases = array_values(array_unique(array_filter($aliases)));
                $tokenClauses = [];

                foreach ($aliases as $aliasIndex => $alias) {
                    $needle = '%' . $alias . '%';
                    $columns = [
                        'order_number', 'email', 'first_name', 'last_name', 'phone', 'status',
                        'payment_status', 'payment_method', 'awb', 'created_at', 'shipping_address_json',
                        'billing_address_json', 'internal_notes',
                    ];
                    foreach ($columns as $columnIndex => $column) {
                        $placeholder = ':search_' . $tokenIndex . '_' . $aliasIndex . '_' . $columnIndex;
                        $tokenClauses[] = "LOWER(COALESCE(o.{$column}, '')) LIKE {$placeholder}";
                        $parameters[$placeholder] = $needle;
                    }

                    $itemName = ':item_name_' . $tokenIndex . '_' . $aliasIndex;
                    $itemSku = ':item_sku_' . $tokenIndex . '_' . $aliasIndex;
                    $itemVariant = ':item_variant_' . $tokenIndex . '_' . $aliasIndex;
                    $tokenClauses[] = "EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND (LOWER(oi.product_name) LIKE {$itemName} OR LOWER(oi.sku) LIKE {$itemSku} OR LOWER(oi.variant_summary) LIKE {$itemVariant}))";
                    $parameters[$itemName] = $needle;
                    $parameters[$itemSku] = $needle;
                    $parameters[$itemVariant] = $needle;
                }

                if (preg_match('/^\d+(?:[.,]\d{1,2})?$/', $token) === 1) {
                    $amount = (int) round((float) str_replace(',', '.', $token) * 100);
                    $amountName = ':amount_' . $tokenIndex;
                    $tokenClauses[] = "o.total_bani = {$amountName}";
                    $parameters[$amountName] = $amount;
                }

                $where[] = '(' . implode(' OR ', $tokenClauses) . ')';
            }

        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $parameters];
    }

    /** @return list<string> */
    private function adminSearchAliases(string $token): array
    {
        $semanticAliases = [
            'nou' => ['new'], 'noua' => ['new'], 'noi' => ['new'],
            'confirmat' => ['confirmed'], 'confirmata' => ['confirmed'],
            'pregatit' => ['preparing'], 'pregatita' => ['preparing'], 'pregatire' => ['preparing'],
            'expediat' => ['shipped'], 'expediata' => ['shipped'], 'trimis' => ['shipped'], 'trimisa' => ['shipped'],
            'livrat' => ['delivered'], 'livrata' => ['delivered'],
            'anulat' => ['cancelled'], 'anulata' => ['cancelled'],
            'returnat' => ['returned'], 'returnata' => ['returned'],
            'verificare' => ['manual_review'], 'manual' => ['manual_review'], 'manuala' => ['manual_review'],
            'asteptare' => ['pending'], 'neplatit' => ['pending'], 'neplatita' => ['pending'],
            'platit' => ['paid'], 'platita' => ['paid'], 'achitat' => ['paid'], 'achitata' => ['paid'],
            'esuat' => ['failed'], 'esuata' => ['failed'], 'refuzat' => ['failed'], 'refuzata' => ['failed'],
            'rambursat' => ['refunded', 'partially_refunded'], 'rambursata' => ['refunded', 'partially_refunded'],
            'partial' => ['partially_refunded'], 'partiala' => ['partially_refunded'],
            'ramburs' => ['cod'], 'cash' => ['cod'], 'numerar' => ['cod'],
            'card' => ['stripe'], 'online' => ['stripe'],
        ];

        return array_values(array_unique(array_merge([$token], $semanticAliases[$token] ?? [])));
    }

    private function normalizeAdminSearch(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return strtr($value, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findForAdmin(int $orderId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $orderId]);
        $order = $statement->fetch();
        if (!is_array($order)) {
            return null;
        }

        $items = $this->pdo->prepare(
            'SELECT item.*,product.slug AS product_slug,product.status AS product_status,'
            . 'COALESCE(variant_media.public_path,'
            . ' (SELECT product_media.public_path FROM product_images pi JOIN media product_media ON product_media.id=pi.media_id'
            . '  WHERE pi.product_id=item.product_id AND product_media.public_path IS NOT NULL ORDER BY pi.position,pi.media_id LIMIT 1),'
            . ' (SELECT category_media.public_path FROM product_categories image_pc'
            . '  JOIN categories image_category ON image_category.id=image_pc.category_id'
            . '  JOIN media category_media ON category_media.id=image_category.image_media_id'
            . '  WHERE image_pc.product_id=item.product_id AND category_media.public_path IS NOT NULL'
            . '  ORDER BY CASE WHEN image_category.parent_id IS NULL THEN 0 ELSE 1 END,image_category.position,image_category.id LIMIT 1)'
            . ') AS image_path,'
            . 'COALESCE((SELECT SUM(return_item.quantity) FROM order_return_items return_item WHERE return_item.order_item_id=item.id),0) AS returned_quantity '
            . 'FROM order_items item '
            . 'LEFT JOIN products product ON product.id=item.product_id '
            . 'LEFT JOIN product_variants variant ON variant.id=item.variant_id '
            . 'LEFT JOIN media variant_media ON variant_media.id=variant.image_media_id '
            . 'WHERE item.order_id = :id ORDER BY item.id'
        );
        $items->execute(['id' => $orderId]);
        $order['items'] = $items->fetchAll();
        foreach ($order['items'] as &$item) {
            $item['personalization'] = $this->decodeJson((string) $item['personalization_json']);
            $item['returned_quantity'] = (int) $item['returned_quantity'];
            $item['returnable_quantity'] = max(0, (int) $item['quantity'] - $item['returned_quantity']);
        }
        unset($item);

        $payments = $this->pdo->prepare('SELECT provider,provider_payment_id,amount_bani,refunded_bani,currency,status,created_at,updated_at FROM payments WHERE order_id = :id ORDER BY id DESC');
        $payments->execute(['id' => $orderId]);
        $order['payments'] = $payments->fetchAll();
        $order['payment_fees'] = \App\Modules\Payments\PaymentFeeService::snapshot($order);
        $order['shipping_address'] = $this->decodeJson((string) $order['shipping_address_json']);
        $order['billing_address'] = $this->decodeJson((string) $order['billing_address_json']);
        $order['company'] = $this->decodeJson((string) $order['company_json']);

        $returns = $this->pdo->prepare(
            'SELECT r.*,u.first_name AS actor_first_name,u.last_name AS actor_last_name '
            . 'FROM order_returns r LEFT JOIN users u ON u.id=r.actor_user_id WHERE r.order_id=:id ORDER BY r.id DESC'
        );
        $returns->execute(['id' => $orderId]);
        $order['returns'] = $returns->fetchAll();
        $returnItems = $this->pdo->prepare(
            'SELECT ri.*,oi.product_name,oi.variant_summary,oi.sku FROM order_return_items ri '
            . 'JOIN order_items oi ON oi.id=ri.order_item_id WHERE ri.return_id=:return ORDER BY ri.id'
        );
        foreach ($order['returns'] as &$return) {
            $returnItems->execute(['return' => $return['id']]);
            $return['items'] = $returnItems->fetchAll();
        }
        unset($return);

        $history = $this->pdo->prepare(
            "SELECT log.*,u.first_name AS actor_first_name,u.last_name AS actor_last_name FROM audit_log log "
            . "LEFT JOIN users u ON u.id=log.actor_user_id WHERE log.entity_type='order' AND log.entity_id=:id ORDER BY log.id ASC LIMIT 40"
        );
        $history->execute(['id' => $orderId]);
        $order['history'] = $history->fetchAll();
        foreach ($order['history'] as &$event) {
            $event['context'] = $this->decodeJson((string) $event['context_json']);
        }
        unset($event);
        $order['available_actions'] = $this->availableActions((string) $order['status']);
        $cancellation = $this->pdo->prepare('SELECT cr.id,cr.order_id,cr.reason,cr.order_status_at_request,cr.created_at,cr.status,cr.decision_reason,cr.resolved_at,cr.resolved_by,u.first_name AS resolver_first_name,u.last_name AS resolver_last_name FROM order_cancellation_requests cr LEFT JOIN users u ON u.id=cr.resolved_by WHERE cr.order_id=:id');
        $cancellation->execute(['id'=>$orderId]);
        $order['cancellation_request'] = $cancellation->fetch() ?: null;
        $order['can_accept_cancellation'] = is_array($order['cancellation_request']) && $order['cancellation_request']['status'] === 'pending'
            && in_array($order['status'], ['new','confirmed','preparing','cancelled'], true);
        $order['can_return'] = in_array((string) $order['status'], ['shipped', 'delivered', 'returned'], true)
            && array_sum(array_column($order['items'], 'returnable_quantity')) > 0;

        return $order;
    }

    /** @return array<string, mixed>|null */
    public function byPublicToken(string $token): ?array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT * FROM orders WHERE public_token_hash = :token');
        $statement->execute(['token' => hash('sha256', $token)]);
        $order = $statement->fetch();
        return is_array($order) ? $this->hydratePublicOrder($order) : null;
    }

    /** Caller must authorize access to this order before rendering these details.
     * @return array<string,mixed>|null
     */
    public function detailsForTracking(int $orderId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orders WHERE id=:id');
        $statement->execute(['id' => $orderId]);
        $order = $statement->fetch();
        return is_array($order) ? $this->hydratePublicOrder($order) : null;
    }

    /** @param array<string,mixed> $order @return array<string,mixed> */
    private function hydratePublicOrder(array $order): array
    {
        $order['payment_fees'] = \App\Modules\Payments\PaymentFeeService::snapshot($order);
        $items = $this->pdo->prepare('SELECT item.*,product.slug AS product_slug,
                COALESCE(variant_media.public_path,
                    (SELECT product_media.public_path FROM product_images pi
                     JOIN media product_media ON product_media.id=pi.media_id
                     WHERE pi.product_id=item.product_id AND product_media.public_path IS NOT NULL
                     ORDER BY pi.position,pi.media_id LIMIT 1),
                    (SELECT category_media.public_path FROM product_categories image_pc
                     JOIN categories image_category ON image_category.id=image_pc.category_id
                     JOIN media category_media ON category_media.id=image_category.image_media_id
                     WHERE image_pc.product_id=item.product_id AND category_media.public_path IS NOT NULL
                     ORDER BY CASE WHEN image_category.parent_id IS NULL THEN 0 ELSE 1 END,image_category.position,image_category.id LIMIT 1)
                ) AS image_path
            FROM order_items item
            LEFT JOIN products product ON product.id=item.product_id AND product.status=\'published\'
            LEFT JOIN product_variants variant ON variant.id=item.variant_id
            LEFT JOIN media variant_media ON variant_media.id=variant.image_media_id
            WHERE item.order_id=:id ORDER BY item.id');
        $items->execute(['id' => $order['id']]);
        $order['items'] = $items->fetchAll();
        foreach ($order['items'] as &$item) {
            $item['personalization'] = $this->decodeJson((string) $item['personalization_json']);
        }
        unset($item);
        $payment = $this->pdo->prepare('SELECT status FROM payments WHERE order_id=:order AND provider=:provider ORDER BY id DESC LIMIT 1');
        $payment->execute(['order' => $order['id'], 'provider' => $order['payment_method']]);
        $order['latest_payment_status'] = $payment->fetchColumn() ?: null;
        $order['shipping_estimate'] = $this->decodeJson((string) ($order['shipping_estimate_json'] ?? ''));
        if ($order['shipping_estimate'] === [] && !empty($order['shipping_method_id'])) {
            // Historical orders have no snapshot: use known method settings, never an invented deadline.
            $estimate = $this->pdo->prepare('SELECT estimated_min_days,estimated_max_days,type FROM shipping_methods WHERE id=:id');
            $estimate->execute(['id' => $order['shipping_method_id']]);
            $method = $estimate->fetch();
            if (is_array($method)) {
                $order['shipping_estimate'] = \App\Modules\Shipping\StoredDeliveryEstimate::snapshot($method);
                $order['shipping_estimate']['source'] = 'current_shipping_method';
            }
        }
        $order['shipping_address'] = $this->decodeJson((string) $order['shipping_address_json']);
        $order['billing_address'] = $this->decodeJson((string) $order['billing_address_json']);
        $order['company'] = $this->decodeJson((string) $order['company_json']);
        return $order;
    }

    public function transition(int $orderId, string $action, ?string $awb, int $actorId, string $reason = '', bool $notifyCustomer = false): void
    {
        $allowed = [
            'confirm' => ['from' => ['new'], 'to' => 'confirmed'],
            'prepare' => ['from' => ['confirmed'], 'to' => 'preparing'],
            'ship' => ['from' => ['confirmed', 'preparing'], 'to' => 'shipped'],
            'deliver' => ['from' => ['shipped'], 'to' => 'delivered'],
            'cancel' => ['from' => ['new', 'confirmed', 'preparing'], 'to' => 'cancelled'],
        ];
        if (!isset($allowed[$action])) {
            throw new RuntimeException('Acțiune de comandă invalidă.');
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            $select = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id' . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $select->execute(['id' => $orderId]);
            $order = $select->fetch();
            if (!is_array($order) || !in_array($order['status'], $allowed[$action]['from'], true)) {
                throw new RuntimeException('Tranziția nu este permisă din starea curentă.');
            }
            $now = gmdate('c');
            if ($action === 'ship') {
                $reservations = $this->pdo->prepare("SELECT * FROM inventory_reservations WHERE order_id = :id AND status = 'active'");
                $reservations->execute(['id' => $orderId]);
                foreach ($reservations->fetchAll() as $reservation) {
                    $update = $this->pdo->prepare('UPDATE inventory SET physical_quantity = physical_quantity - :physical_delta, reserved_quantity = reserved_quantity - :reserved_delta, updated_at = :now WHERE variant_id = :variant AND reserved_quantity >= :reserved_check AND physical_quantity >= :physical_check');
                    $update->bindValue(':physical_delta', (int) $reservation['quantity'], PDO::PARAM_INT);
                    $update->bindValue(':reserved_delta', (int) $reservation['quantity'], PDO::PARAM_INT);
                    $update->bindValue(':reserved_check', (int) $reservation['quantity'], PDO::PARAM_INT);
                    $update->bindValue(':physical_check', (int) $reservation['quantity'], PDO::PARAM_INT);
                    $update->bindValue(':now', $now);
                    $update->bindValue(':variant', (int) $reservation['variant_id'], PDO::PARAM_INT);
                    $update->execute();
                    if ($update->rowCount() !== 1) {
                        throw new RuntimeException('Stoc inconsistent; expedierea necesită verificare manuală.');
                    }
                    $this->pdo->prepare("UPDATE inventory_reservations SET status = 'consumed', updated_at = :now WHERE id = :id AND status = 'active'")->execute(['now' => $now, 'id' => $reservation['id']]);
                    $this->pdo->prepare("INSERT INTO inventory_movements (variant_id, type, physical_delta, reserved_delta, reason, reference_type, reference_id, actor_user_id, created_at) VALUES (:variant, 'ship', :physical, :reserved, 'Expediere comandă', 'order', :order, :actor, :now)")->execute([
                        'variant' => $reservation['variant_id'], 'physical' => -((int) $reservation['quantity']), 'reserved' => -((int) $reservation['quantity']), 'order' => $orderId, 'actor' => $actorId, 'now' => $now,
                    ]);
                }
            } elseif ($action === 'cancel') {
                $reservations = $this->pdo->prepare("SELECT * FROM inventory_reservations WHERE order_id = :id AND status = 'active'");
                $reservations->execute(['id' => $orderId]);
                foreach ($reservations->fetchAll() as $reservation) {
                    $release = $this->pdo->prepare('UPDATE inventory SET reserved_quantity = reserved_quantity - :quantity_delta, updated_at = :now WHERE variant_id = :variant AND reserved_quantity >= :quantity_check');
                    $release->bindValue(':quantity_delta', (int) $reservation['quantity'], PDO::PARAM_INT);
                    $release->bindValue(':quantity_check', (int) $reservation['quantity'], PDO::PARAM_INT);
                    $release->bindValue(':now', $now);
                    $release->bindValue(':variant', (int) $reservation['variant_id'], PDO::PARAM_INT);
                    $release->execute();
                    if ($release->rowCount() !== 1) {
                        throw new RuntimeException('Stoc inconsistent; anularea necesită verificarea rezervărilor.');
                    }
                    $this->pdo->prepare("UPDATE inventory_reservations SET status = 'released', updated_at = :now WHERE id = :id AND status = 'active'")->execute(['now' => $now, 'id' => $reservation['id']]);
                    $this->pdo->prepare("INSERT INTO inventory_movements (variant_id, type, physical_delta, reserved_delta, reason, reference_type, reference_id, actor_user_id, created_at) VALUES (:variant, 'release', 0, :reserved, 'Anulare comandă', 'order', :order, :actor, :now)")->execute([
                        'variant' => $reservation['variant_id'], 'reserved' => -((int) $reservation['quantity']), 'order' => $orderId, 'actor' => $actorId, 'now' => $now,
                    ]);
                }
            }
            $extra = $action === 'ship' ? ', shipped_at = :now2, awb = :awb' : ($action === 'cancel' ? ', cancelled_at = :now2' : '');
            $params = ['status' => $allowed[$action]['to'], 'now' => $now, 'id' => $orderId];
            if ($action === 'ship') {
                $params['now2'] = $now;
                $params['awb'] = trim((string) $awb) ?: null;
            } elseif ($action === 'cancel') {
                $params['now2'] = $now;
            }
            $this->pdo->prepare("UPDATE orders SET status = :status, updated_at = :now{$extra} WHERE id = :id")->execute($params);
            if ($action === 'confirm') {
                $this->pdo->prepare("UPDATE coupon_redemptions SET status='confirmed',updated_at=:now WHERE order_id=:order AND status='reserved'")
                    ->execute(['now' => $now, 'order' => $orderId]);
            } elseif ($action === 'cancel') {
                $this->pdo->prepare("UPDATE coupon_redemptions SET status='released',updated_at=:now WHERE order_id=:order AND status IN ('reserved','confirmed')")
                    ->execute(['now' => $now, 'order' => $orderId]);
            }
            $context = ['from' => $order['status'], 'to' => $allowed[$action]['to']];
            if (trim($reason) !== '') {
                $context['reason'] = mb_substr(trim($reason), 0, 500);
            }
            $this->pdo->prepare("INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id, context_json, created_at) VALUES (:actor, :action, 'order', :id, :context, :now)")->execute([
                'actor' => $actorId, 'action' => 'order.' . $action, 'id' => $orderId, 'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'now' => $now,
            ]);
            if ($notifyCustomer) {
                $this->queueStatusEmail($order, $allowed[$action]['to'], '', $now);
            }
            if ($ownsTransaction) { $this->pdo->commit(); }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param array<int,int|string> $quantities keyed by order item id
     */
    public function registerReturn(
        int $orderId,
        array $quantities,
        string $reason,
        string $notes,
        bool $restock,
        string $refundMode,
        int $refundBani,
        int $actorId,
        bool $notifyCustomer = false,
    ): int {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new RuntimeException('Spune pe scurt motivul returului.');
        }
        if (!in_array($refundMode, ['none', 'partial', 'full'], true)) {
            throw new RuntimeException('Opțiunea de rambursare nu este validă.');
        }

        $this->pdo->beginTransaction();
        try {
            $orderStatement = $this->pdo->prepare('SELECT * FROM orders WHERE id=:id');
            $orderStatement->execute(['id' => $orderId]);
            $order = $orderStatement->fetch();
            if (!is_array($order)) {
                throw new RuntimeException('Comanda nu a fost găsită.');
            }
            if (!in_array((string) $order['status'], ['shipped', 'delivered', 'returned'], true)) {
                throw new RuntimeException('Returul poate fi înregistrat după expedierea comenzii.');
            }

            $itemsStatement = $this->pdo->prepare(
                'SELECT item.*,COALESCE((SELECT SUM(return_item.quantity) FROM order_return_items return_item WHERE return_item.order_item_id=item.id),0) AS returned_quantity '
                . 'FROM order_items item WHERE item.order_id=:order ORDER BY item.id'
            );
            $itemsStatement->execute(['order' => $orderId]);
            $items = $itemsStatement->fetchAll();
            $selected = [];
            $remainingTotal = 0;
            $selectedTotal = 0;
            foreach ($items as $item) {
                $remaining = max(0, (int) $item['quantity'] - (int) $item['returned_quantity']);
                $remainingTotal += $remaining;
                $quantity = max(0, (int) ($quantities[(int) $item['id']] ?? 0));
                if ($quantity > $remaining) {
                    throw new RuntimeException('Cantitatea de retur depășește cantitatea disponibilă pentru ' . $item['product_name'] . '.');
                }
                if ($quantity > 0) {
                    $item['return_quantity'] = $quantity;
                    $selected[] = $item;
                    $selectedTotal += $quantity;
                }
            }
            if ($selected === []) {
                throw new RuntimeException('Selectează cel puțin un produs și o cantitate.');
            }

            $isFull = $selectedTotal === $remainingTotal;
            if ($refundMode === 'full' && !$isFull) {
                throw new RuntimeException('Rambursarea integrală este disponibilă când returnezi toate produsele rămase.');
            }
            $alreadyRefunded = (int) $this->pdo->query('SELECT COALESCE(MAX(refunded_bani),0) FROM payments WHERE order_id=' . $orderId)->fetchColumn();
            $refundable = max(0, (int) $order['total_bani'] - $alreadyRefunded);
            if ($refundMode === 'none') {
                $refundBani = 0;
            } elseif ($refundMode === 'full') {
                $refundBani = $refundable;
            } elseif ($refundBani <= 0 || $refundBani > $refundable) {
                throw new RuntimeException('Suma rambursată trebuie să fie mai mare decât zero și să nu depășească soldul comenzii.');
            }

            $now = gmdate('c');
            $returnStatement = $this->pdo->prepare(
                'INSERT INTO order_returns (order_id,return_type,reason,notes,restock,refund_mode,refund_bani,actor_user_id,created_at) '
                . 'VALUES (:order,:type,:reason,:notes,:restock,:refund_mode,:refund,:actor,:now)'
            );
            $returnStatement->execute([
                'order' => $orderId,
                'type' => $isFull ? 'full' : 'partial',
                'reason' => mb_substr($reason, 0, 500),
                'notes' => mb_substr(trim($notes), 0, 2000),
                'restock' => $restock ? 1 : 0,
                'refund_mode' => $refundMode,
                'refund' => $refundBani,
                'actor' => $actorId,
                'now' => $now,
            ]);
            $returnId = (int) $this->pdo->lastInsertId();
            $allocatedRefund = 0;
            $selectedValue = array_sum(array_map(static function (array $item): int {
                return (int) round(((int) $item['line_total_bani'] / max(1, (int) $item['quantity'])) * (int) $item['return_quantity']);
            }, $selected));

            foreach ($selected as $index => $item) {
                $lineRefund = 0;
                if ($refundBani > 0 && $selectedValue > 0) {
                    $lineValue = (int) round(((int) $item['line_total_bani'] / max(1, (int) $item['quantity'])) * (int) $item['return_quantity']);
                    $lineRefund = $index === array_key_last($selected)
                        ? $refundBani - $allocatedRefund
                        : min($refundBani - $allocatedRefund, (int) round($refundBani * ($lineValue / $selectedValue)));
                    $allocatedRefund += $lineRefund;
                }
                $this->pdo->prepare(
                    'INSERT INTO order_return_items (return_id,order_item_id,quantity,restocked_quantity,line_refund_bani) '
                    . 'VALUES (:return,:item,:quantity,:restocked,:refund)'
                )->execute([
                    'return' => $returnId,
                    'item' => $item['id'],
                    'quantity' => $item['return_quantity'],
                    'restocked' => $restock ? $item['return_quantity'] : 0,
                    'refund' => $lineRefund,
                ]);
                if ($restock && $item['variant_id'] !== null) {
                    $stock = $this->pdo->prepare('UPDATE inventory SET physical_quantity=physical_quantity+:quantity,updated_at=:now WHERE variant_id=:variant');
                    $stock->execute(['quantity' => $item['return_quantity'], 'now' => $now, 'variant' => $item['variant_id']]);
                    if ($stock->rowCount() !== 1) {
                        throw new RuntimeException('Stocul variantei ' . $item['sku'] . ' nu a putut fi refăcut.');
                    }
                    $this->pdo->prepare(
                        "INSERT INTO inventory_movements (variant_id,type,physical_delta,reserved_delta,reason,reference_type,reference_id,actor_user_id,created_at) "
                        . "VALUES (:variant,'return',:quantity,0,:reason,'order_return',:return,:actor,:now)"
                    )->execute([
                        'variant' => $item['variant_id'],
                        'quantity' => $item['return_quantity'],
                        'reason' => 'Retur ' . ((string) $order['order_number'] ?: '#' . $orderId),
                        'return' => $returnId,
                        'actor' => $actorId,
                        'now' => $now,
                    ]);
                }
            }

            if ($refundBani > 0) {
                $payment = $this->pdo->prepare('SELECT id,refunded_bani FROM payments WHERE order_id=:order ORDER BY id DESC LIMIT 1');
                $payment->execute(['order' => $orderId]);
                $paymentRow = $payment->fetch();
                $newRefunded = min((int) $order['total_bani'], $alreadyRefunded + $refundBani);
                $paymentStatus = $newRefunded >= (int) $order['total_bani'] ? 'refunded' : 'partially_refunded';
                if (is_array($paymentRow)) {
                    $this->pdo->prepare('UPDATE payments SET refunded_bani=:refunded,status=:status,updated_at=:now WHERE id=:id')->execute([
                        'refunded' => $newRefunded, 'status' => $paymentStatus, 'now' => $now, 'id' => $paymentRow['id'],
                    ]);
                }
                $this->pdo->prepare('UPDATE orders SET payment_status=:payment_status,updated_at=:now WHERE id=:id')->execute([
                    'payment_status' => $paymentStatus, 'now' => $now, 'id' => $orderId,
                ]);
            }

            if ($isFull) {
                $this->pdo->prepare("UPDATE orders SET status='returned',updated_at=:now WHERE id=:id")->execute(['now' => $now, 'id' => $orderId]);
            }
            $context = [
                'return_id' => $returnId,
                'type' => $isFull ? 'full' : 'partial',
                'items' => count($selected),
                'quantity' => $selectedTotal,
                'restock' => $restock,
                'refund_bani' => $refundBani,
                'reason' => $reason,
            ];
            $this->pdo->prepare("INSERT INTO audit_log (actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES (:actor,:action,'order',:order,:context,:now)")->execute([
                'actor' => $actorId,
                'action' => $isFull ? 'order.return_full' : 'order.return_partial',
                'order' => $orderId,
                'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'now' => $now,
            ]);
            if ($notifyCustomer) {
                $this->queueStatusEmail($order, $isFull ? 'returned' : (string) $order['status'], 'Retur înregistrat: ' . $reason, $now);
            }
            $this->pdo->commit();

            return $returnId;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function notifyCustomer(int $orderId, string $message, int $actorId): void
    {
        $message = trim($message);
        if ($message !== '' && mb_strlen($message) < 3) {
            throw new RuntimeException('Mesajul este prea scurt.');
        }
        $order = $this->findForAdmin($orderId);
        if ($order === null) {
            throw new RuntimeException('Comanda nu a fost găsită.');
        }
        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            $this->queueStatusEmail($order, (string) $order['status'], $message, $now);
            $this->pdo->prepare("INSERT INTO audit_log (actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES (:actor,'order.email_queued','order',:order,:context,:now)")->execute([
                'actor' => $actorId,
                'order' => $orderId,
                'context' => json_encode(['message' => mb_substr($message, 0, 1000)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'now' => $now,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array{action:string,status:string}> */
    private function availableActions(string $status): array
    {
        $actions = [
            'new' => [['action' => 'confirm', 'status' => 'confirmed'], ['action' => 'cancel', 'status' => 'cancelled']],
            'confirmed' => [['action' => 'prepare', 'status' => 'preparing'], ['action' => 'ship', 'status' => 'shipped'], ['action' => 'cancel', 'status' => 'cancelled']],
            'preparing' => [['action' => 'ship', 'status' => 'shipped'], ['action' => 'cancel', 'status' => 'cancelled']],
            'shipped' => [['action' => 'deliver', 'status' => 'delivered']],
        ];

        return $actions[$status] ?? [];
    }

    /** @param array<string,mixed> $order */
    private function queueStatusEmail(array $order, string $status, string $message, string $now): void
    {
        $payload = [
            'order_id' => (int) $order['id'],
            'order_number' => (string) ($order['order_number'] ?: '#' . $order['id']),
            'customer_name' => trim((string) $order['first_name'] . ' ' . (string) $order['last_name']),
            'status' => $status,
            'message' => mb_substr(trim($message), 0, 1000),
        ];
        $this->pdo->prepare(
            "INSERT INTO outbox_messages (message_type,recipient,payload_json,status,attempts,available_at,created_at) "
            . "VALUES ('order_status_update',:recipient,:payload,'pending',0,:now,:now)"
        )->execute([
            'recipient' => (string) $order['email'],
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'now' => $now,
        ]);
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $value): array
    {
        if ($value === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
