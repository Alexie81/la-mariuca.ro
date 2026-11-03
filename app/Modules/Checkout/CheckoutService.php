<?php

declare(strict_types=1);

namespace App\Modules\Checkout;

use App\Modules\Admin\AdminPushService;
use App\Modules\Cart\CartService;
use App\Modules\Shipping\ShippingMethodService;
use App\Shared\Config\Config;
use App\Shared\Support\RomanianLocations;
use PDO;
use RuntimeException;

final class CheckoutService
{
    private readonly RomanianLocations $locations;
    private readonly ShippingMethodService $shippingMethods;

    public function __construct(private readonly PDO $pdo, private readonly CartService $cart, private readonly Config $config, ?RomanianLocations $locations = null, private readonly ?AdminPushService $push = null, ?ShippingMethodService $shippingMethods = null)
    {
        $this->locations = $locations ?? RomanianLocations::fromProjectRoot();
        $this->shippingMethods = $shippingMethods ?? new ShippingMethodService($pdo);
    }

    /**
     * @param array<string,mixed> $cart
     * @param array<string,mixed> $shippingMethod
     * @return array{shipping_bani:int,shipping_discount_bani:int,total_bani:int,shipping_offer:?array<string,mixed>}
     */
    public function quoteShipping(array $cart, array $shippingMethod): array
    {
        $standardPrice = (int) $shippingMethod['price_bani'];
        $benefit = $this->cart->bestShippingDiscount($standardPrice, (array) ($cart['shipping_discounts'] ?? $cart['customer_shipping_discounts'] ?? []));
        $shipping = max(0, $standardPrice - $benefit['discount_bani']);

        return [
            'shipping_bani' => $shipping,
            'shipping_discount_bani' => $benefit['discount_bani'],
            'total_bani' => (int) $cart['subtotal_bani'] - (int) $cart['discount_bani'] + $shipping,
            'shipping_offer' => $benefit['discount'],
        ];
    }

