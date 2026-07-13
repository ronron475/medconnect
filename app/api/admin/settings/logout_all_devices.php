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

$userId = admin_settings_require_staff();
admin_settings_verify_csrf();

$result = admin_settings_logout_all_devices($pdo, $userId);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
