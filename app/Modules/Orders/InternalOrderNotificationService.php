<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use PDO;

final class InternalOrderNotificationService
{
    private readonly OrderNotificationRepository $repository;

    public function __construct(private readonly PDO $pdo, ?OrderNotificationRepository $repository = null)
    {
        $this->repository = $repository ?? new OrderNotificationRepository($pdo);
    }

    public function queue(int $orderId): void
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            // Serializes successful provider events for the same order in MySQL.
            $order = $this->repository->lockedOrder($orderId);
            if ($order !== null && !in_array($order['status'], ['cancelled', 'returned'], true)
                && ($order['payment_method'] === 'cod' || ($order['payment_method'] === 'stripe' && $order['payment_status'] === 'paid'))) {
                foreach ($this->repository->recipients() as $recipient) {
                    if (!$this->repository->hasDelivery($orderId, $recipient)) {
                        $this->repository->enqueue($orderId, $recipient);
                    }
                }
            }
            if ($ownsTransaction) { $this->pdo->commit(); }
        } catch (\Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }
}
