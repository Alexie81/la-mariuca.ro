<?php

declare(strict_types=1);

namespace App\Modules\Shipping;

use App\Shared\Support\Money;
use PDO;
use RuntimeException;

final class ShippingMethodService
{
    private const TYPES = ['courier', 'locker', 'pickup', 'other'];
    private const STATUSES = ['active', 'inactive'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function allForAdmin(): array
    {
        return $this->pdo->query("SELECT method.*,
                (SELECT COUNT(*) FROM orders customer_order WHERE customer_order.shipping_method_id=method.id) AS order_count
            FROM shipping_methods method
            WHERE method.deleted_at IS NULL
            ORDER BY method.position,method.id")->fetchAll();
    }

    /** @return array{total:int,active:int,inactive:int,default_name:string,average_cost_bani:int} */
    public function summary(): array
    {
        $row = $this->pdo->query("SELECT COUNT(*) AS total,
                SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN status='inactive' THEN 1 ELSE 0 END) AS inactive,
                COALESCE(ROUND(AVG(CASE WHEN status='active' THEN price_bani END)),0) AS average_cost_bani
            FROM shipping_methods WHERE deleted_at IS NULL")->fetch();
        $default = $this->pdo->query("SELECT name FROM shipping_methods WHERE deleted_at IS NULL AND status='active' AND is_default=1 ORDER BY position,id LIMIT 1")->fetchColumn();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'inactive' => (int) ($row['inactive'] ?? 0),
            'default_name' => is_string($default) ? $default : '',
            'average_cost_bani' => (int) ($row['average_cost_bani'] ?? 0),
        ];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $statement = $this->pdo->prepare("SELECT method.*,
                (SELECT COUNT(*) FROM orders customer_order WHERE customer_order.shipping_method_id=method.id) AS order_count
            FROM shipping_methods method WHERE method.id=:id AND method.deleted_at IS NULL");
        $statement->execute(['id' => $id]);
        $method = $statement->fetch();

        return is_array($method) ? $method : null;
    }

    /** @return list<array<string,mixed>> */
    public function activeForCheckout(): array
    {
        return $this->pdo->query("SELECT id,code,name,description,type,price_bani,estimated_min_days,estimated_max_days,position,is_default
            FROM shipping_methods
            WHERE status='active' AND deleted_at IS NULL
            ORDER BY is_default DESC,position,id")->fetchAll();
    }

    /** @param array<string,mixed> $data */
    public function save(array $data, ?int $id = null): int
    {
        $current = $id === null ? null : $this->find($id);
        if ($id !== null && $current === null) {
            throw new RuntimeException('Metoda de livrare nu mai există.');
        }

        $name = trim(mb_substr((string) ($data['name'] ?? ''), 0, 120));
        if (mb_strlen($name) < 2) {
            throw new RuntimeException('Introdu un nume clar pentru metoda de livrare.');
        }
        $code = $this->normalizeCode((string) ($data['code'] ?? ''), $name);
        if (!$this->codeAvailable($code, $id)) {
            throw new RuntimeException('Codul acestei metode este deja folosit.');
        }
        $type = trim((string) ($data['type'] ?? 'courier'));
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException('Alege un tip valid de livrare.');
        }
        $status = trim((string) ($data['status'] ?? 'active'));
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Alege o stare validă pentru metodă.');
        }
        try {
            $priceBani = Money::parse((string) ($data['price'] ?? ''));
        } catch (\InvalidArgumentException) {
            throw new RuntimeException('Introdu un cost valid în Lei, de exemplu 19,00.');
        }
        if ($priceBani > 100000000) {
            throw new RuntimeException('Costul livrării este prea mare.');
        }
        $minDays = $this->integer($data, 'estimated_min_days', 0, 365, 'Numărul minim de zile nu este valid.');
        $maxDays = $this->integer($data, 'estimated_max_days', 0, 365, 'Numărul maxim de zile nu este valid.');
        if ($maxDays < $minDays) {
            throw new RuntimeException('Durata maximă trebuie să fie egală sau mai mare decât durata minimă.');
        }
        $position = $this->integer($data, 'position', 0, 9999, 'Ordinea de afișare trebuie să fie între 0 și 9999.');
        $isDefault = isset($data['is_default']) && in_array((string) $data['is_default'], ['1', 'on', 'true'], true);
        if ($status !== 'active' && $isDefault) {
            throw new RuntimeException('Metoda implicită trebuie să fie activă.');
        }

        $description = trim((string) ($data['description'] ?? ''));
        if (mb_strlen($description) > 160) {
            throw new RuntimeException('Explicația scurtă poate avea maximum 160 de caractere.');
        }
        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            $this->lockConfigurationRows();
            if ($status === 'inactive' && (string) ($current['status'] ?? '') === 'active' && $this->activeCount($id) < 1) {
                throw new RuntimeException('Păstrează cel puțin o metodă de livrare activă.');
            }
            if ($status === 'active' && !$isDefault && !$this->hasActiveDefault($id)) {
                $isDefault = true;
            }
            if ($isDefault) {
                $this->pdo->exec('UPDATE shipping_methods SET is_default=0 WHERE deleted_at IS NULL');
            }

            $parameters = [
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'type' => $type,
                'price' => $priceBani,
                'min_days' => $minDays,
                'max_days' => $maxDays,
                'position' => $position,
                'is_default' => $isDefault ? 1 : 0,
                'status' => $status,
                'updated_at' => $now,
            ];
            if ($id === null) {
                $statement = $this->pdo->prepare('INSERT INTO shipping_methods (code,name,description,type,price_bani,estimated_min_days,estimated_max_days,position,is_default,status,created_at,updated_at,deleted_at) VALUES (:code,:name,:description,:type,:price,:min_days,:max_days,:position,:is_default,:status,:created_at,:updated_at,NULL)');
                $statement->execute($parameters + ['created_at' => $now]);
                $id = (int) $this->pdo->lastInsertId();
            } else {
                $statement = $this->pdo->prepare('UPDATE shipping_methods SET code=:code,name=:name,description=:description,type=:type,price_bani=:price,estimated_min_days=:min_days,estimated_max_days=:max_days,position=:position,is_default=:is_default,status=:status,updated_at=:updated_at WHERE id=:id AND deleted_at IS NULL');
                $statement->execute($parameters + ['id' => $id]);
            }
            $this->ensureActiveDefault($now);
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function changeStatus(int $id, string $status): string
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Alege o stare validă pentru metodă.');
        }
        $method = $this->find($id);
        if ($method === null) {
            throw new RuntimeException('Metoda de livrare nu mai există.');
        }
        if ((string) $method['status'] === $status) {
            return $status === 'active' ? 'Metoda este deja activă.' : 'Metoda este deja inactivă.';
        }

        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            $this->lockConfigurationRows();
            if ($status === 'inactive' && $this->activeCount($id) < 1) {
                throw new RuntimeException('Păstrează cel puțin o metodă de livrare activă.');
            }
            $this->pdo->prepare('UPDATE shipping_methods SET status=:status,is_default=CASE WHEN :status_default=\'active\' THEN is_default ELSE 0 END,updated_at=:now WHERE id=:id AND deleted_at IS NULL')
                ->execute(['status' => $status, 'status_default' => $status, 'now' => $now, 'id' => $id]);
            $this->ensureActiveDefault($now);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $status === 'active' ? 'Metoda de livrare a fost activată.' : 'Metoda de livrare a fost dezactivată.';
    }

