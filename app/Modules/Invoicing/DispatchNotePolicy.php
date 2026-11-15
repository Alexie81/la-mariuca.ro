<?php
declare(strict_types=1);

namespace App\Modules\Invoicing;

final class DispatchNotePolicy
{
    public function __construct(private readonly DispatchNoteRepository $repository) {}

    /** @param array<string,mixed> $note */
    public function deletionBlocker(array $note): string
    {
        if($note['deleted_at']!==null) { return 'Avizul a fost deja șters.'; }
        if((int)$note['id']!==$this->repository->latestId()) { return 'Se poate șterge numai ultimul aviz emis.'; }
        if($this->repository->fiscalBlocksDeletion((int)$note['order_id'])) { return 'Avizul este legat de o factură blocată prin transmiterea în SPV.'; }
        return '';
    }
}
