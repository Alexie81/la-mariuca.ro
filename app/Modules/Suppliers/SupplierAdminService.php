<?php

declare(strict_types=1);

namespace App\Modules\Suppliers;

use PDO;
use RuntimeException;

final class SupplierAdminService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{items:list<array<string,mixed>>,page:int,per_page:int,total:int,total_pages:int,from:int,to:int} */
    public function paginate(int $page, int $perPage, string $search = ''): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $search = trim($search);
        $where = '';
        $parameters = [];
        if ($search !== '') {
            $where = " WHERE LOWER(COALESCE(s.alias,'')) LIKE :search OR LOWER(COALESCE(s.company_name,'')) LIKE :search OR LOWER(COALESCE(s.contact_name,'')) LIKE :search OR LOWER(COALESCE(s.tax_id,'')) LIKE :search OR LOWER(COALESCE(s.email,'')) LIKE :search OR LOWER(COALESCE(s.phone,'')) LIKE :search OR LOWER(COALESCE(s.website,'')) LIKE :search";
            $parameters['search'] = '%' . mb_strtolower($search) . '%';
        }

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM suppliers s' . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;
        $sql = 'SELECT s.*, (SELECT COUNT(*) FROM products p WHERE p.supplier_id=s.id) AS product_count FROM suppliers s' . $where . ' ORDER BY CASE WHEN s.status=\'active\' THEN 0 ELSE 1 END, LOWER(s.alias), s.id LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => $statement->fetchAll(),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => min($total, $offset + $perPage),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function allForSelect(): array
    {
        return $this->pdo->query("SELECT id, alias, company_name, status FROM suppliers WHERE status='active' ORDER BY LOWER(alias), LOWER(COALESCE(company_name,''))")->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT s.*, (SELECT COUNT(*) FROM products p WHERE p.supplier_id=s.id) AS product_count FROM suppliers s WHERE s.id=:id');
        $statement->execute(['id' => $id]);
        $supplier = $statement->fetch();
        return is_array($supplier) ? $supplier : null;
    }

    /** @param array<string,mixed> $data */
    public function save(array $data, ?int $id = null): int
    {
        $alias = $this->text($data, 'alias', 100);
        if ($alias === '') {
            throw new RuntimeException('Aliasul furnizorului este obligatoriu.');
        }
        if (!$this->aliasAvailable($alias, $id)) {
            throw new RuntimeException('Acest alias este deja folosit de alt furnizor.');
        }

        $email = $this->text($data, 'email', 254);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Adresa de email a furnizorului nu este validă.');
        }
        $website = $this->text($data, 'website', 500);
        if ($website !== '' && !preg_match('~^https?://~i', $website)) {
            $website = 'https://' . $website;
        }
        if ($website !== '' && filter_var($website, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Website-ul furnizorului nu este valid.');
        }
        $status = ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $now = $this->now();
        $parameters = [
            'alias' => $alias,
            'company_name' => $this->nullableText($data, 'company_name', 190),
            'tax_id' => $this->nullableText($data, 'tax_id', 60),
            'trade_register' => $this->nullableText($data, 'trade_register', 80),
            'contact_name' => $this->nullableText($data, 'contact_name', 190),
            'email' => $email === '' ? null : $email,
            'phone' => $this->nullableText($data, 'phone', 50),
            'website' => $website === '' ? null : $website,
            'address' => $this->nullableText($data, 'address', 1000),
            'notes' => $this->nullableText($data, 'notes', 5000),
            'status' => $status,
            'updated_at' => $now,
        ];

        if ($id === null) {
            $statement = $this->pdo->prepare('INSERT INTO suppliers (alias,company_name,tax_id,trade_register,contact_name,email,phone,website,address,notes,status,created_at,updated_at) VALUES (:alias,:company_name,:tax_id,:trade_register,:contact_name,:email,:phone,:website,:address,:notes,:status,:created_at,:updated_at)');
            $statement->execute($parameters + ['created_at' => $now]);
            return (int) $this->pdo->lastInsertId();
        }

        if ($this->find($id) === null) {
            throw new RuntimeException('Furnizorul nu mai există.');
        }
        $statement = $this->pdo->prepare('UPDATE suppliers SET alias=:alias,company_name=:company_name,tax_id=:tax_id,trade_register=:trade_register,contact_name=:contact_name,email=:email,phone=:phone,website=:website,address=:address,notes=:notes,status=:status,updated_at=:updated_at WHERE id=:id');
        $statement->execute($parameters + ['id' => $id]);
        return $id;
    }

    public function delete(int $id): void
    {
        if ($id <= 0 || $this->find($id) === null) {
            throw new RuntimeException('Furnizorul nu mai există.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE products SET supplier_id=NULL WHERE supplier_id=:id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM suppliers WHERE id=:id')->execute(['id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function aliasAvailable(string $alias, ?int $excludeId): bool
    {
        $sql = 'SELECT COUNT(*) FROM suppliers WHERE LOWER(alias)=LOWER(:alias)';
        $parameters = ['alias' => $alias];
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id<>:id';
            $parameters['id'] = $excludeId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn() === 0;
    }

    /** @param array<string,mixed> $data */
    private function text(array $data, string $key, int $maxLength): string
    {
        return trim(mb_substr((string) ($data[$key] ?? ''), 0, $maxLength));
    }

    /** @param array<string,mixed> $data */
    private function nullableText(array $data, string $key, int $maxLength): ?string
    {
        $value = $this->text($data, $key, $maxLength);
        return $value === '' ? null : $value;
    }

    private function now(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? gmdate('Y-m-d H:i:s')
            : gmdate('c');
    }
}
