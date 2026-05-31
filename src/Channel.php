<?php

declare(strict_types=1);

namespace NoSocket;

use NoSocket\Exception\InvalidChannel;

final class Channel
{
    private const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/';

    public static function validate(string $channel): string
    {
        if (!preg_match(self::PATTERN, $channel)) {
            throw new InvalidChannel('Channel must be 1-128 characters and contain only letters, numbers, dot, underscore, colon, or dash.');
        }

        return $channel;
    }

    public static function validateEvent(string $event): string
    {
        if (!preg_match(self::PATTERN, $event)) {
            throw new InvalidChannel('Event name must be 1-128 characters and contain only letters, numbers, dot, underscore, colon, or dash.');
        }

        return $event;
    }
}
