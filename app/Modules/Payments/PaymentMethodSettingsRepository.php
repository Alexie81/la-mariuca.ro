<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use LogicException;
use PDO;
use RuntimeException;

final class PaymentMethodSettingsRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed> */
    public function settings(bool $lock = false): array
    {
        if ($lock && !$this->pdo->inTransaction()) {
            throw new LogicException('Payment settings locking requires a transaction.');
        }
        $suffix = $lock && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $settings = $this->pdo->query('SELECT cod_enabled,stripe_enabled,updated_at FROM payment_method_settings WHERE id=1' . $suffix)->fetch(PDO::FETCH_ASSOC);
        if (!is_array($settings)) { throw new RuntimeException('Payment settings are missing.'); }
        return $settings;
    }

    public function canManage(int $actorId): bool
    {
        $statement = $this->pdo->prepare("SELECT id FROM users WHERE id=:id AND role IN ('administrator','operator') AND status='active'");
        $statement->execute(['id' => $actorId]);
        return $statement->fetchColumn() !== false;
    }

    public function save(bool $cod, bool $stripe, int $actorId): void
    {
        $now = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? gmdate('Y-m-d H:i:s') : gmdate('c');
        $this->pdo->prepare('UPDATE payment_method_settings SET cod_enabled=:cod,stripe_enabled=:stripe,updated_by=:actor,updated_at=:now WHERE id=1')
            ->execute(['cod' => (int) $cod, 'stripe' => (int) $stripe, 'actor' => $actorId, 'now' => $now]);
        $this->pdo->prepare("INSERT INTO audit_log (actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES (:actor,'payment_methods.settings_updated','payment_method_settings',1,:context,:now)")
            ->execute(['actor' => $actorId, 'context' => json_encode(['cod_enabled' => $cod, 'stripe_enabled' => $stripe], JSON_THROW_ON_ERROR), 'now' => $now]);
    }
}
