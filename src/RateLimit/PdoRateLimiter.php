<?php

declare(strict_types=1);

namespace NoSocket\RateLimit;

use PDO;

final class PdoRateLimiter implements RateLimiter
{
    private readonly string $table;

    public function __construct(private readonly PDO $pdo, string $table = 'nosocket_rate_limits')
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('NoSocket table names may contain only letters, numbers, and underscores.');
        }
        $this->table = $table;
    }

    public function hit(string $key, int $limit, int $windowSeconds): bool
    {
        $bucket = (int) floor(time() / $windowSeconds);
        $hash = hash('sha256', $key);
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = in_array($driver, ['pgsql', 'sqlite'], true)
            ? sprintf('INSERT INTO %1$s (key_hash, bucket, hits, expires_at) VALUES (?, ?, 1, ?)
               ON CONFLICT (key_hash, bucket) DO UPDATE SET hits = %1$s.hits + 1', $this->table)
            : sprintf(
                'INSERT INTO %s (key_hash, bucket, hits, expires_at) VALUES (?, ?, 1, ?)
                 ON DUPLICATE KEY UPDATE hits = hits + 1',
                $this->table
            );
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$hash, $bucket, gmdate('Y-m-d H:i:s', time() + ($windowSeconds * 2))]);

        $statement = $this->pdo->prepare(sprintf('SELECT hits FROM %s WHERE key_hash = ? AND bucket = ?', $this->table));
        $statement->execute([$hash, $bucket]);

        return (int) $statement->fetchColumn() <= $limit;
    }

    public function deleteExpired(): int
    {
        $statement = $this->pdo->prepare(sprintf('DELETE FROM %s WHERE expires_at <= ?', $this->table));
        $statement->execute([gmdate('Y-m-d H:i:s')]);

        return $statement->rowCount();
    }
}
