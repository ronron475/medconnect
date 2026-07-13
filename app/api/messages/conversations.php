<?php
/**
 * API: Recent message conversations for quick panel
 * GET /app/api/messages/conversations.php
 *
 * Uses existing consultations + consultation_messages tables.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/message_deletion.php';

Api::startJson();
messages_api_require_auth($pdo);

$userId = (int) $_SESSION['user_id'];
$limit = max(1, min(25, (int) ($_GET['limit'] ?? 15)));
$box = strtolower(trim((string) ($_GET['box'] ?? 'inbox'))); // inbox|archived|all
if (!in_array($box, ['inbox', 'archived', 'all'], true)) {
    $box = 'inbox';
}

try {
    consultation_messages_ensure_schema($pdo);
    consultation_thread_state_ensure_schema($pdo);

    if ($role === 'provider') {
        $stmt = $pdo->prepare("
            SELECT
                c.id AS consultation_id,
                CONCAT(u.first_name, ' ', u.last_name) AS name,
                CONCAT(UPPER(LEFT(u.first_name,1)), UPPER(LEFT(u.last_name,1))) AS initials,
                COALESCE(last_msg.message, c.consult_type) AS preview,
                COALESCE(last_msg.created_at, CONCAT(c.consult_date,' ',c.consult_time), c.created_at) AS last_at,
                COALESCE(unread.cnt, 0) AS unread,
                COALESCE(ts.is_archived, 0) AS is_archived,
                COALESCE(ts.is_deleted, 0) AS is_deleted
            FROM consultations c
            JOIN users u ON u.id = c.patient_id
            LEFT JOIN consultation_thread_state ts
              ON ts.consultation_id = c.id AND ts.user_id = ?
            LEFT JOIN (
                SELECT cm1.consultation_id, cm1.message, cm1.created_at
                FROM consultation_messages cm1
                WHERE cm1.id = (
                    SELECT MAX(cm2.id)
                    FROM consultation_messages cm2
                    WHERE cm2.consultation_id = cm1.consultation_id
                )
            ) last_msg ON last_msg.consultation_id = c.id
            LEFT JOIN (
                SELECT consultation_id, COUNT(*) AS cnt
                FROM consultation_messages
                WHERE receiver_id = ? AND is_read = 0
                GROUP BY consultation_id
            ) unread ON unread.consultation_id = c.id
            WHERE c.provider_id = ?
              AND (ts.is_deleted IS NULL OR ts.is_deleted = 0)
            ORDER BY COALESCE(last_msg.created_at, c.created_at) DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$userId, $userId, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $stmt = $pdo->prepare("
            SELECT
                c.id AS consultation_id,
                CONCAT('Dr. ', COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ''), NULLIF(c.provider_name,''), 'Healthcare Provider')) AS name,
                CONCAT(UPPER(LEFT(COALESCE(u.first_name,'D'),1)), UPPER(LEFT(COALESCE(u.last_name,''),1))) AS initials,
                COALESCE(last_msg.message, c.consult_type) AS preview,
                COALESCE(last_msg.created_at, CONCAT(c.consult_date,' ',c.consult_time), c.created_at) AS last_at,
                COALESCE(unread.cnt, 0) AS unread,
                COALESCE(ts.is_archived, 0) AS is_archived,
                COALESCE(ts.is_deleted, 0) AS is_deleted
            FROM consultations c
            LEFT JOIN users u ON u.id = c.provider_id
            LEFT JOIN consultation_thread_state ts
              ON ts.consultation_id = c.id AND ts.user_id = ?
            LEFT JOIN (
                SELECT cm1.consultation_id, cm1.message, cm1.created_at
                FROM consultation_messages cm1
                WHERE cm1.id = (
                    SELECT MAX(cm2.id)
                    FROM consultation_messages cm2
                    WHERE cm2.consultation_id = cm1.consultation_id
                )
            ) last_msg ON last_msg.consultation_id = c.id
            LEFT JOIN (
                SELECT consultation_id, COUNT(*) AS cnt
                FROM consultation_messages
                WHERE receiver_id = ? AND is_read = 0
                GROUP BY consultation_id
            ) unread ON unread.consultation_id = c.id
            WHERE c.patient_id = ?
              AND (ts.is_deleted IS NULL OR ts.is_deleted = 0)
            ORDER BY COALESCE(last_msg.created_at, c.created_at) DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$userId, $userId, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $items = [];
    foreach ($rows as $row) {
        $archived = (int) ($row['is_archived'] ?? 0);
        if ($box === 'inbox' && $archived) continue;
        if ($box === 'archived' && !$archived) continue;
        $items[] = [
            'consultation_id' => (int) ($row['consultation_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'initials' => (string) ($row['initials'] ?? 'MC'),
            'preview' => (string) ($row['preview'] ?? ''),
            'last_at' => (string) ($row['last_at'] ?? ''),
            'unread' => (int) ($row['unread'] ?? 0),
            'is_archived' => $archived,
        ];
    }

    Api::success([
        'items' => $items,
        'unread_count' => message_unread_count($pdo, $userId),
    ]);
} catch (Exception $e) {
    Api::error('Could not load conversations.', 500);
}

