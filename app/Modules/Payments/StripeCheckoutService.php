<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Modules\Checkout\CheckoutAttemptService;
use App\Shared\Config\Config;
use PDO;
use RuntimeException;
use Stripe\StripeClient;
use Stripe\StripeClientInterface;
use Stripe\Service\Checkout\SessionService;
use Stripe\Service\CouponService;
use Stripe\Checkout\Session;

final class StripeCheckoutService
{
    private readonly PaymentMethodSettingsService $paymentSettings;

    public function __construct(private readonly PDO $pdo, private readonly Config $config, ?PaymentMethodSettingsService $paymentSettings = null, private readonly ?StripeClientInterface $client = null)
    {
        $this->paymentSettings = $paymentSettings ?? new PaymentMethodSettingsService($pdo, $config);
    }

    public function createSession(int $orderId, string $publicToken, string $idempotencyKey): string
    {
        if (!$this->paymentSettings->available()['stripe']) {
            throw new RuntimeException('Plata online cu cardul nu este disponibilă momentan în magazin.');
        }
        $statement = $this->pdo->prepare("SELECT * FROM orders WHERE id=:id AND payment_method='stripe' AND payment_status='pending' AND status='new'");
        $statement->execute(['id' => $orderId]);
        $order = $statement->fetch();
        if (!is_array($order) || !hash_equals((string) $order['public_token_hash'], hash('sha256', $publicToken))
            || !hash_equals((string) $order['idempotency_key'], $idempotencyKey)) {
            throw new RuntimeException('Comanda Stripe nu este eligibilă pentru inițierea plății.');
        }
        $paymentQuery = $this->pdo->prepare("SELECT * FROM payments WHERE order_id=:id AND provider='stripe' AND status='pending' ORDER BY id DESC LIMIT 1");
        $paymentQuery->execute(['id' => $orderId]);
        $payment = $paymentQuery->fetch();
        if (!is_array($payment) || (int) $payment['amount_bani'] !== (int) $order['total_bani'] || $payment['currency'] !== $order['currency']) {
            throw new RuntimeException('Înregistrarea plății nu corespunde comenzii.');
        }
        $client = $this->client ?? new StripeClient($this->config->get('STRIPE_SECRET_KEY'));
        $sessions = new SessionService($client);
        $existingSession = (string) ($payment['checkout_session_id'] ?? '');
        if ($existingSession !== '') {
            return $this->sessionUrl($sessions->retrieve($existingSession), $order);
        }
        $items = $this->pdo->prepare('SELECT item.*,COALESCE(variant_media.public_path,'
            . ' (SELECT media.public_path FROM product_images image JOIN media ON media.id=image.media_id WHERE image.product_id=item.product_id AND media.public_path IS NOT NULL ORDER BY image.position,image.media_id LIMIT 1),'
            . ' (SELECT media.public_path FROM product_categories pc JOIN categories category ON category.id=pc.category_id JOIN media ON media.id=category.image_media_id WHERE pc.product_id=item.product_id AND media.public_path IS NOT NULL ORDER BY category.position,category.id LIMIT 1)) AS image_path'
            . ' FROM order_items item LEFT JOIN product_variants variant ON variant.id=item.variant_id LEFT JOIN media variant_media ON variant_media.id=variant.image_media_id WHERE item.order_id=:id ORDER BY item.id');
        $items->execute(['id' => $orderId]);
        $builder = new StripeCheckoutPayload($this->config);
        $payload = $builder->build($order, $items->fetchAll(PDO::FETCH_ASSOC), $publicToken);
        $customerDetails = StripeCheckoutCustomer::details($order);
        $customer = (new \Stripe\Service\CustomerService($client))->create($customerDetails, ['idempotency_key' => 'checkout-customer-' . $idempotencyKey]);
        $payload['customer'] = $customer->id;
        unset($payload['customer_email']);
        $payload['payment_intent_data']['shipping'] = $customerDetails['shipping'];
        $coupon = $builder->coupon($order);
        if ($coupon !== null) {
            $preparedProducts = (new StripeCheckoutProducts($client))->prepare($payload);
            $payload = $preparedProducts['payload'];
            $coupon = $builder->coupon($order, $preparedProducts['merchandise_product_ids']);
            // Restrict the merchandise discount to merchandise: operational fees stay intact.
            // Delivery is already net of its own separately described shipping discount.
            (new CouponService($client))->create($coupon, ['idempotency_key' => 'checkout-discount-' . $idempotencyKey]);
        }
        $session = $sessions->create($payload, ['idempotency_key' => 'checkout-' . $idempotencyKey]);
        $url = $this->sessionUrl($session, $order);
        $this->pdo->prepare("UPDATE payments SET checkout_session_id=:session,provider_payment_id=:intent,updated_at=:now WHERE id=:id AND status='pending' AND (checkout_session_id IS NULL OR checkout_session_id=:same_session)")->execute([
            'session' => $session->id, 'same_session' => $session->id,
            'intent' => is_string($session->payment_intent) ? $session->payment_intent : null,
            'now' => gmdate('c'), 'id' => $payment['id'],
        ]);
        return $url;
    }

