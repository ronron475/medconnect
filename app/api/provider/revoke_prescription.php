<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/clinical_tables.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/prescription_verification.php';

clinical_tables_ensure($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$role = (string) ($_SESSION['user_role'] ?? '');
$providerId = (int) ($_SESSION['user_id'] ?? 0);
$csrf = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (prescription_revoke_gate($role, auth_csrf_validate($csrf), $providerId) !== null) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$prescriptionId = (int) ($_POST['prescription_id'] ?? 0);
$result = prescription_revoke_for_provider($pdo, $prescriptionId, $providerId);
if ($result === 'forbidden') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
if ($result === 'ineligible') {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'This prescription cannot be revoked.']);
    exit;
}

$message = $result === 'already_revoked'
    ? 'This prescription is already revoked.'
    : 'Prescription revoked. It remains in the patient record.';
echo json_encode(['success' => true, 'message' => $message, 'status' => 'revoked']);
