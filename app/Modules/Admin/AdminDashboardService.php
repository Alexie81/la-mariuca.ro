<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class AdminDashboardService
{
    private const BUCHAREST = 'Europe/Bucharest';
    private const UTC = 'UTC';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $query */
    public function overview(array $query): array
    {
        $period = $this->resolvePeriod($query);
        $orders = $this->ordersInPeriod($period['start_utc'], $period['end_utc']);
        $validOrders = array_values(array_filter(
            $orders,
            static fn(array $order): bool => !in_array((string) $order['status'], ['cancelled', 'returned'], true),
        ));

        $netSales = 0;
        foreach ($validOrders as &$order) {
            $isCollected = in_array((string) $order['payment_status'], ['paid', 'partially_refunded', 'refunded'], true);
            $order['net_bani'] = $isCollected
                ? max(0, (int) $order['total_bani'] - (int) $order['refunded_bani'])
                : 0;
            $netSales += $order['net_bani'];
        }
        unset($order);

        $customerStatement = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE role='customer' AND created_at>=:start AND created_at<=:end");
        $customerStatement->execute(['start' => $period['start_utc'], 'end' => $period['end_utc']]);

        $lowStock = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM inventory i JOIN product_variants v ON v.id=i.variant_id WHERE v.status='active' AND (i.physical_quantity-i.reserved_quantity)<=3"
        )->fetchColumn();

        $recentOrders = $this->recentOrders();

        return [
            'period' => $period,
            'stats' => [
                'net_sales' => $netSales,
                'orders' => count($validOrders),
                'new_customers' => (int) $customerStatement->fetchColumn(),
                'low_stock' => $lowStock,
            ],
            'chart' => $this->chart($period, $validOrders),
            'notifications' => $this->notifications($recentOrders),
            'recent_orders' => array_slice($recentOrders, 0, 7),
        ];
    }

    /** @param array<string, mixed> $query */
    private function resolvePeriod(array $query): array
    {
        $zone = new DateTimeZone(self::BUCHAREST);
        $utc = new DateTimeZone(self::UTC);
        $today = (new DateTimeImmutable('now', $zone))->setTime(0, 0);
        $key = in_array((string) ($query['perioada'] ?? ''), ['7z', 'saptamana', '1l', '3l', '6l', '1a', 'tot', 'personalizat'], true)
            ? (string) $query['perioada']
            : '7z';

        $start = match ($key) {
            'saptamana' => $today->modify('monday this week'),
            '1l' => $today->modify('-1 month'),
            '3l' => $today->modify('-3 months'),
            '6l' => $today->modify('-6 months'),
            '1a' => $today->modify('-1 year'),
            'tot' => $this->earliestLocalDate($today),
            'personalizat' => $this->customDate((string) ($query['de_la'] ?? ''), $today->modify('-6 days'), $zone),
            default => $today->modify('-6 days'),
        };
        $end = $key === 'personalizat'
            ? $this->customDate((string) ($query['pana_la'] ?? ''), $today, $zone)
            : $today;

        if ($end > $today) {
            $end = $today;
        }
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        $end = $end->setTime(23, 59, 59);

        $labels = [
            '7z' => '7 zile',
            'saptamana' => 'Săptămâna curentă',
            '1l' => 'O lună',
            '3l' => '3 luni',
            '6l' => '6 luni',
            '1a' => 'Un an',
            'tot' => 'Tot timpul',
            'personalizat' => 'Personalizat',
        ];

        return [
            'key' => $key,
            'label' => $labels[$key],
            'start_local' => $start,
            'end_local' => $end,
            'start_utc' => $this->databaseDate($start->setTimezone($utc)),
            'end_utc' => $this->databaseDate($end->setTimezone($utc)),
            'start_input' => $start->format('Y-m-d'),
            'end_input' => $end->format('Y-m-d'),
            'display' => $labels[$key] . ' · ' . $start->format('d.m.Y') . ' – ' . $end->format('d.m.Y'),
        ];
    }

    private function customDate(string $value, DateTimeImmutable $fallback, DateTimeZone $zone): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);

        return $date instanceof DateTimeImmutable ? $date : $fallback;
    }

    private function earliestLocalDate(DateTimeImmutable $fallback): DateTimeImmutable
    {
        $values = [
            $this->pdo->query('SELECT MIN(created_at) FROM orders')->fetchColumn(),
            $this->pdo->query("SELECT MIN(created_at) FROM users WHERE role='customer'")->fetchColumn(),
        ];
        $timestamps = [];
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $timestamps[] = $this->localDate($value)->getTimestamp();
            }
        }

        return $timestamps === []
            ? $fallback
            : (new DateTimeImmutable('@' . min($timestamps)))->setTimezone(new DateTimeZone(self::BUCHAREST))->setTime(0, 0);
    }

    /** @return list<array<string, mixed>> */
    private function ordersInPeriod(string $start, string $end): array
    {
        $statement = $this->pdo->prepare(
            'SELECT o.id,o.order_number,o.email,o.first_name,o.last_name,o.total_bani,o.status,o.payment_status,o.created_at,'
            . 'COALESCE((SELECT SUM(p.refunded_bani) FROM payments p WHERE p.order_id=o.id),0) AS refunded_bani '
            . 'FROM orders o WHERE o.created_at>=:start AND o.created_at<=:end ORDER BY o.created_at ASC,o.id ASC'
        );
        $statement->execute(['start' => $start, 'end' => $end]);

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    private function recentOrders(): array
    {
        $rows = $this->pdo->query(
            'SELECT id,order_number,email,first_name,last_name,total_bani,status,payment_status,created_at FROM orders ORDER BY created_at DESC,id DESC LIMIT 10'
        )->fetchAll();

        $statusLabels = [
            'new' => 'Nouă',
            'confirmed' => 'Confirmată',
            'preparing' => 'În pregătire',
            'shipped' => 'Expediată',
            'delivered' => 'Livrată',
            'cancelled' => 'Anulată',
            'returned' => 'Returnată',
            'manual_review' => 'De verificat',
        ];
        foreach ($rows as &$row) {
            $row['display_date'] = $this->localDate((string) $row['created_at'])->format('d.m.Y H:i');
            $row['status_label'] = $statusLabels[(string) $row['status']] ?? ucfirst((string) $row['status']);
            $row['status_tone'] = in_array((string) $row['status'], ['delivered'], true)
                ? 'success'
                : (in_array((string) $row['status'], ['cancelled', 'returned'], true) ? 'danger' : (string) $row['status']);
        }
        unset($row);

        return $rows;
    }

    /** @param list<array<string, mixed>> $orders @return list<array<string, mixed>> */
    private function notifications(array $orders): array
    {
        $items = [];
        foreach ($orders as $order) {
            if ((string) $order['payment_status'] === 'paid') {
                $items[] = [
                    'order_id' => (int) $order['id'],
                    'type' => 'payment',
                    'title' => 'Plată confirmată ' . (string) ($order['order_number'] ?: '#' . $order['id']),
                    'detail' => (string) $order['email'],
                    'total_bani' => (int) $order['total_bani'],
                ];
            }
            $items[] = [
                'order_id' => (int) $order['id'],
                'type' => 'order',
                'title' => 'Comandă nouă ' . (string) ($order['order_number'] ?: '#' . $order['id']),
                'detail' => (string) $order['email'],
                'total_bani' => (int) $order['total_bani'],
            ];
            if (count($items) >= 7) {
                break;
            }
        }

        return array_slice($items, 0, 7);
    }

    /** @param array<string, mixed> $period @param list<array<string, mixed>> $orders */
    private function chart(array $period, array $orders): array
    {
        /** @var DateTimeImmutable $start */
        $start = $period['start_local'];
        /** @var DateTimeImmutable $end */
        $end = $period['end_local'];
        $days = max(1, (int) $start->diff($end)->format('%a') + 1);
        $unit = $days <= 62 ? 'day' : ($days <= 210 ? 'week' : 'month');
        $buckets = [];
        $cursor = $start->setTime(0, 0);

        while ($cursor <= $end) {
            if ($unit === 'month') {
                $key = $cursor->format('Y-m');
                $label = $this->romanianMonth($cursor) . ' ' . $cursor->format('Y');
                $next = $cursor->modify('first day of next month');
            } elseif ($unit === 'week') {
                $key = $cursor->format('Y-m-d');
                $label = $cursor->format('d.m');
                $next = $cursor->modify('+7 days');
            } else {
                $key = $cursor->format('Y-m-d');
                $label = $cursor->format('d.m');
                $next = $cursor->modify('+1 day');
            }
            $buckets[$key] = ['key' => $key, 'label' => $label, 'value' => 0, 'from' => $cursor, 'to' => $next];
            $cursor = $next;
        }

        foreach ($orders as $order) {
            $created = $this->localDate((string) $order['created_at']);
            foreach ($buckets as &$bucket) {
                if ($created >= $bucket['from'] && $created < $bucket['to']) {
                    $bucket['value'] += (int) $order['net_bani'];
                    break;
                }
            }
            unset($bucket);
        }

        $max = 0;
        foreach ($buckets as $bucket) {
            $max = max($max, (int) $bucket['value']);
        }
        $points = [];
        foreach ($buckets as $bucket) {
            $points[] = [
                'label' => $bucket['label'],
                'value' => (int) $bucket['value'],
                'percent' => $max > 0 ? (int) round(((int) $bucket['value'] / $max) * 100) : 0,
                'scale' => $max > 0 ? min(1.0, (int) $bucket['value'] / $max) : 0.0,
            ];
        }

        return [
            'points' => $points,
            'max' => $max,
            'unit' => $unit,
            'scrollable' => count($points) > 12,
        ];
    }

    private function localDate(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value, new DateTimeZone(self::UTC)))
            ->setTimezone(new DateTimeZone(self::BUCHAREST));
    }

    private function databaseDate(DateTimeImmutable $date): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? $date->format('Y-m-d H:i:s')
            : $date->format('c');
    }

    private function romanianMonth(DateTimeImmutable $date): string
    {
        return [1 => 'Ian', 'Feb', 'Mar', 'Apr', 'Mai', 'Iun', 'Iul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][(int) $date->format('n')];
    }
}
