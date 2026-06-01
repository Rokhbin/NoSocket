<?php

declare(strict_types=1);

namespace NoSocket\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \NoSocket\Event emit(string $channel, string $event, array $payload, ?int $ttlSeconds = null)
 * @method static list<\NoSocket\Event> emitBatch(array $events)
 * @method static int cleanup()
 * @method static int cleanupProbabilistically(?int $roll = null)
 */
final class NoSocket extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \NoSocket\NoSocket::class;
    }
}
