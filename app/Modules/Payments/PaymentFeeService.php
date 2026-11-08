<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use DomainException;
use PDO;

final class PaymentFeeService
{
    private readonly PaymentFeeRepository $repository;
    private readonly PaymentMethodSettingsRepository $settings;

    public function __construct(private readonly PDO $pdo)
    {
        $this->repository = new PaymentFeeRepository($pdo);
        $this->settings = new PaymentMethodSettingsRepository($pdo);
    }

    /** @return list<array<string,mixed>> */
    public function all(?string $method = null): array
    {
        if ($method !== null) { $this->assertMethod($method); }
        return $this->repository->all($method);
    }

    /** @return array{items:list<array{id:int,name:string,amount_bani:int}>,total_bani:int} */
    public function quote(string $method, bool $lock = false): array
    {
        $this->assertMethod($method);
        if ($lock) { $this->settings->settings(true); }
        $items = array_map(static fn (array $fee): array => ['id' => (int) $fee['id'], 'name' => (string) $fee['name'], 'amount_bani' => (int) $fee['amount_bani']], $this->repository->all($method));
        return ['items' => $items, 'total_bani' => array_sum(array_column($items, 'amount_bani'))];
    }

    /** @param array<string,mixed> $data */
    public function save(array $data, int $actorId, ?int $id = null): int
    {
        $this->assertActor($actorId);
        $method = is_string($data['payment_method'] ?? null) ? $data['payment_method'] : '';
        $this->assertMethod($method);
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new DomainException('Denumirea costului trebuie să aibă între 2 și 100 de caractere.');
        }
        $price = is_scalar($data['price'] ?? null) ? trim((string) $data['price']) : '';
        if (preg_match('/^\d{1,5}(?:[.,]\d{1,2})?$/D', $price) !== 1) {
            throw new DomainException('Introdu un preț în lei, de exemplu 12,50, cu cel mult două zecimale.');
        }
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $price), 2), 2, '');
        $amount = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($amount < 1 || $amount > 1000000) {
            throw new DomainException('Costul trebuie să fie între 0,01 și 10.000,00 Lei.');
        }
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) { $this->pdo->beginTransaction(); }
        try {
            $this->settings->settings(true);
            $existing = $id === null ? null : $this->repository->find($id);
            if ($id !== null && $existing === null) { throw new DomainException('Costul nu mai există.'); }
            $fees = $this->repository->all($method);
            if (($id === null || ($existing['payment_method'] ?? '') !== $method) && count($fees) >= 20) {
                throw new DomainException('Poți configura cel mult 20 de costuri pentru o metodă de plată.');
            }
            foreach ($fees as $fee) {
                if ((int) $fee['id'] !== $id && mb_strtolower((string) $fee['name']) === mb_strtolower($name)) {
                    throw new DomainException('Există deja un cost cu această denumire pentru metoda aleasă.');
                }
            }
            $savedId = $this->repository->save($method, $name, $amount, $actorId, $id);
            $this->repository->audit($savedId, $actorId, $id === null ? 'payment_fee.created' : 'payment_fee.updated', ['payment_method' => $method, 'name' => $name, 'amount_bani' => $amount]);
            if ($ownTransaction) { $this->pdo->commit(); }
            return $savedId;
        } catch (\Throwable $error) {
            if ($ownTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    public function delete(int $id, int $actorId): void
    {
        $this->assertActor($actorId);
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) { $this->pdo->beginTransaction(); }
        try {
            $this->settings->settings(true);
            $fee = $this->repository->find($id);
            if ($fee === null) { throw new DomainException('Costul nu mai există.'); }
            $this->repository->delete($id);
            $this->repository->audit($id, $actorId, 'payment_fee.deleted', ['payment_method' => $fee['payment_method'], 'name' => $fee['name']]);
            if ($ownTransaction) { $this->pdo->commit(); }
        } catch (\Throwable $error) {
            if ($ownTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    /** @param array<string,mixed> $order @return list<array{id:int,name:string,amount_bani:int}> */
    public static function snapshot(array $order): array
    {
        $decoded = json_decode((string) ($order['payment_fees_json'] ?? '[]'), true);
        if (!is_array($decoded)) { return []; }
        $fees = [];
        foreach ($decoded as $fee) {
            if (is_array($fee) && is_string($fee['name'] ?? null) && is_int($fee['amount_bani'] ?? null) && $fee['amount_bani'] > 0) {
                $fees[] = ['id' => (int) ($fee['id'] ?? 0), 'name' => $fee['name'], 'amount_bani' => $fee['amount_bani']];
            }
        }
        return $fees;
    }

    private function assertMethod(string $method): void
    {
        if (!in_array($method, ['cod', 'stripe'], true)) { throw new DomainException('Metoda de plată nu este validă.'); }
    }

    private function assertActor(int $id): void
    {
        if (!$this->settings->canManage($id)) { throw new DomainException('Nu ai permisiunea să modifici costurile operaționale.'); }
    }
}
