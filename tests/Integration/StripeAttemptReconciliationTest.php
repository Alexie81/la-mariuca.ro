<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutAttemptService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Payments\StripeAttemptReconciliationService;
use App\Shared\Config\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\Collection;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClientInterface;
use Tests\DatabaseTestCase;

final class StripeAttemptReconciliationTest extends DatabaseTestCase
{
    private array $environment;
    private array $attempt;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        parent::setUp();
        $this->seed();
        $_ENV['STRIPE_ENABLED'] = 'true';
        $_ENV['STRIPE_MODE'] = 'test';
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_reconcile_fixture';
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_reconcile_fixture';
        $_ENV['WEB_PUSH_ENABLED'] = 'false';
        $this->pdo->exec('UPDATE payment_method_settings SET stripe_enabled=1 WHERE id=1');
        $variant = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND v.status='active' AND i.physical_quantity>4 ORDER BY v.id LIMIT 1")->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($variant, 2);
        $result = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place([
            'idempotency_key' => bin2hex(random_bytes(32)), 'email' => 'reconcile@example.test', 'first_name' => 'Ana', 'last_name' => 'Test',
            'phone' => '0712345678', 'address' => 'Strada Test nr. 1', 'city' => 'Cluj-Napoca', 'county' => 'Cluj', 'postal_code' => '', 'terms' => '1', 'payment_method' => 'stripe',
            'shipping_method_id' => (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn(),
        ]);
        $this->pdo->prepare('UPDATE checkout_attempts SET created_at=:created,expires_at=:expires WHERE id=:id')
            ->execute(['created' => gmdate('c', time() - 4200), 'expires' => gmdate('c', time() - 600), 'id' => $result['attempt_id']]);
        $this->attempt = $this->pdo->query('SELECT * FROM checkout_attempts')->fetch();
    }

    protected function tearDown(): void { parent::tearDown(); $_ENV = $this->environment; }

    public function testKnownExpiredSessionReleasesHoldsOnceAndNeverCreatesAnOrderOrEmail(): void
    {
        $this->associate('cs_expired');
        $client = $this->createMock(StripeClientInterface::class);
        $client->expects(self::once())->method('request')->willReturnCallback(function ($method, $path) {
            self::assertFalse($this->pdo->inTransaction());
            self::assertSame('get', $method);
            self::assertSame('/v1/checkout/sessions/cs_expired', $path);
            return $this->session('cs_expired', 'expired');
        });
        $service = $this->service($client);
        self::assertSame(1, $service->process()['released']);
        self::assertSame(0, $service->process()['checked']);
        $this->assertReleased();
    }

    public function testOpenSessionMustBeExpiredRemotelyBeforeRelease(): void
    {
        $this->associate('cs_open');
        $client = $this->createMock(StripeClientInterface::class);
        $client->expects(self::exactly(2))->method('request')->willReturnCallback(function ($method, $path) {
            self::assertFalse($this->pdo->inTransaction());
            self::assertSame('active', $this->pdo->query('SELECT status FROM checkout_attempt_holds')->fetchColumn());
            if ($method === 'get') { return $this->session('cs_open', 'open'); }
            self::assertSame('/v1/checkout/sessions/cs_open/expire', $path);
            return $this->session('cs_open', 'expired');
        });
        self::assertSame(1, $this->service($client)->process()['released']);
        $this->assertReleased();
    }

    public function testPaidRaceDuringExpiryPreservesReservationUntilSignedWebhook(): void
    {
        $this->associate('cs_race');
        $client = $this->createMock(StripeClientInterface::class);
        $calls = 0;
        $client->expects(self::exactly(3))->method('request')->willReturnCallback(function ($method) use (&$calls) {
            self::assertFalse($this->pdo->inTransaction());
            if (++$calls === 1) { return $this->session('cs_race', 'open'); }
            if ($method === 'post') { throw InvalidRequestException::factory('Already completed'); }
            return $this->session('cs_race', 'complete', 'paid');
        });
        self::assertSame(1, $this->service($client)->process()['waiting_webhook']);
        $this->assertHeld();
    }

