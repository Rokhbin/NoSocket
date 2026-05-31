<?php

declare(strict_types=1);

use NoSocket\NoSocket;
use NoSocket\Store\PdoEventStore;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$pdo = new PDO((string) getenv('NOSOCKET_DSN'), (string) getenv('NOSOCKET_DB_USER'), (string) getenv('NOSOCKET_DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

return new NoSocket(new PdoEventStore($pdo));
