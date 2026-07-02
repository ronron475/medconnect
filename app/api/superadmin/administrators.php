<?php
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/superadmin/service.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/superadmin/security.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_verification.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/user_account_status.php';
require_once BASE_PATH . '/app/includes/audit_log.php';

user_account_status_ensure_schema($pdo);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

if ($method === 'GET' && $action === 'list') {
    echo json_encode(['success' => true, 'admins' => superadmin_list_admins($pdo)]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$userId = (int) ($_POST['user_id'] ?? 0);

switch ($action) {
    case 'create':
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        if (!$first || !$last || !$email || !$password) {
            echo json_encode(['success' => false, 'message' => 'All fields are required.']);
            exit;
        }
        if (strlen($password) < 8) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
            exit;
        }
        $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $dup->execute([$email]);
        if ($dup->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already exists.']);
            exit;
        }
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("
            INSERT INTO users (first_name, last_name, email, password, role, is_active, account_status, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'admin', 1, 'active', NOW(), NOW())
        ")->execute([$first, $last, $email, $hash]);
        $newId = (int) $pdo->lastInsertId();
        superadmin_security_log($pdo, 'admin_created', 'administrators', 'success', "Created admin {$email}", (int) $_SESSION['user_id'], 'superadmin');
        audit_log($pdo, ['patient_id' => (int) $_SESSION['user_id'], 'action_type' => 'admin_created', 'description' => "Super Admin created administrator {$email}."]);
        echo json_encode(['success' => true, 'message' => 'Administrator created.', 'user_id' => $newId]);
        break;

    case 'update':
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        if (!$userId || !$first || !$last || !$email) {
            echo json_encode(['success' => false, 'message' => 'Invalid data.']);
            exit;
        }
        if (!portal_can_manage_user($pdo, $userId)) {
            echo json_encode(['success' => false, 'message' => 'Cannot modify this account.']);
            exit;
        }
        $pdo->prepare('UPDATE users SET first_name=?, last_name=?, email=?, updated_at=NOW() WHERE id=? AND role IN (\'admin\', \'superadmin\')')
            ->execute([$first, $last, $email, $userId]);
        superadmin_security_log($pdo, 'admin_updated', 'administrators', 'success', "Updated admin #{$userId}");
        echo json_encode(['success' => true, 'message' => 'Administrator updated.']);
        break;

    case 'toggle_status':
        $status = (int) ($_POST['status'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (!$userId || !portal_can_manage_user($pdo, $userId)) {
            echo json_encode(['success' => false, 'message' => 'Cannot modify this account.']);
            exit;
        }
        if ($reason === '') {
            echo json_encode(['success' => false, 'message' => 'A reason is required for account status changes.']);
            exit;
        }
        if ($userId === (int) $_SESSION['user_id'] && $status === 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot suspend your own account.']);
            exit;
        }
        $roleStmt = $pdo->prepare('SELECT role, is_active, account_status FROM users WHERE id = ?');
        $roleStmt->execute([$userId]);
        $targetRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
        if (($targetRow['role'] ?? '') === 'superadmin') {
            echo json_encode(['success' => false, 'message' => 'Super Admin accounts cannot be suspended here.']);
            exit;
        }
        $currentStatus = user_account_status_effective($targetRow ?: []);
        $action = $status === 1
            ? (in_array('reactivate', user_account_status_allowed_actions($currentStatus), true) ? 'reactivate' : 'activate')
            : 'suspend';
        $result = user_account_status_change($pdo, $userId, $action, $reason, (int) $_SESSION['user_id']);
        if ($result['success']) {
            superadmin_security_log($pdo, $status ? 'admin_activated' : 'admin_suspended', 'administrators', 'success', "Admin #{$userId} status changed");
        }
        echo json_encode($result);
        break;

    case 'reset_password':
        $password = $_POST['password'] ?? '';
        if (!$userId || strlen($password) < 8) {
            echo json_encode(['success' => false, 'message' => 'Valid user and password (8+ chars) required.']);
            exit;
        }
        if (!portal_can_manage_user($pdo, $userId)) {
            echo json_encode(['success' => false, 'message' => 'Cannot modify this account.']);
            exit;
        }
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?')->execute([$hash, $userId]);
        superadmin_security_log($pdo, 'password_reset', 'administrators', 'warning', "Reset password for user #{$userId}");
        echo json_encode(['success' => true, 'message' => 'Password reset successfully.']);
        break;

    case 'delete':
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (!$userId || !portal_can_manage_user($pdo, $userId)) {
            echo json_encode(['success' => false, 'message' => 'Cannot archive this account.']);
            exit;
        }
        if ($reason === '') {
            echo json_encode(['success' => false, 'message' => 'A reason is required to archive an account.']);
            exit;
        }
        $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
        $roleStmt->execute([$userId]);
        $role = $roleStmt->fetchColumn();
        if ($role === 'superadmin') {
            echo json_encode(['success' => false, 'message' => 'Super Admin accounts cannot be archived.']);
            exit;
        }
        $result = user_account_status_change($pdo, $userId, 'archive', $reason, (int) $_SESSION['user_id']);
        if ($result['success']) {
            superadmin_security_log($pdo, 'admin_archived', 'administrators', 'warning', "Archived admin #{$userId}");
        }
        echo json_encode($result['success']
            ? ['success' => true, 'message' => 'Administrator archived. All records preserved for audit.']
            : $result);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
