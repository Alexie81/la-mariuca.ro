<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use PDO;

/** Durable local change journal; enqueue is safe inside the catalog transaction. */
final class StripeCatalogQueue
{
    public function __construct(private readonly PDO $pdo) {}

    public function enqueue(int $productId): void
    {
        $sql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'INSERT INTO stripe_catalog_changes (product_id,revision,updated_at) VALUES (:id,1,:now) ON DUPLICATE KEY UPDATE revision=revision+1,updated_at=VALUES(updated_at)'
            : 'INSERT INTO stripe_catalog_changes (product_id,revision,updated_at) VALUES (:id,1,:now) ON CONFLICT(product_id) DO UPDATE SET revision=revision+1,updated_at=excluded.updated_at';
        $this->pdo->prepare($sql)->execute(['id' => $productId, 'now' => gmdate('c')]);
    }

    public function enqueueAll(): int
    {
        $ids = $this->pdo->query('SELECT id FROM products ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) { $this->enqueue((int) $id); }
        return count($ids);
    }
}
