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
        $cartId = $this->cartId();
        if ($quantity <= 0) {
            $this->pdo->prepare('DELETE FROM cart_items WHERE id = :id AND cart_id = :cart')->execute(['id' => $itemId, 'cart' => $cartId]);
            return;
        }
        if ($quantity > 20) {
            throw new RuntimeException('Cantitatea maximă pe linie este 20.');
        }
        $statement = $this->pdo->prepare('SELECT ci.variant_id, (i.physical_quantity - i.reserved_quantity) AS available_quantity FROM cart_items ci JOIN inventory i ON i.variant_id = ci.variant_id WHERE ci.id = :id AND ci.cart_id = :cart');
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
        $code = strtoupper(trim($code));
        $cartId = $this->cartId();
        if ($code === '') {
            $this->pdo->prepare('UPDATE carts SET coupon_code=NULL,updated_at=:now WHERE id=:id')->execute(['now' => gmdate('c'), 'id' => $cartId]);
            return;
        }
        $statement = $this->pdo->prepare("SELECT * FROM coupons WHERE code=:code AND status='active' AND (starts_at IS NULL OR starts_at<=:now_start) AND (ends_at IS NULL OR ends_at>=:now_end)");
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
        if ($coupon['usage_limit'] !== null) {
            $usage = $this->pdo->prepare("SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id=:id AND status IN ('reserved','confirmed')");
            $usage->execute(['id' => $coupon['id']]);
            if ((int) $usage->fetchColumn() >= (int) $coupon['usage_limit']) {
                throw new RuntimeException('Limita de utilizare a cuponului a fost atinsă.');
            }
        }
        $this->pdo->prepare('UPDATE carts SET coupon_code=:code,updated_at=:now WHERE id=:id')->execute(['code' => $code, 'now' => $now, 'id' => $cartId]);
    }

    /** @return array{items:list<array<string,mixed>>,subtotal_bani:int,discount_bani:int,total_bani:int,count:int,coupon:?array<string,mixed>} */
    public function summary(): array
    {
        $summary = $this->baseSummary();
        $coupon = null;
        $discount = 0;
        $cart = $this->pdo->prepare('SELECT coupon_code FROM carts WHERE id=:id');
        $cart->execute(['id' => $this->cartId()]);
        $code = $cart->fetchColumn();
        if (is_string($code) && $code !== '') {
            $statement = $this->pdo->prepare("SELECT * FROM coupons WHERE code=:code AND status='active' AND minimum_bani<=:subtotal AND (starts_at IS NULL OR starts_at<=:now_start) AND (ends_at IS NULL OR ends_at>=:now_end)");
            $now = gmdate('c');
            $statement->execute(['code' => $code, 'subtotal' => $summary['subtotal_bani'], 'now_start' => $now, 'now_end' => $now]);
            $row = $statement->fetch();
            if (is_array($row)) {
                $coupon = $row;
                $discount = $row['type'] === 'percent'
                    ? intdiv(($summary['subtotal_bani'] * (int) $row['value']) + 5000, 10000)
                    : min($summary['subtotal_bani'], (int) $row['value']);
            }
        }
        return $summary + ['discount_bani' => $discount, 'total_bani' => $summary['subtotal_bani'] - $discount, 'coupon' => $coupon];
    }

    /** @return array{items:list<array<string,mixed>>,subtotal_bani:int,count:int} */
    private function baseSummary(): array
    {
        $statement = $this->pdo->prepare("SELECT ci.id, ci.variant_id, ci.quantity, ci.personalization_json,
                p.name, p.slug, p.status AS product_status, v.sku, v.option_summary, v.price_bani,
                COALESCE(i.physical_quantity - i.reserved_quantity, 0) AS available_quantity
            FROM cart_items ci
            JOIN product_variants v ON v.id = ci.variant_id
            JOIN products p ON p.id = v.product_id
            LEFT JOIN inventory i ON i.variant_id = v.id
            WHERE ci.cart_id = :cart ORDER BY ci.id");
        $statement->execute(['cart' => $this->cartId()]);
        $items = $statement->fetchAll();
        $subtotal = 0;
        $count = 0;
        foreach ($items as &$item) {
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
        $statement = $this->pdo->prepare("SELECT v.*, p.status AS product_status, COALESCE(i.physical_quantity - i.reserved_quantity, 0) AS available_quantity FROM product_variants v JOIN products p ON p.id = v.product_id LEFT JOIN inventory i ON i.variant_id = v.id WHERE v.id = :id");
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
