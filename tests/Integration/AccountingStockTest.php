<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Inventory\AccountingStockService;
use App\Modules\Orders\OrderService;
use App\Shared\Config\Config;
use Tests\DatabaseTestCase;

final class AccountingStockTest extends DatabaseTestCase
{
    public function testInvoiceFallsBackToParentAndNirRestoresTheSameBucket(): void
    {
        $this->seed();
        $variant = $this->variantWithOptions();
        $productId = (int) $variant['product_id'];
        $variantId = (int) $variant['id'];
        $this->pdo->prepare("UPDATE products SET primary_sku='TEST-PARENT-ACCOUNT',track_in_conta=1 WHERE id=:id")->execute(['id' => $productId]);
        $this->pdo->prepare('UPDATE product_variants SET track_in_conta=0 WHERE product_id=:id')->execute(['id' => $productId]);

        $orderId = $this->placeOrder($variantId, 2, 'account-parent');
        $snapshot = $this->pdo->query('SELECT accounting_scope_type,accounting_scope_id,accounting_sku FROM order_items WHERE order_id=' . $orderId)->fetch();
        self::assertSame('product', $snapshot['accounting_scope_type']);
        self::assertSame($productId, (int) $snapshot['accounting_scope_id']);
        self::assertSame('TEST-PARENT-ACCOUNT', $snapshot['accounting_sku']);

        // A later catalog edit must not reroute an already placed order.
        $this->pdo->prepare('UPDATE products SET track_in_conta=0 WHERE id=:id')->execute(['id' => $productId]);
        $this->pdo->prepare('UPDATE product_variants SET track_in_conta=1 WHERE id=:id')->execute(['id' => $variantId]);
        $actorId = $this->adminActor();
        $accounting = new AccountingStockService($this->pdo);
        $this->ship($orderId, $actorId);

        // Operational order transitions must never create accounting movements.
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_stock_balances')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_stock_movements')->fetchColumn());

        $accounting->recordInvoice($orderId, 'FACT-TEST-PARENT', $actorId);

        $balance = $this->pdo->query("SELECT * FROM accounting_stock_balances WHERE scope_type='product' AND scope_id={$productId}")->fetch();
        self::assertIsArray($balance);
        self::assertSame(-2, (int) $balance['quantity']);
        self::assertNull($balance['variant_id']);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM accounting_stock_balances WHERE scope_type='variant'")->fetchColumn());

        // Reposting the same fiscal document is idempotent.
        $accounting->recordInvoice($orderId, 'FACT-TEST-PARENT', $actorId);
        self::assertSame(-2, (int) $this->pdo->query("SELECT quantity FROM accounting_stock_balances WHERE scope_type='product' AND scope_id={$productId}")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_stock_movements')->fetchColumn());

        $this->pdo->prepare('UPDATE products SET track_in_conta=1 WHERE id=:id')->execute(['id' => $productId]);
        $accounting->recordNir($productId, null, 2, 'NIR-TEST-PARENT', $actorId);
        self::assertSame(0, (int) $this->pdo->query("SELECT quantity FROM accounting_stock_balances WHERE scope_type='product' AND scope_id={$productId}")->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_stock_movements')->fetchColumn());

        // Replaying the same NIR is idempotent.
        $accounting->recordNir($productId, null, 2, 'NIR-TEST-PARENT', $actorId);
        self::assertSame(0, (int) $this->pdo->query("SELECT quantity FROM accounting_stock_balances WHERE scope_type='product' AND scope_id={$productId}")->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_stock_movements')->fetchColumn());
    }

    public function testTrackedCombinationOverridesParentWithoutDoubleCounting(): void
    {
        $this->seed();
        $variant = $this->variantWithOptions();
        $productId = (int) $variant['product_id'];
        $variantId = (int) $variant['id'];
        $this->pdo->prepare("UPDATE products SET primary_sku='TEST-PARENT-OVERRIDE',track_in_conta=1 WHERE id=:id")->execute(['id' => $productId]);
        $this->pdo->prepare('UPDATE product_variants SET track_in_conta=0 WHERE product_id=:id')->execute(['id' => $productId]);
        $this->pdo->prepare('UPDATE product_variants SET track_in_conta=1 WHERE id=:id')->execute(['id' => $variantId]);

        $orderId = $this->placeOrder($variantId, 1, 'account-variant');
        $snapshot = $this->pdo->query('SELECT accounting_scope_type,accounting_scope_id,accounting_sku FROM order_items WHERE order_id=' . $orderId)->fetch();
        self::assertSame('variant', $snapshot['accounting_scope_type']);
        self::assertSame($variantId, (int) $snapshot['accounting_scope_id']);
        self::assertSame($variant['sku'], $snapshot['accounting_sku']);

        $actorId = $this->adminActor();
        $accounting = new AccountingStockService($this->pdo);
        $this->ship($orderId, $actorId);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_stock_movements')->fetchColumn());

        $accounting->recordInvoice($orderId, 'FACT-TEST-VARIANT', $actorId);
        self::assertSame(-1, (int) $this->pdo->query("SELECT quantity FROM accounting_stock_balances WHERE scope_type='variant' AND scope_id={$variantId}")->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM accounting_stock_balances WHERE scope_type='product' AND scope_id={$productId}")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_stock_movements')->fetchColumn());

        $accounting->recordNir($productId, $variantId, 1, 'NIR-TEST-VARIANT', $actorId);
        self::assertSame(0, (int) $this->pdo->query("SELECT quantity FROM accounting_stock_balances WHERE scope_type='variant' AND scope_id={$variantId}")->fetchColumn());
    }

    public function testMultipleCombinationsFallingBackToParentAreAggregatedOnce(): void
    {
        $this->seed();
        [$first, $second] = $this->twoVariantsFromSameProduct();
        $productId = (int) $first['product_id'];
        $this->pdo->prepare("UPDATE products SET primary_sku='TEST-PARENT-AGGREGATE',track_in_conta=1 WHERE id=:id")->execute(['id' => $productId]);
        $this->pdo->prepare('UPDATE product_variants SET track_in_conta=0 WHERE product_id=:id')->execute(['id' => $productId]);

        $orderId = $this->placeOrderWithVariants([
            [(int) $first['id'], 1],
            [(int) $second['id'], 2],
        ], 'account-parent-aggregate');
        $snapshots = $this->pdo->query('SELECT accounting_scope_type,accounting_scope_id FROM order_items WHERE order_id=' . $orderId)->fetchAll();
        self::assertCount(2, $snapshots);
        foreach ($snapshots as $snapshot) {
            self::assertSame('product', $snapshot['accounting_scope_type']);
            self::assertSame($productId, (int) $snapshot['accounting_scope_id']);
        }

        $actorId = $this->adminActor();
        $accounting = new AccountingStockService($this->pdo);
        $this->ship($orderId, $actorId);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_stock_movements')->fetchColumn());

        $accounting->recordInvoice($orderId, 'FACT-TEST-AGGREGATE', $actorId);
        self::assertSame(-3, (int) $this->pdo->query("SELECT quantity FROM accounting_stock_balances WHERE scope_type='product' AND scope_id={$productId}")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_stock_movements')->fetchColumn());
    }

    public function testDisabledTrackingCreatesNoAccountingMovementButKeepsTheSale(): void
    {
        $this->seed();
        $variant = $this->variantWithOptions();
        $productId = (int) $variant['product_id'];
        $variantId = (int) $variant['id'];
        $this->pdo->prepare('UPDATE products SET track_in_conta=0 WHERE id=:id')->execute(['id' => $productId]);
        $this->pdo->prepare('UPDATE product_variants SET track_in_conta=0 WHERE product_id=:id')->execute(['id' => $productId]);

        $orderId = $this->placeOrder($variantId, 1, 'account-disabled');
        $snapshot = $this->pdo->query('SELECT accounting_scope_type,accounting_scope_id,accounting_sku FROM order_items WHERE order_id=' . $orderId)->fetch();
        self::assertNull($snapshot['accounting_scope_type']);
        self::assertNull($snapshot['accounting_scope_id']);
        self::assertNull($snapshot['accounting_sku']);

        $actorId = $this->adminActor();
        $accounting = new AccountingStockService($this->pdo);
        $this->ship($orderId, $actorId);
        $accounting->recordInvoice($orderId, 'FACT-TEST-NOT-TRACKED', $actorId);
        self::assertSame('shipped', $this->pdo->query('SELECT status FROM orders WHERE id=' . $orderId)->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_stock_balances')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_stock_movements')->fetchColumn());
    }

    /** @return array<string,mixed> */
    private function variantWithOptions(): array
    {
        foreach ($this->pdo->query('SELECT v.id,v.product_id,v.sku,v.options_json FROM product_variants v JOIN inventory i ON i.variant_id=v.id WHERE i.physical_quantity>=2 ORDER BY v.id')->fetchAll() as $variant) {
            $options = json_decode((string) $variant['options_json'], true);
            if (is_array($options) && $options !== []) {
                return $variant;
            }
        }
        self::fail('Datele demo trebuie să conțină o combinație cu stoc.');
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function twoVariantsFromSameProduct(): array
    {
        $rows = $this->pdo->query(
            'SELECT v.id,v.product_id,v.sku,v.options_json
             FROM product_variants v
             JOIN inventory i ON i.variant_id=v.id
             WHERE i.physical_quantity>=2
             ORDER BY v.product_id,v.id'
        )->fetchAll();
        $byProduct = [];
        foreach ($rows as $variant) {
            $options = json_decode((string) $variant['options_json'], true);
            if (!is_array($options) || $options === []) {
                continue;
            }
            $productId = (int) $variant['product_id'];
            $byProduct[$productId][] = $variant;
            if (count($byProduct[$productId]) >= 2) {
                return [$byProduct[$productId][0], $byProduct[$productId][1]];
            }
        }
        self::fail('Datele demo trebuie să conțină două combinații ale aceluiași produs cu stoc.');
    }

    private function placeOrder(int $variantId, int $quantity, string $key): int
    {
        return $this->placeOrderWithVariants([[$variantId, $quantity]], $key);
    }

    /** @param list<array{0:int,1:int}> $variants */
    private function placeOrderWithVariants(array $variants, string $key): int
    {
        $_SESSION['cart_token'] = bin2hex(random_bytes(32));
        $cart = new CartService($this->pdo);
        foreach ($variants as [$variantId, $quantity]) {
            $cart->add($variantId, $quantity);
        }
        $result = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place([
            'email' => 'contabilitate@example.test',
            'first_name' => 'Ana',
            'last_name' => 'Pop',
            'phone' => '0700000000',
            'address' => 'Strada Test 1',
            'city' => 'București — Sector 1',
            'county' => 'București',
            'postal_code' => '010101',
            'payment_method' => 'cod',
            'idempotency_key' => $key,
            'terms' => '1',
        ]);
        return (int) $result['id'];
    }

    private function adminActor(): int
    {
        $now = gmdate('c');
        $email = 'accounting-admin-' . bin2hex(random_bytes(4)) . '@example.test';
        $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES (:email,:password,'Admin','Conta','administrator','active',:now,:now,:now)")->execute([
            'email' => $email,
            'password' => password_hash('long-password-for-test', PASSWORD_DEFAULT),
            'now' => $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function ship(int $orderId, int $actorId): void
    {
        $orders = new OrderService($this->pdo);
        $orders->transition($orderId, 'confirm', null, $actorId);
        $orders->transition($orderId, 'ship', 'AWB-TEST', $actorId);
    }
}
