<?php
/**
 * API: Mark notification(s) as read
 * POST /app/api/notifications/mark_read.php
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
$action = $_POST['action'] ?? 'single';
$notificationId = (int) ($_POST['notification_id'] ?? 0);

try {
    if ($action === 'all') {
        NotificationManager::markAllRead($pdo, $userId);
        Api::success(['unread_count' => 0], 'All notifications marked as read.');
    }

    if ($notificationId <= 0) {
        Api::error('Notification ID required.', 400);
    }

    if ($action === 'unread') {
        NotificationManager::markUnread($pdo, $userId, $notificationId);
        Api::success([
            'unread_count' => NotificationManager::getUnreadCount($pdo, $userId),
        ], 'Notification marked as unread.');
    }

    NotificationManager::markRead($pdo, $userId, $notificationId);
    Api::success([
        'unread_count' => NotificationManager::getUnreadCount($pdo, $userId),
    ], 'Notification marked as read.');
} catch (Exception $e) {
    Api::error('Could not update notification.', 500);
}
