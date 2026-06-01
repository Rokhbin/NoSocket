<?php

declare(strict_types=1);

namespace NoSocket\Observability;

use Closure;

final class CallableMetricsHook implements MetricsHook
{
    /** @var Closure(string, int|float, array<string, string|int|float|bool>): void */
    private readonly Closure $recorder;

    /**
     * @param callable(string, int|float, array<string, string|int|float|bool>): void $recorder
     */
    public function __construct(callable $recorder)
    {
        $this->recorder = Closure::fromCallable($recorder);
    }

    public function record(string $metric, int|float $value = 1, array $attributes = []): void
    {
        ($this->recorder)($metric, $value, $attributes);
    }
}
