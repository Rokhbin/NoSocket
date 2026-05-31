<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'NoSocket\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use NoSocket\RateLimit\PdoRateLimiter;
use NoSocket\Store\PdoEventStore;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dsn = (string) getenv('NOSOCKET_TEST_DSN');
if ($dsn === '') {
    fwrite(STDOUT, "Database integration skipped: NOSOCKET_TEST_DSN is not set.\n");
    exit(0);
}

$pdo = new PDO($dsn, (string) getenv('NOSOCKET_TEST_USER'), (string) getenv('NOSOCKET_TEST_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('DROP TABLE IF EXISTS nosocket_rate_limits');
$pdo->exec('DROP TABLE IF EXISTS nosocket_channel_watermarks');
$pdo->exec('DROP TABLE IF EXISTS nosocket_events');
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$schema = $driver === 'pgsql' ? 'postgresql' : 'mysql';
foreach (explode(';', (string) file_get_contents(dirname(__DIR__, 2) . "/database/{$schema}/schema.sql")) as $statement) {
    if (trim($statement) !== '') {
        $pdo->exec($statement);
    }
}
$pdo->query('SELECT event_id FROM nosocket_channel_watermarks WHERE 1 = 0');

$store = new PdoEventStore($pdo);
$event = $store->append('orders', 'order.created', ['id' => 123], 60);
check($store->after(['orders' => 0], 10)[0]->id === $event->id, 'store reads emitted event');
$pdo->exec("UPDATE nosocket_events SET expires_at = '2000-01-01 00:00:00'");
check($store->deleteExpired() === 1, 'cleanup deletes expired event');
check($store->watermarks(['orders']) === ['orders' => $event->id], 'cleanup stores watermark');

$limiter = new PdoRateLimiter($pdo);
check($limiter->hit('integration', 1, 60), 'limiter permits first request');
check(!$limiter->hit('integration', 1, 60), 'limiter rejects excess request');

fwrite(STDOUT, "Database integration passed for {$driver}.\n");
