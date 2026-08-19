<?php
/**
 * Cheap per-role fingerprints for live UI sync.
 * Hashes and integer counts only — never other patients' clinical details.
 */

declare(strict_types=1);

require_once __DIR__ . '/appointment_slots.php';

/**
 * @return array<string, mixed>
 */
function live_sync_payload(PDO $pdo, int $userId, string $role): array
{
    $role = strtolower(trim($role));
    $today = appointment_now()->format('Y-m-d');
    $todayDay = appointment_now()->format('l');

    $fingerprints = [
        'notifications' => live_sync_notifications_fp($pdo, $userId),
        'messages' => live_sync_messages_fp($pdo, $userId),
    ];
    $counts = [
        'notifications' => live_sync_notifications_unread($pdo, $userId),
        'messages' => live_sync_messages_unread_approx($pdo, $userId),
    ];

    if ($role === 'provider') {
        require_once __DIR__ . '/patient_slot_waitlist.php';
        patient_slot_waitlist_process_throttled($pdo);
        $fingerprints['slots'] = live_sync_provider_slots_fp($pdo, $userId, $today, $todayDay);
        $fingerprints['schedule'] = $fingerprints['slots'];
        $fingerprints['appointments'] = live_sync_consultations_fp($pdo, 'provider_id', $userId, $today);
        $fingerprints['queue'] = $fingerprints['appointments'];
        $fingerprints['triage'] = live_sync_provider_triage_fp($pdo, $userId);
        $fingerprints['booking_state'] = live_sync_hash(
            $fingerprints['triage'],
            patient_slot_waitlist_provider_fingerprint($pdo, $userId)
        );
    } elseif ($role === 'patient') {
        require_once __DIR__ . '/patient_slot_waitlist.php';
        patient_slot_waitlist_process_throttled($pdo);
        $fingerprints['slots'] = live_sync_patient_slots_fp($pdo, $today, $todayDay);
        $fingerprints['schedule'] = $fingerprints['slots'];
        $fingerprints['appointments'] = live_sync_consultations_fp($pdo, 'patient_id', $userId, $today);
        $fingerprints['triage'] = live_sync_patient_triage_fp($pdo, $userId);
        $fingerprints['booking_state'] = live_sync_hash(
            $fingerprints['slots'],
            $fingerprints['triage'],
            patient_slot_waitlist_fingerprint($pdo, $userId)
        );
    } elseif ($role === 'bhw') {
        $barangayId = live_sync_bhw_barangay_id($pdo, $userId);
        $fingerprints['triage'] = live_sync_bhw_triage_fp($pdo, $barangayId);
        $fingerprints['appointments'] = live_sync_bhw_consultations_fp($pdo, $barangayId, $today);
        $fingerprints['queue'] = $fingerprints['triage'];
    } elseif (in_array($role, ['admin', 'superadmin'], true)) {
        $fingerprints['queue'] = live_sync_admin_queue_fp($pdo, $today);
        $fingerprints['appointments'] = $fingerprints['queue'];
        $fingerprints['triage'] = live_sync_admin_triage_fp($pdo);
        $fingerprints['dashboard'] = live_sync_hash(
            $fingerprints['queue'],
            $fingerprints['triage'],
            live_sync_admin_users_fp($pdo)
        );
    }

    return [
        'role' => $role,
        'server_time' => appointment_now()->format('c'),
        'fingerprints' => $fingerprints,
        'counts' => $counts,
    ];
}

function live_sync_hash(string ...$parts): string
{
    return hash('sha256', implode('|', $parts));
}

function live_sync_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        $cache[$table] = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->rowCount() > 0;
    } catch (Throwable $e) {
        $cache[$table] = false;
    }

    return $cache[$table];
}

/**
 * @param list<mixed> $params
 */
function live_sync_row(PDO $pdo, string $sql, array $params = []): string
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        if (!$row) {
            return '0';
        }

        return implode(':', array_map(static fn ($v) => (string) ($v ?? '0'), $row));
    } catch (Throwable $e) {
        return '0';
    }
}

