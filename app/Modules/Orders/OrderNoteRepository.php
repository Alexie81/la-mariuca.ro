<?php
declare(strict_types=1);

namespace App\Modules\Orders;

use PDO;

final class OrderNoteRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function find(int $orderId): ?string
    {
        $query = $this->pdo->prepare('SELECT internal_notes FROM orders WHERE id=:id');
        $query->execute(['id'=>$orderId]);
        $row = $query->fetch();
        return is_array($row) ? (string) $row['internal_notes'] : null;
    }

    /** Atomic comparison prevents an older open page from overwriting another editor. */
    public function replace(int $orderId, string $previous, string $note): bool
    {
        $comparison = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'BINARY internal_notes = BINARY :previous' : 'internal_notes = :previous';
        $query = $this->pdo->prepare('UPDATE orders SET internal_notes=:note,updated_at=:now WHERE id=:id AND ' . $comparison);
        $query->execute(['note'=>$note,'now'=>gmdate('c'),'id'=>$orderId,'previous'=>$previous]);
        return $query->rowCount() === 1;
    }
}
