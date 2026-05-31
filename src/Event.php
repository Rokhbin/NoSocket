<?php

declare(strict_types=1);

namespace NoSocket;

use JsonSerializable;

final class Event implements JsonSerializable
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $id,
        public readonly string $channel,
        public readonly string $event,
        public readonly array $payload,
        public readonly string $createdAt,
    ) {
    }

    /**
     * @return array{id: int, channel: string, event: string, payload: array<string, mixed>, created_at: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'event' => $this->event,
            'payload' => $this->payload,
            'created_at' => $this->createdAt,
        ];
    }
}
