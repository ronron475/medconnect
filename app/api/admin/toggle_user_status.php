<?php
/**
 * API: Toggle user account status (deprecated path — Super Administrator only).
 * URL: /app/api/admin/toggle_user_status.php
 */
session_start();
header('Content-Type: application/json');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/portal_auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/user_account_status.php';

portal_api_require_superadmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$user_id = (int) ($_POST['user_id'] ?? 0);
$status  = (int) ($_POST['status'] ?? 0);
$reason  = trim((string) ($_POST['reason'] ?? ''));

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'User ID is required.']);
    exit;
}

if ($reason === '') {
    echo json_encode(['success' => false, 'message' => 'A reason is required for account status changes.']);
    exit;
}

if (!portal_can_manage_user($pdo, $user_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You cannot manage this account.']);
    exit;
}

$action = $status === 1 ? 'activate' : 'deactivate';
$performedBy = (int) ($_SESSION['user_id'] ?? 0);

try {
    $result = user_account_status_change($pdo, $user_id, $action, $reason, $performedBy);
    if (!$result['success']) {
        http_response_code(400);
    }
    echo json_encode($result);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
