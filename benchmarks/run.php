<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'NoSocket\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use NoSocket\Auth\SubscriptionSigner;

$iterations = 10000;
$signer = new SubscriptionSigner(str_repeat('b', 32));
$started = hrtime(true);
for ($index = 0; $index < $iterations; $index++) {
    $token = $signer->issue(['orders', 'notifications'], 'benchmark', 300);
    $signer->verify($token);
}
$elapsedMs = (hrtime(true) - $started) / 1e6;

echo json_encode([
    'environment' => PHP_VERSION . ' / ' . PHP_OS_FAMILY,
    'sign_and_verify_iterations' => $iterations,
    'elapsed_ms' => round($elapsedMs, 2),
    'operations_per_second' => round(($iterations * 2) / ($elapsedMs / 1000)),
    'request_model' => [
        'users' => 100,
        'tabs_per_user' => 10,
        'normal_interval_seconds' => 30,
        'traditional_polls_per_minute' => 2000,
        'nosocket_polls_per_minute' => 200,
        'reduction_percent' => 90,
    ],
], JSON_PRETTY_PRINT) . PHP_EOL;
