<?php

declare(strict_types=1);

use NoSocket\Auth\SubscriptionSigner;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
$requested = array_values(array_filter((array) ($body['channels'] ?? []), 'is_string'));

// Replace this allowlist with your application's authorization policy.
$allowed = ['orders', 'notifications', 'user:' . $_SESSION['user_id']];
$granted = array_values(array_intersect($requested, $allowed));
$signer = new SubscriptionSigner((string) getenv('NOSOCKET_SECRET'));

echo json_encode([
    'token' => $signer->issue($granted, 'user:' . $_SESSION['user_id'], 3600),
    'channels' => $granted,
    'expires_at' => gmdate(DATE_ATOM, time() + 3600),
], JSON_THROW_ON_ERROR);
