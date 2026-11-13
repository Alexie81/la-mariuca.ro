<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use DateTimeImmutable;
use DateTimeZone;

final class InvoiceConfigurationService
{
    public function __construct(private readonly InvoiceConfigurationRepository $repository) {}

    /** @return array<string,mixed> */
    public function state(): array
    {
        return ['settings'=>$this->repository->settings(),'series'=>$this->repository->series()];
    }

    /** @return array<string,mixed> */
    public function series(int $id): array
    {
        return $this->repository->find($id) ?? throw new InvoiceConfigurationException('Seria nu mai există în configurator. Reîncarcă pagina.', 404);
    }

    /** @param array<string,mixed> $input */
    public function saveSeries(array $input, ?int $id, int $actorId): int
    {
        $code = strtoupper(preg_replace('/\s+/', ' ', trim($this->text($input['code'] ?? ''))) ?? '');
        $label = trim($this->text($input['label'] ?? ''));
        if (!preg_match('/^[A-Z0-9][A-Z0-9 -]{0,29}$/D', $code)) {
            throw new InvoiceConfigurationException('Scrie o serie de 1–30 de caractere: litere fără diacritice, cifre, spații sau cratimă.');
        }
        if (mb_strlen($label) > 100) { throw new InvoiceConfigurationException('Denumirea poate avea cel mult 100 de caractere.'); }
        $use = $input['in_use'] ?? '0';
        if (!in_array($use, ['0','1',0,1], true)) { throw new InvoiceConfigurationException('Verifică opțiunea de folosire a seriei.'); }
        return $this->repository->transaction(function () use ($input, $id, $code, $label, $use, $actorId): int {
            $this->checkRevision($input);
            if ($id !== null) { $this->series($id); }
            if ($this->repository->hasCode($code, $id)) { throw new InvoiceConfigurationException('Există deja o serie cu acest cod. Alege un cod diferit.'); }
            $savedId = $this->repository->saveSeries($id, $code, $label);
            // Editing never implicitly disables the current series.
            if ((string) $use === '1') { $this->repository->activate($savedId); }
            $this->repository->changed($actorId, $id === null ? 'series_created' : 'series_edited', ['series_id'=>$savedId,'code'=>$code]);
            return $savedId;
        });
    }

    /** @param array<string,mixed> $input */
    public function changeUse(int $id, array $input, int $actorId): void
    {
        $this->repository->transaction(function () use ($id, $input, $actorId): void {
            $settings = $this->checkRevision($input);
            $this->series($id);
            $target = $id;
            if ((int) $settings['active_series_id'] === $id) {
                $target = $this->replacement($input, $id);
            }
            $this->repository->activate($target);
            $this->repository->changed($actorId, 'series_selected', ['from'=>$settings['active_series_id'],'to'=>$target]);
        });
    }

    /** @param array<string,mixed> $input */
    public function delete(int $id, array $input, int $actorId): void
    {
        $this->repository->transaction(function () use ($id, $input, $actorId): void {
            $settings = $this->checkRevision($input);
            $this->series($id);
            if (count($this->repository->series()) <= 1) { throw new InvoiceConfigurationException('Păstrează cel puțin o serie. Adaugă mai întâi seria care o va înlocui.'); }
            if ((int) $settings['active_series_id'] === $id) {
                $this->repository->activate($this->replacement($input, $id));
            }
            $this->repository->remove($id);
            $this->repository->changed($actorId, 'series_deleted', ['series_id'=>$id]);
        });
    }

    /** @param array<string,mixed> $input */
    public function saveDefaults(array $input, int $actorId): void
    {
        $days = $this->text($input['due_days'] ?? '');
        $notes = trim(str_replace(["\r\n","\r"], "\n", $this->text($input['default_notes'] ?? '')));
        if (!preg_match('/^\d{1,4}$/D', $days) || (int) $days > 3650) { throw new InvoiceConfigurationException('Zilele de scadență trebuie să fie un număr întreg între 0 și 3650.'); }
        if (mb_strlen($notes) > 3000) { throw new InvoiceConfigurationException('Notele implicite pot avea cel mult 3000 de caractere.'); }
        $this->repository->transaction(function () use ($input, $days, $notes, $actorId): void {
            $this->checkRevision($input);
            $this->repository->saveDefaults((int) $days, $notes);
            $this->repository->changed($actorId, 'defaults_saved', ['due_days'=>(int) $days]);
        });
    }

    /**
     * Issuance integration point: invoke inside the transaction creating the issued invoice.
     * Drafts must not call this. Regeneration must read snapshotForInvoice, never current state.
     * The invoice module owns number allocation; the unique constraint also prevents reuse
     * if a deleted code is added again. No issuance is exposed by the configurator UI.
     * @return array<string,mixed>
     */
    public function freezeForInvoice(string $documentKey, int $number, DateTimeImmutable $issuedAt): array
    {
        if (!preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $documentKey) || $number < 1) { throw new \InvalidArgumentException('Invalid invoice identity.'); }
        return $this->repository->transaction(function () use ($documentKey, $number, $issuedAt): array {
            $existing = $this->repository->snapshot($documentKey);
            if ($existing !== null) { return $existing; }
            $settings = $this->repository->settings();
            $dueDate = $issuedAt->setTimezone(new DateTimeZone('Europe/Bucharest'))->modify('+' . (int) $settings['due_days'] . ' days')->format('Y-m-d');
            $snapshot = [
                'document_key'=>$documentKey,'series_id'=>(int) $settings['active_series_id'],
                'series_code'=>$settings['active_code'],'series_label'=>$settings['active_label'],
                'invoice_number'=>$number,'due_days'=>(int) $settings['due_days'],'default_notes'=>$settings['default_notes'],
                'issued_at'=>$issuedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'due_date'=>$dueDate,'configuration_revision'=>(int) $settings['revision'],
            ];
            $this->repository->insertSnapshot($snapshot);
            return $snapshot;
        });
    }

    /** @return array<string,mixed> */
    public function snapshotForInvoice(string $documentKey): array
    {
        return $this->repository->snapshot($documentKey) ?? throw new InvoiceConfigurationException('Factura nu are încă date de emitere salvate.', 404);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function checkRevision(array $input): array
    {
        $settings = $this->repository->settings();
        if ($this->text($input['revision'] ?? '') !== (string) $settings['revision']) {
            throw new InvoiceConfigurationException('Setările au fost schimbate în altă fereastră. Reîncarcă pagina înainte de a salva din nou.', 409);
        }
        return $settings;
    }

    /** @param array<string,mixed> $input */
    private function replacement(array $input, int $oldId): int
    {
        $value = $this->text($input['replacement_id'] ?? '');
        if (!ctype_digit($value) || (int) $value === $oldId || $this->repository->find((int) $value) === null) {
            throw new InvoiceConfigurationException('Alege seria care va fi folosită începând cu următoarea factură.');
        }
        return (int) $value;
    }

    private function text(mixed $value): string
    {
        if (!is_scalar($value)) { throw new InvoiceConfigurationException('Verifică valorile completate în formular.'); }
        return (string) $value;
    }
}
