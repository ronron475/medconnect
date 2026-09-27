<?php
/**
 * Patient accepts an early start or keeps the scheduled time.
 * Neither choice is a no-show.
 */
ob_start();

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_settings.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/consultation_queue_timing.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$patientId = patient_settings_require_patient_ready($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

auth_csrf_require();

$consultationId = (int) ($_POST['consultation_id'] ?? 0);
$choice = (string) ($_POST['choice'] ?? '');

try {
    $result = consultation_timing_record_early_response($pdo, $patientId, $consultationId, $choice);
    ob_end_clean();
    echo json_encode([
        'success' => $result['ok'],
        'message' => $result['message'],
        'response' => $result['response'],
    ]);
} catch (Throwable $e) {
    error_log('early_start_response.php: ' . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save your choice.']);
}
