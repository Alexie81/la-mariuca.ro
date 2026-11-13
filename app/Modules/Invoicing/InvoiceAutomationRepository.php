<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use PDO;

final class InvoiceAutomationRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed> */
    public function settings(): array
    {
        return $this->pdo->query('SELECT * FROM invoice_automation_settings WHERE id=1' . $this->readLock())->fetch();
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $own = !$this->pdo->inTransaction();
        if ($own) { $this->pdo->beginTransaction(); }
        try {
            $this->pdo->exec('UPDATE invoice_automation_settings SET revision=revision WHERE id=1');
            $result = $callback();
            if ($own) { $this->pdo->commit(); }
            return $result;
        } catch (\Throwable $error) {
            if ($own && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    /** @param array<string,bool> $values */
    public function save(array $values, int $actorId): void
    {
        $now = gmdate('c');
        $this->pdo->prepare('UPDATE invoice_automation_settings SET card_issue=:card_issue,card_email=:card_email,cod_issue=:cod_issue,cod_email=:cod_email,revision=revision+1,updated_at=:now WHERE id=1')
            ->execute(array_map(static fn(bool $value): int => (int) $value, $values) + ['now'=>$now]);
        $this->pdo->prepare("INSERT INTO audit_log (actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES (:actor,'invoice_automation.settings_saved','invoice_automation_settings',1,:context,:now)")
            ->execute(['actor'=>$actorId > 0 ? $actorId : null,'context'=>json_encode($values, JSON_THROW_ON_ERROR),'now'=>$now]);
    }

    /** @return array<string,mixed>|null */
    public function order(int $id): ?array
    {
        $query = $this->pdo->prepare('SELECT id,payment_method,payment_status,status FROM orders WHERE id=:id' . $this->readLock());
        $query->execute(['id'=>$id]);
        return $query->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function intent(int $id): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM invoice_automation_intents WHERE order_id=:id' . $this->readLock());
        $query->execute(['id'=>$id]);
        return $query->fetch() ?: null;
    }

    /** @param array<string,mixed> $intent */
    public function insertIntent(array $intent): void
    {
        $this->pdo->prepare('INSERT INTO invoice_automation_intents (order_id,payment_method,send_email,settings_revision,issue_key,email_key,created_at) VALUES (:order_id,:payment_method,:send_email,:settings_revision,:issue_key,:email_key,:created_at)')->execute($intent);
    }

    private function readLock(): string
    {
        return $this->pdo->inTransaction() && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
