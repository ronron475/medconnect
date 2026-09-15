<?php
/**
 * BHW sidebar Records / Appointment Follow-ups unread helpers.
 * Reuses notifications.is_read — does not invent a second read-state system.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/NotificationManager.php';
require_once __DIR__ . '/bhw_scope.php';

/** related_table for BHW Records sidebar unread rows. */
const BHW_NAV_RELATED_RECORDS = 'bhw_records';

/** related_table for BHW Appointment Follow-ups sidebar unread rows. */
const BHW_NAV_RELATED_FOLLOWUPS = 'followups';

/**
 * Unread Records items for this BHW (barangay-scoped).
 * related_id may be a patient id or consultation id; both must resolve to a barangay patient.
 */
function bhw_nav_records_unread_count(PDO $pdo, int $bhwId, array $ctx): int
{
    if ($bhwId <= 0 || empty($ctx['allowed'])) {
        return 0;
    }
    NotificationManager::ensureSchema($pdo);
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'notifications'")->rowCount()) {
            return 0;
        }
        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $join = bhw_pr_user_join('pr', 'u');
        $hasConsultations = (bool) $pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount();
        $baseParams = array_merge([$bhwId, BHW_NAV_RELATED_RECORDS], $params);

        if ($hasConsultations) {
            // related_id is either a patient id or a consultation id — never double-count.
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM (
                    SELECT n.id
                    FROM notifications n
                    INNER JOIN users u ON u.id = n.related_id AND u.role = 'patient'
                    INNER JOIN patient_registrations pr ON {$join}
                    WHERE n.user_id = ?
                      AND n.related_table = ?
                      AND n.related_id IS NOT NULL
                      AND n.related_id > 0
                      AND n.is_read = 0
                      AND n.status = 'active'
                      AND (n.expires_at IS NULL OR n.expires_at > NOW())
                      AND NOT EXISTS (SELECT 1 FROM consultations cx WHERE cx.id = n.related_id)
                      AND {$clause}
                    UNION
                    SELECT n.id
                    FROM notifications n
                    INNER JOIN consultations c ON c.id = n.related_id
                    INNER JOIN users u ON u.id = c.patient_id AND u.role = 'patient'
                    INNER JOIN patient_registrations pr ON {$join}
                    WHERE n.user_id = ?
                      AND n.related_table = ?
                      AND n.related_id IS NOT NULL
                      AND n.related_id > 0
                      AND n.is_read = 0
                      AND n.status = 'active'
                      AND (n.expires_at IS NULL OR n.expires_at > NOW())
                      AND {$clause}
                ) unread_records
            ");
            $stmt->execute(array_merge($baseParams, $baseParams));
            return max(0, (int) $stmt->fetchColumn());
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT n.id)
            FROM notifications n
            INNER JOIN users u ON u.id = n.related_id AND u.role = 'patient'
            INNER JOIN patient_registrations pr ON {$join}
            WHERE n.user_id = ?
              AND n.related_table = ?
              AND n.related_id IS NOT NULL
              AND n.related_id > 0
              AND n.is_read = 0
              AND n.status = 'active'
              AND (n.expires_at IS NULL OR n.expires_at > NOW())
              AND {$clause}
        ");
        $stmt->execute($baseParams);
        return max(0, (int) $stmt->fetchColumn());
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Unread Appointment Follow-up items for this BHW (barangay-scoped, existing followups only).
 */
function bhw_nav_followups_unread_count(PDO $pdo, int $bhwId, array $ctx): int
{
    if ($bhwId <= 0 || empty($ctx['allowed'])) {
        return 0;
    }
    NotificationManager::ensureSchema($pdo);
    try {
        if (
            !$pdo->query("SHOW TABLES LIKE 'notifications'")->rowCount()
            || !$pdo->query("SHOW TABLES LIKE 'followups'")->rowCount()
        ) {
            return 0;
        }
        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $join = bhw_pr_user_join('pr', 'u');
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT n.related_id)
            FROM notifications n
            INNER JOIN followups f ON f.id = n.related_id
            INNER JOIN users u ON u.id = f.patient_id AND u.role = 'patient'
            INNER JOIN patient_registrations pr ON {$join}
            WHERE n.user_id = ?
              AND n.related_table = ?
              AND n.related_id IS NOT NULL
              AND n.related_id > 0
              AND n.is_read = 0
              AND n.status = 'active'
              AND (n.expires_at IS NULL OR n.expires_at > NOW())
              AND LOWER(COALESCE(f.status, '')) NOT IN ('completed', 'cancelled', 'canceled')
              AND {$clause}
        ");
        $stmt->execute(array_merge([$bhwId, BHW_NAV_RELATED_FOLLOWUPS], $params));
        return max(0, (int) $stmt->fetchColumn());
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Mark Records notifications for one patient as read for this BHW.
 */
function bhw_nav_mark_records_read(PDO $pdo, int $bhwId, int $patientId): int
{
    if ($bhwId <= 0 || $patientId <= 0) {
        return 0;
    }
    NotificationManager::ensureSchema($pdo);
    $updated = 0;
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1, updated_at = NOW()
            WHERE user_id = ?
              AND related_table = ?
              AND related_id = ?
              AND is_read = 0
              AND status = 'active'
        ");
        $stmt->execute([$bhwId, BHW_NAV_RELATED_RECORDS, $patientId]);
        $updated += (int) $stmt->rowCount();
    } catch (Throwable $e) {
        // continue
    }
    try {
        if ($pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
            $stmt = $pdo->prepare("
                UPDATE notifications n
                INNER JOIN consultations c ON c.id = n.related_id AND c.patient_id = ?
                SET n.is_read = 1, n.updated_at = NOW()
                WHERE n.user_id = ?
                  AND n.related_table = ?
                  AND n.is_read = 0
                  AND n.status = 'active'
            ");
            $stmt->execute([$patientId, $bhwId, BHW_NAV_RELATED_RECORDS]);
            $updated += (int) $stmt->rowCount();
        }
    } catch (Throwable $e) {
        // non-fatal
    }
    try {
        $like = '%patient_id=' . $patientId . '%';
        $stmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1, updated_at = NOW()
            WHERE user_id = ?
              AND related_table = ?
              AND is_read = 0
              AND status = 'active'
              AND link LIKE ?
        ");
        $stmt->execute([$bhwId, BHW_NAV_RELATED_RECORDS, $like]);
        $updated += (int) $stmt->rowCount();
    } catch (Throwable $e) {
        // non-fatal
    }
    return $updated;
}

/**
 * Mark Appointment Follow-ups notifications as read for this BHW.
 * When $followupId > 0, marks that item only; otherwise marks all active followup inbox rows.
 */
function bhw_nav_mark_followups_read(PDO $pdo, int $bhwId, int $followupId = 0): int
{
    if ($bhwId <= 0) {
        return 0;
    }
    NotificationManager::ensureSchema($pdo);
    if ($followupId > 0) {
        return NotificationManager::markRelatedRead($pdo, $bhwId, BHW_NAV_RELATED_FOLLOWUPS, $followupId);
    }
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1, updated_at = NOW()
            WHERE user_id = ?
              AND related_table = ?
              AND is_read = 0
              AND status = 'active'
        ");
        $stmt->execute([$bhwId, BHW_NAV_RELATED_FOLLOWUPS]);
        return (int) $stmt->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}
