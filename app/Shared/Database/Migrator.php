<?php

declare(strict_types=1);

namespace App\Shared\Database;

use PDO;
use RuntimeException;

final class Migrator
{
    public function __construct(private readonly PDO $pdo, private readonly string $root)
    {
    }

    /** @return list<string> */
    public function migrate(): array
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(190) PRIMARY KEY, applied_at VARCHAR(30) NOT NULL)');
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $directory = $this->root . '/database/migrations/' . $driver;
        $files = glob($directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $applied = $this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $completed = [];

        foreach ($files as $file) {
            $version = basename($file);
            if (in_array($version, $applied, true)) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('Nu se poate citi migrarea ' . $version);
            }
            $transactional = $driver !== 'mysql';
            if ($transactional) {
                $this->pdo->beginTransaction();
            }
            try {
                $this->pdo->exec($sql);
                $statement = $this->pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)');
                $statement->execute(['version' => $version, 'applied_at' => gmdate('c')]);
                if ($transactional) {
                    $this->pdo->commit();
                }
                $completed[] = $version;
            } catch (\Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
        }
        return $completed;
    }
}
