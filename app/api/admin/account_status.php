<?php
/**
 * API: Change user account status.
 * Super Admin: all actions. Administrator: archive only.
 * URL: /app/api/admin/account_status.php
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/portal_auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/user_account_status.php';

portal_api_require_admin_portal();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$userId = (int) ($_POST['user_id'] ?? 0);
$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$reason = trim((string) ($_POST['reason'] ?? ''));

if ($userId <= 0) {
    echo json_encode(['success' => false, 'message' => 'User ID is required.']);
    exit;
}

if ($action === '') {
    echo json_encode(['success' => false, 'message' => 'Action is required.']);
    exit;
}

if (!portal_is_superadmin() && $action !== 'archive') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only the Super Administrator can perform this action.']);
    exit;
}

if (!portal_is_superadmin() && !portal_can_archive_account($pdo, $userId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You cannot archive this account.']);
    exit;
}

if (portal_is_superadmin() && !portal_can_manage_user($pdo, $userId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You cannot manage this account.']);
    exit;
}

$performedBy = (int) ($_SESSION['user_id'] ?? 0);
if ($performedBy <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired. Please sign in again.']);
    exit;
}

try {
    $result = user_account_status_change($pdo, $userId, $action, $reason, $performedBy);
    if (!$result['success']) {
        http_response_code(400);
    }
    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to update account status. Please try again.']);
}
