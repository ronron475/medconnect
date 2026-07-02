<?php
/**
 * API: Complete first-login patient account setup (password + policy acceptance).
 * URL: /app/api/patient/complete_account_setup.php
 */
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_account_security.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/password_history.php';
require_once BASE_PATH . '/app/includes/audit_log.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'patient') {
    ob_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (!auth_csrf_validate($_POST['csrf_token'] ?? '')) {
    ob_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
if (!patient_requires_account_setup($pdo, $userId)) {
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Account setup already completed.',
        'redirect' => ASSET_BASE . '/views/patient/dashboard.php',
    ]);
    exit;
}

$password = $_POST['password'] ?? '';
$confirm  = $_POST['confirm_password'] ?? '';
$terms    = !empty($_POST['accept_terms']);
$privacy  = !empty($_POST['accept_privacy']);

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

// Prevent password reuse (including current password)
try {
    $curStmt = $pdo->prepare('SELECT password FROM users WHERE id = ? AND role = ? LIMIT 1');
    $curStmt->execute([$userId, 'patient']);
    $currentHash = (string) ($curStmt->fetchColumn() ?: '');
    if ($currentHash !== '' && password_verify($password, $currentHash)) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'New password must be different from the current password.']);
        exit;
    }
    if (password_history_is_reused($pdo, $userId, $password, 5)) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'You cannot reuse a recent password. Please choose a new one.']);
        exit;
    }
} catch (Throwable $e) { /* non-fatal */ }

try {
    // Record old password hash (best-effort) before updating.
    try {
        if (!empty($currentHash)) {
            password_history_add($pdo, $userId, $currentHash);
        }
    } catch (Throwable $e) { /* non-fatal */ }

    patient_complete_account_setup($pdo, $userId, $password);

    // Record new password hash.
    try {
        $newStmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $newStmt->execute([$userId]);
        $newHash = (string) ($newStmt->fetchColumn() ?: '');
        if ($newHash !== '') {
            password_history_add($pdo, $userId, $newHash);
        }
    } catch (Throwable $e) { /* non-fatal */ }

    audit_log($pdo, [
        'patient_id'  => $userId,
        'action_type' => AuditAction::ACCOUNT_SETUP_COMPLETED,
        'description' => 'Patient completed first-login account setup.',
        'meta'        => ['password_changed' => true],
    ]);
    audit_log($pdo, [
        'patient_id'  => $userId,
        'action_type' => AuditAction::PASSWORD_CHANGED,
        'description' => 'Patient changed password during account setup.',
    ]);
    require_once dirname(dirname(__DIR__)) . '/app/includes/notification_events.php';
    NotificationEvents::passwordChanged($pdo, $userId, 'patient');
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not complete account setup.']);
    exit;
}

ob_clean();
echo json_encode([
    'success' => true,
    'message' => 'Your account is ready. Welcome to MedConnect!',
    'redirect' => ASSET_BASE . '/views/patient/dashboard.php',
]);
