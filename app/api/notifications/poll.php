<?php
/**
 * API: Poll for new notifications (real-time sync)
 * GET /app/api/notifications/poll.php?since_id=N
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/NotificationManager.php';

Api::startJson();
Api::requireAuth();

$userId  = (int) $_SESSION['user_id'];
$sinceId = (int) ($_GET['since_id'] ?? 0);

try {
    $filters = ['limit' => 20, 'since_id' => $sinceId > 0 ? $sinceId : null];
    if ($sinceId > 0) {
        unset($filters['status']);
        NotificationManager::ensureSchema($pdo);
        $stmt = $pdo->prepare("
            SELECT * FROM notifications
            WHERE user_id = ? AND id > ? AND status != 'deleted'
              AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY id ASC LIMIT 20
        ");
        $stmt->execute([$userId, $sinceId]);
        $items = array_map([NotificationManager::class, 'formatRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $lastId = $sinceId;
        foreach ($items as $item) {
            $lastId = max($lastId, $item['notification_id']);
        }
    } else {
        $result = NotificationManager::list($pdo, $userId, ['limit' => 10, 'unread_only' => true]);
        $items = $result['items'];
        $lastId = 0;
        foreach ($items as $item) {
            $lastId = max($lastId, $item['notification_id']);
        }
    }

    Api::success([
        'notifications' => $items,
        'unread_count'  => NotificationManager::getUnreadCount($pdo, $userId),
        'last_id'       => $lastId,
    ]);
} catch (Exception $e) {
    Api::error('Poll failed.', 500);
}
