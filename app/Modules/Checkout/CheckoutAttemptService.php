<?php

declare(strict_types=1);

namespace App\Modules\Checkout;

use App\Modules\Admin\AdminPushService;
use App\Modules\Orders\InternalOrderNotificationService;
use App\Shared\Config\Config;
use PDO;
use RuntimeException;

/** Pending card checkouts are private attempts, never commercial orders. */
final class CheckoutAttemptService
{
    public function __construct(private readonly PDO $pdo, private readonly Config $config, private readonly ?AdminPushService $push = null) {}

    /** @return array<string,mixed>|null */
    public function byId(int $id, bool $lock = false): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM checkout_attempts WHERE id=:id' . ($lock ? $this->lock() : ''));
        $query->execute(['id' => $id]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function byToken(string $token): ?array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) { return null; }
        $query = $this->pdo->prepare('SELECT * FROM checkout_attempts WHERE public_token_hash=:hash');
        $query->execute(['hash' => hash('sha256', $token)]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function findPendingForCart(int $cartId): ?array
    {
        $query = $this->pdo->prepare("SELECT * FROM checkout_attempts WHERE active_cart_id=:id AND status='pending'");
        $query->execute(['id' => $cartId]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $attempt */
    public function publicToken(array $attempt): string
    {
        return hash_hmac('sha256', 'checkout-attempt:' . (string) $attempt['idempotency_key'], $this->config->get('APP_KEY'));
    }

    /** @param array<string,mixed> $attempt @return array<string,mixed> */
    public function snapshot(array $attempt): array
    {
        $snapshot = json_decode((string) $attempt['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot) || !is_array($snapshot['items'] ?? null)) { throw new RuntimeException('Datele încercării de plată nu sunt disponibile.'); }
        unset($snapshot['stripe_key_fingerprint']);
        $terminal = in_array($attempt['status'], ['failed', 'expired'], true);
        return array_replace($snapshot, [
            'id' => (int) $attempt['id'], 'attempt_id' => (int) $attempt['id'], 'is_payment_attempt' => true,
            'order_number' => '', 'status' => $terminal ? 'cancelled' : 'new',
            'payment_status' => $terminal ? 'failed' : 'pending', 'latest_payment_status' => $terminal || !empty($attempt['last_error']) ? 'failed' : 'pending',
            'public_token_hash' => $attempt['public_token_hash'], 'idempotency_key' => $attempt['idempotency_key'],
            'created_at' => $attempt['created_at'], 'updated_at' => $attempt['updated_at'],
            'shipping_address' => json_decode((string) $snapshot['shipping_address_json'], true, 512, JSON_THROW_ON_ERROR),
            'billing_address' => json_decode((string) $snapshot['billing_address_json'], true, 512, JSON_THROW_ON_ERROR),
            'payment_fees' => json_decode((string) $snapshot['payment_fees_json'], true, 512, JSON_THROW_ON_ERROR),
        ]);
    }

    /** Called only after server checkout validation, inside its transaction.
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed>|null $coupon
     */
    public function create(array $snapshot, string $token, string $idempotencyKey, ?array $coupon = null): int
    {
        $this->requireTransaction();
        if ((int) $snapshot['total_bani'] <= 0) { throw new RuntimeException('Plata cu cardul necesită o sumă pozitivă.'); }
        $cartId = (int) $snapshot['cart_id'];
        $cart = $this->pdo->prepare('SELECT status FROM carts WHERE id=:id' . $this->lock());
        $cart->execute(['id' => $cartId]);
        if ($cart->fetchColumn() !== 'active') { throw new RuntimeException('Coșul nu mai poate fi trimis.'); }
        $pending = $this->pdo->prepare("SELECT id FROM checkout_attempts WHERE cart_id=:cart AND status='pending'" . $this->lock());
        $pending->execute(['cart' => $cartId]);
        if ($pending->fetchColumn() !== false) { throw new RuntimeException('Există deja o plată în curs pentru acest coș. Revino la plata începută sau așteaptă expirarea acesteia.'); }
        $now = gmdate('c');
        $snapshot['stripe_key_fingerprint'] = hash('sha256', $this->config->get('STRIPE_SECRET_KEY'));
        $token = $this->publicToken(['idempotency_key' => $idempotencyKey]);
        $this->pdo->prepare("INSERT INTO checkout_attempts (public_token_hash,idempotency_key,cart_id,active_cart_id,user_id,status,currency,total_bani,snapshot_json,expires_at,stripe_mode,created_at,updated_at)
            VALUES (:token,:key,:cart,:active_cart,:user,'pending',:currency,:total,:snapshot,:expires,:mode,:created,:updated)")
            ->execute(['token' => hash('sha256', $token), 'key' => $idempotencyKey, 'cart' => $cartId, 'active_cart' => $cartId, 'user' => $snapshot['user_id'],
                'currency' => $snapshot['currency'], 'total' => $snapshot['total_bani'], 'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'expires' => gmdate('c', time() + 3600), 'mode' => $this->config->stripeMode(), 'created' => $now, 'updated' => $now]);
        $id = (int) $this->pdo->lastInsertId();
        $quantities = [];
        foreach ($snapshot['items'] as $item) {
            if ((int) ($item['stock_unlimited'] ?? 0) === 1) { continue; }
            $variant = (int) $item['variant_id'];
            $quantities[$variant] = ($quantities[$variant] ?? 0) + (int) $item['quantity'];
        }
        ksort($quantities);
        foreach ($quantities as $variant => $quantity) {
            if (!$this->reserve($variant, $quantity, $now)) { throw new RuntimeException('Stocul s-a modificat. Verifică din nou coșul.'); }
            $this->pdo->prepare("INSERT INTO checkout_attempt_holds (attempt_id,variant_id,quantity,status,created_at,updated_at) VALUES (:attempt,:variant,:quantity,'active',:created,:updated)")
                ->execute(['attempt' => $id, 'variant' => $variant, 'quantity' => $quantity, 'created' => $now, 'updated' => $now]);
            $this->movement($variant, $quantity, 'reserve', $id, $now);
        }
        if ($coupon !== null) {
            $this->pdo->prepare("INSERT INTO coupon_redemptions (coupon_id,attempt_id,order_id,email,status,merchandise_discount_bani,shipping_discount_bani,created_at,updated_at) VALUES (:coupon,:attempt,NULL,:email,'reserved',:merchandise,:shipping,:created,:updated)")
                ->execute(['coupon' => $coupon['id'], 'attempt' => $id, 'email' => $snapshot['email'],
                    'merchandise' => $coupon['merchandise_discount_bani'], 'shipping' => $coupon['shipping_discount_bani'], 'created' => $now, 'updated' => $now]);
        }
        return $id;
    }

    /** The verified webhook owns the transaction and validates Stripe amount/currency/session first. */
    public function complete(int $attemptId, string $intentId, string $sessionId): int
    {
        $this->requireTransaction();
        $initial = $this->byId($attemptId);
        if ($initial === null) { throw new RuntimeException('Încercarea de plată nu este disponibilă.'); }
        // Use the same cart -> attempt lock order as checkout creation and cart edits.
        $cart = $this->pdo->prepare('SELECT id FROM carts WHERE id=:id' . $this->lock());
        $cart->execute(['id' => $initial['cart_id']]);
        $attempt = $this->byId($attemptId, true);
        if ($attempt === null || $attempt['checkout_session_id'] !== $sessionId || !str_starts_with($intentId, 'pi_')
            || ($attempt['provider_payment_id'] !== null && $attempt['provider_payment_id'] !== $intentId)) {
            throw new RuntimeException('Încercarea de plată nu corespunde confirmării.');
        }
        if ($attempt['status'] === 'completed' && $attempt['order_id'] !== null) { return (int) $attempt['order_id']; }
        $snapshot = $this->snapshot($attempt);
        $now = gmdate('c');
        $holds = $this->pdo->prepare('SELECT * FROM checkout_attempt_holds WHERE attempt_id=:id ORDER BY variant_id' . $this->lock());
        $holds->execute(['id' => $attemptId]);
        $holds = $holds->fetchAll();
        $existingOrder = $this->pdo->prepare('SELECT id FROM orders WHERE cart_id=:id');
        $existingOrder->execute(['id' => $attempt['cart_id']]);
        $review = $existingOrder->fetchColumn() !== false;
        if ($review) {
            // Two real, delayed successful charges must both be traceable; never drop the latter payment.
            // The duplicate checkout is held for staff review, with its own immutable converted cart.
            $this->pdo->prepare("INSERT INTO carts (token,user_id,status,created_at,updated_at,expires_at) VALUES (:token,:user,'converted',:created,:updated,:expires)")
                ->execute(['token' => bin2hex(random_bytes(32)), 'user' => $attempt['user_id'], 'created' => $now, 'updated' => $now, 'expires' => $now]);
            $snapshot['cart_id'] = (int) $this->pdo->lastInsertId();
        }
        // A delayed success can arrive after expiry released its stock. Reacquire safely where possible.
        foreach ($holds as &$hold) {
            if ($hold['status'] === 'released') {
                if ($this->reserve((int) $hold['variant_id'], (int) $hold['quantity'], $now)) {
                    $hold['status'] = 'active';
                    $this->movement((int) $hold['variant_id'], (int) $hold['quantity'], 'reserve', $attemptId, $now);
                } else { $review = true; }
            }
        }
        unset($hold);
        $columns = ['public_token_hash','idempotency_key','cart_id','user_id','email','first_name','last_name','phone','customer_type','company_json',
            'shipping_address_json','billing_address_json','subtotal_bani','discount_bani','customer_discount_id','customer_shipping_discount_id','promotion_id','shipping_promotion_id',
            'shipping_bani','shipping_discount_bani','tax_bani','total_bani','currency','payment_method','shipping_method','shipping_method_id','shipping_method_name',
            'internal_notes','terms_accepted_at','marketing_consent','shipping_estimate_json','payment_fee_bani','payment_fees_json'];
        $values = [];
        foreach ($columns as $column) { $values[$column] = $snapshot[$column]; }
        $values['status'] = $review ? 'manual_review' : 'confirmed';
        $values['payment_status'] = 'paid';
        $values['created_at'] = $now;
        $values['updated_at'] = $now;
        $columns = array_keys($values);
        $this->pdo->prepare('INSERT INTO orders (' . implode(',', $columns) . ') VALUES (:' . implode(',:', $columns) . ')')->execute($values);
        $orderId = (int) $this->pdo->lastInsertId();
        $number = 'LM-' . gmdate('Y') . '-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
        $this->pdo->prepare('UPDATE orders SET order_number=:number WHERE id=:id')->execute(['number' => $number, 'id' => $orderId]);
        foreach ($snapshot['items'] as $item) {
            $columns = ['product_id','variant_id','product_name','variant_summary','sku','quantity','unit_price_bani','personalization_bani','line_total_bani','tax_bani','personalization_json','accounting_scope_type','accounting_scope_id','accounting_sku'];
            $values = ['order_id' => $orderId];
            foreach ($columns as $column) { $values[$column] = $item[$column]; }
            $columns = array_keys($values);
            $this->pdo->prepare('INSERT INTO order_items (' . implode(',', $columns) . ') VALUES (:' . implode(',:', $columns) . ')')->execute($values);
        }
        foreach ($holds as $hold) {
            if ($hold['status'] !== 'active') { continue; }
            $this->pdo->prepare("INSERT INTO inventory_reservations (order_id,variant_id,quantity,status,expires_at,created_at,updated_at) VALUES (:order_id,:variant,:quantity,'active',NULL,:created,:updated)")
                ->execute(['order_id' => $orderId, 'variant' => $hold['variant_id'], 'quantity' => $hold['quantity'], 'created' => $now, 'updated' => $now]);
            $this->pdo->prepare("UPDATE checkout_attempt_holds SET status='transferred',updated_at=:now WHERE id=:id")->execute(['now' => $now, 'id' => $hold['id']]);
        }
        $this->pdo->prepare("INSERT INTO payments (order_id,provider,provider_payment_id,checkout_session_id,amount_bani,refunded_bani,currency,status,created_at,updated_at) VALUES (:order_id,'stripe',:intent,:session,:amount,0,:currency,'paid',:created,:updated)")
            ->execute(['order_id' => $orderId, 'intent' => $intentId, 'session' => $sessionId, 'amount' => $attempt['total_bani'], 'currency' => $attempt['currency'], 'created' => $now, 'updated' => $now]);
        $this->pdo->prepare("UPDATE coupon_redemptions SET order_id=:order_id,status='confirmed',updated_at=:now WHERE attempt_id=:attempt")
            ->execute(['order_id' => $orderId, 'now' => $now, 'attempt' => $attemptId]);
        $this->pdo->prepare("UPDATE checkout_attempts SET status='completed',active_cart_id=NULL,last_error=NULL,order_id=:order_id,provider_payment_id=:intent,updated_at=:now WHERE id=:id")
            ->execute(['order_id' => $orderId, 'intent' => $intentId, 'now' => $now, 'id' => $attemptId]);
        $this->pdo->prepare("UPDATE carts SET status='converted',updated_at=:now WHERE id=:id")->execute(['now' => $now, 'id' => $attempt['cart_id']]);
        $this->pdo->prepare("INSERT INTO outbox_messages (message_type,recipient,payload_json,status,attempts,available_at,created_at) VALUES ('order_confirmation',:email,:payload,'pending',0,:available,:created)")
            ->execute(['email' => $snapshot['email'], 'payload' => json_encode(['order_id' => $orderId], JSON_THROW_ON_ERROR), 'available' => $now, 'created' => $now]);
        (new InternalOrderNotificationService($this->pdo))->queue($orderId);
        $this->pdo->prepare("INSERT INTO audit_log (actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES (NULL,:action,'order',:id,:context,:now)")
            ->execute(['action' => $review ? 'order.manual_review' : 'order.confirm', 'id' => $orderId,
                'context' => json_encode(['source' => 'stripe', 'checkout_attempt_id' => $attemptId, 'stock_review' => $review], JSON_THROW_ON_ERROR), 'now' => $now]);
        $this->push?->queueOrder($orderId);
        $this->push?->queuePayment($orderId, 'paid');
        $this->push?->queueLowStockForOrder($orderId);
        return $orderId;
    }

    public function release(int $attemptId, string $terminalStatus): void
    {
        $this->requireTransaction();
        if (!in_array($terminalStatus, ['failed', 'expired'], true)) { throw new RuntimeException('Stare de plată invalidă.'); }
        $attempt = $this->byId($attemptId, true);
        if ($attempt === null || $attempt['status'] === 'completed') { return; }
        $now = gmdate('c');
        $query = $this->pdo->prepare("SELECT * FROM checkout_attempt_holds WHERE attempt_id=:id AND status='active' ORDER BY variant_id" . $this->lock());
        $query->execute(['id' => $attemptId]);
        foreach ($query->fetchAll() as $hold) {
            $release = $this->pdo->prepare('UPDATE inventory SET reserved_quantity=reserved_quantity-:amount,updated_at=:now WHERE variant_id=:variant AND reserved_quantity>=:required');
            $release->bindValue(':amount', (int) $hold['quantity'], PDO::PARAM_INT);
            $release->bindValue(':required', (int) $hold['quantity'], PDO::PARAM_INT);
            $release->bindValue(':variant', (int) $hold['variant_id'], PDO::PARAM_INT);
            $release->bindValue(':now', $now);
            $release->execute();
            if ($release->rowCount() !== 1) { throw new RuntimeException('Rezervarea încercării de plată este inconsistentă.'); }
            $this->pdo->prepare("UPDATE checkout_attempt_holds SET status='released',updated_at=:now WHERE id=:id")->execute(['now' => $now, 'id' => $hold['id']]);
            $this->movement((int) $hold['variant_id'], -(int) $hold['quantity'], 'release', $attemptId, $now);
        }
        $this->pdo->prepare("UPDATE coupon_redemptions SET status='released',updated_at=:now WHERE attempt_id=:id AND status='reserved'")->execute(['now' => $now, 'id' => $attemptId]);
        $this->pdo->prepare("UPDATE checkout_attempts SET status=:status,active_cart_id=NULL,last_error=NULL,updated_at=:now WHERE id=:id AND status<>'completed'")->execute(['status' => $terminalStatus, 'now' => $now, 'id' => $attemptId]);
    }

    public function markSessionFailure(int $attemptId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE checkout_attempts SET last_error=:error,updated_at=:now WHERE id=:id AND status='pending'")
                ->execute(['error' => 'Sesiunea de plată nu a putut fi încărcată. Poți reîncerca în siguranță.', 'now' => gmdate('c'), 'id' => $attemptId]);
            $this->pdo->commit();
        }
        catch (\Throwable $error) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } throw $error; }
    }

    private function reserve(int $variant, int $quantity, string $now): bool
    {
        $query = $this->pdo->prepare('UPDATE inventory SET reserved_quantity=reserved_quantity+:amount,updated_at=:now WHERE variant_id=:variant AND physical_quantity-reserved_quantity>=:required');
        $query->bindValue(':amount', $quantity, PDO::PARAM_INT);
        $query->bindValue(':required', $quantity, PDO::PARAM_INT);
        $query->bindValue(':variant', $variant, PDO::PARAM_INT);
        $query->bindValue(':now', $now);
        $query->execute();
        return $query->rowCount() === 1;
    }

    private function movement(int $variant, int $quantity, string $type, int $attemptId, string $now): void
    {
        $this->pdo->prepare("INSERT INTO inventory_movements (variant_id,type,physical_delta,reserved_delta,reason,reference_type,reference_id,created_at) VALUES (:variant,:type,0,:quantity,'Încercare de plată card','checkout_attempt',:attempt,:now)")
            ->execute(['variant' => $variant, 'type' => $type, 'quantity' => $quantity, 'attempt' => $attemptId, 'now' => $now]);
    }

    private function lock(): string { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }
    private function requireTransaction(): void { if (!$this->pdo->inTransaction()) { throw new RuntimeException('Operația necesită tranzacție.'); } }
}
