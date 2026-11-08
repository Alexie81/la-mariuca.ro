<?php

declare(strict_types=1);

namespace App\Modules\Cart;

use PDO;
use RuntimeException;

final class CartService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function edit(callable $operation): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            $cartId = $this->cartId();
            $lock = $this->pdo->prepare('SELECT id FROM carts WHERE id=:id' . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $lock->execute(['id' => $cartId]);
            $pending = $this->pdo->prepare("SELECT id FROM checkout_attempts WHERE active_cart_id=:id AND status='pending'");
            $pending->execute(['id' => $cartId]);
            if ($pending->fetchColumn() !== false) { throw new RuntimeException('Ai o plată cu cardul în curs. Reia plata sau anulează încercarea din finalizarea comenzii înainte de a modifica acest coș.'); }
            $result = $operation();
            if ($ownsTransaction) { $this->pdo->commit(); }
            return $result;
        } catch (\Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    public function token(): string
    {
        if (!isset($_SESSION['cart_token']) || !is_string($_SESSION['cart_token'])) {
            $_SESSION['cart_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['cart_token'];
    }

    public function cartId(): int
    {
        $token = $this->token();
        $statement = $this->pdo->prepare("SELECT id FROM carts WHERE token = :token AND status = 'active'");
        $statement->execute(['token' => $token]);
        $id = $statement->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $oldCart = $this->pdo->prepare('SELECT id FROM carts WHERE token=:token');
        $oldCart->execute(['token' => $token]);
        if ($oldCart->fetchColumn() !== false) {
            // A successful webhook converts the cart in another request, without this browser's session.
            $_SESSION['cart_token'] = $token = bin2hex(random_bytes(32));
        }
        $now = gmdate('c');
        $statement = $this->pdo->prepare("INSERT INTO carts (token, user_id, status, created_at, updated_at, expires_at) VALUES (:token, :user_id, 'active', :now, :now, :expires)");
        $statement->execute([
            'token' => $token,
            'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            'now' => $now,
            'expires' => gmdate('c', time() + 30 * 86400),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, string> $personalization */
    public function add(int $variantId, int $quantity, array $personalization = []): void
    {
        $this->edit(function () use ($variantId, $quantity, $personalization): void { $this->addUnlocked($variantId, $quantity, $personalization); });
    }

    /** @param array<string,string> $personalization */
    private function addUnlocked(int $variantId, int $quantity, array $personalization): void
    {
        if ($quantity < 1 || $quantity > 20) {
            throw new RuntimeException('Cantitatea trebuie să fie între 1 și 20.');
        }
        $variant = $this->variant($variantId);
        if ($variant === null || $variant['product_status'] !== 'published' || $variant['status'] !== 'active') {
            throw new RuntimeException('Varianta nu este disponibilă.');
        }
        $this->validatePersonalization((int) $variant['product_id'], $personalization);
        $key = hash('sha256', json_encode($personalization, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $cartId = $this->cartId();
        $statement = $this->pdo->prepare('SELECT id, quantity FROM cart_items WHERE cart_id = :cart AND variant_id = :variant AND personalization_key = :key');
        $statement->execute(['cart' => $cartId, 'variant' => $variantId, 'key' => $key]);
        $existing = $statement->fetch();
        $newQuantity = $quantity + (is_array($existing) ? (int) $existing['quantity'] : 0);
        if ($newQuantity > (int) $variant['available_quantity']) {
            throw new RuntimeException('Stoc insuficient pentru cantitatea solicitată.');
        }
        $now = gmdate('c');
        if (is_array($existing)) {
            $this->pdo->prepare('UPDATE cart_items SET quantity = :quantity, updated_at = :now WHERE id = :id')->execute(['quantity' => $newQuantity, 'now' => $now, 'id' => $existing['id']]);
        } else {
            $this->pdo->prepare('INSERT INTO cart_items (cart_id, variant_id, quantity, personalization_json, personalization_key, created_at, updated_at) VALUES (:cart, :variant, :quantity, :personalization, :key, :now, :now)')->execute([
                'cart' => $cartId, 'variant' => $variantId, 'quantity' => $quantity,
                'personalization' => json_encode($personalization, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'key' => $key, 'now' => $now,
            ]);
        }
        $this->pdo->prepare('UPDATE carts SET updated_at = :now WHERE id = :id')->execute(['now' => $now, 'id' => $cartId]);
    }

    /** @param array<string, string> $personalization @return 'added'|'removed' */
    public function toggle(int $variantId, int $quantity = 1, array $personalization = []): string
    {
        return $this->edit(fn (): string => $this->toggleUnlocked($variantId, $quantity, $personalization));
    }

    /** @param array<string,string> $personalization @return 'added'|'removed' */
    private function toggleUnlocked(int $variantId, int $quantity, array $personalization): string
    {
        $cartId = $this->cartId();
        $statement = $this->pdo->prepare('SELECT 1 FROM cart_items WHERE cart_id = :cart AND variant_id = :variant LIMIT 1');
        $statement->execute(['cart' => $cartId, 'variant' => $variantId]);
        $exists = $statement->fetchColumn();

        if ($exists !== false) {
            $this->pdo->prepare('DELETE FROM cart_items WHERE cart_id = :cart AND variant_id = :variant')->execute([
                'cart' => $cartId,
                'variant' => $variantId,
            ]);
            $this->pdo->prepare('UPDATE carts SET updated_at = :now WHERE id = :id')->execute([
                'now' => gmdate('c'),
                'id' => $cartId,
            ]);
            return 'removed';
        }

        $this->add($variantId, $quantity, $personalization);
        return 'added';
    }

    public function update(int $itemId, int $quantity): void
    {
        $this->edit(function () use ($itemId, $quantity): void { $this->updateUnlocked($itemId, $quantity); });
    }

    private function updateUnlocked(int $itemId, int $quantity): void
    {
        $cartId = $this->cartId();
        if ($quantity <= 0) {
            $this->pdo->prepare('DELETE FROM cart_items WHERE id = :id AND cart_id = :cart')->execute(['id' => $itemId, 'cart' => $cartId]);
            return;
        }
        if ($quantity > 20) {
            throw new RuntimeException('Cantitatea maximă pe linie este 20.');
        }
        $statement = $this->pdo->prepare('SELECT ci.variant_id, CASE WHEN v.stock_unlimited=1 THEN 999999 ELSE (i.physical_quantity - i.reserved_quantity) END AS available_quantity FROM cart_items ci JOIN product_variants v ON v.id=ci.variant_id JOIN inventory i ON i.variant_id = ci.variant_id WHERE ci.id = :id AND ci.cart_id = :cart');
        $statement->execute(['id' => $itemId, 'cart' => $cartId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Linia nu aparține coșului curent.');
        }
        if ($quantity > (int) $row['available_quantity']) {
            throw new RuntimeException('Stoc insuficient.');
        }
        $this->pdo->prepare('UPDATE cart_items SET quantity = :quantity, updated_at = :now WHERE id = :id')->execute(['quantity' => $quantity, 'now' => gmdate('c'), 'id' => $itemId]);
    }

    public function applyCoupon(string $code): void
    {
        $this->edit(function () use ($code): void { $this->applyCouponUnlocked($code); });
    }

    private function applyCouponUnlocked(string $code): void
    {
        $code = strtoupper(trim($code));
        $cartId = $this->cartId();
        if ($code === '') {
            $this->pdo->prepare('UPDATE carts SET coupon_code=NULL,updated_at=:now WHERE id=:id')->execute(['now' => gmdate('c'), 'id' => $cartId]);
            return;
        }
        $statement = $this->pdo->prepare("SELECT * FROM coupons WHERE code=:code AND status='active' AND deleted_at IS NULL AND (starts_at IS NULL OR starts_at<=:now_start) AND (ends_at IS NULL OR ends_at>:now_end)");
        $now = gmdate('c');
        $statement->execute(['code' => $code, 'now_start' => $now, 'now_end' => $now]);
        $coupon = $statement->fetch();
        if (!is_array($coupon)) {
            throw new RuntimeException('Cuponul nu este valid sau activ.');
        }
        $subtotal = $this->baseSummary()['subtotal_bani'];
        if ($subtotal < (int) $coupon['minimum_bani']) {
            throw new RuntimeException('Valoarea coșului nu atinge pragul minim al cuponului.');
        }
        $benefits = $this->evaluateDiscounts([$coupon], $this->baseSummary(), 'coupon');
        if ($benefits['merchandise'] === null && $benefits['shipping'] === []) {
            throw new RuntimeException('Cuponul nu se aplică produselor din coșul curent.');
        }
        if ($coupon['usage_limit'] !== null) {
            $usage = $this->pdo->prepare("SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id=:id AND status IN ('reserved','confirmed')");
            $usage->execute(['id' => $coupon['id']]);
            if ((int) $usage->fetchColumn() >= (int) $coupon['usage_limit']) {
                throw new RuntimeException('Limita de utilizare a cuponului a fost atinsă.');
            }
        }
        $this->pdo->prepare('UPDATE carts SET coupon_code=:code,updated_at=:now WHERE id=:id')->execute(['code' => $code, 'now' => $now, 'id' => $cartId]);
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $summary = $this->baseSummary();
        $couponCandidate = null;
        $couponBenefits = ['merchandise' => null, 'shipping' => []];
        $cart = $this->pdo->prepare('SELECT coupon_code FROM carts WHERE id=:id');
        $cart->execute(['id' => $this->cartId()]);
        $code = $cart->fetchColumn();
        if (is_string($code) && $code !== '') {
            $statement = $this->pdo->prepare("SELECT * FROM coupons WHERE code=:code AND status='active' AND deleted_at IS NULL AND minimum_bani<=:subtotal AND (starts_at IS NULL OR starts_at<=:now_start) AND (ends_at IS NULL OR ends_at>:now_end)");
            $now = gmdate('c');
            $statement->execute(['code' => $code, 'subtotal' => $summary['subtotal_bani'], 'now_start' => $now, 'now_end' => $now]);
            $row = $statement->fetch();
            if (is_array($row)) {
                $couponCandidate = $row;
                $couponBenefits = $this->evaluateDiscounts([$row], $summary, 'coupon');
            }
        }
        $personal = $this->personalDiscounts($summary);
        $global = $this->globalPromotions($summary);
        $selected = $couponBenefits['merchandise'];
        foreach ([$personal['merchandise'], $global['merchandise']] as $candidate) {
            if (is_array($candidate) && (int) $candidate['discount_bani'] > (int) ($selected['discount_bani'] ?? 0)) {
                $selected = $candidate;
            }
        }
        $discount = (int) ($selected['discount_bani'] ?? 0);
        $selectedSource = (string) ($selected['offer_source'] ?? '');
        $customerDiscount = $selectedSource === 'customer' ? $selected : null;
        $promotion = $selectedSource === 'promotion' ? $selected : null;
        $coupon = $selectedSource === 'coupon' ? $couponCandidate : null;
        $discountLabel = match ($selectedSource) {
            'customer' => 'personală · ' . (string) ($selected['label'] ?? 'comandă'),
            'promotion' => (string) ($selected['name'] ?? 'promoție automată'),
            'coupon' => (string) ($couponCandidate['code'] ?? ''),
            default => '',
        };
        $shippingDiscounts = array_merge($couponBenefits['shipping'], $personal['shipping'], $global['shipping']);
        return $summary + [
            'discount_bani' => $discount,
            'total_bani' => $summary['subtotal_bani'] - $discount,
            'coupon' => $coupon,
            'coupon_candidate' => $couponCandidate,
            'merchandise_discount' => $selected,
            'promotion' => $promotion,
            'customer_discount' => $customerDiscount,
            'shipping_discounts' => $shippingDiscounts,
            'customer_shipping_discounts' => $shippingDiscounts,
            'discount_label' => $discountLabel,
        ];
    }

    /** @param list<array<string,mixed>> $discounts @return array{discount_bani:int,discount:?array<string,mixed>} */
    public function bestShippingDiscount(int $shippingBani, array $discounts): array
    {
        $best = 0;
        $selected = null;
        foreach ($discounts as $discount) {
            $amount = match ((string) ($discount['value_type'] ?? '')) {
                'free' => $shippingBani,
                'percent' => intdiv(($shippingBani * (int) $discount['value']) + 5000, 10000),
                'fixed' => min($shippingBani, (int) $discount['value']),
                default => 0,
            };
            if ($amount > $best) {
                $best = $amount;
                $selected = $discount;
            }
        }
        return ['discount_bani' => $best, 'discount' => $selected];
    }

    /** @param array{items:list<array<string,mixed>>,subtotal_bani:int,count:int} $summary @return array{merchandise:?array<string,mixed>,shipping:list<array<string,mixed>>} */
    private function personalDiscounts(array $summary): array
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId < 1 || $summary['items'] === []) {
            return ['merchandise' => null, 'shipping' => []];
        }
        $now = gmdate('c');
        $statement = $this->pdo->prepare("SELECT d.* FROM customer_discounts d JOIN users u ON u.id=d.user_id
            WHERE d.user_id=:user AND u.status='active' AND d.status='active' AND d.deleted_at IS NULL
              AND (d.starts_at IS NULL OR d.starts_at<=:starts) AND (d.ends_at IS NULL OR d.ends_at>=:ends)
            ORDER BY d.id");
        $statement->execute(['user' => $userId, 'starts' => $now, 'ends' => $now]);
        $discounts = $statement->fetchAll();
        if ($discounts === []) {
            return ['merchandise' => null, 'shipping' => []];
        }

        return $this->evaluateDiscounts($discounts, $summary, 'customer');
    }

    /** @param array{items:list<array<string,mixed>>,subtotal_bani:int,count:int} $summary @return array{merchandise:?array<string,mixed>,shipping:list<array<string,mixed>>} */
    private function globalPromotions(array $summary): array
    {
        if ($summary['items'] === []) {
            return ['merchandise' => null, 'shipping' => []];
        }
        $now = gmdate('c');
        $statement = $this->pdo->prepare("SELECT * FROM promotions
            WHERE status='active' AND deleted_at IS NULL
              AND minimum_bani<=:subtotal
              AND (starts_at IS NULL OR starts_at<=:starts) AND (ends_at IS NULL OR ends_at>:ends)
            ORDER BY id");
        $statement->execute(['subtotal' => $summary['subtotal_bani'], 'starts' => $now, 'ends' => $now]);
        $discounts = $statement->fetchAll();

        return $discounts === []
            ? ['merchandise' => null, 'shipping' => []]
            : $this->evaluateDiscounts($discounts, $summary, 'promotion');
    }

    /**
     * @param list<array<string,mixed>> $discounts
     * @param array{items:list<array<string,mixed>>,subtotal_bani:int,count:int} $summary
     * @return array{merchandise:?array<string,mixed>,shipping:list<array<string,mixed>>}
     */
    private function evaluateDiscounts(array $discounts, array $summary, string $source): array
    {
        if ($discounts === [] || $summary['items'] === []) {
            return ['merchandise' => null, 'shipping' => []];
        }

        $categories = [];
        $categoryParents = [];
        $productIds = array_values(array_unique(array_map(static fn (array $item): int => (int) $item['product_id'], $summary['items'])));
        if ($productIds !== []) {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $categoryRows = $this->pdo->prepare("SELECT product_id,category_id FROM product_categories WHERE product_id IN ({$placeholders})");
            $categoryRows->execute($productIds);
            foreach ($categoryRows->fetchAll() as $row) {
                $categories[(int) $row['product_id']][] = (int) $row['category_id'];
            }
        }
        if (array_filter($discounts, static fn (array $discount): bool => $discount['scope'] === 'category') !== []) {
            foreach ($this->pdo->query('SELECT id,parent_id FROM categories')->fetchAll() as $category) {
                $categoryParents[(int) $category['id']] = (int) ($category['parent_id'] ?? 0);
            }
        }

        $best = null;
        $shipping = [];
        foreach ($discounts as $discount) {
            $discount['offer_source'] = $source;
            $discount['value_type'] = trim((string) ($discount['value_type'] ?? '')) !== ''
                ? (string) $discount['value_type']
                : (string) ($discount['type'] ?? '');
            if ($discount['scope'] === 'shipping') {
                $shipping[] = $discount;
                continue;
            }
            $eligible = 0;
            foreach ($summary['items'] as $item) {
                $matches = $discount['scope'] === 'order'
                    || ($discount['scope'] === 'product' && (int) $discount['target_id'] === (int) $item['product_id'])
                    || ($discount['scope'] === 'category' && $this->productBelongsToCategory(
                        $categories[(int) $item['product_id']] ?? [],
                        (int) $discount['target_id'],
                        $categoryParents,
                    ));
                if ($matches) {
                    $eligible += (int) $item['line_total_bani'];
                }
            }
            if ($eligible < 1) {
                continue;
            }
            $amount = $discount['value_type'] === 'percent'
                ? intdiv(($eligible * (int) $discount['value']) + 5000, 10000)
                : ($discount['value_type'] === 'fixed' ? min($eligible, (int) $discount['value']) : 0);
            if ($amount > (int) ($best['discount_bani'] ?? 0)) {
                $discount['discount_bani'] = $amount;
                $discount['eligible_bani'] = $eligible;
                $discount['label'] = match ($source) {
                    'coupon' => (string) ($discount['code'] ?? 'cupon'),
                    'promotion' => (string) ($discount['name'] ?? 'promoție'),
                    default => match ((string) $discount['scope']) {
                        'product' => 'produs', 'category' => 'categorie', default => 'comandă',
                    },
                };
                $best = $discount;
            }
        }
        return ['merchandise' => $best, 'shipping' => $shipping];
    }

    /** @param list<int> $assignedCategoryIds @param array<int,int> $parents */
    private function productBelongsToCategory(array $assignedCategoryIds, int $targetCategoryId, array $parents): bool
    {
        foreach ($assignedCategoryIds as $categoryId) {
            $current = $categoryId;
            $guard = 0;
            while ($current > 0 && $guard++ < 30) {
                if ($current === $targetCategoryId) {
                    return true;
                }
                $current = $parents[$current] ?? 0;
            }
        }
        return false;
    }

    /** @return array{items:list<array<string,mixed>>,subtotal_bani:int,count:int} */
    private function baseSummary(): array
    {
        $statement = $this->pdo->prepare("SELECT ci.id, ci.variant_id, ci.quantity, ci.personalization_json,
                p.id AS product_id, p.name, p.slug, p.status AS product_status, v.sku, v.option_summary, v.options_json, v.price_bani,
                v.stock_unlimited,
                COALESCE(variant_media.public_path,
                    (SELECT product_media.public_path
                     FROM product_images pi
                     JOIN media product_media ON product_media.id = pi.media_id
                     WHERE pi.product_id = p.id AND product_media.public_path IS NOT NULL
                     ORDER BY pi.position, pi.media_id LIMIT 1)
                ) AS image_path,
                COALESCE(NULLIF(variant_media.alt_text, ''),
                    (SELECT product_media.alt_text
                     FROM product_images pi
                     JOIN media product_media ON product_media.id = pi.media_id
                     WHERE pi.product_id = p.id AND product_media.public_path IS NOT NULL
                     ORDER BY pi.position, pi.media_id LIMIT 1),
                    p.name
                ) AS image_alt,
                CASE WHEN v.stock_unlimited=1 THEN 999999 ELSE COALESCE(i.physical_quantity - i.reserved_quantity, 0) END AS available_quantity
            FROM cart_items ci
            JOIN product_variants v ON v.id = ci.variant_id
            JOIN products p ON p.id = v.product_id
            LEFT JOIN media variant_media ON variant_media.id = v.image_media_id
            LEFT JOIN inventory i ON i.variant_id = v.id
            WHERE ci.cart_id = :cart ORDER BY ci.id");
        $statement->execute(['cart' => $this->cartId()]);
        $items = $statement->fetchAll();
        $subtotal = 0;
        $count = 0;
        foreach ($items as &$item) {
            $variantOptions = json_decode((string) $item['options_json'], true);
            if (!is_array($variantOptions) || $variantOptions === []) {
                $item['option_summary'] = '';
            }
            unset($item['options_json']);
            $item['personalization'] = json_decode((string) $item['personalization_json'], true) ?: [];
            $item['line_total_bani'] = (int) $item['price_bani'] * (int) $item['quantity'];
            $subtotal += $item['line_total_bani'];
            $count += (int) $item['quantity'];
        }
        return ['items' => $items, 'subtotal_bani' => $subtotal, 'count' => $count];
    }

    /** @return array<string, mixed>|null */
    private function variant(int $id): ?array
    {
        $statement = $this->pdo->prepare("SELECT v.*, p.status AS product_status, CASE WHEN v.stock_unlimited=1 THEN 999999 ELSE COALESCE(i.physical_quantity - i.reserved_quantity, 0) END AS available_quantity FROM product_variants v JOIN products p ON p.id = v.product_id LEFT JOIN inventory i ON i.variant_id = v.id WHERE v.id = :id");
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<string, string> $values */
    private function validatePersonalization(int $productId, array $values): void
    {
        $statement = $this->pdo->prepare('SELECT * FROM personalization_options WHERE product_id = :id');
        $statement->execute(['id' => $productId]);
        foreach ($statement->fetchAll() as $option) {
            $key = 'option_' . $option['id'];
            $value = trim((string) ($values[$key] ?? ''));
            if ((int) $option['required'] === 1 && $value === '') {
                throw new RuntimeException('Completează opțiunea „' . $option['label'] . '”.');
            }
            if ($option['type'] === 'text' && $option['max_length'] !== null && mb_strlen($value) > (int) $option['max_length']) {
                throw new RuntimeException('Textul pentru „' . $option['label'] . '” este prea lung.');
            }
            if ($option['type'] === 'select') {
                $allowed = json_decode((string) $option['allowed_values_json'], true) ?: [];
                if ($value !== '' && !in_array($value, $allowed, true)) {
                    throw new RuntimeException('Valoare invalidă pentru personalizare.');
                }
            }
        }
    }
}
