<?php
/**
 * Destroy provider session after client-side inactivity timeout.
 */
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once dirname(__DIR__, 4) . '/bootstrap.php';

if (($_SESSION['user_role'] ?? '') !== 'provider') {
    echo json_encode(['success' => false, 'message' => 'Not a provider session.']);
    exit;
}

$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_unset();
    session_destroy();
}

echo json_encode([
    'success' => true,
    'redirect' => BASE_URL . '/index.php?session_expired=1',
    'message' => 'Your session expired due to inactivity.',
]);
