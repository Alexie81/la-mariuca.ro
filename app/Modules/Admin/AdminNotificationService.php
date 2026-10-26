<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use PDO;

final class AdminNotificationService
{
    private const DEFAULT_PREFERENCES = [
        'comenzi' => true,
        'plati' => true,
        'stoc' => true,
        'recenzii' => true,
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{items:list<array<string,mixed>>,page:int,per_page:int,total:int,total_pages:int,unread_total:int,counts:array<string,int>,preferences:array<string,bool>} */
    public function feed(int $adminUserId, int $page = 1, int $perPage = 15, string $type = 'toate', bool $unreadOnly = false): array
    {
        $preferences = $this->preferences($adminUserId);
        $events = $this->events($preferences);
        $read = $this->readMap($adminUserId);
        $dismissed = $this->dismissedMap($adminUserId);
        $events = array_values(array_filter(
            $events,
            static fn (array $event): bool => !isset($dismissed[(string) $event['key']])
        ));
        $counts = ['toate' => 0, 'comenzi' => 0, 'plati' => 0, 'stoc' => 0, 'recenzii' => 0];
        $unreadTotal = 0;

        foreach ($events as &$event) {
            $event['is_read'] = isset($read[(string) $event['key']]);
            ++$counts['toate'];
            ++$counts[(string) $event['type']];
            if (!$event['is_read']) {
                ++$unreadTotal;
            }
        }
        unset($event);

        $filtered = array_values(array_filter($events, static function (array $event) use ($type, $unreadOnly): bool {
            if ($type !== 'toate' && (string) $event['type'] !== $type) {
                return false;
            }

            return !$unreadOnly || !$event['is_read'];
        }));

        $perPage = max(5, min(30, $perPage));
        $total = count($filtered);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));