    /** New checkouts have an attempt and inventory holds, never an unpaid order. */
    public function createAttemptSession(int $attemptId, string $publicToken, string $idempotencyKey): string
    {
        if ($this->pdo->inTransaction()) { throw new RuntimeException('Stripe nu poate fi apelat într-o tranzacție de stoc.'); }
        $attempts = new CheckoutAttemptService($this->pdo, $this->config);
        $attempt = $attempts->byId($attemptId);
        if (!is_array($attempt) || !hash_equals((string) $attempt['public_token_hash'], hash('sha256', $publicToken))
            || !hash_equals((string) $attempt['idempotency_key'], $idempotencyKey)
            || !$this->config->stripeConfigured() || $attempt['stripe_mode'] !== $this->config->stripeMode()) {
            throw new RuntimeException('Încercarea de plată nu este disponibilă.');
        }
        $resultUrl = $this->config->rootUrl() . '/comanda/' . rawurlencode($publicToken) . '/?plata=verificare';
        if ($attempt['status'] === 'completed') { return $resultUrl; }
        if ($attempt['status'] !== 'pending') { throw new RuntimeException('Această încercare de plată s-a încheiat. Revino la coș.'); }
        $client = $this->client ?? new StripeClient($this->config->get('STRIPE_SECRET_KEY'));
        $sessions = new SessionService($client);
        $order = $attempts->snapshot($attempt);
        $order['id'] = $attemptId;
        $order['checkout_attempt_id'] = $attemptId;
        $order['expires_at'] = $attempt['expires_at'];
        $order['idempotency_key'] = $attempt['idempotency_key'];
        $existingSession = (string) ($attempt['checkout_session_id'] ?? '');
        if ($existingSession !== '') {
            $session = $sessions->retrieve($existingSession);
            $this->assertAttemptSession($session, $attempt);
            if ($session->status === 'complete') { return $resultUrl; }
            if ($session->status === 'expired') {
                $this->releaseAttempt($attemptId, $existingSession);
                throw new RuntimeException('Sesiunea de plată a expirat. Nu s-a creat o comandă.');
            }
            $url = $this->sessionUrl($session, $order);
            $this->pdo->prepare("UPDATE checkout_attempts SET last_error=NULL,updated_at=:now WHERE id=:id AND status='pending'")
                ->execute(['now' => gmdate('c'), 'id' => $attemptId]);
            return $url;
        }
        $builder = new StripeCheckoutPayload($this->config);
        $payload = $builder->build($order, $order['items'], $publicToken);
        $customerDetails = StripeCheckoutCustomer::details($order);
        $customer = (new \Stripe\Service\CustomerService($client))->create($customerDetails, ['idempotency_key' => 'attempt-customer-' . $idempotencyKey]);
        $payload['customer'] = $customer->id;
        unset($payload['customer_email']);
        $payload['payment_intent_data']['shipping'] = $customerDetails['shipping'];
        if ($builder->coupon($order) !== null) {
            $prepared = (new StripeCheckoutProducts($client))->prepare($payload);
            $payload = $prepared['payload'];
            $coupon = $builder->coupon($order, $prepared['merchandise_product_ids']);
            (new CouponService($client))->create($coupon, ['idempotency_key' => 'attempt-discount-' . $idempotencyKey]);
        }
        $session = $sessions->create($payload, ['idempotency_key' => 'attempt-checkout-' . $idempotencyKey]);
        $this->assertAttemptSession($session, $attempt);
        // A timeout can leave a real Stripe session behind. Retries reuse the same idempotency key.
        $save = $this->pdo->prepare("UPDATE checkout_attempts SET checkout_session_id=:session,provider_payment_id=:intent,last_error=NULL,updated_at=:now WHERE id=:id AND status='pending' AND (checkout_session_id IS NULL OR checkout_session_id=:same_session)");
        $save->execute(['session' => $session->id, 'same_session' => $session->id,
            'intent' => is_string($session->payment_intent) ? $session->payment_intent : null, 'now' => gmdate('c'), 'id' => $attemptId]);
        if ($session->status === 'complete') { return $resultUrl; }
        if ($session->status === 'expired') {
            $this->releaseAttempt($attemptId, (string) $session->id);
            throw new RuntimeException('Sesiunea de plată a expirat. Nu s-a creat o comandă.');
        }
        return $this->sessionUrl($session, $order);
    }

