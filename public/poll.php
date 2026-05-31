<?php

declare(strict_types=1);

use NoSocket\Auth\SubscriptionSigner;
use NoSocket\Config;
use NoSocket\Http\PollService;
use NoSocket\Http\RateLimitExceeded;
use NoSocket\RateLimit\PdoRateLimiter;
use NoSocket\Store\PdoEventStore;

require dirname(__DIR__) . '/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['error' => 'NoSocket poll requires POST.']);
        exit;
    }
    $pdo = new PDO((string) getenv('NOSOCKET_DSN'), (string) getenv('NOSOCKET_DB_USER'), (string) getenv('NOSOCKET_DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $body = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    $token = preg_replace('/^Bearer\s+/i', '', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    $clientKey = ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ':' . hash('sha256', $token);
    $service = new PollService(
        new PdoEventStore($pdo),
        new SubscriptionSigner((string) getenv('NOSOCKET_SECRET')),
        new PdoRateLimiter($pdo),
        new Config(),
    );
    echo json_encode($service->poll((array) ($body['subscriptions'] ?? []), $token, $clientKey), JSON_THROW_ON_ERROR);
} catch (RateLimitExceeded $exception) {
    http_response_code(429);
    header('Retry-After: 120');
    echo json_encode(['error' => $exception->getMessage()]);
} catch (JsonException $exception) {
    http_response_code(400);
    echo json_encode(['error' => 'NoSocket poll requires a valid JSON body.']);
} catch (InvalidArgumentException $exception) {
    http_response_code(403);
    echo json_encode(['error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'NoSocket poll failed.']);
}
