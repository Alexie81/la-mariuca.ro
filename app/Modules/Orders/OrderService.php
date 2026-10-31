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
            'SELECT o.id,o.order_number,o.email,o.first_name,o.last_name,o.phone,o.total_bani,o.currency,o.status,o.payment_status,o.payment_method,o.created_at '
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

        $items = $this->pdo->prepare('SELECT * FROM order_items WHERE order_id = :id ORDER BY id');
        $items->execute(['id' => $orderId]);
        $order['items'] = $items->fetchAll();

        $payments = $this->pdo->prepare('SELECT provider,provider_payment_id,amount_bani,refunded_bani,currency,status,created_at,updated_at FROM payments WHERE order_id = :id ORDER BY id DESC');
        $payments->execute(['id' => $orderId]);
        $order['payments'] = $payments->fetchAll();
        $order['shipping_address'] = $this->decodeJson((string) $order['shipping_address_json']);
        $order['billing_address'] = $this->decodeJson((string) $order['billing_address_json']);
        $order['company'] = $this->decodeJson((string) $order['company_json']);

        return $order;
    }

    /** @return array<string, mixed>|null */
    public function byPublicToken(string $token): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orders WHERE public_token_hash = :token');
        $statement->execute(['token' => hash('sha256', $token)]);
        $order = $statement->fetch();
        if (!is_array($order)) {
            return null;
        }
        $items = $this->pdo->prepare('SELECT * FROM order_items WHERE order_id = :id ORDER BY id');
        $items->execute(['id' => $order['id']]);
        $order['items'] = $items->fetchAll();
        return $order;
    }

    public function transition(int $orderId, string $action, ?string $awb, int $actorId): void
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
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id');
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
            $this->pdo->prepare("INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id, context_json, created_at) VALUES (:actor, :action, 'order', :id, :context, :now)")->execute([
                'actor' => $actorId, 'action' => 'order.' . $action, 'id' => $orderId, 'context' => json_encode(['from' => $order['status'], 'to' => $allowed[$action]['to']], JSON_THROW_ON_ERROR), 'now' => $now,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
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
