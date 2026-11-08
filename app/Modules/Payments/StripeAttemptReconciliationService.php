<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Modules\Checkout\CheckoutAttemptService;
use App\Shared\Config\Config;
use PDO;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\Exception\InvalidRequestException;
use Stripe\Service\Checkout\SessionService;
use Stripe\StripeClient;
use Stripe\StripeClientInterface;

/** Frees abandoned holds only after Stripe proves there is no payable session. */
final class StripeAttemptReconciliationService
{
    private const GRACE_SECONDS = 300;
    private const MAX_PAGES = 20;

    public function __construct(private readonly PDO $pdo, private readonly Config $config, private readonly ?StripeClientInterface $client = null) {}

    /** @return array{checked:int,released:int,waiting_webhook:int,skipped:int,manual_review:int,failed:int,errors:list<array{attempt_id:int,code:string}>} */
    public function process(int $limit = 20): array
    {
        if ($this->pdo->inTransaction() || !$this->config->stripeConfigured()) {
            throw new RuntimeException('Reconcilierea necesită configurarea Stripe și o conexiune fără tranzacție activă.');
        }
        $result = ['checked' => 0, 'released' => 0, 'waiting_webhook' => 0, 'skipped' => 0, 'manual_review' => 0, 'failed' => 0, 'errors' => []];
        $query = $this->pdo->prepare("SELECT * FROM checkout_attempts WHERE status='pending' AND stripe_mode=:mode AND expires_at<=:deadline ORDER BY expires_at,id LIMIT :limit");
        $query->bindValue(':mode', $this->config->stripeMode());
        $query->bindValue(':deadline', gmdate('c', time() - self::GRACE_SECONDS));
        $query->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $query->execute();
        $sessions = new SessionService($this->client ?? new StripeClient($this->config->get('STRIPE_SECRET_KEY')));
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $attempt) {
            ++$result['checked'];
            if (!$this->sameAccount($attempt)) {
                ++$result['manual_review'];
                $result['errors'][] = ['attempt_id' => (int) $attempt['id'], 'code' => 'account_scope_unverified'];
                continue;
            }
            try {
                $outcome = $this->reconcile($attempt, $sessions);
                ++$result[$outcome];
            } catch (\Throwable $error) {
                ++$result['failed'];
                // Never expose provider exception messages, request bodies, email addresses or API keys.
                $safeCode = in_array($error->getMessage(), ['session_mismatch', 'ambiguous_sessions', 'scan_limit', 'invalid_session_list', 'unexpected_session_state'], true)
                    ? $error->getMessage() : 'provider_or_storage_error';
                $result['errors'][] = ['attempt_id' => (int) $attempt['id'], 'code' => $safeCode];
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $attempt */
    private function reconcile(array $attempt, SessionService $sessions): string
    {
        $sessionId = (string) ($attempt['checkout_session_id'] ?? '');
        if ($sessionId !== '') {
            $session = $sessions->retrieve($sessionId);
            $this->assertSession($session, $attempt, $sessionId);
        } else {
            $session = $this->findSession($attempt, $sessions);
            if ($session === null) { return $this->persist($attempt, null, true); }
            if ($this->persist($attempt, $session, false) === 'skipped') { return 'skipped'; }
            $attempt['checkout_session_id'] = $session->id;
        }
        if ($session->status === 'complete' || $session->payment_status === 'paid') { return 'waiting_webhook'; }
        if ($session->status === 'open') {
            try { $session = $sessions->expire((string) $session->id); }
            catch (InvalidRequestException) {
                // A customer may finish paying while the expiration request is in flight.
                $session = $sessions->retrieve((string) $session->id);
            }
            $this->assertSession($session, $attempt, (string) $attempt['checkout_session_id']);
            if ($session->status === 'complete' || $session->payment_status === 'paid') { return 'waiting_webhook'; }
        }
        if ($session->status !== 'expired' || $session->payment_status !== 'unpaid') { throw new RuntimeException('unexpected_session_state'); }
        return $this->persist($attempt, $session, true);
    }

    /** @param array<string,mixed> $attempt */
    private function findSession(array $attempt, SessionService $sessions): ?Session
    {
        $created = strtotime((string) $attempt['created_at']);
        $expires = strtotime((string) $attempt['expires_at']);
        if ($created === false || $expires === false || $created > $expires) { throw new RuntimeException('session_mismatch'); }
        $parameters = ['created' => ['gte' => max(0, $created - 60), 'lte' => $expires + self::GRACE_SECONDS], 'limit' => 100];
        $match = null;
        $previousCursor = '';
        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $collection = $sessions->all($parameters);
            if (!is_array($collection->data) || !is_bool($collection->has_more)) { throw new RuntimeException('invalid_session_list'); }
            foreach ($collection->data as $candidate) {
                if (!$candidate instanceof Session) { throw new RuntimeException('invalid_session_list'); }
                if ((string) ($candidate->metadata->checkout_attempt_id ?? '') !== (string) $attempt['id']
                    || !hash_equals((string) $attempt['idempotency_key'], (string) ($candidate->metadata->checkout_attempt_key ?? ''))) { continue; }
                $this->assertSession($candidate, $attempt);
                if ($match !== null && $match->id !== $candidate->id) { throw new RuntimeException('ambiguous_sessions'); }
                $match = $candidate;
            }
            if ($collection->has_more === false) { return $match; }
            $last = $collection->data[array_key_last($collection->data)] ?? null;
            $cursor = $last instanceof Session ? (string) $last->id : '';
            if ($cursor === '' || $cursor === $previousCursor) { throw new RuntimeException('invalid_session_list'); }
            $parameters['starting_after'] = $cursor;
            $previousCursor = $cursor;
        }
        throw new RuntimeException('scan_limit');
    }

    /** @param array<string,mixed> $attempt */
    private function assertSession(Session $session, array $attempt, ?string $expectedId = null): void
    {
        if (!is_string($session->id) || $session->id === '' || ($expectedId !== null && $session->id !== $expectedId)
            || $session->livemode !== ($attempt['stripe_mode'] === 'live')
            || (int) $session->amount_total !== (int) $attempt['total_bani']
            || strtoupper((string) $session->currency) !== strtoupper((string) $attempt['currency'])
            || (string) ($session->metadata->checkout_attempt_id ?? '') !== (string) $attempt['id']
            || !hash_equals((string) $attempt['idempotency_key'], (string) ($session->metadata->checkout_attempt_key ?? ''))) {
            throw new RuntimeException('session_mismatch');
        }
    }

    /** @param array<string,mixed> $attempt */
    private function sameAccount(array $attempt): bool
    {
        $snapshot = json_decode((string) $attempt['snapshot_json'], true);
        $fingerprint = is_array($snapshot) ? ($snapshot['stripe_key_fingerprint'] ?? '') : '';
        return is_string($fingerprint) && $fingerprint !== '' && hash_equals(hash('sha256', $this->config->get('STRIPE_SECRET_KEY')), $fingerprint);
    }

    /** @param array<string,mixed> $original */
    private function persist(array $original, ?Session $session, bool $release): string
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $cart = $this->pdo->prepare('SELECT id FROM carts WHERE id=:id' . $lock);
            $cart->execute(['id' => $original['cart_id']]);
            $attempts = new CheckoutAttemptService($this->pdo, $this->config);
            $current = $attempts->byId((int) $original['id'], true);
            if ($current === null || $current['status'] !== 'pending'
                || !$this->sameAccount($current) || $current['stripe_mode'] !== $this->config->stripeMode()
                || $current['idempotency_key'] !== $original['idempotency_key'] || $current['expires_at'] !== $original['expires_at']
                || strtotime((string) $current['expires_at']) > time() - self::GRACE_SECONDS
                || (string) ($current['checkout_session_id'] ?? '') !== (string) ($original['checkout_session_id'] ?? '')) {
                $this->pdo->commit();
                return 'skipped';
            }
            if ($session !== null) {
                $this->assertSession($session, $current);
                $this->pdo->prepare("UPDATE checkout_attempts SET checkout_session_id=:session,provider_payment_id=:intent,last_error=NULL,updated_at=:now WHERE id=:id AND status='pending'")
                    ->execute(['session' => $session->id, 'intent' => is_string($session->payment_intent) ? $session->payment_intent : null, 'now' => gmdate('c'), 'id' => $current['id']]);
            }
            if ($release) { $attempts->release((int) $current['id'], 'expired'); }
            $this->pdo->commit();
            return $release ? 'released' : 'waiting_webhook';
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }
}
