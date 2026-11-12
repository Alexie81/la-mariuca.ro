<?php

declare(strict_types=1);

namespace App\Modules\Companies;

use PDO;

final class CompanyRepository
{
    private const COMPANY_FIELDS = ['legal_name','trade_name','tax_id','trade_register','registered_address','city','county','postal_code','country','email','phone','website','physical_address','service_area','bank_name','iban','share_capital_bani','is_vat_payer','vat_rate_basis_points'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function allCompanies(): array
    {
        return $this->pdo->query('SELECT c.*, (SELECT COUNT(*) FROM company_warehouses w WHERE w.company_id=c.id) AS warehouse_count FROM companies c ORDER BY c.is_default DESC, c.id ASC')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function company(int $id): ?array
    {
        $query = $this->pdo->prepare('SELECT c.*, (SELECT COUNT(*) FROM company_warehouses w WHERE w.company_id=c.id) AS warehouse_count FROM companies c WHERE c.id=:id');
        $query->execute(['id'=>$id]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function warehouses(int $companyId): array
    {
        $query = $this->pdo->prepare('SELECT * FROM company_warehouses WHERE company_id=:company ORDER BY is_default DESC,id ASC');
        $query->execute(['company'=>$companyId]);
        return $query->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function warehouse(int $companyId, int $id): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM company_warehouses WHERE company_id=:company AND id=:id');
        $query->execute(['company'=>$companyId,'id'=>$id]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,string|int|null> $data
     * @return array{id:int,previous_stamp:?string}
     */
    public function saveCompany(array $data, ?int $id, bool $changeStamp, ?string $stamp): array
    {
        return $this->write(function () use ($data, $id, $changeStamp, $stamp): array {
            $existing = $id === null ? null : $this->company($id);
            if ($id !== null && $existing === null) {
                throw new CompanyNotFoundException('Firma nu mai există.');
            }
            $first = (int) $this->pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn() === 0;
            if ($id === null && !$first) {
                throw new CompanyValidationException(['_form'=>'Datele firmei sunt deja completate. Editează firma existentă.']);
            }
            // The store has one company profile; legacy IDs remain editable without creating another profile.
            $this->pdo->exec('UPDATE companies SET is_default=0 WHERE is_default=1');
            $parameters = [];
            foreach (self::COMPANY_FIELDS as $field) {
                $parameters[$field] = $data[$field];
            }
            $parameters['is_default'] = 1;
            $parameters['updated_at'] = $this->now();
            $parameters['stamp_path'] = $changeStamp ? $stamp : ($existing['stamp_path'] ?? null);
            if ($id === null) {
                $parameters['created_at'] = $parameters['updated_at'];
                $columns = array_keys($parameters);
                $query = $this->pdo->prepare('INSERT INTO companies (' . implode(',', $columns) . ') VALUES (:' . implode(',:', $columns) . ')');
                $query->execute($parameters);
                $id = (int) $this->pdo->lastInsertId();
            } else {
                $assignments = array_map(static fn(string $field): string => $field . '=:' . $field, array_keys($parameters));
                $query = $this->pdo->prepare('UPDATE companies SET ' . implode(',', $assignments) . ' WHERE id=:id');
                $query->execute($parameters + ['id'=>$id]);
            }
            return ['id'=>$id, 'previous_stamp'=>$changeStamp && is_string($existing['stamp_path'] ?? null) ? $existing['stamp_path'] : null];
        });
    }

    public function deleteCompany(int $id): ?string
    {
        return $this->write(function () use ($id): ?string {
            $company = $this->company($id);
            if ($company === null) {
                throw new CompanyNotFoundException('Firma nu mai există.');
            }
            $this->pdo->prepare('DELETE FROM companies WHERE id=:id')->execute(['id'=>$id]);
            if ((bool) $company['is_default']) {
                $nextId = $this->pdo->query('SELECT id FROM companies ORDER BY id ASC LIMIT 1')->fetchColumn();
                if ($nextId !== false) {
                    $this->pdo->prepare('UPDATE companies SET is_default=1,updated_at=:now WHERE id=:id')->execute(['now'=>$this->now(),'id'=>$nextId]);
                }
            }
            return is_string($company['stamp_path']) ? $company['stamp_path'] : null;
        });
    }

    public function saveWarehouse(int $companyId, ?int $id, string $name, string $nameKey, bool $makeDefault): int
    {
        return $this->write(function () use ($companyId, $id, $name, $nameKey, $makeDefault): int {
            if ($this->company($companyId) === null) {
                throw new CompanyNotFoundException('Firma nu mai există.');
            }
            $existing = $id === null ? null : $this->warehouse($companyId, $id);
            if ($id !== null && $existing === null) {
                throw new CompanyNotFoundException('Gestiunea nu există în această firmă.');
            }
            $duplicate = $this->pdo->prepare('SELECT id FROM company_warehouses WHERE company_id=:company AND name_key=:name AND id<>:id');
            $duplicate->execute(['company'=>$companyId,'name'=>$nameKey,'id'=>$id ?? 0]);
            if ($duplicate->fetchColumn() !== false) {
                throw new CompanyValidationException(['name'=>'Există deja o gestiune cu această denumire în firmă.']);
            }
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM company_warehouses WHERE company_id=:company');
            $count->execute(['company'=>$companyId]);
            $isDefault = (int) $count->fetchColumn() === 0 || $makeDefault || (bool) ($existing['is_default'] ?? false);
            if ($isDefault) {
                $this->pdo->prepare('UPDATE company_warehouses SET is_default=0 WHERE company_id=:company AND is_default=1')->execute(['company'=>$companyId]);
            }
            $parameters = ['company'=>$companyId,'name'=>$name,'name_key'=>$nameKey,'is_default'=>(int) $isDefault,'updated_at'=>$this->now()];
            if ($id === null) {
                $this->pdo->prepare('INSERT INTO company_warehouses (company_id,name,name_key,is_default,created_at,updated_at) VALUES (:company,:name,:name_key,:is_default,:created_at,:updated_at)')->execute($parameters + ['created_at'=>$parameters['updated_at']]);
                return (int) $this->pdo->lastInsertId();
            }
            $this->pdo->prepare('UPDATE company_warehouses SET name=:name,name_key=:name_key,is_default=:is_default,updated_at=:updated_at WHERE company_id=:company AND id=:id')->execute($parameters + ['id'=>$id]);
            return $id;
        });
    }

    public function deleteWarehouse(int $companyId, int $id): void
    {
        $this->write(function () use ($companyId, $id): void {
            $warehouse = $this->warehouse($companyId, $id);
            if ($warehouse === null) {
                throw new CompanyNotFoundException('Gestiunea nu există în această firmă.');
            }
            $this->pdo->prepare('DELETE FROM company_warehouses WHERE company_id=:company AND id=:id')->execute(['company'=>$companyId,'id'=>$id]);
            if ((bool) $warehouse['is_default']) {
                $next = $this->pdo->prepare('SELECT id FROM company_warehouses WHERE company_id=:company ORDER BY id ASC LIMIT 1');
                $next->execute(['company'=>$companyId]);
                $nextId = $next->fetchColumn();
                if ($nextId !== false) {
                    $this->pdo->prepare('UPDATE company_warehouses SET is_default=1,updated_at=:now WHERE company_id=:company AND id=:id')->execute(['now'=>$this->now(),'company'=>$companyId,'id'=>$nextId]);
                }
            }
        });
    }

    /** @return list<array<string,mixed>> */
    public function receiptLocations(int $companyId): array
    {
        $query = $this->pdo->prepare('SELECT l.*, w.name AS warehouse_name, (SELECT COUNT(*) FROM nir_documents d WHERE d.location_id=l.id) AS document_count FROM nir_locations l JOIN company_warehouses w ON w.id=l.warehouse_id WHERE w.company_id=:company AND l.is_active=1 ORDER BY w.is_default DESC,w.id,l.is_default DESC,l.id');
        $query->execute(['company'=>$companyId]);
        return $query->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function receiptLocation(int $companyId, int $id): ?array
    {
        foreach ($this->receiptLocations($companyId) as $location) {
            if ((int) $location['id'] === $id) { return $location; }
        }
        return null;
    }

    public function saveReceiptLocation(int $companyId, int $warehouseId, ?int $id, string $name, bool $makeDefault): int
    {
        return $this->write(function () use ($companyId, $warehouseId, $id, $name, $makeDefault): int {
            $this->pdo->exec('UPDATE nir_write_lock SET revision=revision+1 WHERE id=1');
            $warehouse = $this->warehouse($companyId, $warehouseId);
            if ($warehouse === null || !(bool) $warehouse['is_active']) { throw new CompanyValidationException(['warehouse_id'=>'Alege o gestiune activă a firmei.']); }
            $existing = $id === null ? null : $this->receiptLocation($companyId, $id);
            if ($id !== null && ($existing === null || (int) $existing['warehouse_id'] !== $warehouseId)) { throw new CompanyNotFoundException('Locația nu mai este disponibilă în această gestiune.'); }
            $key = static fn(string $value): string => mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value));
            $siblings = array_values(array_filter($this->receiptLocations($companyId), static fn(array $row): bool => (int) $row['warehouse_id'] === $warehouseId));
            foreach ($siblings as $sibling) {
                if ((int) $sibling['id'] !== $id && $key((string) $sibling['name']) === $key($name)) { throw new CompanyValidationException(['name'=>'Există deja o locație cu această denumire în gestiunea aleasă.']); }
            }
            $default = $makeDefault || (bool) ($existing['is_default'] ?? false) || !array_filter($siblings, static fn(array $row): bool => (bool) $row['is_default']);
            if ($default) { $this->pdo->prepare('UPDATE nir_locations SET is_default=0 WHERE warehouse_id=:warehouse')->execute(['warehouse'=>$warehouseId]); }
            $values = ['name'=>$name,'is_default'=>(int) $default,'now'=>$this->now()];
            if ($id === null) {
                $this->pdo->prepare("INSERT INTO nir_locations (warehouse_id,name,address,is_active,is_default,created_at,updated_at) VALUES (:warehouse,:name,'',1,:is_default,:created,:now)")->execute($values + ['warehouse'=>$warehouseId,'created'=>$values['now']]);
                return (int) $this->pdo->lastInsertId();
            }
            $this->pdo->prepare('UPDATE nir_locations SET name=:name,is_default=:is_default,updated_at=:now WHERE id=:id')->execute($values + ['id'=>$id]);
            return $id;
        });
    }

    public function deleteReceiptLocation(int $companyId, int $id): void
    {
        $this->write(function () use ($companyId, $id): void {
            $this->pdo->exec('UPDATE nir_write_lock SET revision=revision+1 WHERE id=1');
            $location = $this->receiptLocation($companyId, $id) ?? throw new CompanyNotFoundException('Locația nu mai este disponibilă.');
            // Keep document references and confirmed snapshots; retired locations cannot be selected again.
            $this->pdo->prepare('UPDATE nir_locations SET is_active=0,is_default=0,updated_at=:now WHERE id=:id')->execute(['id'=>$id,'now'=>$this->now()]);
            if ((bool) $location['is_default']) {
                $next = $this->pdo->prepare('SELECT id FROM nir_locations WHERE warehouse_id=:warehouse AND is_active=1 ORDER BY id LIMIT 1');
                $next->execute(['warehouse'=>$location['warehouse_id']]);
                $nextId = $next->fetchColumn();
                if ($nextId !== false) { $this->pdo->prepare('UPDATE nir_locations SET is_default=1,updated_at=:now WHERE id=:id')->execute(['id'=>$nextId,'now'=>$this->now()]); }
            }
        });
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function write(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            // A permanent lock row serializes writes even while both entity tables are empty.
            $this->pdo->exec('UPDATE company_write_lock SET revision=revision+1 WHERE id=1');
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function now(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? gmdate('Y-m-d H:i:s') : gmdate('c');
    }
}
