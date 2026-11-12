<?php
declare(strict_types=1);

namespace App\Modules\Companies;

final class ReceiptLocationService
{
    public function __construct(private readonly CompanyRepository $repository) {}

    /** @return list<array<string,mixed>> */
    public function all(int $companyId): array { return $this->repository->receiptLocations($companyId); }

    /** @return array<string,mixed> */
    public function get(int $companyId, int $id): array
    {
        return $this->repository->receiptLocation($companyId, $id) ?? throw new CompanyNotFoundException('Locația de recepție nu mai este disponibilă.');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function save(int $companyId, array $input, ?int $id = null): array
    {
        $name = is_scalar($input['name'] ?? null) ? trim((string) $input['name']) : '';
        if ($name === '' || mb_strlen($name) > 190) {
            throw new CompanyValidationException(['name'=>'Completează denumirea locației, cu maximum 190 de caractere.']);
        }
        $warehouseId = is_scalar($input['warehouse_id'] ?? null) ? (int) $input['warehouse_id'] : 0;
        $warehouse = $this->repository->warehouse($companyId, $warehouseId);
        if ($warehouse === null || !(bool) $warehouse['is_active']) {
            throw new CompanyValidationException(['warehouse_id'=>'Alege o gestiune activă a firmei.']);
        }
        if ($id !== null && (int) $this->get($companyId, $id)['warehouse_id'] !== $warehouseId) {
            throw new CompanyValidationException(['warehouse_id'=>'Locația rămâne în gestiunea în care a fost creată.']);
        }
        $isDefault = in_array($input['is_default'] ?? false, [true,1,'1','on'], true);
        $saved = $this->repository->saveReceiptLocation($companyId, $warehouseId, $id, $name, $isDefault);
        return $this->get($companyId, $saved);
    }

    public function delete(int $companyId, int $id): void { $this->repository->deleteReceiptLocation($companyId, $id); }
}
