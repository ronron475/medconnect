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