    public function testUnknownSessionEmptyCompleteScanCanReleaseOnlyAfterGrace(): void
    {
        $client = $this->createMock(StripeClientInterface::class);
        $client->expects(self::once())->method('requestCollection')->willReturnCallback(function ($method, $path, $params) {
            self::assertFalse($this->pdo->inTransaction());
            self::assertSame('get', $method);
            self::assertSame('/v1/checkout/sessions', $path);
            self::assertSame(strtotime($this->attempt['created_at']) - 60, $params['created']['gte']);
            self::assertSame(strtotime($this->attempt['expires_at']) + 300, $params['created']['lte']);
            return $this->collection([]);
        });
        self::assertSame(1, $this->service($client)->process()['released']);
        $this->assertReleased();
    }

    public function testMissingSessionIsRecoveredAcrossPagesBeforeHandlingExpiry(): void
    {
        $client = $this->createMock(StripeClientInterface::class);
        $page = 0;
        $client->expects(self::exactly(2))->method('requestCollection')->willReturnCallback(function ($method, $path, $params) use (&$page) {
            self::assertFalse($this->pdo->inTransaction());
            if (++$page === 1) {
                $unrelated = $this->session('cs_unrelated', 'expired');
                $unrelated->metadata = ['checkout_attempt_id' => '999', 'checkout_attempt_key' => 'unrelated'];
                return $this->collection([$unrelated], true);
            }
            self::assertSame('cs_unrelated', $params['starting_after']);
            return $this->collection([$this->session('cs_recovered', 'expired')]);
        });
        self::assertSame(1, $this->service($client)->process()['released']);
        self::assertSame('cs_recovered', $this->pdo->query('SELECT checkout_session_id FROM checkout_attempts')->fetchColumn());
        $this->assertReleased();
    }

    public function testRecoveredPaidSessionIsSavedButNotConvertedToOrder(): void
    {
        $client = $this->createMock(StripeClientInterface::class);
        $client->expects(self::once())->method('requestCollection')->willReturn($this->collection([$this->session('cs_paid', 'complete', 'paid')]));
        self::assertSame(1, $this->service($client)->process()['waiting_webhook']);
        self::assertSame('cs_paid', $this->pdo->query('SELECT checkout_session_id FROM checkout_attempts')->fetchColumn());
        $this->assertHeld();
    }

    public function testNetworkFailureDuringScanDoesNotReleaseAndDoesNotLogProviderSecrets(): void
    {
        $client = $this->createMock(StripeClientInterface::class);
        $client->expects(self::once())->method('requestCollection')->willThrowException(new RuntimeException('Provider detail sk_test_do_not_log customer@example.test'));
        $result = $this->service($client)->process();
        self::assertSame(1, $result['failed']);
        self::assertSame([['attempt_id' => (int) $this->attempt['id'], 'code' => 'provider_or_storage_error']], $result['errors']);
        $this->assertHeld();
    }

    public function testIncompleteBoundedScanCannotReleaseOrBindAnUnprovenSession(): void
    {
        $client = $this->createMock(StripeClientInterface::class);
        $page = 0;
        $client->expects(self::exactly(20))->method('requestCollection')->willReturnCallback(function () use (&$page) {
            $candidate = $this->session('cs_page_' . ++$page, 'expired');
            $candidate->metadata = ['checkout_attempt_id' => '999', 'checkout_attempt_key' => 'unrelated'];
            return $this->collection([$candidate], true);
        });
        $result = $this->service($client)->process();
        self::assertSame('scan_limit', $result['errors'][0]['code']);
        $this->assertHeld();
    }