    public function markAttemptSessionFailure(int $attemptId): void
    {
        (new CheckoutAttemptService($this->pdo, $this->config))->markSessionFailure($attemptId);
    }

    public function cancelAttemptSession(int $attemptId, string $publicToken): void
    {
        if ($this->pdo->inTransaction()) { throw new RuntimeException('Stripe nu poate fi apelat într-o tranzacție de stoc.'); }
        $attempts = new CheckoutAttemptService($this->pdo, $this->config);
        $attempt = $attempts->byId($attemptId);
        if (!is_array($attempt) || !hash_equals((string) $attempt['public_token_hash'], hash('sha256', $publicToken))) {
            throw new RuntimeException('Încercarea de plată nu este disponibilă.');
        }
        if ($attempt['status'] === 'completed') { throw new RuntimeException('Plata a fost deja primită. Verificăm confirmarea comenzii.'); }
        if ($attempt['status'] !== 'pending') { return; }
        if (!$this->config->stripeConfigured() || $attempt['stripe_mode'] !== $this->config->stripeMode()) {
            throw new RuntimeException('Conexiunea cu procesatorul nu este disponibilă. Încearcă din nou.');
        }
        if (empty($attempt['checkout_session_id'])) {
            // Recover an ambiguous API timeout before releasing anything payable at Stripe.
            $this->createAttemptSession($attemptId, $publicToken, (string) $attempt['idempotency_key']);
            $attempt = $attempts->byId($attemptId);
        }
        if (!is_array($attempt) || empty($attempt['checkout_session_id'])) { throw new RuntimeException('Confirmarea închiderii plății nu este disponibilă încă.'); }
        $sessions = new SessionService($this->client ?? new StripeClient($this->config->get('STRIPE_SECRET_KEY')));
        $session = $sessions->retrieve((string) $attempt['checkout_session_id']);
        $this->assertAttemptSession($session, $attempt);
        if ($session->status === 'open') {
            try { $session = $sessions->expire((string) $session->id); }
            catch (\Stripe\Exception\InvalidRequestException $error) {
                $session = $sessions->retrieve((string) $session->id);
                if ($session->status === 'open') { throw $error; }
            }
            $this->assertAttemptSession($session, $attempt);
        }
        if ($session->status !== 'expired') { throw new RuntimeException('Plata a fost deja transmisă. Așteptăm confirmarea procesatorului.'); }
        $this->releaseAttempt($attemptId, (string) $session->id);
    }

