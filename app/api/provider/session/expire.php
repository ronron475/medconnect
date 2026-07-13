<?php
/**
 * Destroy provider session after client-side inactivity timeout.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/includes/auth_guard.php';
require_once dirname(__DIR__, 3) . '/app/includes/remember_me.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (($_SESSION['user_role'] ?? '') !== 'provider') {
    echo json_encode(['success' => false, 'message' => 'Not a provider session.']);
    exit;
}

auth_csrf_require();

try {
    remember_me_revoke_current_cookie($pdo);
} catch (Throwable $e) { /* non-fatal */ }

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
