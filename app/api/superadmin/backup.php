<?php
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/superadmin/backup.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($method === 'GET' && $action === 'list') {
    echo json_encode([
        'success' => true,
        'backups' => superadmin_list_backups($pdo),
        'status' => superadmin_backup_status_summary($pdo),
    ]);
    exit;
}

if ($method === 'GET' && $action === 'status') {
    echo json_encode([
        'success' => true,
        'status' => superadmin_backup_status_summary($pdo),
    ]);
    exit;
}

if ($action === 'download' && $method !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Download requires POST with Super Admin password re-authentication.',
    ]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

switch ($action) {
    case 'create':
        $result = superadmin_create_backup($pdo, $userId, 'manual');
        echo json_encode($result);
        break;
    case 'restore':
        // Manual restore only — never triggered by cron/schedule.
        $backupId = (int) ($_POST['backup_id'] ?? 0);
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $confirmText = (string) ($_POST['confirm_text'] ?? '');
        echo json_encode(superadmin_restore_backup($pdo, $backupId, $userId, $currentPassword, $confirmText));
        break;
    case 'download':
        superadmin_ensure_schema($pdo);
        $backupId = (int) ($_POST['backup_id'] ?? 0);
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $prepared = superadmin_backup_download_prepare($pdo, $backupId, $userId, $currentPassword);
        if (empty($prepared['success'])) {
            echo json_encode([
                'success' => false,
                'message' => (string) ($prepared['message'] ?? 'Download failed.'),
            ]);
            break;
        }
        $downloadName = basename((string) ($prepared['filename'] ?? 'medconnect_backup.sql'));
        $downloadName = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName) ?: 'medconnect_backup.sql';
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile((string) $prepared['path']);
        exit;
    case 'save_settings':
        $result = superadmin_backup_settings_save($pdo, [
            'enabled' => $_POST['enabled'] ?? '0',
            'frequency' => $_POST['frequency'] ?? 'daily',
            'hour' => $_POST['hour'] ?? 2,
            'weekday' => $_POST['weekday'] ?? 0,
            'retention_count' => $_POST['retention_count'] ?? 14,
        ], $userId);
        echo json_encode($result);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
