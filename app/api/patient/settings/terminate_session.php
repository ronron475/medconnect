<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/bootstrap.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/config/db.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/app/includes/patient_settings.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$userId = patient_settings_require_patient();
patient_settings_verify_csrf();

$sessionId = (int) ($_POST['session_id'] ?? 0);
$result = patient_settings_terminate_session($pdo, $userId, $sessionId);
if (!$result['success']) {
    http_response_code(400);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
