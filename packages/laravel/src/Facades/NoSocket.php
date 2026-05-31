<?php

declare(strict_types=1);

namespace NoSocket\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \NoSocket\Event emit(string $channel, string $event, array $payload, ?int $ttlSeconds = null)
 * @method static int cleanup()
 */
final class NoSocket extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \NoSocket\NoSocket::class;
    }
}