    #[DataProvider('unverifiedAccount')]
    public function testUnverifiedAccountNeverQueriesStripeOrReleases(bool $legacy): void
    {
        $this->associate('cs_other_account');
        if ($legacy) {
            $snapshot = json_decode($this->attempt['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
            unset($snapshot['stripe_key_fingerprint']);
            $this->pdo->prepare('UPDATE checkout_attempts SET snapshot_json=:snapshot')->execute(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        } else { $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_other_account'; }
        $client = $this->createMock(StripeClientInterface::class);
        $client->expects(self::never())->method('request');
        $client->expects(self::never())->method('requestCollection');
        $result = $this->service($client)->process();
        self::assertSame(1, $result['manual_review']);
        self::assertSame('account_scope_unverified', $result['errors'][0]['code']);
        $this->assertHeld();
    }

    public static function unverifiedAccount(): array { return ['legacy without fingerprint' => [true], 'changed key' => [false]]; }

    public function testMismatchedAmountAndDuplicateSessionsRemainHeld(): void
    {
        $client = $this->createMock(StripeClientInterface::class);
        $wrong = $this->session('cs_wrong', 'expired');
        $wrong->amount_total = (int) $wrong->amount_total + 1;
        $client->expects(self::once())->method('requestCollection')->willReturn($this->collection([$wrong]));
        self::assertSame('session_mismatch', $this->service($client)->process()['errors'][0]['code']);
        $duplicateClient = $this->createMock(StripeClientInterface::class);
        $duplicateClient->expects(self::once())->method('requestCollection')->willReturn($this->collection([$this->session('cs_first', 'expired'), $this->session('cs_second', 'expired')]));
        self::assertSame('ambiguous_sessions', $this->service($duplicateClient)->process()['errors'][0]['code']);
        $this->assertHeld();
    }

    public function testConcurrentSessionBindingPreventsEmptyScanRelease(): void
    {
        $client = $this->createMock(StripeClientInterface::class);
        $client->expects(self::once())->method('requestCollection')->willReturnCallback(function () {
            $this->associate('cs_concurrent');
            return $this->collection([]);
        });
        self::assertSame(1, $this->service($client)->process()['skipped']);
        $this->assertHeld();
    }

    public function testAttemptsWithinGraceAreNotQueriedOrReleased(): void
    {
        $this->pdo->prepare('UPDATE checkout_attempts SET expires_at=:expires')->execute(['expires' => gmdate('c', time() - 240)]);
        $client = $this->createMock(StripeClientInterface::class);
        $client->expects(self::never())->method('requestCollection');
        self::assertSame(0, $this->service($client)->process()['checked']);
        $this->assertHeld();
    }

    private function associate(string $session): void { $this->pdo->prepare('UPDATE checkout_attempts SET checkout_session_id=:session')->execute(['session' => $session]); }
    private function service(StripeClientInterface $client): StripeAttemptReconciliationService { return new StripeAttemptReconciliationService($this->pdo, Config::load($this->root), $client); }

    private function session(string $id, string $status, string $paymentStatus = 'unpaid'): Session
    {
        return Session::constructFrom(['id' => $id, 'object' => 'checkout.session', 'status' => $status, 'payment_status' => $paymentStatus,
            'livemode' => false, 'amount_total' => (int) $this->attempt['total_bani'], 'currency' => 'ron', 'payment_intent' => $paymentStatus === 'paid' ? 'pi_paid' : null,
            'metadata' => ['checkout_attempt_id' => (string) $this->attempt['id'], 'checkout_attempt_key' => $this->attempt['idempotency_key']]]);
    }

    /** @param list<Session> $sessions */
    private function collection(array $sessions, bool $more = false): Collection
    {
        return Collection::constructFrom(['object' => 'list', 'url' => '/v1/checkout/sessions', 'data' => array_map(static fn (Session $session): array => $session->toArray(), $sessions), 'has_more' => $more]);
    }

    private function assertHeld(): void
    {
        self::assertSame('pending', $this->pdo->query('SELECT status FROM checkout_attempts')->fetchColumn());
        self::assertSame('active', $this->pdo->query('SELECT status FROM checkout_attempt_holds')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT SUM(reserved_quantity) FROM inventory')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
    }

    private function assertReleased(): void
    {
        self::assertSame('expired', $this->pdo->query('SELECT status FROM checkout_attempts')->fetchColumn());
        self::assertSame('released', $this->pdo->query('SELECT status FROM checkout_attempt_holds')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT SUM(reserved_quantity) FROM inventory')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM outbox_messages')->fetchColumn());
        self::assertCount(1, (new CartService($this->pdo))->summary()['items']);
    }
}