        return [
            'items' => array_slice($filtered, ($page - 1) * $perPage, $perPage),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'unread_total' => $unreadTotal,
            'counts' => $counts,
            'preferences' => $preferences,
        ];
    }

    public function unreadCount(int $adminUserId): int
    {
        return $this->feed($adminUserId, 1, 5)['unread_total'];
    }

    public function markRead(int $adminUserId, string $notificationKey): void
    {
        $notificationKey = trim($notificationKey);
        if ($notificationKey === '' || mb_strlen($notificationKey) > 190) {
            return;
        }
        $validKeys = array_column($this->events($this->preferences($adminUserId)), 'key');
        if (!in_array($notificationKey, $validKeys, true)) {
            return;
        }

        $existing = $this->pdo->prepare('SELECT 1 FROM admin_notification_reads WHERE admin_user_id=:user AND notification_key=:notification');
        $existing->execute(['user' => $adminUserId, 'notification' => $notificationKey]);
        if ($existing->fetchColumn() !== false) {
            return;
        }

        $statement = $this->pdo->prepare('INSERT INTO admin_notification_reads (admin_user_id,notification_key,read_at) VALUES (:user,:notification,:read_at)');
        $statement->execute([
            'user' => $adminUserId,
            'notification' => $notificationKey,
            'read_at' => $this->now(),
        ]);
    }

    public function markAllRead(int $adminUserId): int
    {
        $preferences = $this->preferences($adminUserId);
        $read = $this->readMap($adminUserId);
        $dismissed = $this->dismissedMap($adminUserId);
        $marked = 0;
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('INSERT INTO admin_notification_reads (admin_user_id,notification_key,read_at) VALUES (:user,:notification,:read_at)');
            foreach ($this->events($preferences) as $event) {
                $key = (string) $event['key'];
                if (isset($read[$key]) || isset($dismissed[$key])) {
                    continue;
                }
                $statement->execute(['user' => $adminUserId, 'notification' => $key, 'read_at' => $this->now()]);
                ++$marked;
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $marked;
    }

    public function dismiss(int $adminUserId, string $notificationKey): bool
    {
        $notificationKey = trim($notificationKey);
        if ($notificationKey === '' || mb_strlen($notificationKey) > 190) {
            return false;
        }

        $validKeys = array_column($this->events($this->preferences($adminUserId)), 'key');
        if (!in_array($notificationKey, $validKeys, true)) {
            return false;
        }

        return $this->storeDismissal($adminUserId, $notificationKey);
    }

    public function dismissAll(int $adminUserId, string $type = 'toate'): int
    {
        $type = in_array($type, ['toate', 'comenzi', 'plati', 'stoc', 'recenzii'], true) ? $type : 'toate';
        $dismissed = $this->dismissedMap($adminUserId);
        $keys = [];
        foreach ($this->events($this->preferences($adminUserId)) as $event) {
            $key = (string) $event['key'];
            if (($type === 'toate' || (string) $event['type'] === $type) && !isset($dismissed[$key])) {
                $keys[] = $key;
            }
        }

        if ($keys === []) {
            return 0;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO admin_notification_dismissals (admin_user_id,notification_key,dismissed_at) '
            . 'VALUES (:user,:notification,:dismissed_at)'
        );
        $this->pdo->beginTransaction();
        try {
            foreach ($keys as $key) {
                $insert->execute([
                    'user' => $adminUserId,
                    'notification' => $key,
                    'dismissed_at' => $this->now(),
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return count($keys);
    }

    /** @return array<string,bool> */
    public function preferences(int $adminUserId): array
    {
        $statement = $this->pdo->prepare('SELECT orders_enabled,payments_enabled,stock_enabled,reviews_enabled FROM admin_notification_preferences WHERE admin_user_id=:user');
        $statement->execute(['user' => $adminUserId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return self::DEFAULT_PREFERENCES;
        }

        return [
            'comenzi' => (bool) $row['orders_enabled'],
            'plati' => (bool) $row['payments_enabled'],
            'stoc' => (bool) $row['stock_enabled'],
            'recenzii' => (bool) $row['reviews_enabled'],
        ];
    }

    /** @param array<string,mixed> $values */
    public function savePreferences(int $adminUserId, array $values): void
    {
        $preferences = [
            'orders' => isset($values['comenzi']) ? 1 : 0,
            'payments' => isset($values['plati']) ? 1 : 0,
            'stock' => isset($values['stoc']) ? 1 : 0,
            'reviews' => isset($values['recenzii']) ? 1 : 0,
        ];
        $existing = $this->pdo->prepare('SELECT 1 FROM admin_notification_preferences WHERE admin_user_id=:user');
        $existing->execute(['user' => $adminUserId]);
        $parameters = [
            'user' => $adminUserId,
            ...$preferences,
            'updated_at' => $this->now(),
        ];

        if ($existing->fetchColumn() === false) {
            $statement = $this->pdo->prepare('INSERT INTO admin_notification_preferences (admin_user_id,orders_enabled,payments_enabled,stock_enabled,reviews_enabled,updated_at) VALUES (:user,:orders,:payments,:stock,:reviews,:updated_at)');
        } else {
            $statement = $this->pdo->prepare('UPDATE admin_notification_preferences SET orders_enabled=:orders,payments_enabled=:payments,stock_enabled=:stock,reviews_enabled=:reviews,updated_at=:updated_at WHERE admin_user_id=:user');
        }
        $statement->execute($parameters);
    }

    /** @param array<string,bool> $preferences @return list<array<string,mixed>> */
    private function events(array $preferences): array
    {
        $events = [];
        if ($preferences['comenzi'] || $preferences['plati']) {
            $orders = $this->pdo->query(
                'SELECT id,order_number,email,first_name,last_name,total_bani,status,payment_status,created_at,updated_at '
                . 'FROM orders ORDER BY created_at DESC,id DESC LIMIT 200'
            )->fetchAll();
            foreach ($orders as $order) {
                $orderId = (int) $order['id'];
                $number = (string) ($order['order_number'] ?: '#' . $orderId);
                $customer = trim((string) $order['first_name'] . ' ' . (string) $order['last_name']);
                if ($preferences['comenzi']) {
                    $events[] = [
                        'key' => 'order:' . $orderId . ':created',
                        'type' => 'comenzi',
                        'tone' => in_array((string) $order['status'], ['cancelled', 'returned', 'manual_review'], true) ? 'attention' : 'order',
                        'priority' => in_array((string) $order['status'], ['cancelled', 'manual_review'], true) ? 2 : 1,
                        'title' => 'Comandă nouă ' . $number,
                        'detail' => ($customer !== '' ? $customer . ' · ' : '') . (string) $order['email'],
                        'meta' => (int) $order['total_bani'],
                        'occurred_at' => (string) $order['created_at'],
                        'target_path' => 'admin/comenzi/' . $orderId . '/',
                    ];
                }
                if ($preferences['plati'] && in_array((string) $order['payment_status'], ['paid', 'failed', 'partially_refunded', 'refunded'], true)) {
                    $paymentStatus = (string) $order['payment_status'];
                    $events[] = [
                        'key' => 'order:' . $orderId . ':payment:' . $paymentStatus,
                        'type' => 'plati',
                        'tone' => $paymentStatus === 'failed' ? 'attention' : 'payment',
                        'priority' => $paymentStatus === 'failed' ? 3 : 2,
                        'title' => $this->paymentTitle($paymentStatus) . ' · ' . $number,
                        'detail' => (string) $order['email'],
                        'meta' => (int) $order['total_bani'],
                        'occurred_at' => (string) $order['updated_at'],
                        'target_path' => 'admin/comenzi/' . $orderId . '/',
                    ];
                }
            }
        }

        if ($preferences['stoc']) {
            $rows = $this->pdo->query(
                "SELECT v.id AS variant_id,v.product_id,v.sku,v.option_summary,p.name AS product_name,"
                . '(i.physical_quantity-i.reserved_quantity) AS available_quantity,i.updated_at '
                . "FROM inventory i JOIN product_variants v ON v.id=i.variant_id JOIN products p ON p.id=v.product_id "
                . "WHERE v.status='active' AND (i.physical_quantity-i.reserved_quantity)<=3 ORDER BY i.updated_at DESC,v.id DESC LIMIT 100"
            )->fetchAll();
            foreach ($rows as $row) {
                $available = (int) $row['available_quantity'];
                $events[] = [
                    'key' => 'variant:' . (int) $row['variant_id'] . ':low-stock:' . $available,
                    'type' => 'stoc',
                    'tone' => $available <= 0 ? 'attention' : 'stock',
                    'priority' => $available <= 0 ? 3 : 2,
                    'title' => ($available <= 0 ? 'Stoc epuizat: ' : 'Stoc redus: ') . (string) $row['product_name'],
                    'detail' => (string) $row['sku'] . ((string) $row['option_summary'] !== '' ? ' · ' . (string) $row['option_summary'] : ''),
                    'meta' => $available . ' buc.',
                    'occurred_at' => (string) $row['updated_at'],
                    'target_path' => 'admin/produse/' . (int) $row['product_id'] . '/editare/',
                ];
            }
        }

        if ($preferences['recenzii']) {
            $rows = $this->pdo->query(
                "SELECT r.id,r.product_id,r.author_name,r.rating,r.created_at,p.name AS product_name FROM product_reviews r "
                . "JOIN products p ON p.id=r.product_id WHERE r.status='pending' ORDER BY r.created_at DESC,r.id DESC LIMIT 100"
            )->fetchAll();
            foreach ($rows as $row) {
                $events[] = [
                    'key' => 'review:' . (int) $row['id'] . ':pending',
                    'type' => 'recenzii',
                    'tone' => 'review',
                    'priority' => 2,
                    'title' => 'Recenzie nouă pentru ' . (string) $row['product_name'],
                    'detail' => (string) $row['author_name'],
                    'meta' => (int) $row['rating'] . '/5 stele',
                    'occurred_at' => (string) $row['created_at'],
                    'target_path' => 'admin/recenzii/',
                ];
            }
        }

        usort($events, static function (array $left, array $right): int {
            $time = strcmp((string) $right['occurred_at'], (string) $left['occurred_at']);
            return $time !== 0 ? $time : ((int) $right['priority'] <=> (int) $left['priority']);
        });

        return $events;
    }

    /** @return array<string,true> */
    private function readMap(int $adminUserId): array
    {
        $statement = $this->pdo->prepare('SELECT notification_key FROM admin_notification_reads WHERE admin_user_id=:user');
        $statement->execute(['user' => $adminUserId]);
        $map = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $map[(string) $key] = true;
        }

        return $map;
    }

    /** @return array<string,true> */
    private function dismissedMap(int $adminUserId): array
    {
        $statement = $this->pdo->prepare('SELECT notification_key FROM admin_notification_dismissals WHERE admin_user_id=:user');
        $statement->execute(['user' => $adminUserId]);
        $map = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $map[(string) $key] = true;
        }

        return $map;
    }

    private function storeDismissal(int $adminUserId, string $notificationKey): bool
    {
        $existing = $this->pdo->prepare(
            'SELECT 1 FROM admin_notification_dismissals WHERE admin_user_id=:user AND notification_key=:notification'
        );
        $existing->execute(['user' => $adminUserId, 'notification' => $notificationKey]);
        if ($existing->fetchColumn() !== false) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO admin_notification_dismissals (admin_user_id,notification_key,dismissed_at) '
            . 'VALUES (:user,:notification,:dismissed_at)'
        );
        $statement->execute([
            'user' => $adminUserId,
            'notification' => $notificationKey,
            'dismissed_at' => $this->now(),
        ]);

        return true;
    }

    private function paymentTitle(string $status): string
    {
        return match ($status) {
            'paid' => 'Plată confirmată',
            'failed' => 'Plată nereușită',
            'partially_refunded' => 'Plată rambursată parțial',
            'refunded' => 'Plată rambursată',
            default => 'Plată actualizată',
        };
    }

    private function now(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? gmdate('Y-m-d H:i:s')
            : gmdate('c');
    }
}
