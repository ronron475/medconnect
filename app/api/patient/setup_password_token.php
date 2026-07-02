<?php
/**
 * API: Set password via secure setup token (email link, no login required).
 * URL: /app/api/patient/setup_password_token.php
 */
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_account_security.php';
require_once BASE_PATH . '/app/includes/audit_log.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$token    = trim($_POST['token'] ?? '');
$password = $_POST['password'] ?? '';
$confirm  = $_POST['confirm_password'] ?? '';
$terms    = !empty($_POST['accept_terms']);
$privacy  = !empty($_POST['accept_privacy']);

$user = patient_find_by_setup_token($pdo, $token);
if (!$user) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'This setup link is invalid or has expired.']);
    exit;
}

if (!$terms || !$privacy) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'You must accept the Privacy Policy and Terms of Service.']);
    exit;
}

$policyError = patient_validate_password_policy($password);
if ($policyError !== null) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $policyError]);
    exit;
}
if ($password !== $confirm) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

$userId = (int) $user['id'];
try {
    patient_complete_account_setup($pdo, $userId, $password);
    audit_log($pdo, [
        'patient_id'  => $userId,
        'action_type' => AuditAction::ACCOUNT_SETUP_COMPLETED,
        'description' => 'Patient completed account setup via email link.',
        'meta'        => ['via' => 'setup_token'],
    ]);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not set password.']);
    exit;
}

ob_clean();
echo json_encode([
    'success' => true,
    'message' => 'Password set successfully. You may now sign in.',
    'redirect' => ASSET_BASE . '/index.php?setup_complete=1',
]);