    /** @return array<string,mixed> */
    public function requireActiveForCheckout(int $id, bool $lock = false): array
    {
        if ($id < 1) {
            throw new RuntimeException('Alege metoda de livrare.');
        }
        $sql = "SELECT id,code,name,description,type,price_bani,estimated_min_days,estimated_max_days,position,is_default
            FROM shipping_methods WHERE id=:id AND status='active' AND deleted_at IS NULL
            ORDER BY is_default DESC,position,id LIMIT 1";
        if ($lock && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $id]);
        $method = $statement->fetch();
        if (!is_array($method)) {
            throw new RuntimeException('Metoda de livrare aleasă nu mai este disponibilă. Reîncarcă pagina și alege din nou.');
        }

        return $method;
    }

    private function normalizeCode(string $code, string $fallback): string
    {
        $value = trim($code) !== '' ? $code : $fallback;
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = strtolower(is_string($converted) ? $converted : $value);
        $value = trim((string) preg_replace('/[^a-z0-9]+/', '-', $value), '-');
        $value = substr($value, 0, 80);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/', $value)) {
            throw new RuntimeException('Codul metodei trebuie să conțină litere mici, cifre și cratime.');
        }
        return $value;
    }

    private function codeAvailable(string $code, ?int $excludeId): bool
    {
        $sql = 'SELECT COUNT(*) FROM shipping_methods WHERE LOWER(code)=LOWER(:code) AND deleted_at IS NULL';
        $parameters = ['code' => $code];
        if ($excludeId !== null) {
            $sql .= ' AND id<>:id';
            $parameters['id'] = $excludeId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn() === 0;
    }

    /** @param array<string,mixed> $data */
    private function integer(array $data, string $key, int $minimum, int $maximum, string $message): int
    {
        $raw = trim((string) ($data[$key] ?? ''));
        if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
            throw new RuntimeException($message);
        }
        $value = (int) $raw;
        if ($value < $minimum || $value > $maximum) {
            throw new RuntimeException($message);
        }
        return $value;
    }

    private function activeCount(?int $excludeId = null): int
    {
        $sql = "SELECT COUNT(*) FROM shipping_methods WHERE status='active' AND deleted_at IS NULL";
        $parameters = [];
        if ($excludeId !== null) {
            $sql .= ' AND id<>:id';
            $parameters['id'] = $excludeId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn();
    }

    private function hasActiveDefault(?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM shipping_methods WHERE status='active' AND is_default=1 AND deleted_at IS NULL";
        $parameters = [];
        if ($excludeId !== null) {
            $sql .= ' AND id<>:id';
            $parameters['id'] = $excludeId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn() > 0;
    }

    private function ensureActiveDefault(string $now): void
    {
        $default = $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND is_default=1 AND deleted_at IS NULL ORDER BY position,id LIMIT 1")->fetchColumn();
        if ($default !== false) {
            return;
        }
        $replacement = $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY position,id LIMIT 1")->fetchColumn();
        if ($replacement !== false) {
            $this->pdo->prepare('UPDATE shipping_methods SET is_default=1,updated_at=:now WHERE id=:id')
                ->execute(['now' => $now, 'id' => $replacement]);
        }
    }

    private function lockConfigurationRows(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }

        $this->pdo->query('SELECT id FROM shipping_methods WHERE deleted_at IS NULL ORDER BY id FOR UPDATE')->fetchAll();
    }
}
