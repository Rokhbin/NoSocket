<?php

declare(strict_types=1);

namespace NoSocket\RateLimit;

interface RateLimiter
{
    public function hit(string $key, int $limit, int $windowSeconds): bool;
}
