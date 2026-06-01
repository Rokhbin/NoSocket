<?php

declare(strict_types=1);

namespace NoSocket;

use InvalidArgumentException;
use NoSocket\Observability\MetricsHook;
use NoSocket\Observability\NullMetricsHook;
use NoSocket\Store\BatchEventStore;
use NoSocket\Store\EventStore;

final class NoSocket
{
    public function __construct(
        private readonly EventStore $store,
        private readonly Config $config = new Config(),
        private readonly MetricsHook $metrics = new NullMetricsHook(),
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

        $event = $this->store->append(
            Channel::validate($channel),
            Channel::validateEvent($event),
            $payload,
            $ttlSeconds,
        );
        $this->recordMetric('nosocket.events.emitted', 1, ['channel' => $event->channel]);
        $this->cleanupProbabilistically();

        return $event;
    }

    /**
     * @param list<array{channel: string, event: string, payload?: array<string, mixed>, ttl_seconds?: int|null}> $events
     * @return list<Event>
     */
    public function emitBatch(array $events): array
    {
        $normalized = array_map(function (array $event): array {
            $ttlSeconds = $event['ttl_seconds'] ?? $this->config->eventTtlSeconds;
            if ($ttlSeconds < 1) {
                throw new InvalidArgumentException('Event TTL must be greater than zero.');
            }

            return [
                'channel' => Channel::validate($event['channel']),
                'event' => Channel::validateEvent($event['event']),
                'payload' => $event['payload'] ?? [],
                'ttl_seconds' => $ttlSeconds,
            ];
        }, $events);

        $appended = $this->store instanceof BatchEventStore
            ? $this->store->appendBatch($normalized)
            : array_map(
                fn (array $event): Event => $this->store->append(
                    $event['channel'],
                    $event['event'],
                    $event['payload'],
                    $event['ttl_seconds'],
                ),
                $normalized,
            );
        if ($appended !== []) {
            $this->recordMetric('nosocket.events.emitted', count($appended), ['mode' => 'batch']);
            $this->cleanupProbabilistically();
        }

        return $appended;
    }

    public function cleanup(): int
    {
        $deleted = $this->store->deleteExpired();
        $this->recordMetric('nosocket.events.cleaned', $deleted);

        return $deleted;
    }

    public function cleanupProbabilistically(?int $roll = null): int
    {
        if ($this->config->cleanupProbability <= 0.0) {
            return 0;
        }
        $roll ??= random_int(1, 1_000_000);
        if ($roll > (int) round($this->config->cleanupProbability * 1_000_000)) {
            return 0;
        }

        try {
            return $this->cleanup();
        } catch (\Throwable) {
            $this->recordMetric('nosocket.cleanup.failures');

            return 0;
        }
    }

    /**
     * @param array<string, string|int|float|bool> $attributes
     */
    private function recordMetric(string $metric, int|float $value = 1, array $attributes = []): void
    {
        try {
            $this->metrics->record($metric, $value, $attributes);
        } catch (\Throwable) {
            // Instrumentation must not break event delivery.
        }
    }
}
