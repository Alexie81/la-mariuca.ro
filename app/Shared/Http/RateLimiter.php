<?php

declare(strict_types=1);

namespace App\Shared\Http;

use PDO;

final class RateLimiter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function allow(string $bucket, string $identity, int $limit, int $windowSeconds): bool
    {
        $key = hash('sha256', $bucket . '|' . $identity);
        $cutoff = gmdate('c', time() - $windowSeconds);
        $this->pdo->prepare('DELETE FROM rate_limits WHERE occurred_at < :cutoff')->execute(['cutoff' => $cutoff]);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM rate_limits WHERE bucket_key = :key');
        $statement->execute(['key' => $key]);
        if ((int) $statement->fetchColumn() >= $limit) {
            return false;
        }
        $this->pdo->prepare('INSERT INTO rate_limits (bucket_key, occurred_at) VALUES (:key, :time)')->execute(['key' => $key, 'time' => gmdate('c')]);
        return true;
    }
}
