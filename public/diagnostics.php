<?php

declare(strict_types=1);

use NoSocket\Diagnostics\DiagnosticsService;
use NoSocket\Store\PdoEventStore;

require dirname(__DIR__) . '/vendor/autoload.php';

header('Cache-Control: no-store');

$configuredToken = (string) getenv('NOSOCKET_DIAGNOSTICS_TOKEN');
$providedToken = preg_replace('/^Bearer\s+/i', '', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
if ($configuredToken === '' || !hash_equals($configuredToken, $providedToken)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
}

$pdo = new PDO((string) getenv('NOSOCKET_DSN'), (string) getenv('NOSOCKET_DB_USER'), (string) getenv('NOSOCKET_DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$snapshot = (new DiagnosticsService(new PdoEventStore($pdo)))->snapshot();

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NoSocket Diagnostics</title>
    <style>
        body { color: #172033; font: 16px system-ui, sans-serif; margin: 2rem auto; max-width: 52rem; padding: 0 1rem; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border-bottom: 1px solid #d9dfeb; padding: .75rem; text-align: left; }
        th { color: #526075; }
    </style>
</head>
<body>
    <h1>NoSocket Diagnostics</h1>
    <p>Current event-log snapshot. This endpoint is read-only and protected by <code>NOSOCKET_DIAGNOSTICS_TOKEN</code>.</p>
    <table>
        <tbody>
        <?php foreach ($snapshot as $label => $value): ?>
            <tr><th><?= htmlspecialchars(str_replace('_', ' ', $label), ENT_QUOTES, 'UTF-8') ?></th><td><?= $value ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
