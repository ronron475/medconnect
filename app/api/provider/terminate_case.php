<?php
/**
 * API: Provider terminates the current triage case (not the patient account).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/case_reports.php';

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'provider') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

auth_csrf_require();

$triageId = (int) ($_POST['triage_id'] ?? $_POST['id'] ?? 0);
$reason = trim((string) ($_POST['reason'] ?? ''));

if ($triageId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Case ID is required.']);
    exit;
}

try {
    $result = case_terminate($pdo, $triageId, (int) $_SESSION['user_id'], $reason);
    if (!$result['success']) {
        http_response_code(400);
    }
    echo json_encode($result);
} catch (Throwable $e) {
    error_log('provider/terminate_case: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to terminate case. Please try again.']);
}
