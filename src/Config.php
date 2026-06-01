<?php

declare(strict_types=1);

namespace NoSocket;

use InvalidArgumentException;

final class Config
{
    public function __construct(
        public readonly int $eventTtlSeconds = 3600,
        public readonly int $pollLimit = 100,
        public readonly int $rateLimit = 120,
        public readonly int $rateWindowSeconds = 60,
        public readonly int $tokenTtlSeconds = 3600,
        public readonly float $cleanupProbability = 0.0,
    ) {
        foreach ([
            'eventTtlSeconds' => $eventTtlSeconds,
            'pollLimit' => $pollLimit,
            'rateLimit' => $rateLimit,
            'rateWindowSeconds' => $rateWindowSeconds,
            'tokenTtlSeconds' => $tokenTtlSeconds,
        ] as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException(sprintf('%s must be greater than zero.', $name));
            }
        }
        if ($cleanupProbability < 0.0 || $cleanupProbability > 1.0) {
            throw new InvalidArgumentException('cleanupProbability must be between zero and one.');
        }
    }
}
