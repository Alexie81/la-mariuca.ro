<?php

declare(strict_types=1);

namespace App\Modules\Companies;

use PDO;

final class CompanyRepository
{
    private const COMPANY_FIELDS = ['legal_name','trade_name','tax_id','trade_register','registered_address','city','county','postal_code','country','email','phone','website','physical_address','service_area','bank_name','iban','share_capital_bani'];

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
    public function saveCompany(array $data, ?int $id, bool $makeDefault, bool $changeStamp, ?string $stamp): array
    {
        return $this->write(function () use ($data, $id, $makeDefault, $changeStamp, $stamp): array {
            $existing = $id === null ? null : $this->company($id);
            if ($id !== null && $existing === null) {
                throw new CompanyNotFoundException('Firma nu mai există.');
            }
            $first = (int) $this->pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn() === 0;
            $isDefault = $first || $makeDefault || (bool) ($existing['is_default'] ?? false);
            if ($isDefault) {
                $this->pdo->exec('UPDATE companies SET is_default=0 WHERE is_default=1');
            }
            $parameters = [];
            foreach (self::COMPANY_FIELDS as $field) {
                $parameters[$field] = $data[$field];
            }
            $parameters['is_default'] = (int) $isDefault;
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
