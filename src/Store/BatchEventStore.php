<?php

declare(strict_types=1);

namespace NoSocket\Store;

use NoSocket\Event;

interface BatchEventStore
{
    /**
     * @param list<array{channel: string, event: string, payload: array<string, mixed>, ttl_seconds: int}> $events
     * @return list<Event>
     */
    public function appendBatch(array $events): array;
}