function live_sync_notifications_unread(PDO $pdo, int $userId): int
{
    if ($userId <= 0 || !live_sync_table_exists($pdo, 'notifications')) {
        return 0;
    }
    try {
        require_once dirname(__DIR__) . '/core/NotificationManager.php';

        return NotificationManager::getUnreadCount($pdo, $userId);
    } catch (Throwable $e) {
        return 0;
    }
}

function live_sync_notifications_fp(PDO $pdo, int $userId): string
{
    if ($userId <= 0 || !live_sync_table_exists($pdo, 'notifications')) {
        return live_sync_hash('0');
    }

    return live_sync_hash(live_sync_row(
        $pdo,
        "SELECT COUNT(*), COALESCE(SUM(is_read=0),0), COALESCE(MAX(id),0),
                COALESCE(MAX(UNIX_TIMESTAMP(updated_at)),0)
         FROM notifications
         WHERE user_id = ? AND status != 'deleted'",
        [$userId]
    ));
}

function live_sync_messages_unread_approx(PDO $pdo, int $userId): int
{
    if ($userId <= 0 || !live_sync_table_exists($pdo, 'consultation_messages')) {
        return 0;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM consultation_messages
            WHERE receiver_id = ?
              AND is_read = 0
              AND is_deleted_for_everyone = 0
        ");
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function live_sync_messages_fp(PDO $pdo, int $userId): string
{
    if ($userId <= 0 || !live_sync_table_exists($pdo, 'consultation_messages')) {
        return live_sync_hash('0');
    }

    return live_sync_hash(live_sync_row(
        $pdo,
        "SELECT COUNT(*), COALESCE(MAX(id),0), COALESCE(SUM(is_read=0),0)
         FROM consultation_messages
         WHERE (receiver_id = ? OR sender_id = ?)
           AND is_deleted_for_everyone = 0",
        [$userId, $userId]
    ));
}

function live_sync_provider_slots_fp(PDO $pdo, int $providerId, string $today, string $todayDay): string
{
    $slots = '0';
    $sched = '0';
    if (live_sync_table_exists($pdo, 'appointment_slots')) {
        $slots = live_sync_row(
            $pdo,
            "SELECT COUNT(*), COALESCE(SUM(id),0), COALESCE(MAX(id),0),
                    COALESCE(SUM(CASE status WHEN 'available' THEN 1 WHEN 'booked' THEN 2 ELSE 3 END),0)
             FROM appointment_slots
             WHERE provider_id = ? AND slot_date = ?",
            [$providerId, $today]
        );
    }
    if (live_sync_table_exists($pdo, 'provider_schedules')) {
        $sched = live_sync_row(
            $pdo,
            'SELECT COUNT(*), COALESCE(SUM(is_active),0), COALESCE(MAX(id),0)
             FROM provider_schedules
             WHERE provider_id = ? AND day_of_week = ?',
            [$providerId, $todayDay]
        );
    }

    return live_sync_hash($slots, $sched);
}

function live_sync_patient_slots_fp(PDO $pdo, string $today, string $todayDay): string
{
    $slots = '0';
    $sched = '0';
    if (live_sync_table_exists($pdo, 'appointment_slots')) {
        $slots = live_sync_row(
            $pdo,
            "SELECT COUNT(*), COALESCE(SUM(id),0), COALESCE(MAX(id),0),
                    COALESCE(SUM(CASE status WHEN 'available' THEN 1 WHEN 'booked' THEN 2 ELSE 3 END),0)
             FROM appointment_slots
             WHERE slot_date = ?",
            [$today]
        );
    }
    if (live_sync_table_exists($pdo, 'provider_schedules')) {
        $sched = live_sync_row(
            $pdo,
            'SELECT COUNT(*), COALESCE(SUM(is_active),0), COALESCE(MAX(id),0)
             FROM provider_schedules
             WHERE day_of_week = ?',
            [$todayDay]
        );
    }

    return live_sync_hash($slots, $sched);
}

function live_sync_consultations_fp(PDO $pdo, string $ownerColumn, int $ownerId, string $today): string
{
    if (!live_sync_table_exists($pdo, 'consultations') || !in_array($ownerColumn, ['provider_id', 'patient_id'], true)) {
        return live_sync_hash('0');
    }

    return live_sync_hash(live_sync_row(
        $pdo,
        "SELECT COUNT(*), COALESCE(SUM(id),0), COALESCE(MAX(id),0),
                COALESCE(SUM(CASE LOWER(COALESCE(status,''))
                    WHEN 'scheduled' THEN 1
                    WHEN 'pending' THEN 2
                    WHEN 'in_consultation' THEN 3
                    WHEN 'completed' THEN 4
                    WHEN 'cancelled' THEN 5
                    WHEN 'canceled' THEN 5
                    ELSE 6 END),0)
         FROM consultations
         WHERE {$ownerColumn} = ?
           AND consult_date >= DATE_SUB(?, INTERVAL 1 DAY)",
        [$ownerId, $today]
    ));
}

function live_sync_provider_triage_fp(PDO $pdo, int $providerId): string
{
    if (!live_sync_table_exists($pdo, 'triage_results')) {
        return live_sync_hash('0');
    }

    return live_sync_hash(
        live_sync_row(
            $pdo,
            "SELECT COUNT(*), COALESCE(SUM(id),0), COALESCE(MAX(id),0)
             FROM triage_results
             WHERE assigned_provider_id = ?
                OR EXISTS (
                    SELECT 1 FROM consultations c
                    WHERE c.patient_id = triage_results.patient_id
                      AND c.provider_id = ?
                )",
            [$providerId, $providerId]
        ),
        live_sync_table_exists($pdo, 'consultation_clinical_support')
            ? live_sync_row(
                $pdo,
                "SELECT COUNT(*), COALESCE(MAX(id),0), COALESCE(MAX(UNIX_TIMESTAMP(created_at)),0)
                 FROM consultation_clinical_support
                 WHERE provider_id = ? AND event_type = 'urgency_override'",
                [$providerId]
            )
            : '0'
    );
}

function live_sync_patient_triage_fp(PDO $pdo, int $patientId): string
{
    if (!live_sync_table_exists($pdo, 'triage_results')) {
        return live_sync_hash('0');
    }

    return live_sync_hash(
        live_sync_row(
            $pdo,
            "SELECT COUNT(*), COALESCE(MAX(id),0),
                    COALESCE(MAX(UNIX_TIMESTAMP(assessed_at)),0),
                    COALESCE(MAX(UNIX_TIMESTAMP(assigned_at)),0),
                    COALESCE(MAX(CAST(triage_level AS CHAR)),''),
                    COALESCE(MAX(CAST(outcome AS CHAR)),''),
                    COALESCE(SUM(CASE COALESCE(recommendation_status,'hidden')
                        WHEN 'pending_approval' THEN 1
                        WHEN 'approved' THEN 2
                        WHEN 'rejected' THEN 4
                        ELSE 0 END),0)
             FROM triage_results
             WHERE patient_id = ?",
            [$patientId]
        ),
        live_sync_table_exists($pdo, 'consultation_clinical_support')
            ? live_sync_row(
                $pdo,
                "SELECT COUNT(*), COALESCE(MAX(id),0),
                        COALESCE(MAX(UNIX_TIMESTAMP(created_at)),0),
                        COALESCE(MAX(CAST(urgency_bucket AS CHAR)),'')
                 FROM consultation_clinical_support
                 WHERE patient_id = ? AND event_type = 'urgency_override'",
                [$patientId]
            )
            : '0'
    );
}

function live_sync_bhw_barangay_id(PDO $pdo, int $userId): int
{
    $fromSession = (int) ($_SESSION['user_barangay_id'] ?? 0);
    if ($fromSession > 0) {
        return $fromSession;
    }
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('barangay_id', $cols, true)) {
            return 0;
        }
        $stmt = $pdo->prepare('SELECT barangay_id FROM users WHERE id = ? AND role = ? LIMIT 1');
        $stmt->execute([$userId, 'bhw']);

        return (int) ($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function live_sync_bhw_triage_fp(PDO $pdo, int $barangayId): string
{
    if ($barangayId <= 0 || !live_sync_table_exists($pdo, 'triage_results')) {
        return live_sync_hash('0');
    }

    $sql = "SELECT COUNT(*), COALESCE(MAX(tr.id),0)
            FROM triage_results tr
            INNER JOIN users u ON u.id = tr.patient_id
            WHERE u.barangay_id = ?";
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('barangay_id', $cols, true) && live_sync_table_exists($pdo, 'patient_registrations')) {
            $sql = "SELECT COUNT(*), COALESCE(MAX(tr.id),0)
                    FROM triage_results tr
                    INNER JOIN patient_registrations pr ON pr.user_id = tr.patient_id
                    WHERE pr.barangay_id = ?";
        }
    } catch (Throwable $e) {
        // keep users.barangay_id query
    }

    return live_sync_hash(live_sync_row($pdo, $sql, [$barangayId]));
}

function live_sync_bhw_consultations_fp(PDO $pdo, int $barangayId, string $today): string
{
    if ($barangayId <= 0 || !live_sync_table_exists($pdo, 'consultations')) {
        return live_sync_hash('0');
    }

    $sql = "SELECT COUNT(*), COALESCE(MAX(c.id),0),
                   COALESCE(SUM(CASE LOWER(COALESCE(c.status,''))
                       WHEN 'in_consultation' THEN 1 ELSE 0 END),0)
            FROM consultations c
            INNER JOIN users u ON u.id = c.patient_id
            WHERE u.barangay_id = ?
              AND c.consult_date >= DATE_SUB(?, INTERVAL 1 DAY)";
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('barangay_id', $cols, true) && live_sync_table_exists($pdo, 'patient_registrations')) {
            $sql = "SELECT COUNT(*), COALESCE(MAX(c.id),0),
                           COALESCE(SUM(CASE LOWER(COALESCE(c.status,''))
                               WHEN 'in_consultation' THEN 1 ELSE 0 END),0)
                    FROM consultations c
                    INNER JOIN patient_registrations pr ON pr.user_id = c.patient_id
                    WHERE pr.barangay_id = ?
                      AND c.consult_date >= DATE_SUB(?, INTERVAL 1 DAY)";
        }
    } catch (Throwable $e) {
        // keep users.barangay_id query
    }

    return live_sync_hash(live_sync_row($pdo, $sql, [$barangayId, $today]));
}

function live_sync_admin_queue_fp(PDO $pdo, string $today): string
{
    if (!live_sync_table_exists($pdo, 'consultations')) {
        return live_sync_hash('0');
    }

    return live_sync_hash(live_sync_row(
        $pdo,
        "SELECT COUNT(*), COALESCE(MAX(id),0),
                COALESCE(SUM(status='scheduled'),0),
                COALESCE(SUM(status='in_consultation'),0),
                COALESCE(SUM(status='completed'),0)
         FROM consultations
         WHERE consult_date = ?",
        [$today]
    ));
}

function live_sync_admin_triage_fp(PDO $pdo): string
{
    if (!live_sync_table_exists($pdo, 'triage_results')) {
        return live_sync_hash('0');
    }

    return live_sync_hash(live_sync_row(
        $pdo,
        'SELECT COUNT(*), COALESCE(MAX(id),0) FROM triage_results'
    ));
}

function live_sync_admin_users_fp(PDO $pdo): string
{
    if (!live_sync_table_exists($pdo, 'users')) {
        return live_sync_hash('0');
    }

    return live_sync_hash(live_sync_row(
        $pdo,
        'SELECT COUNT(*), COALESCE(MAX(id),0) FROM users'
    ));
}
