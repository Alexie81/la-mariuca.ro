<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Inventory\StockLedgerRepository;
use App\Modules\Inventory\StockLedgerService;
use App\Modules\Invoicing\InvoiceException;
use Tests\DatabaseTestCase;

final class StockOnlineAdjustmentTest extends DatabaseTestCase
{
    public function testUnlimitedVariantsNeverExposeAQuantityInTheStockWorkspace(): void
    {
        $this->seed();
        $variant = $this->pdo->query("SELECT v.id,v.product_id,v.sku FROM product_variants v JOIN (SELECT product_id FROM product_variants WHERE status='active' GROUP BY product_id HAVING COUNT(*)=1) single_variant ON single_variant.product_id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE v.status='active' ORDER BY v.id LIMIT 1")->fetch();
        self::assertIsArray($variant);
        $this->pdo->prepare('UPDATE product_variants SET stock_unlimited=1 WHERE id=:variant')->execute(['variant' => $variant['id']]);
        $this->pdo->prepare('UPDATE inventory SET physical_quantity=987,reserved_quantity=123 WHERE variant_id=:variant')->execute(['variant' => $variant['id']]);
        $service = new StockLedgerService(new StockLedgerRepository($this->pdo));
        $actor = $this->administrator();

        $registry = $service->registry(['q' => $variant['sku']], $actor);
        self::assertCount(1, $registry['items']);
        self::assertSame('unlimited', $registry['items'][0]['online_mode']);
        self::assertSame(0, (int) $registry['items'][0]['online_quantity']);
        self::assertSame('unlimited', $registry['items'][0]['online_state']);

        $detail = $service->detail((int) $variant['product_id'], [], $actor);
        self::assertSame('unlimited', $detail['online_mode']);
        self::assertSame(0, $detail['online']);
        self::assertSame(0, $detail['online_physical']);
        self::assertSame(0, $detail['online_reserved']);
    }

    public function testOnlineAdjustmentIsIdempotentAuditedAndAccountingNeutral(): void
    {
        $this->seed();
        $variant = $this->pdo->query("SELECT v.id,v.product_id,i.physical_quantity,i.reserved_quantity FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE v.stock_unlimited=0 AND p.track_in_conta=1 ORDER BY v.id LIMIT 1")->fetch();
        self::assertIsArray($variant);
        $actor = $this->administrator();
        $service = new StockLedgerService(new StockLedgerRepository($this->pdo));
        $beforeAccounting = (string) $this->pdo->query('SELECT COALESCE(SUM(quantity_units),0) FROM nir_stock_balances')->fetchColumn();

        $result = $service->adjustOnline((int) $variant['product_id'], (int) $variant['id'], 'increase', '3', 'Corecție după inventarul fizic', 'online-adjustment-test', $actor);
        self::assertFalse($result['duplicate']);
        self::assertSame((int) $variant['physical_quantity'] + 3, $result['after']);
        self::assertSame($result['after'], (int) $this->pdo->query('SELECT physical_quantity FROM inventory WHERE variant_id=' . (int) $variant['id'])->fetchColumn());
        self::assertSame($beforeAccounting, (string) $this->pdo->query('SELECT COALESCE(SUM(quantity_units),0) FROM nir_stock_balances')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE reference_type='manual_online_adjustment'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='stock.online_adjusted'")->fetchColumn());

        $duplicate = $service->adjustOnline((int) $variant['product_id'], (int) $variant['id'], 'increase', '3', 'Corecție după inventarul fizic', 'online-adjustment-test', $actor);
        self::assertTrue($duplicate['duplicate']);
        self::assertSame($result['after'], (int) $this->pdo->query('SELECT physical_quantity FROM inventory WHERE variant_id=' . (int) $variant['id'])->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM stock_online_adjustments')->fetchColumn());

        $detail = $service->detail((int) $variant['product_id'], [], $actor);
        self::assertSame('Corecție după inventarul fizic', $detail['online_adjustments'][0]['reason']);
        self::assertSame(3, (int) $detail['online_adjustments'][0]['physical_delta']);
    }

    public function testAdjustmentCannotBreakReservationsAndOperatorsCannotWrite(): void
    {
        $this->seed();
        $variant = $this->pdo->query("SELECT v.id,v.product_id,i.physical_quantity,i.reserved_quantity FROM product_variants v JOIN inventory i ON i.variant_id=v.id WHERE v.stock_unlimited=0 ORDER BY v.id LIMIT 1")->fetch();
        self::assertIsArray($variant);
        $service = new StockLedgerService(new StockLedgerRepository($this->pdo));
        $actor = $this->administrator();

        try {
            $service->adjustOnline((int) $variant['product_id'], (int) $variant['id'], 'decrease', (string) ((int) $variant['physical_quantity'] + 1), 'Scădere imposibilă', 'negative-adjustment', $actor);
            self::fail('Stocul online nu trebuie să devină negativ.');
        } catch (InvoiceException $exception) {
            self::assertStringContainsString('nu poate deveni negativ', $exception->getMessage());
        }

        $operator = $this->user('operator');
        $this->expectException(InvoiceException::class);
        $this->expectExceptionMessage('Nu ai acces la evidența stocului.');
        $service->adjustOnline((int) $variant['product_id'], (int) $variant['id'], 'increase', '1', 'Fără drepturi', 'operator-adjustment', $operator);
    }

    /** @return array<string,mixed> */
    private function administrator(): array
    {
        return $this->user('administrator');
    }

    /** @return array<string,mixed> */
    private function user(string $role): array
    {
        $now = gmdate('c');
        $email = $role . '.stock-adjustment@example.test';
        $statement = $this->pdo->prepare('INSERT INTO users(email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES(:email,:password,:first_name,:last_name,:role,\'active\',:now,:now,:now)');
        $statement->execute(['email' => $email, 'password' => 'x', 'first_name' => ucfirst($role), 'last_name' => 'Stoc', 'role' => $role, 'now' => $now]);
        return ['id' => (int) $this->pdo->lastInsertId(), 'role' => $role];
    }
}
