<?php
/**
 * API: Archived accounts — details, audit history, restore (Super Admin only for restore).
 * URL: /app/api/admin/archived_accounts.php
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/portal_auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/user_account_status.php';

portal_api_require_admin_portal();

user_account_status_ensure_schema($pdo);

$action = $_GET['action'] ?? $_POST['action'] ?? 'details';
$userId = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);

if ($userId <= 0 && $action !== 'list') {
    echo json_encode(['success' => false, 'message' => 'User ID is required.']);
    exit;
}

try {
    switch ($action) {
        case 'details':
            $user = user_account_status_get_details($pdo, $userId);
            if (!$user) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Account not found.']);
                exit;
            }
            if (AccountStatus::normalize((string) ($user['account_status'] ?? '')) !== AccountStatus::ARCHIVED) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'This account is not archived.']);
                exit;
            }

            $archiverName = trim(($user['archiver_first_name'] ?? '') . ' ' . ($user['archiver_last_name'] ?? ''));
            $restorerName = trim(($user['restorer_first_name'] ?? '') . ' ' . ($user['restorer_last_name'] ?? ''));

            echo json_encode([
                'success' => true,
                'data'    => [
                    'id'              => (int) $user['id'],
                    'name'            => trim($user['first_name'] . ' ' . $user['last_name']),
                    'email'           => $user['email'],
                    'role'            => $user['role'],
                    'role_label'      => user_account_role_label((string) $user['role']),
                    'account_status'  => AccountStatus::label((string) $user['account_status']),
                    'archived_at'     => $user['archived_at'],
                    'archived_by'     => $archiverName !== '' ? $archiverName : null,
                    'archive_reason'  => $user['archive_reason'],
                    'restored_at'     => $user['restored_at'],
                    'restored_by'     => $restorerName !== '' ? $restorerName : null,
                    'restore_reason'  => $user['restore_reason'],
                    'can_restore'     => portal_is_superadmin(),
                ],
            ]);
            break;

        case 'audit':
            $user = user_account_status_get_details($pdo, $userId);
            if (!$user) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Account not found.']);
                exit;
            }
            $logs = user_account_status_audit_history($pdo, $userId);
            echo json_encode([
                'success' => true,
                'data'    => [
                    'user_name' => trim($user['first_name'] . ' ' . $user['last_name']),
                    'logs'      => array_map(static function (array $log): array {
                        return [
                            'action'         => user_account_status_action_label((string) $log['action_performed']),
                            'previous_status'=> AccountStatus::label((string) $log['previous_status']),
                            'new_status'     => AccountStatus::label((string) $log['new_status']),
                            'reason'         => $log['reason'],
                            'performed_by'   => $log['performed_by_name'],
                            'ip_address'     => $log['ip_address'] ?? '—',
                            'created_at'     => $log['created_at'],
                        ];
                    }, $logs),
                ],
            ]);
            break;

        case 'restore':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                exit;
            }
            portal_api_require_superadmin();
            if (!portal_can_manage_user($pdo, $userId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'You cannot manage this account.']);
                exit;
            }
            $reason = trim((string) ($_POST['reason'] ?? ''));
            $performedBy = (int) ($_SESSION['user_id'] ?? 0);
            $result = user_account_status_change($pdo, $userId, 'restore', $reason, $performedBy);
            if (!$result['success']) {
                http_response_code(400);
            }
            echo json_encode($result);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Request failed. Please try again.']);
}
