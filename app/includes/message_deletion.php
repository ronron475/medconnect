<?php
/**
 * Consultation message deletion — schema, permissions, and formatting.
 */

const MESSAGE_DELETED_FOR_EVERYONE_TEXT = 'This message was deleted.';

/**
 * Ensure consultation_messages has deletion columns and the realtime events table exists.
 */
function consultation_messages_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS consultation_messages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            consultation_id INT UNSIGNED NOT NULL,
            sender_id INT UNSIGNED NOT NULL,
            receiver_id INT UNSIGNED NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_consultation_created (consultation_id, created_at),
            KEY idx_receiver_read (receiver_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM consultation_messages') as $col) {
        $columns[$col['Field']] = true;
    }

    $alters = [];
    if (!isset($columns['read_at'])) {
        $alters[] = 'ADD COLUMN read_at DATETIME NULL DEFAULT NULL AFTER is_read';
    }
    if (!isset($columns['is_deleted_for_everyone'])) {
        $alters[] = 'ADD COLUMN is_deleted_for_everyone TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read';
    }
    if (!isset($columns['deleted_at'])) {
        $alters[] = 'ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER is_deleted_for_everyone';
    }
    if (!isset($columns['deleted_by_user_id'])) {
        $alters[] = 'ADD COLUMN deleted_by_user_id INT UNSIGNED NULL DEFAULT NULL AFTER deleted_at';
    }
    if (!isset($columns['deleted_for_me_users'])) {
        $alters[] = 'ADD COLUMN deleted_for_me_users JSON NULL DEFAULT NULL AFTER deleted_by_user_id';
    }
    if (!isset($columns['message_original'])) {
        $alters[] = 'ADD COLUMN message_original TEXT NULL DEFAULT NULL COMMENT \'Audit-only original body after delete-for-everyone\' AFTER deleted_for_me_users';
    }
    if (!isset($columns['message_kind'])) {
        $alters[] = "ADD COLUMN message_kind VARCHAR(32) NOT NULL DEFAULT 'chat' COMMENT 'chat|mute_tts' AFTER message";
    }

    if ($alters) {
        $pdo->exec('ALTER TABLE consultation_messages ' . implode(', ', $alters));
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS message_chat_events (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            consultation_id INT UNSIGNED NOT NULL,
            message_id INT UNSIGNED NOT NULL,
            event_type ENUM('deleted_for_me', 'deleted_for_everyone') NOT NULL,
            actor_user_id INT UNSIGNED NOT NULL,
            payload JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_consultation_created (consultation_id, created_at),
            KEY idx_message (message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * Per-user consultation thread state (archive / soft delete / last read).
 */
function consultation_thread_state_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS consultation_thread_state (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            consultation_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            is_deleted  TINYINT(1) NOT NULL DEFAULT 0,
            last_read_message_id INT UNSIGNED NULL DEFAULT NULL,
            last_read_at DATETIME NULL DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_consult_user (consultation_id, user_id),
            KEY idx_user_archived (user_id, is_archived),
            KEY idx_user_deleted (user_id, is_deleted),
            KEY idx_user_updated (user_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * Upsert thread state for a user.
 *
 * @param array{is_archived?:int,is_deleted?:int,last_read_message_id?:int|null,last_read_at?:string|null} $patch
 */
function consultation_thread_state_upsert(PDO $pdo, int $consultationId, int $userId, array $patch): void
{
    consultation_thread_state_ensure_schema($pdo);

    $fields = [
        'is_archived' => array_key_exists('is_archived', $patch) ? (int) $patch['is_archived'] : null,
        'is_deleted' => array_key_exists('is_deleted', $patch) ? (int) $patch['is_deleted'] : null,
        'last_read_message_id' => array_key_exists('last_read_message_id', $patch) ? ($patch['last_read_message_id'] === null ? null : (int) $patch['last_read_message_id']) : null,
        'last_read_at' => array_key_exists('last_read_at', $patch) ? ($patch['last_read_at'] === null ? null : (string) $patch['last_read_at']) : null,
    ];

    // Insert defaults if missing, then update selected columns.
    $pdo->prepare("
        INSERT INTO consultation_thread_state (consultation_id, user_id, is_archived, is_deleted, last_read_message_id, last_read_at)
        VALUES (?, ?, 0, 0, NULL, NULL)
        ON DUPLICATE KEY UPDATE consultation_id = consultation_id
    ")->execute([$consultationId, $userId]);

    $set = [];
    $params = [];
    foreach ($fields as $col => $val) {
        if ($val === null) continue;
        $set[] = "{$col} = ?";
        $params[] = $val;
    }
    if (!$set) return;

    $params[] = $consultationId;
    $params[] = $userId;
    $pdo->prepare("UPDATE consultation_thread_state SET " . implode(', ', $set) . " WHERE consultation_id = ? AND user_id = ?")
        ->execute($params);
}

function consultation_thread_state_get(PDO $pdo, int $consultationId, int $userId): array
{
    consultation_thread_state_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM consultation_thread_state WHERE consultation_id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$consultationId, $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return int[]
 */
function message_deleted_for_me_user_ids(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    return array_values(array_unique(array_map('intval', $decoded)));
}

function message_is_hidden_for_user(array $row, int $userId): bool
{
    if (!empty($row['is_deleted_for_everyone'])) {
        return false;
    }
    return in_array($userId, message_deleted_for_me_user_ids($row['deleted_for_me_users'] ?? null), true);
}

function message_can_delete_for_everyone(array $row, int $userId): bool
{
    if (!empty($row['is_deleted_for_everyone'])) {
        return false;
    }
    return (int)$row['sender_id'] === $userId;
}

function message_can_delete_for_me(array $row, int $userId): bool
{
    if (message_is_hidden_for_user($row, $userId)) {
        return false;
    }
    return true;
}

/**
 * Format a DB row for API/UI. Returns null when hidden via delete-for-me.
 *
 * @return array<string, mixed>|null
 */
function message_format_for_viewer(array $row, int $viewerUserId): ?array
{
    if (message_is_hidden_for_user($row, $viewerUserId)) {
        return null;
    }

    $deletedForEveryone = !empty($row['is_deleted_for_everyone']);
    $senderName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

    $kind = (string) ($row['message_kind'] ?? 'chat');
    if ($kind !== 'mute_tts') {
        $kind = 'chat';
    }

    return [
        'id' => (int)$row['id'],
        'consultation_id' => (int)$row['consultation_id'],
        'sender_id' => (int)$row['sender_id'],
        'receiver_id' => (int)$row['receiver_id'],
        'sender_role' => $row['role'] ?? '',
        'sender_name' => $senderName,
        'message' => $deletedForEveryone ? MESSAGE_DELETED_FOR_EVERYONE_TEXT : (string)$row['message'],
        'message_kind' => $kind,
        'is_deleted_for_everyone' => $deletedForEveryone,
        'deleted_at' => $row['deleted_at'] ?? null,
        'created_at' => $row['created_at'],
        'time' => date('M j, g:i A', strtotime($row['created_at'])),
        'can_delete_for_everyone' => message_can_delete_for_everyone($row, $viewerUserId),
        'can_delete_for_me' => message_can_delete_for_me($row, $viewerUserId),
    ];
}

/**
 * @return array{success:bool,message:string,data?:array}
 */
function message_assert_participant(PDO $pdo, int $consultationId, int $userId): array
{
    $stmt = $pdo->prepare('
        SELECT id, patient_id, provider_id
        FROM consultations
        WHERE id = ? AND (patient_id = ? OR provider_id = ?)
        LIMIT 1
    ');
    $stmt->execute([$consultationId, $userId, $userId]);
    $consultation = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$consultation) {
        // Security log (no PHI): unauthorized conversation access attempt
        $logPath = BASE_PATH . '/app/includes/security_log.php';
        if (is_file($logPath)) {
            require_once $logPath;
            if (function_exists('security_log_event')) {
                security_log_event('messages_access_denied', [
                    'consultation_id' => $consultationId,
                ]);
            }
        }
        return ['success' => false, 'message' => 'Access denied.'];
    }
    return ['success' => true, 'message' => 'ok', 'consultation' => $consultation];
}

/**
 * @return array{success:bool,message:string,http_code?:int,data?:array}
 */
function message_fetch_by_id(PDO $pdo, int $messageId): array
{
    $stmt = $pdo->prepare('
        SELECT cm.*, u.first_name, u.last_name, u.role
        FROM consultation_messages cm
        JOIN users u ON u.id = cm.sender_id
        WHERE cm.id = ?
        LIMIT 1
    ');
    $stmt->execute([$messageId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['success' => false, 'message' => 'Message not found.', 'http_code' => 404];
    }
    return ['success' => true, 'message' => 'ok', 'row' => $row];
}

function message_record_chat_event(
    PDO $pdo,
    int $consultationId,
    int $messageId,
    string $eventType,
    int $actorUserId,
    ?array $payload = null
): void {
    $stmt = $pdo->prepare('
        INSERT INTO message_chat_events (consultation_id, message_id, event_type, actor_user_id, payload)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $consultationId,
        $messageId,
        $eventType,
        $actorUserId,
        $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
    ]);
}

function message_log_deletion_audit(PDO $pdo, int $actorUserId, string $actionType, string $description, array $meta): void
{
    $auditPath = BASE_PATH . '/app/includes/audit_log.php';
    if (!is_file($auditPath)) {
        return;
    }
    require_once $auditPath;
    audit_log($pdo, [
        'patient_id' => $actorUserId,
        'action_type' => $actionType,
        'description' => $description,
        'meta' => $meta,
    ]);
}

/**
 * @return array{success:bool,message:string,http_code?:int,data?:array}
 */
function message_delete_for_me(PDO $pdo, int $messageId, int $userId): array
{
    consultation_messages_ensure_schema($pdo);

    $fetched = message_fetch_by_id($pdo, $messageId);
    if (!$fetched['success']) {
        return $fetched;
    }
    $row = $fetched['row'];

    $access = message_assert_participant($pdo, (int)$row['consultation_id'], $userId);
    if (!$access['success']) {
        return ['success' => false, 'message' => $access['message'], 'http_code' => 403];
    }

    if (!message_can_delete_for_me($row, $userId)) {
        return ['success' => false, 'message' => 'This message cannot be deleted for you.', 'http_code' => 403];
    }

    $hiddenFor = message_deleted_for_me_user_ids($row['deleted_for_me_users'] ?? null);
    if (!in_array($userId, $hiddenFor, true)) {
        $hiddenFor[] = $userId;
        $stmt = $pdo->prepare('UPDATE consultation_messages SET deleted_for_me_users = ? WHERE id = ?');
        $stmt->execute([json_encode($hiddenFor), $messageId]);
    }

    message_record_chat_event($pdo, (int)$row['consultation_id'], $messageId, 'deleted_for_me', $userId, [
        'hidden_for_user_id' => $userId,
    ]);

    message_log_deletion_audit($pdo, $userId, 'message_deleted_for_me', 'User deleted a consultation message for themselves only.', [
        'message_id' => $messageId,
        'consultation_id' => (int)$row['consultation_id'],
        'actor_user_id' => $userId,
    ]);

    return [
        'success' => true,
        'message' => 'Message deleted for you.',
        'data' => [
            'message_id' => $messageId,
            'consultation_id' => (int)$row['consultation_id'],
            'event_type' => 'deleted_for_me',
            'hidden_for_user_id' => $userId,
        ],
    ];
}

/**
 * @return array{success:bool,message:string,http_code?:int,data?:array}
 */
function message_delete_for_everyone(PDO $pdo, int $messageId, int $userId): array
{
    consultation_messages_ensure_schema($pdo);

    $fetched = message_fetch_by_id($pdo, $messageId);
    if (!$fetched['success']) {
        return $fetched;
    }
    $row = $fetched['row'];

    $access = message_assert_participant($pdo, (int)$row['consultation_id'], $userId);
    if (!$access['success']) {
        return ['success' => false, 'message' => $access['message'], 'http_code' => 403];
    }

    if (!message_can_delete_for_everyone($row, $userId)) {
        return ['success' => false, 'message' => 'Only the sender can delete this message for everyone.', 'http_code' => 403];
    }

    if (!empty($row['is_deleted_for_everyone'])) {
        return ['success' => false, 'message' => 'Message is already deleted for everyone.', 'http_code' => 409];
    }

    $original = (string)$row['message'];
    $stmt = $pdo->prepare('
        UPDATE consultation_messages
        SET is_deleted_for_everyone = 1,
            deleted_at = NOW(),
            deleted_by_user_id = ?,
            message_original = ?,
            message = ?
        WHERE id = ?
    ');
    $stmt->execute([$userId, $original, MESSAGE_DELETED_FOR_EVERYONE_TEXT, $messageId]);

    message_record_chat_event($pdo, (int)$row['consultation_id'], $messageId, 'deleted_for_everyone', $userId, [
        'deleted_at' => date('Y-m-d H:i:s'),
    ]);

    message_log_deletion_audit($pdo, $userId, 'message_deleted_for_everyone', 'User deleted a consultation message for everyone.', [
        'message_id' => $messageId,
        'consultation_id' => (int)$row['consultation_id'],
        'actor_user_id' => $userId,
        'sender_id' => (int)$row['sender_id'],
    ]);

    $formatted = message_format_for_viewer(array_merge($row, [
        'is_deleted_for_everyone' => 1,
        'message' => MESSAGE_DELETED_FOR_EVERYONE_TEXT,
        'deleted_at' => date('Y-m-d H:i:s'),
    ]), $userId);

    return [
        'success' => true,
        'message' => 'Message deleted for everyone.',
        'data' => [
            'message_id' => $messageId,
            'consultation_id' => (int)$row['consultation_id'],
            'event_type' => 'deleted_for_everyone',
            'message' => $formatted,
        ],
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function message_fetch_consultation_messages(PDO $pdo, int $consultationId, int $viewerUserId): array
{
    consultation_messages_ensure_schema($pdo);

    $stmt = $pdo->prepare('
        SELECT cm.*, u.first_name, u.last_name, u.role
        FROM consultation_messages cm
        JOIN users u ON u.id = cm.sender_id
        WHERE cm.consultation_id = ?
        ORDER BY cm.created_at ASC, cm.id ASC
    ');
    $stmt->execute([$consultationId]);

    $messages = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $formatted = message_format_for_viewer($row, $viewerUserId);
        if ($formatted !== null) {
            $messages[] = $formatted;
        }
    }

    return $messages;
}

/**
 * Total unread messages for a user (excludes delete-for-me hidden rows).
 */
function message_unread_count(PDO $pdo, int $userId): int
{
    consultation_messages_ensure_schema($pdo);
    consultation_thread_state_ensure_schema($pdo);

    $stmt = $pdo->prepare('
        SELECT cm.consultation_id, cm.is_deleted_for_everyone, cm.deleted_for_me_users
        FROM consultation_messages cm
        LEFT JOIN consultation_thread_state ts
          ON ts.consultation_id = cm.consultation_id AND ts.user_id = ?
        WHERE cm.receiver_id = ?
          AND cm.is_read = 0
          AND cm.is_deleted_for_everyone = 0
          AND (ts.is_deleted IS NULL OR ts.is_deleted = 0)
    ');
    $stmt->execute([$userId, $userId]);

    $count = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!message_is_hidden_for_user($row, $userId)) {
            $count++;
        }
    }

    return $count;
}

/**
 * Latest unread message timestamp for polling clients.
 */
function message_latest_unread_at(PDO $pdo, int $userId): ?string
{
    consultation_messages_ensure_schema($pdo);
    consultation_thread_state_ensure_schema($pdo);

    $stmt = $pdo->prepare('
        SELECT cm.created_at, cm.is_deleted_for_everyone, cm.deleted_for_me_users
        FROM consultation_messages cm
        LEFT JOIN consultation_thread_state ts
          ON ts.consultation_id = cm.consultation_id AND ts.user_id = ?
        WHERE cm.receiver_id = ?
          AND cm.is_read = 0
          AND cm.is_deleted_for_everyone = 0
          AND (ts.is_deleted IS NULL OR ts.is_deleted = 0)
        ORDER BY cm.created_at DESC, cm.id DESC
        LIMIT 80
    ');
    $stmt->execute([$userId, $userId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!message_is_hidden_for_user($row, $userId)) {
            return (string) ($row['created_at'] ?? '');
        }
    }

    return null;
}

/**
 * Mark all unread messages in a consultation as read for the viewer.
 */
function message_mark_consultation_read(PDO $pdo, int $consultationId, int $viewerUserId): int
{
    consultation_messages_ensure_schema($pdo);
    consultation_thread_state_ensure_schema($pdo);

    $access = message_assert_participant($pdo, $consultationId, $viewerUserId);
    if (!$access['success']) {
        return 0;
    }

    $stmt = $pdo->prepare('
        UPDATE consultation_messages
        SET is_read = 1,
            read_at = COALESCE(read_at, NOW())
        WHERE consultation_id = ?
          AND receiver_id = ?
          AND is_read = 0
          AND is_deleted_for_everyone = 0
    ');
    $stmt->execute([$consultationId, $viewerUserId]);

    $changed = (int) $stmt->rowCount();

    // Track last read position for "Read" receipts & ordering.
    $maxStmt = $pdo->prepare('
        SELECT MAX(id) AS max_id
        FROM consultation_messages
        WHERE consultation_id = ? AND receiver_id = ? AND is_read = 1
    ');
    $maxStmt->execute([$consultationId, $viewerUserId]);
    $maxId = (int) ($maxStmt->fetchColumn() ?: 0);

    consultation_thread_state_upsert($pdo, $consultationId, $viewerUserId, [
        'last_read_message_id' => $maxId > 0 ? $maxId : null,
        'last_read_at' => date('Y-m-d H:i:s'),
    ]);

    return $changed;
}

/**
 * Patient–provider pair for a consultation the viewer participates in.
 *
 * @return array{success:bool,message:string,patient_id?:int,provider_id?:int,consultation?:array}
 */
function message_resolve_pair(PDO $pdo, int $consultationId, int $userId): array
{
    $access = message_assert_participant($pdo, $consultationId, $userId);
    if (!$access['success']) {
        return $access;
    }
    $c = $access['consultation'];
    $patientId = (int) ($c['patient_id'] ?? 0);
    $providerId = (int) ($c['provider_id'] ?? 0);
    if ($patientId <= 0 || $providerId <= 0) {
        return ['success' => false, 'message' => 'Conversation pair is incomplete.'];
    }
    return [
        'success' => true,
        'message' => 'ok',
        'patient_id' => $patientId,
        'provider_id' => $providerId,
        'consultation' => $c,
    ];
}

/**
 * All consultation IDs for a patient–provider pair (existing rows only; never creates).
 *
 * @return int[]
 */
function message_pair_consultation_ids(PDO $pdo, int $patientId, int $providerId): array
{
    if ($patientId <= 0 || $providerId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('
        SELECT id
        FROM consultations
        WHERE patient_id = ? AND provider_id = ?
        ORDER BY id ASC
    ');
    $stmt->execute([$patientId, $providerId]);
    return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
}

/**
 * Canonical consultation for messaging within a pair (reuses existing rows only).
 * Prefers active clinical statuses, then the consultation with the latest message, then newest id.
 */
function message_pair_canonical_consultation_id(PDO $pdo, int $patientId, int $providerId): int
{
    $ids = message_pair_consultation_ids($pdo, $patientId, $providerId);
    if (!$ids) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT id, status
        FROM consultations
        WHERE id IN ($placeholders)
        ORDER BY
            CASE LOWER(TRIM(COALESCE(status, '')))
                WHEN 'in_consultation' THEN 1
                WHEN 'scheduled' THEN 2
                WHEN 'pending' THEN 3
                ELSE 4
            END,
            COALESCE(consult_date, '1970-01-01') DESC,
            COALESCE(consult_time, '00:00:00') DESC,
            id DESC
        LIMIT 1
    ");
    $stmt->execute($ids);
    $preferred = (int) ($stmt->fetchColumn() ?: 0);

    $msgStmt = $pdo->prepare("
        SELECT consultation_id
        FROM consultation_messages
        WHERE consultation_id IN ($placeholders)
          AND is_deleted_for_everyone = 0
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");
    $msgStmt->execute($ids);
    $withMessages = (int) ($msgStmt->fetchColumn() ?: 0);

    // Prefer an open consult when available; otherwise stay on the thread that already has history.
    if ($preferred > 0) {
        $st = $pdo->prepare('SELECT status FROM consultations WHERE id = ? LIMIT 1');
        $st->execute([$preferred]);
        $status = strtolower(trim((string) ($st->fetchColumn() ?: '')));
        if (in_array($status, ['in_consultation', 'scheduled', 'pending'], true)) {
            return $preferred;
        }
    }

    return $withMessages > 0 ? $withMessages : ($preferred > 0 ? $preferred : (int) max($ids));
}

/**
 * Whether the viewer has soft-deleted the entire patient–provider conversation.
 */
function message_pair_is_deleted_for_user(PDO $pdo, int $patientId, int $providerId, int $userId): bool
{
    $ids = message_pair_consultation_ids($pdo, $patientId, $providerId);
    if (!$ids) {
        return false;
    }
    consultation_thread_state_ensure_schema($pdo);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$userId], $ids);
    $stmt = $pdo->prepare("
        SELECT c.id, COALESCE(ts.is_deleted, 0) AS is_deleted
        FROM consultations c
        LEFT JOIN consultation_thread_state ts
          ON ts.consultation_id = c.id AND ts.user_id = ?
        WHERE c.id IN ($placeholders)
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
        return false;
    }
    foreach ($rows as $row) {
        if ((int) ($row['is_deleted'] ?? 0) === 0) {
            return false;
        }
    }
    return true;
}

/**
 * Soft-undelete a pair conversation for one user (does not remove message rows).
 */
function message_pair_clear_deleted_for_user(PDO $pdo, int $patientId, int $providerId, int $userId): void
{
    foreach (message_pair_consultation_ids($pdo, $patientId, $providerId) as $cid) {
        consultation_thread_state_upsert($pdo, $cid, $userId, ['is_deleted' => 0]);
    }
}

/**
 * Apply archive/restore/delete/undelete across every consultation in the pair for one user.
 *
 * @return array{consultation_id:int,is_archived:int,is_deleted:int,pair_ids:int[]}
 */
function message_pair_thread_state_action(PDO $pdo, int $consultationId, int $userId, string $action): array
{
    $pair = message_resolve_pair($pdo, $consultationId, $userId);
    if (!$pair['success']) {
        throw new RuntimeException($pair['message'] ?? 'Access denied.');
    }

    $patientId = (int) $pair['patient_id'];
    $providerId = (int) $pair['provider_id'];
    $ids = message_pair_consultation_ids($pdo, $patientId, $providerId);
    if (!$ids) {
        $ids = [$consultationId];
    }

    $patch = [];
    if ($action === 'archive') {
        $patch = ['is_archived' => 1];
    } elseif ($action === 'restore') {
        $patch = ['is_archived' => 0];
    } elseif ($action === 'delete') {
        $patch = ['is_deleted' => 1];
    } elseif ($action === 'undelete') {
        $patch = ['is_deleted' => 0];
    } else {
        throw new InvalidArgumentException('Invalid thread action.');
    }

    foreach ($ids as $cid) {
        consultation_thread_state_upsert($pdo, $cid, $userId, $patch);
    }

    $canonical = message_pair_canonical_consultation_id($pdo, $patientId, $providerId) ?: $consultationId;
    $state = consultation_thread_state_get($pdo, $canonical, $userId);

    return [
        'consultation_id' => $canonical,
        'is_archived' => (int) ($state['is_archived'] ?? ($patch['is_archived'] ?? 0)),
        'is_deleted' => (int) ($state['is_deleted'] ?? ($patch['is_deleted'] ?? 0)),
        'pair_ids' => $ids,
    ];
}

/**
 * Merged message history for a patient–provider pair (preserves ids, times, senders).
 *
 * @return array<int, array<string, mixed>>
 */
function message_fetch_pair_messages(PDO $pdo, int $consultationId, int $viewerUserId): array
{
    consultation_messages_ensure_schema($pdo);
    $pair = message_resolve_pair($pdo, $consultationId, $viewerUserId);
    if (!$pair['success']) {
        return [];
    }

    $ids = message_pair_consultation_ids($pdo, (int) $pair['patient_id'], (int) $pair['provider_id']);
    if (!$ids) {
        return message_fetch_consultation_messages($pdo, $consultationId, $viewerUserId);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT cm.*, u.first_name, u.last_name, u.role
        FROM consultation_messages cm
        JOIN users u ON u.id = cm.sender_id
        WHERE cm.consultation_id IN ($placeholders)
        ORDER BY cm.created_at ASC, cm.id ASC
    ");
    $stmt->execute($ids);

    $messages = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $formatted = message_format_for_viewer($row, $viewerUserId);
        if ($formatted !== null) {
            $messages[] = $formatted;
        }
    }
    return $messages;
}

/**
 * Mark unread messages as read across the whole patient–provider pair.
 */
function message_mark_pair_read(PDO $pdo, int $consultationId, int $viewerUserId): int
{
    consultation_messages_ensure_schema($pdo);
    consultation_thread_state_ensure_schema($pdo);

    $pair = message_resolve_pair($pdo, $consultationId, $viewerUserId);
    if (!$pair['success']) {
        return 0;
    }

    $ids = message_pair_consultation_ids($pdo, (int) $pair['patient_id'], (int) $pair['provider_id']);
    if (!$ids) {
        return message_mark_consultation_read($pdo, $consultationId, $viewerUserId);
    }

    $changed = 0;
    foreach ($ids as $cid) {
        $changed += message_mark_consultation_read($pdo, $cid, $viewerUserId);
    }
    return $changed;
}

/**
 * Conversation list grouped by patient–provider pair (one row per relationship).
 *
 * @return array<int, array<string, mixed>>
 */
function message_list_pair_conversations(PDO $pdo, int $userId, string $role, string $box = 'inbox', int $limit = 50): array
{
    consultation_messages_ensure_schema($pdo);
    consultation_thread_state_ensure_schema($pdo);

    $box = strtolower(trim($box));
    if (!in_array($box, ['inbox', 'archived', 'all'], true)) {
        $box = 'inbox';
    }
    $limit = max(1, min(100, $limit));

    if ($role === 'provider') {
        $stmt = $pdo->prepare("
            SELECT
                c.id AS consultation_id,
                c.patient_id,
                c.provider_id,
                c.consult_type,
                c.status,
                c.consult_date,
                c.consult_time,
                c.created_at,
                CONCAT(u.first_name, ' ', u.last_name) AS name,
                CONCAT(UPPER(LEFT(u.first_name,1)), UPPER(LEFT(u.last_name,1))) AS initials,
                COALESCE(ts.is_archived, 0) AS is_archived,
                COALESCE(ts.is_deleted, 0) AS is_deleted
            FROM consultations c
            JOIN users u ON u.id = c.patient_id
            LEFT JOIN consultation_thread_state ts
              ON ts.consultation_id = c.id AND ts.user_id = ?
            WHERE c.provider_id = ?
              AND c.patient_id IS NOT NULL
              AND c.patient_id > 0
            ORDER BY c.id DESC
        ");
        $stmt->execute([$userId, $userId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT
                c.id AS consultation_id,
                c.patient_id,
                c.provider_id,
                c.consult_type,
                c.status,
                c.consult_date,
                c.consult_time,
                c.created_at,
                c.provider_name,
                CONCAT('Dr. ', COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ''), NULLIF(c.provider_name,''), 'Healthcare Provider')) AS name,
                CONCAT(UPPER(LEFT(COALESCE(u.first_name,'D'),1)), UPPER(LEFT(COALESCE(u.last_name,''),1))) AS initials,
                COALESCE(ts.is_archived, 0) AS is_archived,
                COALESCE(ts.is_deleted, 0) AS is_deleted
            FROM consultations c
            LEFT JOIN users u ON u.id = c.provider_id
            LEFT JOIN consultation_thread_state ts
              ON ts.consultation_id = c.id AND ts.user_id = ?
            WHERE c.patient_id = ?
              AND c.provider_id IS NOT NULL
              AND c.provider_id > 0
            ORDER BY c.id DESC
        ");
        $stmt->execute([$userId, $userId]);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $groups = [];
    foreach ($rows as $row) {
        $patientId = (int) ($row['patient_id'] ?? 0);
        $providerId = (int) ($row['provider_id'] ?? 0);
        if ($patientId <= 0 || $providerId <= 0) {
            continue;
        }
        $key = $patientId . ':' . $providerId;
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'patient_id' => $patientId,
                'provider_id' => $providerId,
                'name' => (string) ($row['name'] ?? ''),
                'initials' => (string) ($row['initials'] ?? 'MC'),
                'rows' => [],
            ];
        }
        $groups[$key]['rows'][] = $row;
        if ($groups[$key]['name'] === '' && !empty($row['name'])) {
            $groups[$key]['name'] = (string) $row['name'];
        }
    }

    $items = [];
    foreach ($groups as $group) {
        $pairRows = $group['rows'];
        $ids = array_map(static fn($r) => (int) $r['consultation_id'], $pairRows);
        if (!$ids) {
            continue;
        }

        $allDeleted = true;
        $allArchived = true;
        foreach ($pairRows as $r) {
            if ((int) ($r['is_deleted'] ?? 0) === 0) {
                $allDeleted = false;
            }
            if ((int) ($r['is_archived'] ?? 0) === 0) {
                $allArchived = false;
            }
        }
        if ($allDeleted) {
            continue;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $lastStmt = $pdo->prepare("
            SELECT message, created_at, consultation_id
            FROM consultation_messages
            WHERE consultation_id IN ($placeholders)
              AND is_deleted_for_everyone = 0
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $lastStmt->execute($ids);
        $last = $lastStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $unreadStmt = $pdo->prepare("
            SELECT id, is_deleted_for_everyone, deleted_for_me_users
            FROM consultation_messages
            WHERE consultation_id IN ($placeholders)
              AND receiver_id = ?
              AND is_read = 0
              AND is_deleted_for_everyone = 0
        ");
        $unreadStmt->execute(array_merge($ids, [$userId]));
        $unread = 0;
        foreach ($unreadStmt->fetchAll(PDO::FETCH_ASSOC) as $urow) {
            if (!message_is_hidden_for_user($urow, $userId)) {
                $unread++;
            }
        }

        if ($box === 'inbox' && $allArchived && $unread <= 0) {
            continue;
        }
        if ($box === 'archived' && !$allArchived) {
            continue;
        }

        $canonical = message_pair_canonical_consultation_id($pdo, (int) $group['patient_id'], (int) $group['provider_id']);
        if ($canonical <= 0) {
            $canonical = (int) $ids[0];
        }

        $fallbackPreview = '';
        $fallbackAt = '';
        foreach ($pairRows as $r) {
            if ((int) $r['consultation_id'] === $canonical) {
                $fallbackPreview = (string) ($r['consult_type'] ?? 'Consultation');
                $fallbackAt = trim(($r['consult_date'] ?? '') . ' ' . ($r['consult_time'] ?? ''));
                if ($fallbackAt === '') {
                    $fallbackAt = (string) ($r['created_at'] ?? '');
                }
                break;
            }
        }
        if ($fallbackPreview === '' && $pairRows) {
            $fallbackPreview = (string) ($pairRows[0]['consult_type'] ?? 'Consultation');
            $fallbackAt = (string) ($pairRows[0]['created_at'] ?? '');
        }

        $items[] = [
            'consultation_id' => $canonical,
            'patient_id' => (int) $group['patient_id'],
            'provider_id' => (int) $group['provider_id'],
            'name' => (string) $group['name'],
            'initials' => (string) ($group['initials'] ?: 'MC'),
            'preview' => $last ? (string) $last['message'] : $fallbackPreview,
            'last_at' => $last ? (string) $last['created_at'] : $fallbackAt,
            'unread' => $unread,
            'is_archived' => $allArchived ? 1 : 0,
            'pair_consultation_ids' => $ids,
        ];
    }

    usort($items, static function (array $a, array $b): int {
        $ta = strtotime((string) ($a['last_at'] ?? '')) ?: 0;
        $tb = strtotime((string) ($b['last_at'] ?? '')) ?: 0;
        if ($ta === $tb) {
            return ((int) $b['consultation_id']) <=> ((int) $a['consultation_id']);
        }
        return $tb <=> $ta;
    });

    return array_slice($items, 0, $limit);
}

/**
 * Require authenticated patient or provider; block patients who must complete account setup.
 */
function messages_api_require_auth(PDO $pdo): void
{
    if (empty($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['provider', 'patient'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    if (($_SESSION['user_role'] ?? '') === 'patient') {
        require_once __DIR__ . '/patient_settings.php';
        patient_settings_require_patient_ready($pdo);
    }
}
