<?php

declare(strict_types=1);

namespace App\Modules\Checkout;

use App\Modules\Admin\AdminPushService;
use App\Modules\Cart\CartService;
use App\Shared\Config\Config;
use App\Shared\Support\RomanianLocations;
use PDO;
use RuntimeException;

final class CheckoutService
{
    private readonly RomanianLocations $locations;

    public function __construct(private readonly PDO $pdo, private readonly CartService $cart, private readonly Config $config, ?RomanianLocations $locations = null, private readonly ?AdminPushService $push = null)
    {
        $this->locations = $locations ?? RomanianLocations::fromProjectRoot();
    }

    /** @param array<string, mixed> $data @return array{order_number:string,token:string,id:int,payment_method:string,idempotency_key:string} */
    public function place(array $data): array
    {
        $required = ['email', 'first_name', 'last_name', 'phone', 'address', 'city', 'county', 'postal_code', 'payment_method', 'idempotency_key'];
        foreach ($required as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                throw new RuntimeException('Completează toate câmpurile obligatorii.');
            }
        }
        if (!filter_var((string) $data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Adresa de email nu este validă.');
        }
        if (!isset($data['terms'])) {
            throw new RuntimeException('Trebuie să accepți termenii pentru a plasa comanda.');
        }
        $officialLocation = $this->locations->canonicalize((string) $data['county'], (string) $data['city']);
        if ($officialLocation === null) {
            throw new RuntimeException('Alege județul și localitatea din nomenclatorul oficial disponibil în formular.');
        }
        $method = (string) $data['payment_method'];
        if (!in_array($method, ['cod', 'stripe'], true)) {
            throw new RuntimeException('Metodă de plată invalidă.');
        }
        if ($method === 'stripe' && (!$this->config->bool('STRIPE_ENABLED') || $this->config->get('STRIPE_SECRET_KEY') === '')) {
            throw new RuntimeException('Plata cu cardul nu este configurată încă.');
        }

        $cart = $this->cart->summary();
        if ($cart['items'] === []) {
            throw new RuntimeException('Coșul este gol.');
        }
        foreach ($cart['items'] as $item) {
            if ($item['product_status'] !== 'published' || (int) $item['quantity'] > (int) $item['available_quantity']) {
                throw new RuntimeException('Un produs nu mai este disponibil în cantitatea solicitată.');
            }
        }
        $idempotencyKey = hash('sha256', (string) $data['idempotency_key']);
        $existing = $this->pdo->prepare('SELECT id, order_number FROM orders WHERE idempotency_key = :key');
        $existing->execute(['key' => $idempotencyKey]);
        $found = $existing->fetch();
        if (is_array($found)) {
            throw new RuntimeException('Această comandă a fost deja trimisă.');
        }

        $discount = $cart['discount_bani'];
        $discountedSubtotal = $cart['subtotal_bani'] - $discount;
        $shipping = $discountedSubtotal >= $this->config->int('SHIPPING_FREE_THRESHOLD_BANI', 30000) ? 0 : $this->config->int('SHIPPING_FLAT_RATE_BANI', 1900);
        $tax = 0;
        $total = $cart['subtotal_bani'] - $discount + $shipping + $tax;
        $token = bin2hex(random_bytes(32));
        $address = [
            'address' => trim((string) $data['address']), 'city' => $officialLocation['locality'],
            'county' => $officialLocation['county'], 'postal_code' => trim((string) $data['postal_code']),
        ];
        $now = gmdate('c');

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare("INSERT INTO orders
                (order_number, public_token_hash, idempotency_key, cart_id, user_id, email, first_name, last_name, phone, customer_type, company_json, shipping_address_json, billing_address_json, subtotal_bani, discount_bani, shipping_bani, tax_bani, total_bani, currency, status, payment_status, payment_method, shipping_method, internal_notes, terms_accepted_at, marketing_consent, created_at, updated_at)
                VALUES (NULL, :token_hash, :idempotency, :cart_id, :user_id, :email, :first_name, :last_name, :phone, :customer_type, :company, :shipping_address, :billing_address, :subtotal, :discount, :shipping, :tax, :total, 'RON', 'new', 'pending', :payment_method, 'manual', '', :terms_at, :marketing, :created_at, :updated_at)");
            $insert->execute([
                'token_hash' => hash('sha256', $token), 'idempotency' => $idempotencyKey, 'cart_id' => $this->cart->cartId(),
                'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
                'email' => mb_strtolower(trim((string) $data['email'])), 'first_name' => trim((string) $data['first_name']), 'last_name' => trim((string) $data['last_name']), 'phone' => trim((string) $data['phone']),
                'customer_type' => ($data['customer_type'] ?? 'person') === 'company' ? 'company' : 'person',
                'company' => json_encode([], JSON_THROW_ON_ERROR), 'shipping_address' => json_encode($address, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'billing_address' => json_encode($address, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'subtotal' => $cart['subtotal_bani'],
                'discount' => $discount, 'shipping' => $shipping, 'tax' => $tax, 'total' => $total, 'payment_method' => $method,
                'terms_at' => $now, 'marketing' => isset($data['marketing']) ? 1 : 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $orderId = (int) $this->pdo->lastInsertId();
            $orderNumber = 'LM-' . gmdate('Y') . '-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
            $this->pdo->prepare('UPDATE orders SET order_number = :number WHERE id = :id')->execute(['number' => $orderNumber, 'id' => $orderId]);

            if (is_array($cart['coupon'])) {
                $lockSql = 'SELECT * FROM coupons WHERE id=:id' . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '');
                $lock = $this->pdo->prepare($lockSql);
                $lock->execute(['id' => $cart['coupon']['id']]);
                $coupon = $lock->fetch();
                if (!is_array($coupon) || $coupon['status'] !== 'active') {
                    throw new RuntimeException('Cuponul nu mai este disponibil.');
                }
                $usage = $this->pdo->prepare("SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id=:id AND status IN ('reserved','confirmed')");
                $usage->execute(['id' => $coupon['id']]);
                if ($coupon['usage_limit'] !== null && (int) $usage->fetchColumn() >= (int) $coupon['usage_limit']) {
                    throw new RuntimeException('Limita cuponului a fost atinsă între timp.');
                }
                if ($coupon['per_customer_limit'] !== null) {
                    $customerUsage = $this->pdo->prepare("SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id=:id AND email=:email AND status IN ('reserved','confirmed')");
                    $customerUsage->execute(['id' => $coupon['id'], 'email' => mb_strtolower(trim((string) $data['email']))]);
                    if ((int) $customerUsage->fetchColumn() >= (int) $coupon['per_customer_limit']) {
                        throw new RuntimeException('Cuponul a fost deja folosit de acest client de numărul maxim de ori.');
                    }
                }
                $this->pdo->prepare("INSERT INTO coupon_redemptions (coupon_id,order_id,email,status,created_at,updated_at) VALUES (:coupon,:order,:email,'reserved',:now,:now)")->execute([
                    'coupon' => $coupon['id'], 'order' => $orderId, 'email' => mb_strtolower(trim((string) $data['email'])), 'now' => $now,
                ]);
            }

            foreach ($cart['items'] as $item) {
                $reserve = $this->pdo->prepare('UPDATE inventory SET reserved_quantity = reserved_quantity + :quantity_add, updated_at = :now WHERE variant_id = :variant AND physical_quantity - reserved_quantity >= :quantity_check');
                $reserve->bindValue(':quantity_add', (int) $item['quantity'], PDO::PARAM_INT);
                $reserve->bindValue(':quantity_check', (int) $item['quantity'], PDO::PARAM_INT);
                $reserve->bindValue(':now', $now);
                $reserve->bindValue(':variant', (int) $item['variant_id'], PDO::PARAM_INT);
                $reserve->execute();
                if ($reserve->rowCount() !== 1) {
                    throw new RuntimeException('Stocul s-a modificat. Verifică din nou coșul.');
                }
                $this->pdo->prepare("INSERT INTO order_items (order_id, product_id, variant_id, product_name, variant_summary, sku, quantity, unit_price_bani, personalization_bani, line_total_bani, tax_bani, personalization_json)
                    SELECT :order_id, p.id, v.id, p.name, v.option_summary, v.sku, :quantity, v.price_bani, 0, v.price_bani * :line_quantity, 0, :personalization FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = :variant")->execute([
                    'order_id' => $orderId, 'quantity' => $item['quantity'], 'line_quantity' => $item['quantity'], 'personalization' => $item['personalization_json'], 'variant' => $item['variant_id'],
                ]);
                $this->pdo->prepare("INSERT INTO inventory_reservations (order_id, variant_id, quantity, status, expires_at, created_at, updated_at) VALUES (:order_id, :variant, :quantity, 'active', :expires, :now, :now)")->execute([
                    'order_id' => $orderId, 'variant' => $item['variant_id'], 'quantity' => $item['quantity'],
                    'expires' => $method === 'stripe' ? gmdate('c', time() + 3600) : null, 'now' => $now,
                ]);
                $this->pdo->prepare("INSERT INTO inventory_movements (variant_id, type, physical_delta, reserved_delta, reason, reference_type, reference_id, created_at) VALUES (:variant, 'reserve', 0, :quantity, 'Rezervare la plasarea comenzii', 'order', :order_id, :now)")->execute([
                    'variant' => $item['variant_id'], 'quantity' => $item['quantity'], 'order_id' => $orderId, 'now' => $now,
                ]);
            }
            $this->pdo->prepare("INSERT INTO payments (order_id, provider, amount_bani, refunded_bani, currency, status, created_at, updated_at) VALUES (:order_id, :provider, :amount, 0, 'RON', 'pending', :now, :now)")->execute([
                'order_id' => $orderId, 'provider' => $method, 'amount' => $total, 'now' => $now,
            ]);
            $this->pdo->prepare("INSERT INTO outbox_messages (message_type, recipient, payload_json, status, attempts, available_at, created_at) VALUES ('order_confirmation', :recipient, :payload, 'pending', 0, :now, :now)")->execute([
                'recipient' => $data['email'], 'payload' => json_encode(['order_id' => $orderId], JSON_THROW_ON_ERROR), 'now' => $now,
            ]);
            $this->pdo->prepare("UPDATE carts SET status = 'converted', updated_at = :now WHERE id = :id AND status = 'active'")->execute(['now' => $now, 'id' => $this->cart->cartId()]);
            $this->push?->queueOrder($orderId);
            $this->push?->queueLowStockForOrder($orderId);
            $this->pdo->commit();
            try {
                $this->push?->processPending(20);
            } catch (\Throwable) {
                // Comanda rămâne validă chiar dacă serviciul push este temporar indisponibil.
            }
            unset($_SESSION['cart_token']);
            return ['order_number' => $orderNumber, 'token' => $token, 'id' => $orderId, 'payment_method' => $method, 'idempotency_key' => $idempotencyKey];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
