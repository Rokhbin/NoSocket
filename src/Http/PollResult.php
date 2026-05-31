<?php

declare(strict_types=1);

namespace NoSocket\Http;

use JsonSerializable;

final class PollResult implements JsonSerializable
{
    /**
     * @param list<\NoSocket\Event> $events
     * @param array<string, int> $cursors
     * @param list<string> $resyncRequired
     */
    public function __construct(
        public readonly array $events,
        public readonly array $cursors,
        public readonly bool $hasMore,
        public readonly array $resyncRequired = [],
    ) {
    }

    /**
     * @return array{events: list<\NoSocket\Event>, cursors: array<string, int>, has_more: bool, resync_required: list<string>}
     */
    public function jsonSerialize(): array
    {
        return [
            'events' => $this->events,
            'cursors' => $this->cursors,
            'has_more' => $this->hasMore,
            'resync_required' => $this->resyncRequired,
        ];
    }
}
