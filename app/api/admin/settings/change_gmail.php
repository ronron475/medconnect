<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/bootstrap.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/config/db.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/app/includes/admin_settings.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$userId = admin_settings_require_admin();
admin_settings_verify_csrf();

$result = admin_settings_change_gmail(
    $pdo,
    $userId,
    (string) ($_POST['current_password'] ?? ''),
    (string) ($_POST['email'] ?? '')
);

if (!$result['success']) {
    http_response_code(400);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