    /** @param array<string,mixed> $attempt */
    private function assertAttemptSession(Session $session, array $attempt): void
    {
        if ($session->livemode !== ($attempt['stripe_mode'] === 'live')
            || (int) $session->amount_total !== (int) $attempt['total_bani']
            || strtoupper((string) $session->currency) !== strtoupper((string) $attempt['currency'])
            || (string) ($session->metadata->checkout_attempt_id ?? '') !== (string) $attempt['id']
            || !hash_equals((string) $attempt['idempotency_key'], (string) ($session->metadata->checkout_attempt_key ?? ''))) {
            throw new RuntimeException('Răspunsul Stripe nu corespunde încercării de plată.');
        }
    }

    private function releaseAttempt(int $attemptId, string $sessionId): void
    {
        $this->pdo->beginTransaction();
        try {
            $attempts = new CheckoutAttemptService($this->pdo, $this->config);
            $attempt = $attempts->byId($attemptId);
            if ($attempt !== null) {
                $cartLock = $this->pdo->prepare('SELECT id FROM carts WHERE id=:id' . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
                $cartLock->execute(['id' => $attempt['cart_id']]);
                $attempt = $attempts->byId($attemptId, true);
            }
            if (is_array($attempt) && $attempt['checkout_session_id'] === $sessionId) { $attempts->release($attemptId, 'expired'); }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    /** @param array<string,mixed> $order */
    private function sessionUrl(Session $session, array $order): string
    {
        $host = is_string($session->url) ? parse_url($session->url, PHP_URL_HOST) : null;
        if ($session->status !== 'open' || $session->payment_status !== 'unpaid'
            || $session->livemode !== ($this->config->get('STRIPE_MODE', 'test') === 'live')
            || (int) $session->amount_total !== (int) $order['total_bani']
            || strtolower((string) $session->currency) !== strtolower((string) $order['currency'])
            || (isset($order['checkout_attempt_id'])
                ? (string) ($session->metadata->checkout_attempt_id ?? '') !== (string) $order['checkout_attempt_id']
                : (string) ($session->metadata->order_id ?? '') !== (string) $order['id'])
            || !is_string($session->url) || parse_url($session->url, PHP_URL_SCHEME) !== 'https'
            || !is_string($host) || ($host !== 'checkout.stripe.com' && $host !== 'checkout.stripe.dev')) {
            throw new RuntimeException('Sesiunea de plată nu este disponibilă sau nu corespunde comenzii.');
        }
        return $session->url;
    }

    public function markSessionFailure(int $orderId, string $message): void
    {
        $this->pdo->beginTransaction();
        try {
            $now = gmdate('c');
            $statusUpdate = $this->pdo->prepare("UPDATE orders SET status='manual_review',updated_at=:now WHERE id=:id AND status='new'");
            $statusUpdate->execute(['now' => $now, 'id' => $orderId]);
            $statusChanged = $statusUpdate->rowCount() === 1;
            $this->pdo->prepare("UPDATE payments SET status='failed',updated_at=:now WHERE order_id=:id AND provider='stripe' AND status='pending'")->execute(['now' => $now, 'id' => $orderId]);
            $this->pdo->prepare("INSERT INTO audit_log (actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES (NULL,'stripe.session_failed','order',:id,:context,:now)")->execute(['id' => $orderId, 'context' => json_encode(['error' => 'Sesiunea Stripe nu a putut fi inițiată. Verifică disponibilitatea procesatorului și configurația privată.', 'status_changed'=>$statusChanged], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'now' => $now]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
