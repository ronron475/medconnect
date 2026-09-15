<?php
/**
 * Admin/SuperAdmin Referral Center unread helpers (per-user notifications).
 * Does not change clinical referral records.
 */

require_once __DIR__ . '/../core/NotificationManager.php';

/**
 * Unread Referral Center count for one user: distinct existing referral IDs only.
 */
function referrals_admin_unread_count(PDO $pdo, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    NotificationManager::ensureSchema($pdo);
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'digital_referrals'")->rowCount()) {
            return 0;
        }
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT n.related_id)
            FROM notifications n
            INNER JOIN digital_referrals dr ON dr.id = n.related_id
            WHERE n.user_id = ?
              AND n.related_table = 'digital_referrals'
              AND n.related_id IS NOT NULL
              AND n.related_id > 0
              AND n.is_read = 0
              AND n.status = 'active'
              AND (n.expires_at IS NULL OR n.expires_at > NOW())
        ");
        $stmt->execute([$userId]);
        return max(0, (int) $stmt->fetchColumn());
    } catch (Throwable $e) {
        return NotificationManager::countUnreadRelated($pdo, $userId, 'digital_referrals');
    }
}

/**
 * @return list<int>
 */
function referrals_admin_unread_ids(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    NotificationManager::ensureSchema($pdo);
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'digital_referrals'")->rowCount()) {
            return [];
        }
        $stmt = $pdo->prepare("
            SELECT DISTINCT n.related_id
            FROM notifications n
            INNER JOIN digital_referrals dr ON dr.id = n.related_id
            WHERE n.user_id = ?
              AND n.related_table = 'digital_referrals'
              AND n.related_id IS NOT NULL
              AND n.related_id > 0
              AND n.is_read = 0
              AND n.status = 'active'
              AND (n.expires_at IS NULL OR n.expires_at > NOW())
        ");
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        return NotificationManager::listRelatedUnreadIds($pdo, $userId, 'digital_referrals');
    }
}

/**
 * Mark this user's referral inbox row(s) as read, then return authoritative unread count.
 * Idempotent for already-read referrals. Does not change clinical referral data.
 */
function referrals_mark_read_for_user(PDO $pdo, int $userId, int $referralId): int
{
    NotificationManager::ensureSchema($pdo);

    NotificationManager::markRelatedRead($pdo, $userId, 'digital_referrals', $referralId);

    $existsNotif = $pdo->prepare("
        SELECT id, is_read
        FROM notifications
        WHERE user_id = ?
          AND related_table = 'digital_referrals'
          AND related_id = ?
          AND status = 'active'
        ORDER BY id DESC
        LIMIT 1
    ");
    $existsNotif->execute([$userId, $referralId]);
    $existing = $existsNotif->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if ((int) ($existing['is_read'] ?? 0) === 0) {
            $pdo->prepare("
                UPDATE notifications
                SET is_read = 1, updated_at = NOW()
                WHERE id = ?
                  AND user_id = ?
            ")->execute([(int) $existing['id'], $userId]);
        }
        return referrals_admin_unread_count($pdo, $userId);
    }

    $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $roleStmt->execute([$userId]);
    $role = strtolower(trim((string) ($roleStmt->fetchColumn() ?: '')));
    $actionUrl = $role === 'superadmin'
        ? '/views/superadmin/facility_management.php?tab=referral'
        : '/views/admin/facility_management.php?tab=referral';

    $newId = NotificationManager::create($pdo, $userId, [
        'receiver_role' => $role,
        'type'          => NotificationManager::TYPE_REFERRAL,
        'title'         => 'Referral Issued',
        'message'       => 'A doctor has issued a patient referral. Open Referral Center to monitor the record.',
        'related_table' => 'digital_referrals',
        'related_id'    => $referralId,
        'action_url'    => $actionUrl,
        'icon'          => 'clipboard',
    ]);
    if ($newId) {
        NotificationManager::markRelatedRead($pdo, $userId, 'digital_referrals', $referralId);
    }

    return referrals_admin_unread_count($pdo, $userId);
}
