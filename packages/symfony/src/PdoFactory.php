<?php

declare(strict_types=1);

namespace NoSocket\Symfony;

use Doctrine\DBAL\Connection;
use PDO;
use RuntimeException;

final class PdoFactory
{
    public static function fromDoctrine(Connection $connection): PDO
    {
        $native = $connection->getNativeConnection();
        if (!$native instanceof PDO) {
            throw new RuntimeException('NoSocket requires a PDO-backed Doctrine connection.');
        }

        return $native;
    }
}
