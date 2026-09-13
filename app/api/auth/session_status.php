<?php
/**
 * Lightweight auth probe for cross-tab session sync.
 * Server session remains the authority — this only reports current state.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$uid = (int) ($_SESSION['user_id'] ?? 0);
$authenticated = $uid > 0;
if ($authenticated && empty($_SESSION['authenticated'])) {
    $_SESSION['authenticated'] = true;
}

$payload = [
    'success' => true,
    'authenticated' => $authenticated,
];

if ($authenticated) {
    $payload['user_id'] = $uid;
    $payload['role'] = (string) ($_SESSION['user_role'] ?? '');
} else {
    $payload['message'] = 'Session expired. Please log in again.';
    $payload['redirect'] = (defined('BASE_URL') ? BASE_URL : '') . '/index.php?signin=1';
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE);
exit;
