<?php
/**
 * API: Provider reports a possible violation during a live video consultation.
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

$consultationId = (int) ($_POST['consultation_id'] ?? 0);
$reason = trim((string) ($_POST['reason'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));
$endConsultation = in_array(strtolower((string) ($_POST['end_consultation'] ?? '')), ['1', 'true', 'yes'], true);
$action = strtolower(trim((string) ($_POST['action'] ?? 'report')));

if ($consultationId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Consultation ID is required.']);
    exit;
}

try {
    if ($action === 'end_only') {
        $result = consultation_end_from_violation(
            $pdo,
            $consultationId,
            (int) $_SESSION['user_id'],
            trim((string) ($_POST['reason'] ?? 'Provider ended consultation.'))
        );
    } else {
        $result = consultation_violation_report_submit(
            $pdo,
            $consultationId,
            (int) $_SESSION['user_id'],
            $reason,
            $notes,
            $endConsultation
        );
    }

    if (!$result['success']) {
        http_response_code(($result['code'] ?? '') === 'duplicate_report' ? 409 : 400);
    }
    echo json_encode($result);
} catch (Throwable $e) {
    error_log('provider/consultation_violation_report: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to submit report. Please try again.']);
}
