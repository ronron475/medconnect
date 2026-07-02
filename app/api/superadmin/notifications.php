<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/core/NotificationManager.php';
require_once BASE_PATH . '/app/includes/superadmin/security.php';
require_once BASE_PATH . '/app/includes/audit_log.php';

NotificationManager::ensureSchema($pdo);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$uid = (int) ($_SESSION['user_id'] ?? 0);

if ($method === 'GET' && $action === 'list') {
    $filter = trim($_GET['filter'] ?? 'all');
    $limit = min(200, max(10, (int) ($_GET['limit'] ?? 50)));
    $where = "n.status = 'active'";
    if ($filter === 'unread') {
        $where .= ' AND n.is_read = 0';
    } elseif ($filter === 'read') {
        $where .= ' AND n.is_read = 1';
    }
    $rows = $pdo->query("
        SELECT n.*, u.email, u.first_name, u.last_name
        FROM notifications n
        LEFT JOIN users u ON u.id = n.user_id
        WHERE {$where}
        ORDER BY n.created_at DESC
        LIMIT {$limit}
    ")->fetchAll(PDO::FETCH_ASSOC);
    $pending = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND status = 'active'")->fetchColumn();
    echo json_encode(['success' => true, 'rows' => $rows, 'unread_count' => $pending]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$notificationId = (int) ($_POST['notification_id'] ?? 0);

if ($action === 'mark_read' && $notificationId > 0) {
    $pdo->prepare("UPDATE notifications SET is_read = 1, updated_at = NOW() WHERE id = ?")->execute([$notificationId]);
    echo json_encode(['success' => true, 'message' => 'Notification marked as read.']);
    exit;
}

if ($action === 'mark_unread' && $notificationId > 0) {
    $pdo->prepare("UPDATE notifications SET is_read = 0, updated_at = NOW() WHERE id = ? AND status = 'active'")->execute([$notificationId]);
    echo json_encode(['success' => true, 'message' => 'Notification marked as unread.']);
    exit;
}

if ($action === 'delete' && $notificationId > 0) {
    $pdo->prepare("UPDATE notifications SET status = 'deleted', updated_at = NOW() WHERE id = ?")->execute([$notificationId]);
    echo json_encode(['success' => true, 'message' => 'Notification deleted.']);
    exit;
}

if ($action === 'mark_all_read') {
    $pdo->exec("UPDATE notifications SET is_read = 1, updated_at = NOW() WHERE is_read = 0 AND status = 'active'");
    echo json_encode(['success' => true, 'message' => 'All notifications marked as read.']);
    exit;
}

if ($action === 'broadcast') {
    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $type = trim($_POST['type'] ?? NotificationManager::TYPE_SYSTEM);
    $priority = trim($_POST['priority'] ?? 'normal');
    $targetRole = trim($_POST['target_role'] ?? '');

    if ($title === '' || $message === '') {
        echo json_encode(['success' => false, 'message' => 'Title and message are required.']);
        exit;
    }

    $options = [
        'sender_id' => $uid,
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'priority' => $priority,
    ];

    $count = 0;
    if ($targetRole === '' || $targetRole === 'all') {
        $users = $pdo->query("SELECT id FROM users WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($users as $userId) {
            if (NotificationManager::create($pdo, (int) $userId, $options)) {
                $count++;
            }
        }
    } else {
        $count = NotificationManager::notifyRole($pdo, $targetRole, $options);
    }

    superadmin_security_log($pdo, 'notification_broadcast', 'notifications', 'success', "Broadcast to {$count} users", $uid);
    audit_log($pdo, [
        'patient_id' => $uid,
        'action_type' => 'notification_broadcast',
        'description' => "Super Admin broadcast notification to {$count} users.",
        'meta' => ['role' => $targetRole ?: 'all', 'title' => $title],
    ]);

    echo json_encode(['success' => true, 'message' => "Notification sent to {$count} user(s).", 'count' => $count]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);
