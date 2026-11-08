<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Admin\AdminPushService;
use App\Shared\Config\Config;
use Tests\DatabaseTestCase;

final class AdminPushServiceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        $_ENV['WEB_PUSH_ENABLED'] = 'true';
        $_ENV['WEB_PUSH_VAPID_PUBLIC_KEY'] = 'test-public-key';
        $_ENV['WEB_PUSH_VAPID_PRIVATE_KEY'] = 'test-private-key';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        unset($_ENV['WEB_PUSH_ENABLED'], $_ENV['WEB_PUSH_VAPID_PUBLIC_KEY'], $_ENV['WEB_PUSH_VAPID_PRIVATE_KEY']);
        parent::tearDown();
    }

    public function testSubscriptionAndQueuedTestNotificationArePersistedPerAdminDevice(): void
    {
        $now = gmdate('c');
        $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at)
            VALUES ('push-admin@example.test','hash','Paula','Admin','administrator','active',:now,:now,:now)")->execute(['now' => $now]);
        $adminId = (int) $this->pdo->lastInsertId();
        $service = new AdminPushService($this->pdo, Config::load($this->root));

        self::assertTrue($service->isConfigured());
        $subscriptionId = $service->subscribe($adminId, [
            'endpoint' => 'https://push.example.test/subscription/one',
            'keys' => ['p256dh' => 'public-browser-key', 'auth' => 'auth-browser-key'],
            'contentEncoding' => 'aes128gcm',
        ], 'Mozilla/5.0 (Linux; Android 14) Chrome/130.0', [
            'kind' => 'phone',
            'platform' => 'Android',
            'model' => 'SM-S928B',
            'browser' => 'Chrome',
        ]);
        $service->queueTest($adminId);

        self::assertGreaterThan(0, $subscriptionId);
        self::assertSame('Samsung SM-S928B · Chrome', $service->devices($adminId)[0]['device_label']);
        self::assertSame('mobile', $service->devices($adminId)[0]['device_kind']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM admin_push_events')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM admin_push_deliveries WHERE status='pending'")->fetchColumn());

        $service->unsubscribe($adminId, 'https://push.example.test/subscription/one');
        self::assertSame('revoked', (string) $this->pdo->query('SELECT status FROM admin_push_subscriptions')->fetchColumn());
    }

    public function testQueuedNotificationsRecheckInternalAccountAccessBeforeDelivery(): void
    {
        $now = gmdate('c');
        $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at)
            VALUES ('former-admin@example.test','hash','Paula','Admin','administrator','active',:now,:now,:now)")->execute(['now'=>$now]);
        $adminId = (int) $this->pdo->lastInsertId();
        $service = new AdminPushService($this->pdo, Config::load($this->root));
        $service->subscribe($adminId, ['endpoint'=>'https://push.example.test/former-admin',
            'keys'=>['p256dh'=>'public-browser-key', 'auth'=>'auth-browser-key']], 'Test browser');
        $service->queueTest($adminId);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM admin_push_deliveries')->fetchColumn());
        foreach ([['administrator','blocked'], ['customer','active']] as [$role, $status]) {
            $this->pdo->prepare('UPDATE users SET role=:role,status=:status WHERE id=:id')
                ->execute(['role'=>$role, 'status'=>$status, 'id'=>$adminId]);
            // A stale queued message must not reach a former or disabled internal account.
            // No sender/transport is created when all recipients are now ineligible.
            self::assertSame(['processed'=>0, 'sent'=>0, 'failed'=>0, 'expired'=>0], $service->processPending());
            self::assertSame(0, (int) $this->pdo->query('SELECT SUM(attempts) FROM admin_push_deliveries')->fetchColumn());
        }
    }
}
