<?php

declare(strict_types=1);

namespace Tests;

use App\Shared\Config\Config;
use App\Shared\Database\ConnectionFactory;
use App\Shared\Database\Migrator;
use Database\Seeders\DemoSeeder;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;
    protected string $databasePath;
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__);
        $this->databasePath = sys_get_temp_dir() . '/la-mariuca-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['APP_URL'] = 'http://localhost';
        $_ENV['APP_BASE_PATH'] = '';
        $_ENV['APP_INDEXING'] = 'true';
        $_ENV['APP_KEY'] = 'test-key';
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $this->databasePath;
        $config = Config::load($this->root);
        $this->pdo = ConnectionFactory::create($config, $this->root);
        (new Migrator($this->pdo, $this->root))->migrate();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function seed(): void
    {
        (new DemoSeeder($this->pdo))->run();
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
        parent::tearDown();
    }
}
