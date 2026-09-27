<?php
/**
 * Doctor finished early and offers the next queued patient an early start.
 */
ob_start();

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/consultation_queue_timing.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'provider') {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

auth_csrf_require();

try {
    $result = consultation_timing_offer_early_start($pdo, (int) $_SESSION['user_id']);
    ob_end_clean();
    echo json_encode([
        'success' => $result['ok'],
        'message' => $result['message'],
        'consultation_id' => $result['consultation_id'],
        'patient_name' => $result['patient_name'],
    ]);
} catch (Throwable $e) {
    error_log('ready_for_next.php: ' . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not notify the next patient.']);
}
