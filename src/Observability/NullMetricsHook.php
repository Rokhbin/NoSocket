<?php

declare(strict_types=1);

namespace NoSocket\Observability;

final class NullMetricsHook implements MetricsHook
{
    public function record(string $metric, int|float $value = 1, array $attributes = []): void
    {
    }
}
