<?php

declare(strict_types=1);

namespace NoSocket\Observability;

interface MetricsHook
{
    /**
     * @param array<string, string|int|float|bool> $attributes
     */
    public function record(string $metric, int|float $value = 1, array $attributes = []): void;
}
