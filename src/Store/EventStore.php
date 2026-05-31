<?php

declare(strict_types=1);

namespace NoSocket\Store;

use NoSocket\Event;

interface EventStore
{
    /**
     * @param array<string, mixed> $payload
     */
    public function append(string $channel, string $event, array $payload, int $ttlSeconds): Event;

    /**
     * @param array<string, int> $cursors
     * @return list<Event>
     */
    public function after(array $cursors, int $limit): array;

    /**
     * @param list<string> $channels
     * @return array<string, int>
     */
    public function latestCursors(array $channels): array;

    /**
     * @param list<string> $channels
     * @return array<string, int>
     */
    public function watermarks(array $channels): array;

    public function deleteExpired(): int;
}
