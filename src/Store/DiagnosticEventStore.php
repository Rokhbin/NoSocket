<?php

declare(strict_types=1);

namespace NoSocket\Store;

interface DiagnosticEventStore
{
    /**
     * @return array{events: int, expired_events: int, channels: int, watermarks: int}
     */
    public function diagnostics(): array;
}
