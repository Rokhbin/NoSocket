<?php

declare(strict_types=1);

namespace NoSocket\Diagnostics;

use NoSocket\Store\DiagnosticEventStore;

final class DiagnosticsService
{
    public function __construct(private readonly DiagnosticEventStore $store)
    {
    }

    /**
     * @return array{events: int, expired_events: int, channels: int, watermarks: int}
     */
    public function snapshot(): array
    {
        return $this->store->diagnostics();
    }
}
