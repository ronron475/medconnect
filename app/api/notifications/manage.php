<?php
/**
 * API: Archive or delete notification
 * POST /app/api/notifications/manage.php
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/NotificationManager.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';

Api::startJson();
Api::requireAuth();
Api::requirePost();

if (!auth_csrf_validate($_POST['csrf_token'] ?? '')) {
    Api::error('Invalid CSRF token.', 403);
}

$userId = (int) $_SESSION['user_id'];
$notificationId = (int) ($_POST['notification_id'] ?? 0);
$action = $_POST['action'] ?? '';

if ($notificationId <= 0) {
    Api::error('Notification ID required.', 400);
}

try {
    $ok = match ($action) {
        'archive' => NotificationManager::archive($pdo, $userId, $notificationId),
        'delete'  => NotificationManager::delete($pdo, $userId, $notificationId),
        default   => false,
    };

    if (!$ok) {
        Api::error('Notification not found or action failed.', 404);
    }

    Api::success([
        'unread_count' => NotificationManager::getUnreadCount($pdo, $userId),
    ], ucfirst($action) . 'd successfully.');
} catch (Exception $e) {
    Api::error('Could not manage notification.', 500);
}
