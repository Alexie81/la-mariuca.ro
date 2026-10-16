<?php

declare(strict_types=1);

namespace App\Shared\Database;

use App\Shared\Config\Config;
use PDO;
use RuntimeException;

final class ConnectionFactory
{
    public static function create(Config $config, string $root): PDO
    {
        $driver = $config->get('DB_DRIVER');
        if ($driver === 'sqlite') {
            $database = $config->get('DB_DATABASE');
            if ($database !== ':memory:' && !self::isAbsolute($database)) {
                $database = $root . '/' . ltrim(str_replace('\\', '/', $database), '/');
            }
            if ($database !== ':memory:') {
                $directory = dirname($database);
                if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                    throw new RuntimeException('Nu se poate crea directorul bazei de date.');
                }
            }
            $pdo = new PDO('sqlite:' . $database);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        } elseif ($driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config->get('DB_HOST', '127.0.0.1'),
                $config->int('DB_PORT', 3306),
                $config->get('DB_NAME')
            );
            $pdo = new PDO($dsn, $config->get('DB_USER'), $config->get('DB_PASSWORD'), [
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
            ]);
        } else {
            throw new RuntimeException('DB_DRIVER trebuie să fie sqlite sau mysql.');
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        return $pdo;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
