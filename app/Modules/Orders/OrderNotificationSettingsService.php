<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use PDO;
use DomainException;
use InvalidArgumentException;

final class OrderNotificationSettingsService
{
    public const MAX_RECIPIENTS = 10;
    private readonly OrderNotificationRepository $repository;

    public function __construct(private readonly PDO $pdo, ?OrderNotificationRepository $repository = null)
    {
        $this->repository = $repository ?? new OrderNotificationRepository($pdo);
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return $this->repository->recipients();
    }

    /** @param array<mixed>|string $recipients */
    public function save(array|string $recipients, int $actorId): void
    {
        if (!$this->repository->canManage($actorId)) {
            throw new DomainException('Nu ai permisiunea să modifici notificările comenzilor.');
        }
        $normalized = $this->normalize($recipients);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            $this->repository->save($normalized, $actorId);
            if ($ownsTransaction) { $this->pdo->commit(); }
        } catch (\Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    /** @param array<mixed>|string $recipients @return list<string> */
    private function normalize(array|string $recipients): array
    {
        if (is_string($recipients)) {
            if (strlen($recipients) > 10000) { throw new InvalidArgumentException('Lista adreselor este prea lungă.'); }
            $recipients = preg_split('/[,;\r\n]+/', $recipients) ?: [];
        }
        if (count($recipients) > 100) { throw new InvalidArgumentException('Poți adăuga maximum 10 adrese de email.'); }
        $unique = [];
        foreach ($recipients as $recipient) {
            if (!is_string($recipient)) { throw new InvalidArgumentException('Introdu numai adrese de email valide.'); }
            $email = mb_strtolower(trim($recipient));
            if ($email === '') { continue; }
            if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Una dintre adresele de email nu este validă. Verifică fiecare adresă înainte de salvare.');
            }
            $unique[$email] = $email;
        }
        if (count($unique) > self::MAX_RECIPIENTS) { throw new InvalidArgumentException('Poți adăuga maximum 10 adrese de email.'); }
        return array_values($unique);
    }
}
