<?php

declare(strict_types=1);

namespace NoSocket\Http;

use RuntimeException;

final class RateLimitExceeded extends RuntimeException
{
}
