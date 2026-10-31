<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Shared\Config\Config;
use Http\Client\Curl\Client as CurlClient;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use PDO;
use RuntimeException;

final class AdminPushService
{
    private const TYPES = ['comenzi', 'plati', 'stoc', 'recenzii', 'test'];

    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
    }

    public function isConfigured(): bool
    {
        return $this->config->bool('WEB_PUSH_ENABLED')
            && $this->config->get('WEB_PUSH_VAPID_PUBLIC_KEY') !== ''
            && $this->config->get('WEB_PUSH_VAPID_PRIVATE_KEY') !== '';
    }

    public function publicKey(): string
    {
        return $this->isConfigured() ? $this->config->get('WEB_PUSH_VAPID_PUBLIC_KEY') : '';
    }

    /** @return list<array<string,mixed>> */
    public function devices(int $adminUserId): array
    {
        $statement = $this->pdo->prepare("SELECT id,device_label,user_agent,status,last_seen_at,last_success_at,last_failure_at,failure_count,created_at
            FROM admin_push_subscriptions WHERE admin_user_id=:user AND status<>'revoked'
            ORDER BY status='active' DESC,last_seen_at DESC,id DESC");
        $statement->execute(['user' => $adminUserId]);
        $devices = $statement->fetchAll();
        foreach ($devices as &$device) {
            $device['device_kind'] = $this->deviceKind((string) ($device['user_agent'] ?? ''), (string) ($device['device_label'] ?? ''));
            unset($device['user_agent']);
        }
        unset($device);
        return $devices;
    }

    /** @return array<string,mixed>|null */
    public function device(int $adminUserId, int $subscriptionId): ?array
    {
        foreach ($this->devices($adminUserId) as $device) {
            if ((int) ($device['id'] ?? 0) === $subscriptionId) {
                return $device;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $subscription */
    public function subscribe(int $adminUserId, array $subscription, string $userAgent, array $device = []): int
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Notificările push nu sunt configurate pe acest mediu.');
        }
        $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
        $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
        $p256dh = trim((string) ($keys['p256dh'] ?? ''));
        $auth = trim((string) ($keys['auth'] ?? ''));
        $encoding = trim((string) ($subscription['contentEncoding'] ?? 'aes128gcm'));
        if (!str_starts_with($endpoint, 'https://') || strlen($endpoint) > 4096 || $p256dh === '' || $auth === '') {
            throw new RuntimeException('Abonamentul browserului nu este valid.');
        }
        if (!in_array($encoding, ['aes128gcm', 'aesgcm'], true)) {
            $encoding = 'aes128gcm';
        }
        $now = $this->now();
        $hash = hash('sha256', $endpoint);
        $existing = $this->pdo->prepare('SELECT id FROM admin_push_subscriptions WHERE endpoint_hash=:hash');
        $existing->execute(['hash' => $hash]);
        $id = $existing->fetchColumn();
        if ($id !== false) {
            $statement = $this->pdo->prepare("UPDATE admin_push_subscriptions SET admin_user_id=:user,endpoint=:endpoint,p256dh=:p256dh,auth_token=:auth,
                content_encoding=:encoding,device_label=:label,user_agent=:agent,status='active',failure_count=0,last_seen_at=:now,updated_at=:now WHERE id=:id");
            $statement->execute(['user' => $adminUserId, 'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth, 'encoding' => $encoding,
                'label' => $this->deviceLabel($userAgent, $device), 'agent' => mb_substr($userAgent, 0, 500), 'now' => $now, 'id' => $id]);
            return (int) $id;
        }
        $statement = $this->pdo->prepare("INSERT INTO admin_push_subscriptions
            (admin_user_id,endpoint,endpoint_hash,p256dh,auth_token,content_encoding,device_label,user_agent,status,failure_count,last_seen_at,created_at,updated_at)
            VALUES (:user,:endpoint,:hash,:p256dh,:auth,:encoding,:label,:agent,'active',0,:now,:now,:now)");
        $statement->execute(['user' => $adminUserId, 'endpoint' => $endpoint, 'hash' => $hash, 'p256dh' => $p256dh, 'auth' => $auth,
            'encoding' => $encoding, 'label' => $this->deviceLabel($userAgent, $device), 'agent' => mb_substr($userAgent, 0, 500), 'now' => $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function unsubscribe(int $adminUserId, string $endpoint): void
    {
        $statement = $this->pdo->prepare("UPDATE admin_push_subscriptions SET status='revoked',updated_at=:now WHERE admin_user_id=:user AND endpoint_hash=:hash");
        $statement->execute(['now' => $this->now(), 'user' => $adminUserId, 'hash' => hash('sha256', trim($endpoint))]);
    }

    public function removeDevice(int $adminUserId, int $subscriptionId): void
    {
        $statement = $this->pdo->prepare("UPDATE admin_push_subscriptions SET status='revoked',updated_at=:now WHERE id=:id AND admin_user_id=:user");
        $statement->execute(['now' => $this->now(), 'id' => $subscriptionId, 'user' => $adminUserId]);
    }

    public function queueOrder(int $orderId): void
    {
        $statement = $this->pdo->prepare('SELECT order_number,total_bani FROM orders WHERE id=:id');
        $statement->execute(['id' => $orderId]);
        $order = $statement->fetch();
        if (!is_array($order)) return;
        $this->queue('order:' . $orderId, 'comenzi', 'Comandă nouă ' . $order['order_number'], $this->money((int) $order['total_bani']) . ' · deschide comanda pentru detalii.', 'admin/comenzi/' . $orderId . '/', 'high');
    }

    public function queuePayment(int $orderId, string $status = 'paid'): void
    {
        $statement = $this->pdo->prepare('SELECT order_number,total_bani FROM orders WHERE id=:id');
        $statement->execute(['id' => $orderId]);
        $order = $statement->fetch();
        if (!is_array($order)) return;
        $paid = $status === 'paid';
        $this->queue('payment:' . $orderId . ':' . $status, 'plati', $paid ? 'Plată confirmată' : 'Plată care cere atenție',
            $order['order_number'] . ' · ' . $this->money((int) $order['total_bani']), 'admin/comenzi/' . $orderId . '/', $paid ? 'high' : 'normal');
    }

    public function queueReview(int $reviewId): void
    {
        $statement = $this->pdo->prepare('SELECT r.rating,p.name FROM product_reviews r JOIN products p ON p.id=r.product_id WHERE r.id=:id');
        $statement->execute(['id' => $reviewId]);
        $review = $statement->fetch();
        if (!is_array($review)) return;
        $this->queue('review:' . $reviewId, 'recenzii', 'Recenzie nouă de moderat', (int) $review['rating'] . ' stele · ' . (string) $review['name'], 'admin/recenzii/', 'normal');
    }

    public function queueLowStockForOrder(int $orderId): void
    {
        $statement = $this->pdo->prepare('SELECT DISTINCT v.id,p.id product_id,v.sku,p.name,(i.physical_quantity-i.reserved_quantity) available
            FROM order_items oi JOIN product_variants v ON v.id=oi.variant_id JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id
            WHERE oi.order_id=:order AND v.stock_unlimited=0 AND (i.physical_quantity-i.reserved_quantity)<=3');
        $statement->execute(['order' => $orderId]);
        foreach ($statement->fetchAll() as $row) {
            $available = max(0, (int) $row['available']);
            $this->queue('stock:' . $row['id'] . ':' . $available, 'stoc', $available === 0 ? 'Stoc epuizat' : 'Stoc redus',
                $row['name'] . ' · ' . $row['sku'] . ' · ' . $available . ' disponibile', 'admin/produse/' . $row['product_id'] . '/editare/', $available === 0 ? 'high' : 'normal');
        }
    }

    public function queueTest(int $adminUserId): void
    {
        $this->queue('test:' . $adminUserId . ':' . bin2hex(random_bytes(8)), 'test', 'Notificările sunt active',
            'Acest dispozitiv este conectat la La Măriuca Boutique.', 'admin/notificari/', 'normal', $adminUserId);
    }

    /** @return array{processed:int,sent:int,failed:int,expired:int} */
    public function processPending(int $limit = 30): array
    {
        $result = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'expired' => 0];
        if (!$this->isConfigured() || $this->pdo->inTransaction()) return $result;
        $limit = max(1, min(200, $limit));
        $statement = $this->pdo->prepare("SELECT d.id delivery_id,d.attempts,s.id subscription_id,s.endpoint,s.p256dh,s.auth_token,s.content_encoding,
                e.event_key,e.type,e.title,e.body,e.target_path,e.urgency
            FROM admin_push_deliveries d JOIN admin_push_subscriptions s ON s.id=d.subscription_id JOIN admin_push_events e ON e.id=d.event_id
            WHERE d.status IN ('pending','failed') AND d.available_at<=:now AND d.attempts<5 AND s.status='active'
            ORDER BY d.id LIMIT {$limit}");
        $statement->execute(['now' => $this->now()]);
        $rows = $statement->fetchAll();
        if ($rows === []) return $result;

        $client = new CurlClient(null, null, [CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 9]);
        $sender = new WebPush(['VAPID' => [
            'subject' => $this->config->get('WEB_PUSH_VAPID_SUBJECT', 'mailto:contact@la-mariuca.ro'),
            'publicKey' => $this->config->get('WEB_PUSH_VAPID_PUBLIC_KEY'),
            'privateKey' => $this->config->get('WEB_PUSH_VAPID_PRIVATE_KEY'),
        ]], ['TTL' => 21600], $client);

        foreach ($rows as $row) {
            ++$result['processed'];
            $claimed = $this->pdo->prepare("UPDATE admin_push_deliveries SET status='processing',attempts=attempts+1 WHERE id=:id AND status IN ('pending','failed')");
            $claimed->execute(['id' => $row['delivery_id']]);
            if ($claimed->rowCount() !== 1) continue;
            try {
                $subscription = Subscription::create(['endpoint' => $row['endpoint'], 'publicKey' => $row['p256dh'], 'authToken' => $row['auth_token'], 'contentEncoding' => $row['content_encoding']]);
                $payload = json_encode([
                    'title' => $row['title'], 'body' => $row['body'], 'tag' => $row['event_key'], 'type' => $row['type'],
                    'target' => $this->config->path((string) $row['target_path']),
                    'icon' => $this->config->path('assets/images/favicon-lm-192.png'),
                    'badge' => $this->config->path('assets/images/favicon-lm-192.png'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $report = $sender->sendOneNotification($subscription, $payload, ['urgency' => $row['urgency'], 'topic' => substr(hash('sha256', (string) $row['event_key']), 0, 32)]);
                if ($report->isSuccess()) {
                    $this->pdo->prepare("UPDATE admin_push_deliveries SET status='sent',sent_at=:now,last_error=NULL WHERE id=:id")->execute(['now' => $this->now(), 'id' => $row['delivery_id']]);
                    $this->pdo->prepare('UPDATE admin_push_subscriptions SET last_success_at=:now,last_seen_at=:now,failure_count=0,updated_at=:now WHERE id=:id')->execute(['now' => $this->now(), 'id' => $row['subscription_id']]);
                    ++$result['sent'];
                } elseif ($report->isSubscriptionExpired()) {
                    $this->expire((int) $row['delivery_id'], (int) $row['subscription_id'], $report->getReason());
                    ++$result['expired'];
                } else {
                    $this->retry((int) $row['delivery_id'], (int) $row['subscription_id'], (int) $row['attempts'] + 1, $report->getReason());
                    ++$result['failed'];
                }
            } catch (\Throwable $exception) {
                $this->retry((int) $row['delivery_id'], (int) $row['subscription_id'], (int) $row['attempts'] + 1, $exception->getMessage());
                ++$result['failed'];
            }
        }
        return $result;
    }

    private function queue(string $key, string $type, string $title, string $body, string $targetPath, string $urgency, ?int $onlyAdmin = null): void
    {
        if (!$this->isConfigured() || !in_array($type, self::TYPES, true)) return;
        $exists = $this->pdo->prepare('SELECT id FROM admin_push_events WHERE event_key=:key');
        $exists->execute(['key' => $key]);
        if ($exists->fetchColumn() !== false) return;
        $now = $this->now();
        $insert = $this->pdo->prepare('INSERT INTO admin_push_events (event_key,type,title,body,target_path,urgency,created_at) VALUES (:key,:type,:title,:body,:target,:urgency,:now)');
        $insert->execute(['key' => mb_substr($key, 0, 190), 'type' => $type, 'title' => mb_substr($title, 0, 180), 'body' => mb_substr($body, 0, 255), 'target' => mb_substr($targetPath, 0, 500), 'urgency' => $urgency, 'now' => $now]);
        $eventId = (int) $this->pdo->lastInsertId();

        $preferenceColumn = match ($type) { 'comenzi' => 'orders_enabled', 'plati' => 'payments_enabled', 'stoc' => 'stock_enabled', 'recenzii' => 'reviews_enabled', default => null };
        $sql = "SELECT s.id FROM admin_push_subscriptions s JOIN users u ON u.id=s.admin_user_id LEFT JOIN admin_notification_preferences p ON p.admin_user_id=s.admin_user_id
            WHERE s.status='active' AND u.status='active' AND u.role IN ('administrator','operator')";
        $params = [];
        if ($onlyAdmin !== null) { $sql .= ' AND s.admin_user_id=:admin'; $params['admin'] = $onlyAdmin; }
        if ($preferenceColumn !== null) $sql .= " AND COALESCE(p.{$preferenceColumn},1)=1";
        $subscriptions = $this->pdo->prepare($sql);
        $subscriptions->execute($params);
        $insertDelivery = $this->pdo->prepare('INSERT INTO admin_push_deliveries (event_id,subscription_id,status,attempts,available_at,created_at) VALUES (:event,:subscription,\'pending\',0,:now,:now)');
        foreach ($subscriptions->fetchAll(PDO::FETCH_COLUMN) as $subscriptionId) {
            $insertDelivery->execute(['event' => $eventId, 'subscription' => $subscriptionId, 'now' => $now]);
        }
    }

    private function retry(int $deliveryId, int $subscriptionId, int $attempt, string $error): void
    {
        $delay = min(3600, 60 * (2 ** max(0, $attempt - 1)));
        $this->pdo->prepare("UPDATE admin_push_deliveries SET status='failed',available_at=:retry,last_error=:error WHERE id=:id")
            ->execute(['retry' => gmdate('Y-m-d H:i:s', time() + $delay), 'error' => mb_substr($error, 0, 500), 'id' => $deliveryId]);
        $this->pdo->prepare('UPDATE admin_push_subscriptions SET failure_count=failure_count+1,last_failure_at=:now,updated_at=:now WHERE id=:id')
            ->execute(['now' => $this->now(), 'id' => $subscriptionId]);
    }

    private function expire(int $deliveryId, int $subscriptionId, string $error): void
    {
        $this->pdo->prepare("UPDATE admin_push_deliveries SET status='expired',last_error=:error WHERE id=:id")->execute(['error' => mb_substr($error, 0, 500), 'id' => $deliveryId]);
        $this->pdo->prepare("UPDATE admin_push_subscriptions SET status='expired',last_failure_at=:now,updated_at=:now WHERE id=:id")->execute(['now' => $this->now(), 'id' => $subscriptionId]);
    }

    /** @param array<string,mixed> $details */
    private function deviceLabel(string $agent, array $details = []): string
    {
        $browser = $this->safeDeviceValue($details['browser'] ?? '', 30);
        if ($browser === '') {
            $browser = str_contains($agent, 'Edg/') ? 'Edge' : (str_contains($agent, 'Firefox/') ? 'Firefox' : (str_contains($agent, 'CriOS') || str_contains($agent, 'Chrome/') ? 'Chrome' : (str_contains($agent, 'Safari/') ? 'Safari' : 'Browser')));
        }
        $platform = $this->safeDeviceValue($details['platform'] ?? '', 30);
        if ($platform === '') {
            $platform = str_contains($agent, 'iPhone') ? 'iPhone' : (str_contains($agent, 'iPad') ? 'iPad' : (str_contains($agent, 'Android') ? 'Android' : (str_contains($agent, 'Windows') ? 'Windows' : (str_contains($agent, 'Macintosh') ? 'macOS' : (str_contains($agent, 'Linux') ? 'Linux' : 'Dispozitiv')))));
        }
        $model = $this->safeDeviceValue($details['model'] ?? '', 55);
        $kind = $this->safeDeviceValue($details['kind'] ?? '', 12);
        $mobile = in_array($kind, ['phone', 'tablet', 'mobile'], true)
            || $this->deviceKind($agent, $model . ' ' . $platform) === 'mobile';
        if ($mobile) {
            $name = $model !== '' ? $this->mobileBrand($model) . $model : $platform;
            return mb_substr($name . ' · ' . $browser, 0, 120);
        }
        return mb_substr($browser . ' · ' . $platform, 0, 120);
    }

    private function deviceKind(string $agent, string $label = ''): string
    {
        $value = $agent . ' ' . $label;
        return preg_match('/Android|Mobile|iPhone|iPad|iPod|Windows Phone|Samsung|Pixel|Redmi|POCO|Huawei|HONOR|OnePlus|OPPO|realme|vivo/i', $value) === 1
            ? 'mobile'
            : 'desktop';
    }

    private function safeDeviceValue(mixed $value, int $length): string
    {
        $value = trim((string) $value);
        $value = preg_replace('/[^\pL\pN ._+\-()]/u', '', $value) ?? '';
        return mb_substr(preg_replace('/\s+/u', ' ', $value) ?? '', 0, $length);
    }

    private function mobileBrand(string $model): string
    {
        if (preg_match('/^(SM-|GT-|SCH-|SGH-)/i', $model) === 1) return 'Samsung ';
        if (preg_match('/^Pixel\b/i', $model) === 1) return 'Google ';
        if (preg_match('/^(Redmi|POCO|Mi\s)/i', $model) === 1) return 'Xiaomi ';
        if (preg_match('/^CPH/i', $model) === 1) return 'OPPO ';
        if (preg_match('/^RMX/i', $model) === 1) return 'realme ';
        return '';
    }

    private function money(int $bani): string
    {
        return number_format($bani / 100, 2, ',', '.') . ' lei';
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
