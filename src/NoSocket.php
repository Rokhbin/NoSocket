<?php

declare(strict_types=1);

namespace NoSocket;

use InvalidArgumentException;
use NoSocket\Store\EventStore;

final class NoSocket
{
    public function __construct(
        private readonly EventStore $store,
        private readonly Config $config = new Config(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function emit(string $channel, string $event, array $payload, ?int $ttlSeconds = null): Event
    {
        $ttlSeconds ??= $this->config->eventTtlSeconds;
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('Event TTL must be greater than zero.');
        }

        return $this->store->append(
            Channel::validate($channel),
            Channel::validateEvent($event),
            $payload,
            $ttlSeconds,
        );
    }

    public function cleanup(): int
    {
        return $this->store->deleteExpired();
    }
}
