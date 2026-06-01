<?php

declare(strict_types=1);

namespace NoSocket\Store;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use NoSocket\Event;
use PDO;
use RuntimeException;

final class PdoEventStore implements EventStore, BatchEventStore, DiagnosticEventStore
{
    private readonly string $table;
    private readonly string $watermarksTable;

    public function __construct(
        private readonly PDO $pdo,
        string $table = 'nosocket_events',
        string $watermarksTable = 'nosocket_channel_watermarks',
    ) {
        $this->table = $this->validateTable($table);
        $this->watermarksTable = $this->validateTable($watermarksTable);
    }

    public function append(string $channel, string $event, array $payload, int $ttlSeconds): Event
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify(sprintf('+%d seconds', $ttlSeconds));
        $statement = $this->pdo->prepare(
            sprintf(
                'INSERT INTO %s (channel, event, payload_json, created_at, expires_at)
                 VALUES (:channel, :event, :payload, :created_at, :expires_at)',
                $this->table
            )
        );
        $statement->execute([
            'channel' => $channel,
            'event' => $event,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);

        return new Event((int) $this->pdo->lastInsertId(), $channel, $event, $payload, $now->format(DATE_ATOM));
    }

    public function appendBatch(array $events): array
    {
        if ($events === []) {
            return [];
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $appended = array_map(
                fn (array $event): Event => $this->append(
                    $event['channel'],
                    $event['event'],
                    $event['payload'],
                    $event['ttl_seconds'],
                ),
                $events,
            );
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $appended;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function after(array $cursors, int $limit): array
    {
        if ($cursors === []) {
            return [];
        }

        $conditions = [];
        $parameters = [];
        foreach ($cursors as $channel => $cursor) {
            $conditions[] = '(channel = ? AND id > ?)';
            $parameters[] = $channel;
            $parameters[] = $cursor;
        }
        $sql = sprintf(
            'SELECT id, channel, event, payload_json, created_at
             FROM %s
             WHERE (%s) AND expires_at > ?
             ORDER BY id ASC
             LIMIT %d',
            $this->table,
            implode(' OR ', $conditions),
            $limit
        );
        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ...$parameters,
            gmdate('Y-m-d H:i:s'),
        ]);

        return array_map(fn (array $row): Event => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function latestCursors(array $channels): array
    {
        if ($channels === []) {
            return [];
        }

        $statement = $this->pdo->prepare(sprintf(
            'SELECT channel, MAX(id) AS latest_event_id FROM %s WHERE channel IN (%s) GROUP BY channel',
            $this->table,
            implode(', ', array_fill(0, count($channels), '?'))
        ));
        $statement->execute($channels);
        $cursors = array_fill_keys($channels, 0);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cursors[$row['channel']] = (int) $row['latest_event_id'];
        }

        return $cursors;
    }

    public function watermarks(array $channels): array
    {
        if ($channels === []) {
            return [];
        }

        $statement = $this->pdo->prepare(sprintf(
            'SELECT channel, event_id FROM %s WHERE channel IN (%s)',
            $this->watermarksTable,
            implode(', ', array_fill(0, count($channels), '?'))
        ));
        $statement->execute($channels);
        $watermarks = array_fill_keys($channels, 0);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $watermarks[$row['channel']] = (int) $row['event_id'];
        }

        return $watermarks;
    }

    public function deleteExpired(): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(sprintf(
                'SELECT channel, MAX(id) AS deleted_event_id FROM %s WHERE expires_at <= ? GROUP BY channel',
                $this->table
            ));
            $statement->execute([$now]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $this->upsertWatermark($row['channel'], (int) $row['deleted_event_id'], $now);
            }

            $statement = $this->pdo->prepare(sprintf('DELETE FROM %s WHERE expires_at <= ?', $this->table));
            $statement->execute([$now]);
            $deleted = $statement->rowCount();
            $this->pdo->commit();

            return $deleted;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function diagnostics(): array
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT COUNT(*) AS events,
                    COALESCE(SUM(CASE WHEN expires_at <= ? THEN 1 ELSE 0 END), 0) AS expired_events,
                    COUNT(DISTINCT channel) AS channels
             FROM %s',
            $this->table
        ));
        $statement->execute([gmdate('Y-m-d H:i:s')]);
        $events = $statement->fetch(PDO::FETCH_ASSOC);

        return [
            'events' => (int) $events['events'],
            'expired_events' => (int) $events['expired_events'],
            'channels' => (int) $events['channels'],
            'watermarks' => (int) $this->pdo->query(sprintf('SELECT COUNT(*) FROM %s', $this->watermarksTable))->fetchColumn(),
        ];
    }

    private function validateTable(string $table): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new RuntimeException('NoSocket table names may contain only letters, numbers, and underscores.');
        }

        return $table;
    }

    private function upsertWatermark(string $channel, int $cursor, string $updatedAt): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'mysql'
            ? sprintf(
                'INSERT INTO %s (channel, event_id, updated_at) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE event_id = GREATEST(event_id, VALUES(event_id)), updated_at = VALUES(updated_at)',
                $this->watermarksTable
            )
            : ($driver === 'pgsql' ? sprintf(
                'INSERT INTO %s (channel, event_id, updated_at) VALUES (?, ?, ?)
                 ON CONFLICT (channel) DO UPDATE SET event_id = GREATEST(%s.event_id, excluded.event_id), updated_at = excluded.updated_at',
                $this->watermarksTable,
                $this->watermarksTable
            ) : sprintf(
                'INSERT INTO %s (channel, event_id, updated_at) VALUES (?, ?, ?)
                 ON CONFLICT (channel) DO UPDATE SET event_id = MAX(%s.event_id, excluded.event_id), updated_at = excluded.updated_at',
                $this->watermarksTable,
                $this->watermarksTable
            ));
        $this->pdo->prepare($sql)->execute([$channel, $cursor, $updatedAt]);
    }

    /**
     * @param array{id: int|string, channel: string, event: string, payload_json: string, created_at: string} $row
     */
    private function hydrate(array $row): Event
    {
        try {
            $payload = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored NoSocket event payload is not valid JSON.', 0, $exception);
        }

        if (!is_array($payload)) {
            throw new RuntimeException('Stored NoSocket event payload must decode to an object.');
        }

        return new Event((int) $row['id'], $row['channel'], $row['event'], $payload, $row['created_at']);
    }
}
