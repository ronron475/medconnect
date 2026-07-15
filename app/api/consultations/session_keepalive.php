<?php
/**
 * Keep the PHP session alive during an active video consultation.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/includes/session_timeout.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.', 'code' => 'session_expired']);
    exit;
}

// Touch activity while the call is open so provider idle + global timeout stay open.
session_timeout_touch();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

echo json_encode([
    'success' => true,
    'touched' => true,
    'at' => time(),
]);
