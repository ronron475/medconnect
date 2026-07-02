<?php
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/superadmin/backup.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($method === 'GET' && $action === 'list') {
    echo json_encode(['success' => true, 'backups' => superadmin_list_backups($pdo)]);
    exit;
}

if ($method === 'GET' && $action === 'download') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT filename, file_path FROM backup_logs WHERE id = ? AND status = ? LIMIT 1');
    $stmt->execute([$id, 'success']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !is_readable($row['file_path'])) {
        http_response_code(404);
        die('Backup not found.');
    }
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . basename($row['filename']) . '"');
    readfile($row['file_path']);
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
        $backupId = (int) ($_POST['backup_id'] ?? 0);
        echo json_encode(superadmin_restore_backup($pdo, $backupId, $userId));
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
