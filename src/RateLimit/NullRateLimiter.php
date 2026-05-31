<?php

declare(strict_types=1);

namespace NoSocket\RateLimit;

final class NullRateLimiter implements RateLimiter
{
    public function hit(string $key, int $limit, int $windowSeconds): bool
    {
        return true;
    }
}
