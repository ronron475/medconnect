<?php
/**
 * API: Recent message conversations for quick panel
 * GET /app/api/messages/conversations.php
 *
 * One conversation per patient–provider pair (reuses consultations + consultation_messages).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/message_deletion.php';

Api::startJson();
messages_api_require_auth($pdo);

$userId = (int) $_SESSION['user_id'];
$role = (string) ($_SESSION['user_role'] ?? '');
$limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
$box = strtolower(trim((string) ($_GET['box'] ?? 'inbox'))); // inbox|archived|all
if (!in_array($box, ['inbox', 'archived', 'all'], true)) {
    $box = 'inbox';
}

try {
    $rows = message_list_pair_conversations($pdo, $userId, $role, $box, $limit);

    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'consultation_id' => (int) ($row['consultation_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'initials' => (string) ($row['initials'] ?? 'MC'),
            'preview' => (string) ($row['preview'] ?? ''),
            'last_at' => (string) ($row['last_at'] ?? ''),
            'unread' => (int) ($row['unread'] ?? 0),
            'is_archived' => (int) ($row['is_archived'] ?? 0),
        ];
    }

    Api::success([
        'items' => $items,
        'unread_count' => message_unread_count($pdo, $userId),
    ]);
} catch (Exception $e) {
    Api::error('Could not load conversations.', 500);
}
