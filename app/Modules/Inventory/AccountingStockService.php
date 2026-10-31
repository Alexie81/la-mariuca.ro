<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use PDO;
use RuntimeException;

/**
 * Keeps the accounting stock separate from the online/reserved inventory.
 *
 * A line from a posted accounting document is routed to exactly one bucket:
 * - the variant snapshot, when that combination has its own accounting SKU;
 * - otherwise the parent product snapshot;
 * - nowhere, when accounting tracking was disabled at checkout.
 *
 * Orders, payments and shipments never call this service. Accounting stock is
 * changed only by posted documents: an issued invoice or a received NIR.
 */
final class AccountingStockService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function recordInvoice(
        int $orderId,
        string $invoiceNumber,
        int $actorId,
        ?string $occurredAt = null,
    ): void
    {
        $invoiceNumber = trim($invoiceNumber);
        if ($orderId <= 0 || $invoiceNumber === '') {
            throw new RuntimeException('Comanda și numărul facturii sunt obligatorii.');
        }

        $this->transactional(function () use ($orderId, $invoiceNumber, $actorId, $occurredAt): void {
            $order = $this->pdo->prepare('SELECT 1 FROM orders WHERE id=:order_id LIMIT 1');
            $order->execute(['order_id' => $orderId]);
            if ($order->fetchColumn() === false) {
                throw new RuntimeException('Comanda facturată nu există.');
            }

            $statement = $this->pdo->prepare(
                "SELECT accounting_scope_type,accounting_scope_id,product_id,
                        CASE WHEN accounting_scope_type='variant' THEN accounting_scope_id ELSE NULL END AS variant_id,
                        accounting_sku,SUM(quantity) AS quantity
                 FROM order_items
                 WHERE order_id=:order_id AND accounting_scope_type IS NOT NULL
                 GROUP BY accounting_scope_type,accounting_scope_id,product_id,accounting_sku"
            );
            $statement->execute(['order_id' => $orderId]);
            $now = $occurredAt ?? gmdate('c');
            $documentHash = hash('sha256', mb_strtolower($invoiceNumber, 'UTF-8'));

            foreach ($statement->fetchAll() as $row) {
                $target = $this->targetFromSnapshot($row);
                $quantity = (int) $row['quantity'];
                if ($quantity <= 0) {
                    continue;
                }

                $this->applyMovement(
                    $target,
                    -$quantity,
                    'sale',
                    'Ieșire din stoc prin factura ' . $invoiceNumber,
                    'invoice',
                    $orderId,
                    sprintf('invoice:%s:%s:%d', $documentHash, $target['scope_type'], $target['scope_id']),
                    $actorId,
                    $now,
                );
            }
        });
    }

    /**
     * Foundation for the NIR workflow. The same routing rule is reused, so the
     * posted receipt restores the configured accounting bucket.
     */
    public function recordNir(
        int $productId,
        ?int $variantId,
        int $quantity,
        string $nirNumber,
        int $actorId,
        ?string $occurredAt = null,
    ): void {
        $nirNumber = trim($nirNumber);
        if ($productId <= 0 || $quantity <= 0 || $nirNumber === '') {
            throw new RuntimeException('Produsul, cantitatea și numărul NIR sunt obligatorii.');
        }

        $this->transactional(function () use ($productId, $variantId, $quantity, $nirNumber, $actorId, $occurredAt): void {
            $target = $this->configuredTarget($productId, $variantId);
            $eventKey = sprintf(
                'nir:%s:%s:%d',
                hash('sha256', mb_strtolower($nirNumber, 'UTF-8')),
                $target['scope_type'],
                $target['scope_id'],
            );
            $this->applyMovement(
                $target,
                $quantity,
                'nir',
                'Intrare în stoc prin NIR ' . $nirNumber,
                'nir',
                null,
                $eventKey,
                $actorId,
                $occurredAt ?? gmdate('c'),
            );
        });
    }

    /**
     * @param array<string,mixed> $row
     * @return array{scope_type:'product'|'variant',scope_id:int,product_id:int,variant_id:?int,sku:string}
     */
    private function targetFromSnapshot(array $row): array
    {
        $scopeType = (string) ($row['accounting_scope_type'] ?? '');
        $scopeId = (int) ($row['accounting_scope_id'] ?? 0);
        $productId = (int) ($row['product_id'] ?? 0);
        $variantId = $row['variant_id'] === null ? null : (int) $row['variant_id'];
        $sku = trim((string) ($row['accounting_sku'] ?? ''));
        if (!in_array($scopeType, ['product', 'variant'], true) || $scopeId <= 0 || $productId <= 0 || $sku === '') {
            throw new RuntimeException('Ținta contabilă salvată pe comandă este incompletă.');
        }
        if ($scopeType === 'variant' && ($variantId === null || $variantId !== $scopeId)) {
            throw new RuntimeException('Combinația contabilă salvată pe comandă nu este validă.');
        }

        return [
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'product_id' => $productId,
            'variant_id' => $scopeType === 'variant' ? $variantId : null,
            'sku' => $sku,
        ];
    }

    /** @return array{scope_type:'product'|'variant',scope_id:int,product_id:int,variant_id:?int,sku:string} */
    private function configuredTarget(int $productId, ?int $variantId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id AS product_id,p.primary_sku,p.track_in_conta AS product_track,
                    v.id AS variant_id,v.sku AS variant_sku,v.options_json,v.track_in_conta AS variant_track
             FROM products p
             LEFT JOIN product_variants v ON v.id=:variant_id AND v.product_id=p.id
             WHERE p.id=:product_id LIMIT 1'
        );
        $statement->bindValue(':product_id', $productId, PDO::PARAM_INT);
        if ($variantId === null) {
            $statement->bindValue(':variant_id', null, PDO::PARAM_NULL);
        } else {
            $statement->bindValue(':variant_id', $variantId, PDO::PARAM_INT);
        }
        $statement->execute();
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Produsul pentru mișcarea contabilă nu există.');
        }
        if ($variantId !== null && $row['variant_id'] === null) {
            throw new RuntimeException('Combinația nu aparține produsului selectat.');
        }

        $options = json_decode((string) ($row['options_json'] ?? '{}'), true);
        $hasCombination = is_array($options) && $options !== [];
        if ($variantId !== null && $hasCombination && (int) $row['variant_track'] === 1) {
            $sku = trim((string) $row['variant_sku']);
            if ($sku === '') {
                throw new RuntimeException('Combinația urmărită contabil nu are SKU.');
            }
            return [
                'scope_type' => 'variant',
                'scope_id' => $variantId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'sku' => $sku,
            ];
        }

        if ((int) $row['product_track'] === 1) {
            $sku = trim((string) $row['primary_sku']);
            if ($sku === '') {
                throw new RuntimeException('Produsul urmărit contabil nu are SKU principal.');
            }
            return [
                'scope_type' => 'product',
                'scope_id' => $productId,
                'product_id' => $productId,
                'variant_id' => null,
                'sku' => $sku,
            ];
        }

        throw new RuntimeException('Urmărirea contabilă este dezactivată pentru această poziție.');
    }

    /**
     * @param array{scope_type:'product'|'variant',scope_id:int,product_id:int,variant_id:?int,sku:string} $target
     */
    private function applyMovement(
        array $target,
        int $quantityDelta,
        string $type,
        string $reason,
        string $referenceType,
        ?int $referenceId,
        string $eventKey,
        int $actorId,
        string $occurredAt,
    ): void {
        if ($quantityDelta === 0) {
            return;
        }
        $existing = $this->pdo->prepare('SELECT 1 FROM accounting_stock_movements WHERE event_key=:event_key LIMIT 1');
        $existing->execute(['event_key' => $eventKey]);
        if ($existing->fetchColumn() !== false) {
            return;
        }

        $parameters = [
            'scope_type' => $target['scope_type'],
            'scope_id' => $target['scope_id'],
            'product_id' => $target['product_id'],
            'variant_id' => $target['variant_id'],
            'sku' => $target['sku'],
            'quantity' => $quantityDelta,
            'now' => $occurredAt,
        ];
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $balanceSql = 'INSERT INTO accounting_stock_balances
                (scope_type,scope_id,product_id,variant_id,sku,quantity,created_at,updated_at)
                VALUES (:scope_type,:scope_id,:product_id,:variant_id,:sku,:quantity,:now,:now)
                ON DUPLICATE KEY UPDATE product_id=VALUES(product_id),variant_id=VALUES(variant_id),sku=VALUES(sku),quantity=quantity+VALUES(quantity),updated_at=VALUES(updated_at)';
        } else {
            $balanceSql = 'INSERT INTO accounting_stock_balances
                (scope_type,scope_id,product_id,variant_id,sku,quantity,created_at,updated_at)
                VALUES (:scope_type,:scope_id,:product_id,:variant_id,:sku,:quantity,:now,:now)
                ON CONFLICT(scope_type,scope_id) DO UPDATE SET product_id=excluded.product_id,variant_id=excluded.variant_id,sku=excluded.sku,quantity=accounting_stock_balances.quantity+excluded.quantity,updated_at=excluded.updated_at';
        }
        $this->pdo->prepare($balanceSql)->execute($parameters);

        $this->pdo->prepare(
            'INSERT INTO accounting_stock_movements
             (event_key,scope_type,scope_id,product_id,variant_id,sku,quantity_delta,type,reason,reference_type,reference_id,actor_user_id,created_at)
             VALUES (:event_key,:scope_type,:scope_id,:product_id,:variant_id,:sku,:quantity_delta,:type,:reason,:reference_type,:reference_id,:actor_user_id,:created_at)'
        )->execute([
            'event_key' => $eventKey,
            'scope_type' => $target['scope_type'],
            'scope_id' => $target['scope_id'],
            'product_id' => $target['product_id'],
            'variant_id' => $target['variant_id'],
            'sku' => $target['sku'],
            'quantity_delta' => $quantityDelta,
            'type' => $type,
            'reason' => $reason,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'actor_user_id' => $actorId > 0 ? $actorId : null,
            'created_at' => $occurredAt,
        ]);
    }

    /** @param callable():void $callback */
    private function transactional(callable $callback): void
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $callback();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
