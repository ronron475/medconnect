<?php
/**
 * API: Unread notification count
 * GET /app/api/notifications/count.php
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/NotificationManager.php';

Api::startJson();
Api::requireAuth();

$userId = (int) $_SESSION['user_id'];

try {
    Api::success([
        'unread_count' => NotificationManager::getUnreadCount($pdo, $userId),
    ]);
} catch (Exception $e) {
    Api::error('Could not get notification count.', 500);
}
