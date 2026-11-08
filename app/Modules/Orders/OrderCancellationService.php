<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use DomainException;

/** Reviews the customer's request. Payment refunds and fiscal actions are separate integrations. */
final class OrderCancellationService
{
    public function __construct(private readonly OrderTrackingRepository $repository, private readonly OrderService $orders) {}

    /** @return array<string,mixed> The immutable outcome, including on an identical retry. */
    public function decide(int $orderId, string $decision, string $reason, int $actorId): array
    {
        if (!$this->repository->canReviewCancellation($actorId)) {
            throw new DomainException('Nu ai permisiunea să gestionezi cererile de anulare.');
        }
        if (!in_array($decision, ['accepted','rejected'], true)) {
            throw new DomainException('Alege acceptarea sau respingerea solicitării.');
        }
        $reason = trim((string) preg_replace('/[\p{Z}\x{200B}\x{FEFF}]+/u', ' ', $reason));
        if (mb_strlen($reason) > 1000 || ($decision === 'rejected' && mb_strlen($reason) < 5)) {
            throw new DomainException($decision === 'rejected' ? 'Scrie motivul respingerii în 5–1.000 de caractere.' : 'Mesajul pentru client poate avea maximum 1.000 de caractere.');
        }
        return $this->repository->transaction(function () use ($orderId, $decision, $reason, $actorId): array {
            $order = $this->repository->lockCancellationOrder($orderId);
            $request = $this->repository->cancellationRequest($orderId);
            if ($order === null || $request === null) {
                throw new DomainException('Solicitarea de anulare nu a fost găsită.');
            }
            if ($request['status'] !== 'pending') {
                if ($request['status'] === $decision) { return $request; }
                throw new DomainException('Solicitarea a fost deja soluționată. Reîncarcă pagina pentru a vedea decizia.');
            }
            if ($decision === 'accepted' && !in_array($order['status'], ['new','confirmed','preparing','cancelled'], true)) {
                throw new DomainException('Comanda nu mai poate fi anulată din starea actuală. Poți respinge solicitarea și explica motivul clientului.');
            }
            // The request was timely when submitted; processing it does not have a second five-day limit.
            if ($decision === 'accepted' && $order['status'] !== 'cancelled') {
                $this->orders->transition($orderId, 'cancel', null, $actorId, $reason, false);
            }
            $now = $this->repository->now();
            if (!$this->repository->resolveCancellation((int) $request['id'], $decision, $reason, $actorId, $now)) {
                throw new DomainException('Solicitarea a fost modificată între timp. Reîncarcă pagina.');
            }
            $this->repository->auditCancellationDecision($orderId, (int) $request['id'], $decision, $reason, $actorId, $now);
            $this->repository->queueCancellationEmail($orderId, (int) $request['id'], (string) $order['email'], 'order_cancellation_decision', $now);
            return $this->repository->cancellationRequest($orderId) ?? throw new DomainException('Decizia nu a putut fi încărcată.');
        });
    }
}
