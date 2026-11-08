<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Shared\Config\Config;
use DomainException;
use PDO;

final class PaymentMethodSettingsService
{
    private readonly PaymentMethodSettingsRepository $repository;

    public function __construct(private readonly PDO $pdo, private readonly Config $config, ?PaymentMethodSettingsRepository $repository = null)
    {
        $this->repository = $repository ?? new PaymentMethodSettingsRepository($pdo);
    }

    /** @return array{cod_enabled:bool,stripe_enabled:bool,stripe_configured:bool,updated_at:?string} */
    public function settings(bool $lock = false): array
    {
        $saved = $this->repository->settings($lock);
        // Until the first admin save, preserve the store's existing environment configuration.
        return [
            'cod_enabled' => $saved['cod_enabled'] === null ? $this->config->bool('COD_ENABLED', true) : (int) $saved['cod_enabled'] === 1,
            'stripe_enabled' => $saved['stripe_enabled'] === null ? $this->config->bool('STRIPE_ENABLED') : (int) $saved['stripe_enabled'] === 1,
            'stripe_configured' => $this->config->stripeConfigured(),
            'updated_at' => $saved['updated_at'] === null ? null : (string) $saved['updated_at'],
        ];
    }

    /** @return array{cod:bool,stripe:bool} */
    public function available(bool $lock = false): array
    {
        $settings = $this->settings($lock);
        return ['cod' => $settings['cod_enabled'], 'stripe' => $settings['stripe_enabled'] && $settings['stripe_configured']];
    }

    public function save(bool $cod, bool $stripe, int $actorId): void
    {
        if (!$this->repository->canManage($actorId)) {
            throw new DomainException('Nu ai permisiunea să modifici metodele de plată.');
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            $this->repository->settings(true);
            $this->repository->save($cod, $stripe, $actorId);
            if ($ownsTransaction) { $this->pdo->commit(); }
        } catch (\Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }
}
