<?php
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/config/db.php';
require_once BASE_PATH . '/app/includes/language_preferences.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$auth = theme_preferences_require_auth();
theme_preferences_verify_csrf();

$result = language_preferences_save(
    $pdo,
    $auth['user_id'],
    $auth['user_type'],
    (string) ($_POST['language'] ?? '')
);
if ($result['status'] === 'error' || empty($result['success'])) {
    http_response_code(400);
}
echo json_encode($result);
