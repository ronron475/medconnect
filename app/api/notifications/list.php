<?php
/**
 * API: List notifications with filtering, search, pagination
 * GET /app/api/notifications/list.php
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/NotificationManager.php';

Api::startJson();
Api::requireAuth();

$userId = (int) $_SESSION['user_id'];

$filters = [
    'unread_only' => !empty($_GET['unread_only']),
    'status'      => $_GET['status'] ?? null,
    'type'        => $_GET['type'] ?? null,
    'priority'    => $_GET['priority'] ?? null,
    'search'      => trim($_GET['search'] ?? ''),
    'page'        => (int) ($_GET['page'] ?? 1),
    'limit'       => (int) ($_GET['limit'] ?? 20),
    'since_id'    => (int) ($_GET['since_id'] ?? 0) ?: null,
];

if ($filters['search'] === '') {
    unset($filters['search']);
}

try {
    $result = NotificationManager::list($pdo, $userId, $filters);
    Api::success([
        'notifications' => $result['items'],
        'pagination'    => [
            'total'       => $result['total'],
            'page'        => $result['page'],
            'limit'       => $result['limit'],
            'total_pages' => $result['total_pages'],
        ],
        'unread_count'  => NotificationManager::getUnreadCount($pdo, $userId),
    ]);
} catch (Exception $e) {
    Api::error('Could not load notifications.', 500);
}