    /** @param array<string, mixed> $data @return array{order_number:string,token:string,id:int,payment_method:string,idempotency_key:string} */
    public function place(array $data): array
    {
        $required = ['email', 'first_name', 'last_name', 'phone', 'address', 'city', 'county', 'payment_method', 'idempotency_key'];
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
        $postalCode = trim((string) ($data['postal_code'] ?? ''));
        if ($postalCode !== '' && preg_match('/^[0-9]{6}$/', $postalCode) !== 1) {
            throw new RuntimeException('Codul poștal este opțional. Dacă îl completezi, folosește 6 cifre.');
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
        $token = bin2hex(random_bytes(32));
        $address = [
            'address' => trim((string) $data['address']), 'city' => $officialLocation['locality'],
            'county' => $officialLocation['county'], 'postal_code' => $postalCode,
        ];
        $now = gmdate('c');

        $this->pdo->beginTransaction();
        try {
            $shippingMethod = $this->shippingMethods->requireActiveForCheckout((int) ($data['shipping_method_id'] ?? 0), true);
            $shippingQuote = $this->quoteShipping($cart, $shippingMethod);
            $shippingDiscount = $shippingQuote['shipping_discount_bani'];
            $shipping = $shippingQuote['shipping_bani'];
            $tax = 0;
            $total = $shippingQuote['total_bani'] + $tax;
            $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $merchandiseBenefit = is_array($cart['merchandise_discount'] ?? null) ? $cart['merchandise_discount'] : null;
            $shippingOffer = $shippingQuote['shipping_offer'];
            $customerDiscountId = is_array($merchandiseBenefit) && ($merchandiseBenefit['offer_source'] ?? '') === 'customer' ? (int) $merchandiseBenefit['id'] : null;
            $promotionId = is_array($merchandiseBenefit) && ($merchandiseBenefit['offer_source'] ?? '') === 'promotion' ? (int) $merchandiseBenefit['id'] : null;
            $customerShippingDiscountId = is_array($shippingOffer) && ($shippingOffer['offer_source'] ?? '') === 'customer' ? (int) $shippingOffer['id'] : null;
            $shippingPromotionId = is_array($shippingOffer) && ($shippingOffer['offer_source'] ?? '') === 'promotion' ? (int) $shippingOffer['id'] : null;
            $couponSelected = (is_array($merchandiseBenefit) && ($merchandiseBenefit['offer_source'] ?? '') === 'coupon')
                || (is_array($shippingOffer) && ($shippingOffer['offer_source'] ?? '') === 'coupon');
            $couponToRedeem = $couponSelected && is_array($cart['coupon_candidate'] ?? null) ? $cart['coupon_candidate'] : null;
            if ($customerDiscountId !== null) {
                $this->assertCustomerDiscountActive($customerDiscountId, (int) $userId, false);
            }
            if ($customerShippingDiscountId !== null) {
                $this->assertCustomerDiscountActive($customerShippingDiscountId, (int) $userId, true);
            }
            if ($promotionId !== null) {
                $this->assertPromotionActive($promotionId, false, (int) $cart['subtotal_bani']);
            }
            if ($shippingPromotionId !== null) {
                $this->assertPromotionActive($shippingPromotionId, true, (int) $cart['subtotal_bani']);
            }
            $insert = $this->pdo->prepare("INSERT INTO orders
                (order_number, public_token_hash, idempotency_key, cart_id, user_id, email, first_name, last_name, phone, customer_type, company_json, shipping_address_json, billing_address_json, subtotal_bani, discount_bani, customer_discount_id, customer_shipping_discount_id, promotion_id, shipping_promotion_id, shipping_bani, shipping_discount_bani, tax_bani, total_bani, currency, status, payment_status, payment_method, shipping_method, shipping_method_id, shipping_method_name, internal_notes, terms_accepted_at, marketing_consent, created_at, updated_at)
                VALUES (NULL, :token_hash, :idempotency, :cart_id, :user_id, :email, :first_name, :last_name, :phone, :customer_type, :company, :shipping_address, :billing_address, :subtotal, :discount, :customer_discount, :customer_shipping_discount, :promotion, :shipping_promotion, :shipping, :shipping_discount, :tax, :total, 'RON', 'new', 'pending', :payment_method, :shipping_method, :shipping_method_id, :shipping_method_name, '', :terms_at, :marketing, :created_at, :updated_at)");
            $insert->execute([
                'token_hash' => hash('sha256', $token), 'idempotency' => $idempotencyKey, 'cart_id' => $this->cart->cartId(),
                'user_id' => $userId,
                'email' => mb_strtolower(trim((string) $data['email'])), 'first_name' => trim((string) $data['first_name']), 'last_name' => trim((string) $data['last_name']), 'phone' => trim((string) $data['phone']),
                'customer_type' => ($data['customer_type'] ?? 'person') === 'company' ? 'company' : 'person',
                'company' => json_encode([], JSON_THROW_ON_ERROR), 'shipping_address' => json_encode($address, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'billing_address' => json_encode($address, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'subtotal' => $cart['subtotal_bani'],
                'discount' => $discount, 'customer_discount' => $customerDiscountId, 'customer_shipping_discount' => $customerShippingDiscountId,
                'promotion' => $promotionId, 'shipping_promotion' => $shippingPromotionId,
                'shipping' => $shipping, 'shipping_discount' => $shippingDiscount, 'tax' => $tax, 'total' => $total, 'payment_method' => $method,
                'shipping_method' => $shippingMethod['code'], 'shipping_method_id' => $shippingMethod['id'], 'shipping_method_name' => $shippingMethod['name'],
                'terms_at' => $now, 'marketing' => isset($data['marketing']) ? 1 : 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $orderId = (int) $this->pdo->lastInsertId();
            $orderNumber = 'LM-' . gmdate('Y') . '-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
            $this->pdo->prepare('UPDATE orders SET order_number = :number WHERE id = :id')->execute(['number' => $orderNumber, 'id' => $orderId]);

            if (is_array($couponToRedeem)) {
                $lockSql = 'SELECT * FROM coupons WHERE id=:id' . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '');
                $lock = $this->pdo->prepare($lockSql);
                $lock->execute(['id' => $couponToRedeem['id']]);
                $coupon = $lock->fetch();
                if (!is_array($coupon) || $coupon['status'] !== 'active' || $coupon['deleted_at'] !== null
                    || ($coupon['starts_at'] !== null && (string) $coupon['starts_at'] > $now)
                    || ($coupon['ends_at'] !== null && (string) $coupon['ends_at'] <= $now)
                    || (int) $coupon['minimum_bani'] > (int) $cart['subtotal_bani']) {
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
                $stockUnlimited = (int) ($item['stock_unlimited'] ?? 0) === 1;
                if (!$stockUnlimited) {
                    $reserve = $this->pdo->prepare('UPDATE inventory SET reserved_quantity = reserved_quantity + :quantity_add, updated_at = :now WHERE variant_id = :variant AND physical_quantity - reserved_quantity >= :quantity_check');
                    $reserve->bindValue(':quantity_add', (int) $item['quantity'], PDO::PARAM_INT);
                    $reserve->bindValue(':quantity_check', (int) $item['quantity'], PDO::PARAM_INT);
                    $reserve->bindValue(':now', $now);
                    $reserve->bindValue(':variant', (int) $item['variant_id'], PDO::PARAM_INT);
                    $reserve->execute();
                    if ($reserve->rowCount() !== 1) {
                        throw new RuntimeException('Stocul s-a modificat. Verifică din nou coșul.');
                    }
                }
                $accountingTarget = $this->accountingTargetForVariant((int) $item['variant_id']);
                $this->pdo->prepare("INSERT INTO order_items (order_id, product_id, variant_id, product_name, variant_summary, sku, quantity, unit_price_bani, personalization_bani, line_total_bani, tax_bani, personalization_json, accounting_scope_type, accounting_scope_id, accounting_sku)
                    SELECT :order_id, p.id, v.id, p.name, v.option_summary, v.sku, :quantity, v.price_bani, 0, v.price_bani * :line_quantity, 0, :personalization, :accounting_scope_type, :accounting_scope_id, :accounting_sku FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = :variant")->execute([
                    'order_id' => $orderId,
                    'quantity' => $item['quantity'],
                    'line_quantity' => $item['quantity'],
                    'personalization' => $item['personalization_json'],
                    'variant' => $item['variant_id'],
                    'accounting_scope_type' => $accountingTarget['scope_type'] ?? null,
                    'accounting_scope_id' => $accountingTarget['scope_id'] ?? null,
                    'accounting_sku' => $accountingTarget['sku'] ?? null,
                ]);
                if (!$stockUnlimited) {
                    $this->pdo->prepare("INSERT INTO inventory_reservations (order_id, variant_id, quantity, status, expires_at, created_at, updated_at) VALUES (:order_id, :variant, :quantity, 'active', :expires, :now, :now)")->execute([
                        'order_id' => $orderId, 'variant' => $item['variant_id'], 'quantity' => $item['quantity'],
                        'expires' => $method === 'stripe' ? gmdate('c', time() + 3600) : null, 'now' => $now,
                    ]);
                    $this->pdo->prepare("INSERT INTO inventory_movements (variant_id, type, physical_delta, reserved_delta, reason, reference_type, reference_id, created_at) VALUES (:variant, 'reserve', 0, :quantity, 'Rezervare la plasarea comenzii', 'order', :order_id, :now)")->execute([
                        'variant' => $item['variant_id'], 'quantity' => $item['quantity'], 'order_id' => $orderId, 'now' => $now,
                    ]);
                }
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

    private function assertCustomerDiscountActive(int $discountId, int $userId, bool $shipping): void
    {
        $sql = "SELECT id,scope FROM customer_discounts WHERE id=:id AND user_id=:user AND status='active' AND deleted_at IS NULL
            AND (starts_at IS NULL OR starts_at<=:starts) AND (ends_at IS NULL OR ends_at>:ends)";
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $now = gmdate('c');
        $statement->execute(['id' => $discountId, 'user' => $userId, 'starts' => $now, 'ends' => $now]);
        $discount = $statement->fetch();
        if (!is_array($discount) || (($discount['scope'] === 'shipping') !== $shipping)) {
            throw new RuntimeException('Reducerea personalizată nu mai este disponibilă. Reîncarcă pagina și încearcă din nou.');
        }
    }

    private function assertPromotionActive(int $promotionId, bool $shipping, int $subtotalBani): void
    {
        $sql = "SELECT id,scope FROM promotions WHERE id=:id AND status='active' AND deleted_at IS NULL
            AND minimum_bani<=:subtotal
            AND (starts_at IS NULL OR starts_at<=:starts) AND (ends_at IS NULL OR ends_at>:ends)";
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $now = gmdate('c');
        $statement->execute(['id' => $promotionId, 'subtotal' => $subtotalBani, 'starts' => $now, 'ends' => $now]);
        $promotion = $statement->fetch();
        if (!is_array($promotion) || (($promotion['scope'] === 'shipping') !== $shipping)) {
            throw new RuntimeException('Promoția nu mai este disponibilă. Reîncarcă pagina și încearcă din nou.');
        }
    }

    /** @return array{scope_type:'product'|'variant',scope_id:int,sku:string}|null */
    private function accountingTargetForVariant(int $variantId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT v.id AS variant_id,v.product_id,v.sku AS variant_sku,v.options_json,v.track_in_conta AS variant_track,
                    p.primary_sku,p.track_in_conta AS product_track
             FROM product_variants v
             JOIN products p ON p.id=v.product_id
             WHERE v.id=:variant_id LIMIT 1'
        );
        $statement->execute(['variant_id' => $variantId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Combinația produsului nu mai există.');
        }

        $options = json_decode((string) $row['options_json'], true);
        $hasCombination = is_array($options) && $options !== [];
        if ($hasCombination && (int) $row['variant_track'] === 1) {
            $sku = trim((string) $row['variant_sku']);
            if ($sku === '') {
                throw new RuntimeException('Combinația urmărită contabil nu are SKU.');
            }
            return ['scope_type' => 'variant', 'scope_id' => (int) $row['variant_id'], 'sku' => $sku];
        }
        if ((int) $row['product_track'] === 1) {
            $sku = trim((string) $row['primary_sku']);
            if ($sku === '') {
                throw new RuntimeException('Produsul urmărit contabil nu are SKU principal.');
            }
            return ['scope_type' => 'product', 'scope_id' => (int) $row['product_id'], 'sku' => $sku];
        }

        return null;
    }
}
