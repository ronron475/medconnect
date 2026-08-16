<?php
/**
 * NON-URGENT same-day slot waitlist.
 *
 * Patients whose triage is non-urgent and who have no bookable doctor slot
 * stay in a persisted waiting queue. When a suitable slot opens (new schedule,
 * activation, or a cancelled visit), the queue is re-checked, the patient UI
 * updates via live-sync, and a single email is sent per availability wave.
 */

declare(strict_types=1);

require_once __DIR__ . '/appointment_slots.php';
require_once __DIR__ . '/triage_provider_assignment.php';
require_once __DIR__ . '/patient_booking_status.php';

function patient_slot_waitlist_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'patient_slot_waitlist'")->rowCount();
        if ($exists === 0) {
            $pdo->exec("
                CREATE TABLE patient_slot_waitlist (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    patient_id INT UNSIGNED NOT NULL,
                    triage_result_id INT UNSIGNED NOT NULL,
                    assigned_provider_id INT UNSIGNED NULL,
                    eligible_provider_id INT UNSIGNED NULL,
                    complaint TEXT NULL,
                    triage_level VARCHAR(32) NOT NULL DEFAULT 'non_urgent',
                    status ENUM('waiting', 'slot_available', 'booked', 'cancelled', 'expired')
                        NOT NULL DEFAULT 'waiting',
                    waiting_since DATETIME NOT NULL,
                    slot_available_at DATETIME NULL,
                    notified_at DATETIME NULL,
                    notification_id INT UNSIGNED NULL,
                    availability_key VARCHAR(80) NULL,
                    eligible_provider_name VARCHAR(191) NULL,
                    booked_consultation_id INT UNSIGNED NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_waitlist_patient_triage (patient_id, triage_result_id),
                    KEY idx_waitlist_status_since (status, waiting_since),
                    KEY idx_waitlist_patient_status (patient_id, status),
                    KEY idx_waitlist_assigned (assigned_provider_id, status),
                    KEY idx_waitlist_eligible (eligible_provider_id, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    } catch (PDOException $e) {
        error_log('patient_slot_waitlist_ensure_schema: ' . $e->getMessage());
    }
}

function patient_slot_waitlist_table_ready(PDO $pdo): bool
{
    patient_slot_waitlist_ensure_schema($pdo);
    try {
        return $pdo->query("SHOW TABLES LIKE 'patient_slot_waitlist'")->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return list<array{provider_id:int,slot_count:int,min_slot_id:int,max_slot_id:int,provider_name:string}>
 */
function patient_slot_waitlist_bookable_providers(PDO $pdo): array
{
    appointment_schedule_ensure_schema($pdo);
    appointment_slots_expire_passed($pdo);

    $bookable = appointment_slots_bookable_sql('s');
    try {
        $stmt = $pdo->query("
            SELECT
                s.provider_id,
                COUNT(*) AS slot_count,
                MIN(s.id) AS min_slot_id,
                MAX(s.id) AS max_slot_id,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS provider_name
            FROM appointment_slots s
            INNER JOIN users u
                ON u.id = s.provider_id
               AND u.role = 'provider'
               AND u.is_active = 1
            WHERE s.status = 'available'
              AND {$bookable}
            GROUP BY s.provider_id, u.first_name, u.last_name
            ORDER BY slot_count DESC, s.provider_id ASC
        ");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (PDOException $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $id = (int) ($row['provider_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $out[] = [
            'provider_id'   => $id,
            'slot_count'    => (int) ($row['slot_count'] ?? 0),
            'min_slot_id'   => (int) ($row['min_slot_id'] ?? 0),
            'max_slot_id'   => (int) ($row['max_slot_id'] ?? 0),
            'provider_name' => trim((string) ($row['provider_name'] ?? '')),
        ];
    }

    return $out;
}

function patient_slot_waitlist_total_bookable(PDO $pdo): int
{
    $total = 0;
    foreach (patient_slot_waitlist_bookable_providers($pdo) as $row) {
        $total += (int) ($row['slot_count'] ?? 0);
    }

    return $total;
}

/**
 * Only the assigned reviewing doctor can make a waiter eligible.
 * Another doctor's slots do not auto-reassign care-tips review.
 *
 * @param list<array{provider_id:int,slot_count:int,min_slot_id:int,max_slot_id:int,provider_name:string}> $providers
 * @return array{provider_id:int,provider_name:string,slot_count:int}|null
 */
function patient_slot_waitlist_assigned_availability(array $providers, int $assignedProviderId): ?array
{
    if ($assignedProviderId <= 0) {
        return null;
    }
    foreach ($providers as $row) {
        if ((int) ($row['provider_id'] ?? 0) !== $assignedProviderId) {
            continue;
        }
        $count = (int) ($row['slot_count'] ?? 0);
        if ($count <= 0) {
            return null;
        }

        return [
            'provider_id'   => $assignedProviderId,
            'provider_name' => trim((string) ($row['provider_name'] ?? '')),
            'slot_count'    => $count,
        ];
    }

    return null;
}

/**
 * FIFO plan: oldest waiters first, one offer per REAL open slot across any provider.
 * Does not auto-book. Never offers a provider who currently has 0 slots.
 *
 * @param list<array<string, mixed>> $waiters  Must already be oldest-first
 * @param list<array{provider_id:int,slot_count:int,provider_name?:string}> $providers
 * @return list<array{id:int,action:string,provider_id:int,provider_name:string,wave_key:string}>
 */
function patient_slot_waitlist_fifo_plan(array $waiters, array $providers): array
{
    $remaining = [];
    $names = [];
    $order = [];
    foreach ($providers as $row) {
        $pid = (int) ($row['provider_id'] ?? 0);
        $count = (int) ($row['slot_count'] ?? 0);
        if ($pid <= 0 || $count <= 0) {
            continue;
        }
        $remaining[$pid] = $count;
        $names[$pid] = trim((string) ($row['provider_name'] ?? ''));
        $order[] = $pid;
    }

    $today = date('Y-m-d');
    $plan = [];
    foreach ($waiters as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $picked = 0;
        foreach ($order as $pid) {
            if (($remaining[$pid] ?? 0) > 0) {
                $remaining[$pid]--;
                $picked = $pid;
                break;
            }
        }

        if ($picked <= 0) {
            $plan[] = [
                'id'            => $id,
                'action'        => 'revert',
                'provider_id'   => 0,
                'provider_name' => '',
                'wave_key'      => '',
            ];
            continue;
        }

        $plan[] = [
            'id'            => $id,
            'action'        => 'offer',
            'provider_id'   => $picked,
            'provider_name' => $names[$picked] ?? '',
            'wave_key'      => $today . ':open:' . $picked,
        ];
    }

    return $plan;
}

function patient_slot_waitlist_assigned_has_bookable(PDO $pdo, int $assignedProviderId): bool
{
    if ($assignedProviderId <= 0) {
        return false;
    }

    return patient_slot_waitlist_assigned_availability(
        patient_slot_waitlist_bookable_providers($pdo),
        $assignedProviderId
    ) !== null;
}

function patient_slot_waitlist_should_enqueue(PDO $pdo, int $assignedProviderId = 0): bool
{
    unset($assignedProviderId);

    return patient_slot_waitlist_total_bookable($pdo) <= 0;
}

function patient_slot_waitlist_patient_may_book_provider(PDO $pdo, int $patientId, int $providerId): bool
{
    if ($patientId <= 0 || $providerId <= 0 || !patient_slot_waitlist_table_ready($pdo)) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT eligible_provider_id, status
            FROM patient_slot_waitlist
            WHERE patient_id = ?
              AND status = 'slot_available'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return false;
    }
    if (!$row) {
        return false;
    }
    $eligibleId = (int) ($row['eligible_provider_id'] ?? 0);

    return $eligibleId <= 0 || $eligibleId === $providerId;
}

/**
 * Place (or refresh) a NON-URGENT case on the waitlist when no slot exists.
 *
 * @return array{queued:bool,status:string,id:int}
 */
function patient_slot_waitlist_enqueue(
    PDO $pdo,
    int $patientId,
    int $triageId,
    int $assignedProviderId,
    string $complaint,
    string $triageLevel = 'non_urgent'
): array {
    $empty = ['queued' => false, 'status' => '', 'id' => 0];
    if ($patientId <= 0 || $triageId <= 0) {
        return $empty;
    }
    if (!patient_slot_waitlist_table_ready($pdo)) {
        return $empty;
    }

    try {
        $pdo->prepare("
            UPDATE patient_slot_waitlist
            SET status = 'cancelled', updated_at = NOW()
            WHERE patient_id = ?
              AND triage_result_id <> ?
              AND status IN ('waiting', 'slot_available')
        ")->execute([$patientId, $triageId]);
    } catch (PDOException $e) {
        // continue with enqueue
    }

    $triageLevel = strtolower(trim($triageLevel));
    if ($triageLevel === '' || $triageLevel === 'non-urgent') {
        $triageLevel = 'non_urgent';
    }
    if ($triageLevel !== 'non_urgent') {
        return $empty;
    }

    if (patient_portal_has_open_consultation($pdo, $patientId)) {
        return $empty;
    }

    $status = 'waiting';

    $existing = $pdo->prepare("
        SELECT id, status FROM patient_slot_waitlist
        WHERE patient_id = ? AND triage_result_id = ?
        LIMIT 1
    ");
    $existing->execute([$patientId, $triageId]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);

    $eligibleId = $assignedProviderId;
    $eligibleName = triage_provider_display_name($pdo, $assignedProviderId);

    if ($row) {
        $id = (int) $row['id'];
        $prev = (string) ($row['status'] ?? '');
        if (in_array($prev, ['booked', 'cancelled'], true)) {
            return ['queued' => false, 'status' => $prev, 'id' => $id];
        }
        $pdo->prepare("
            UPDATE patient_slot_waitlist
            SET assigned_provider_id = ?,
                eligible_provider_id = ?,
                eligible_provider_name = ?,
                complaint = ?,
                triage_level = ?,
                status = ?,
                slot_available_at = CASE WHEN ? = 'slot_available' THEN COALESCE(slot_available_at, NOW()) ELSE NULL END,
                updated_at = NOW()
            WHERE id = ?
              AND status IN ('waiting', 'slot_available')
        ")->execute([
            $assignedProviderId > 0 ? $assignedProviderId : null,
            $eligibleId > 0 ? $eligibleId : null,
            $eligibleName !== '' ? $eligibleName : null,
            $complaint,
            $triageLevel,
            $status,
            $status,
            $id,
        ]);

        return ['queued' => true, 'status' => $status, 'id' => $id];
    }

    $ins = $pdo->prepare("
        INSERT INTO patient_slot_waitlist
            (patient_id, triage_result_id, assigned_provider_id, eligible_provider_id,
             eligible_provider_name, complaint, triage_level, status, waiting_since, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            assigned_provider_id = VALUES(assigned_provider_id),
            eligible_provider_id = VALUES(eligible_provider_id),
            eligible_provider_name = VALUES(eligible_provider_name),
            complaint = VALUES(complaint),
            triage_level = VALUES(triage_level),
            status = IF(status IN ('booked', 'cancelled'), status, VALUES(status)),
            updated_at = NOW()
    ");
    $ins->execute([
        $patientId,
        $triageId,
        $assignedProviderId > 0 ? $assignedProviderId : null,
        $eligibleId > 0 ? $eligibleId : null,
        $eligibleName !== '' ? $eligibleName : null,
        $complaint,
        $triageLevel,
        $status,
    ]);

    $id = (int) $pdo->lastInsertId();
    if ($id <= 0) {
        $existing->execute([$patientId, $triageId]);
        $again = $existing->fetch(PDO::FETCH_ASSOC);
        $id = (int) ($again['id'] ?? 0);
        if (!empty($again['status'])) {
            $status = (string) $again['status'];
        }
    }
    if ($status === 'waiting') {
        try {
            $pdo->prepare("UPDATE triage_results SET outcome = 'waiting_for_slot' WHERE id = ? AND patient_id = ?")
                ->execute([$triageId, $patientId]);
        } catch (PDOException $e) {
            // outcome column optional
        }
    }

    return ['queued' => true, 'status' => $status, 'id' => $id];
}

/**
 * Enqueue only when no REAL bookable slot exists, then re-check FIFO.
 *
 * @return array{queued:bool,status:string,id:int}
 */
function patient_slot_waitlist_enqueue_if_no_assigned_slot(
    PDO $pdo,
    int $patientId,
    int $triageId,
    int $assignedProviderId,
    string $complaint,
    string $triageLevel = 'non_urgent'
): array {
    $empty = ['queued' => false, 'status' => '', 'id' => 0];
    if (!patient_slot_waitlist_should_enqueue($pdo, $assignedProviderId)) {
        return $empty;
    }

    $queued = patient_slot_waitlist_enqueue(
        $pdo,
        $patientId,
        $triageId,
        $assignedProviderId,
        $complaint,
        $triageLevel
    );
    if (empty($queued['queued']) || (int) ($queued['id'] ?? 0) <= 0) {
        return $queued;
    }

    try {
        patient_slot_waitlist_process($pdo);
    } catch (Throwable $e) {
        error_log('patient_slot_waitlist_enqueue_if_no_assigned_slot process: ' . $e->getMessage());
    }

    try {
        $st = $pdo->prepare('SELECT status FROM patient_slot_waitlist WHERE id = ? LIMIT 1');
        $st->execute([(int) $queued['id']]);
        $status = (string) ($st->fetchColumn() ?: '');
        if ($status !== '') {
            $queued['status'] = $status;
        }
    } catch (PDOException $e) {
        // keep enqueue status
    }

    return $queued;
}

function patient_slot_waitlist_mark_booked(PDO $pdo, int $patientId, int $triageId, int $consultationId): void
{
    if ($patientId <= 0 || $triageId <= 0 || !patient_slot_waitlist_table_ready($pdo)) {
        return;
    }
    try {
        $pdo->prepare("
            UPDATE patient_slot_waitlist
            SET status = 'booked',
                booked_consultation_id = ?,
                updated_at = NOW()
            WHERE patient_id = ?
              AND triage_result_id = ?
              AND status IN ('waiting', 'slot_available')
        ")->execute([$consultationId > 0 ? $consultationId : null, $patientId, $triageId]);
    } catch (PDOException $e) {
        error_log('patient_slot_waitlist_mark_booked: ' . $e->getMessage());
    }
}

function patient_slot_waitlist_cancel_for_triage(PDO $pdo, int $triageId): void
{
    if ($triageId <= 0 || !patient_slot_waitlist_table_ready($pdo)) {
        return;
    }
    try {
        $pdo->prepare("
            UPDATE patient_slot_waitlist
            SET status = 'cancelled',
                updated_at = NOW()
            WHERE triage_result_id = ?
              AND status IN ('waiting', 'slot_available')
        ")->execute([$triageId]);
    } catch (PDOException $e) {
        error_log('patient_slot_waitlist_cancel_for_triage: ' . $e->getMessage());
    }
}

function patient_slot_waitlist_cancel_for_patient(PDO $pdo, int $patientId): void
{
    if ($patientId <= 0 || !patient_slot_waitlist_table_ready($pdo)) {
        return;
    }
    try {
        $pdo->prepare("
            UPDATE patient_slot_waitlist
            SET status = 'cancelled',
                updated_at = NOW()
            WHERE patient_id = ?
              AND status IN ('waiting', 'slot_available')
        ")->execute([$patientId]);
    } catch (PDOException $e) {
        error_log('patient_slot_waitlist_cancel_for_patient: ' . $e->getMessage());
    }
}

/**
 * Enqueue existing open NON-URGENT cases that have no bookable slot.
 */
function patient_slot_waitlist_backfill_open_cases(PDO $pdo): void
{
    if (!patient_slot_waitlist_table_ready($pdo)) {
        return;
    }

    triage_assessment_ensure_schema($pdo);
    try {
        $sql = "
            SELECT tr.id, tr.patient_id, tr.chief_complaint, tr.assigned_provider_id, tr.triage_level
            FROM triage_results tr
            WHERE COALESCE(tr.triage_level, 'non_urgent') = 'non_urgent'
              AND COALESCE(tr.recommendation_status, 'hidden') IN ('pending_approval', 'approved')
              AND TRIM(COALESCE(tr.chief_complaint, '')) <> ''
              AND tr.assessed_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
              " . patient_triage_sql_active_only('tr') . "
              AND NOT EXISTS (
                SELECT 1 FROM patient_slot_waitlist w
                WHERE w.triage_result_id = tr.id
                  AND w.status IN ('waiting', 'slot_available', 'booked')
              )
            ORDER BY tr.assessed_at ASC
            LIMIT 50
        ";
        $stmt = $pdo->query($sql);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (PDOException $e) {
        return;
    }

    foreach ($rows as $row) {
        $patientId = (int) ($row['patient_id'] ?? 0);
        $triageId = (int) ($row['id'] ?? 0);
        if ($patientId <= 0 || $triageId <= 0) {
            continue;
        }
        if (patient_portal_has_open_consultation($pdo, $patientId)) {
            continue;
        }
        $assignedId = (int) ($row['assigned_provider_id'] ?? 0);
        if (!patient_slot_waitlist_should_enqueue($pdo, $assignedId)) {
            continue;
        }
        patient_slot_waitlist_enqueue(
            $pdo,
            $patientId,
            $triageId,
            $assignedId,
            trim((string) ($row['chief_complaint'] ?? '')),
            (string) ($row['triage_level'] ?? 'non_urgent')
        );
    }
}

/**
 * Re-check every open waiter against live appointment_slots.
 * Sends at most one email per patient per availability wave.
 *
 * @return array{checked:int,opened:int,reverted:int,notified:int}
 */
function patient_slot_waitlist_process(PDO $pdo): array
{
    $stats = ['checked' => 0, 'opened' => 0, 'reverted' => 0, 'notified' => 0];
    if (!patient_slot_waitlist_table_ready($pdo)) {
        return $stats;
    }

    patient_slot_waitlist_backfill_open_cases($pdo);

    $providers = patient_slot_waitlist_bookable_providers($pdo);

    try {
        $stmt = $pdo->query("
            SELECT w.*, tr.recommendation_status, tr.assigned_provider_id AS triage_assigned_id
            FROM patient_slot_waitlist w
            LEFT JOIN triage_results tr ON tr.id = w.triage_result_id
            WHERE w.status IN ('waiting', 'slot_available')
            ORDER BY w.waiting_since ASC, w.id ASC
        ");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (PDOException $e) {
        return $stats;
    }

    $open = [];
    foreach ($rows as $row) {
        $stats['checked']++;
        $id = (int) ($row['id'] ?? 0);
        $patientId = (int) ($row['patient_id'] ?? 0);
        $triageId = (int) ($row['triage_result_id'] ?? 0);
        if ($id <= 0 || $patientId <= 0) {
            continue;
        }
        if (patient_portal_has_open_consultation($pdo, $patientId)) {
            patient_slot_waitlist_mark_booked($pdo, $patientId, $triageId, 0);
            continue;
        }
        $open[] = $row;
    }

    $plan = patient_slot_waitlist_fifo_plan($open, $providers);
    $byId = [];
    foreach ($open as $row) {
        $byId[(int) ($row['id'] ?? 0)] = $row;
    }

    $notifyQueue = [];
    foreach ($plan as $step) {
        $id = (int) ($step['id'] ?? 0);
        $row = $byId[$id] ?? null;
        if (!$row) {
            continue;
        }
        $status = (string) ($row['status'] ?? 'waiting');
        $assignedId = (int) ($step['provider_id'] ?? 0);
        $providerName = trim((string) ($step['provider_name'] ?? ''));
        if ($providerName === '' && $assignedId > 0) {
            $providerName = triage_provider_display_name($pdo, $assignedId);
        }

        if (($step['action'] ?? '') === 'revert') {
            if ($status === 'slot_available') {
                $pdo->prepare("
                    UPDATE patient_slot_waitlist
                    SET status = 'waiting',
                        slot_available_at = NULL,
                        notified_at = NULL,
                        availability_key = NULL,
                        eligible_provider_id = assigned_provider_id,
                        updated_at = NOW()
                    WHERE id = ?
                      AND status = 'slot_available'
                ")->execute([$id]);
                $stats['reverted']++;
            }
            continue;
        }

        $waveKey = (string) ($step['wave_key'] ?? '');
        $alreadyNotified = !empty($row['notified_at'])
            && (string) ($row['availability_key'] ?? '') === $waveKey;

        $pdo->prepare("
            UPDATE patient_slot_waitlist
            SET status = 'slot_available',
                eligible_provider_id = ?,
                eligible_provider_name = ?,
                availability_key = ?,
                slot_available_at = COALESCE(slot_available_at, NOW()),
                updated_at = NOW()
            WHERE id = ?
              AND status IN ('waiting', 'slot_available')
        ")->execute([
            $assignedId > 0 ? $assignedId : null,
            $providerName !== '' ? $providerName : null,
            $waveKey,
            $id,
        ]);

        if ($status !== 'slot_available') {
            $stats['opened']++;
        }

        if (!$alreadyNotified) {
            $notifyQueue[] = [
                'id'            => $id,
                'patient_id'    => (int) ($row['patient_id'] ?? 0),
                'triage_id'     => (int) ($row['triage_result_id'] ?? 0),
                'provider_id'   => $assignedId,
                'provider_name' => $providerName,
                'wave_key'      => $waveKey,
            ];
        }
    }

    foreach ($notifyQueue as $item) {
        if (patient_slot_waitlist_notify_patient($pdo, $item)) {
            $stats['notified']++;
        }
    }

    return $stats;
}

/**
 * @param array{id:int,patient_id:int,triage_id:int,provider_id:int,provider_name:string,wave_key:string} $item
 */
function patient_slot_waitlist_notify_patient(PDO $pdo, array $item): bool
{
    $id = (int) ($item['id'] ?? 0);
    $patientId = (int) ($item['patient_id'] ?? 0);
    $waveKey = (string) ($item['wave_key'] ?? '');
    if ($id <= 0 || $patientId <= 0 || $waveKey === '') {
        return false;
    }

    try {
        $lock = $pdo->prepare("
            SELECT id, notified_at, availability_key, status
            FROM patient_slot_waitlist
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $startedTx = !$pdo->inTransaction();
        if ($startedTx) {
            $pdo->beginTransaction();
        }
        $lock->execute([$id]);
        $row = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$row || (string) ($row['status'] ?? '') !== 'slot_available') {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return false;
        }
        if (!empty($row['notified_at']) && (string) ($row['availability_key'] ?? '') === $waveKey) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->commit();
            }

            return false;
        }

        $claim = $pdo->prepare("
            UPDATE patient_slot_waitlist
            SET notified_at = NOW(),
                availability_key = ?,
                updated_at = NOW()
            WHERE id = ?
              AND status = 'slot_available'
              AND (notified_at IS NULL OR availability_key IS NULL OR availability_key <> ?)
        ");
        $claim->execute([$waveKey, $id, $waveKey]);
        $didClaim = $claim->rowCount() > 0;
        if ($startedTx && $pdo->inTransaction()) {
            $pdo->commit();
        }
        if (!$didClaim) {
            return false;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('patient_slot_waitlist_notify_patient lock: ' . $e->getMessage());

        return false;
    }

    require_once __DIR__ . '/notification_events.php';
    $providerName = trim((string) ($item['provider_name'] ?? ''));
    if ($providerName === '') {
        $providerName = 'A healthcare provider';
    }
    $notificationId = NotificationEvents::consultationSlotAvailable(
        $pdo,
        $patientId,
        (int) ($item['provider_id'] ?? 0),
        $providerName,
        $id,
        (int) ($item['triage_id'] ?? 0)
    );
    if ($notificationId) {
        try {
            $pdo->prepare('UPDATE patient_slot_waitlist SET notification_id = ? WHERE id = ?')
                ->execute([$notificationId, $id]);
        } catch (PDOException $e) {
            // non-fatal
        }
    }

    return true;
}

/**
 * Throttled re-check used by live-sync so waiters update without a page refresh.
 */
function patient_slot_waitlist_process_throttled(PDO $pdo, int $minIntervalSeconds = 5): void
{
    $lockPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'medconnect_slot_waitlist.lock';
    $fp = @fopen($lockPath, 'c+');
    if ($fp === false) {
        patient_slot_waitlist_process($pdo);

        return;
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);

        return;
    }
    $raw = stream_get_contents($fp);
    $last = (int) trim((string) $raw);
    $now = time();
    if ($last > 0 && ($now - $last) < $minIntervalSeconds) {
        flock($fp, LOCK_UN);
        fclose($fp);

        return;
    }
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, (string) $now);
    fflush($fp);
    try {
        patient_slot_waitlist_process($pdo);
    } catch (Throwable $e) {
        error_log('patient_slot_waitlist_process_throttled: ' . $e->getMessage());
    }
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * Call after a schedule/slot mutation has been committed.
 */
function patient_slot_waitlist_after_slots_changed(PDO $pdo): void
{
    try {
        patient_slot_waitlist_process($pdo);
    } catch (Throwable $e) {
        error_log('patient_slot_waitlist_after_slots_changed: ' . $e->getMessage());
    }
}

/**
 * After a NON-URGENT waitlist booking is cancelled: keep the triage case,
 * put the patient at the back of the queue, and let FIFO offer the freed slot
 * to the next waiter.
 */
function patient_slot_waitlist_requeue_after_cancel(PDO $pdo, int $patientId, int $consultationId): bool
{
    if ($patientId <= 0 || $consultationId <= 0 || !patient_slot_waitlist_table_ready($pdo)) {
        return false;
    }

    triage_assessment_ensure_schema($pdo);

    $triageId = 0;
    $complaint = '';
    $assignedId = 0;
    $triageLevel = 'non_urgent';
    $recStatus = '';

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (in_array('triage_result_id', $cols, true)) {
            $stmt = $pdo->prepare('
                SELECT triage_result_id FROM consultations
                WHERE id = ? AND patient_id = ?
                LIMIT 1
            ');
            $stmt->execute([$consultationId, $patientId]);
            $triageId = (int) ($stmt->fetchColumn() ?: 0);
        }
    } catch (PDOException $e) {
        $triageId = 0;
    }

    if ($triageId <= 0) {
        try {
            $stmt = $pdo->prepare("
                SELECT triage_result_id FROM patient_slot_waitlist
                WHERE patient_id = ? AND booked_consultation_id = ?
                LIMIT 1
            ");
            $stmt->execute([$patientId, $consultationId]);
            $triageId = (int) ($stmt->fetchColumn() ?: 0);
        } catch (PDOException $e) {
            $triageId = 0;
        }
    }

    if ($triageId <= 0) {
        return false;
    }

    try {
        $tr = $pdo->prepare("
            SELECT id, chief_complaint, assigned_provider_id, triage_level, recommendation_status
            FROM triage_results
            WHERE id = ? AND patient_id = ?
            LIMIT 1
        ");
        $tr->execute([$triageId, $patientId]);
        $row = $tr->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return false;
    }

    if (!$row) {
        return false;
    }

    $triageLevel = strtolower(trim((string) ($row['triage_level'] ?? 'non_urgent')));
    if ($triageLevel === 'non-urgent' || $triageLevel === '') {
        $triageLevel = 'non_urgent';
    }
    if ($triageLevel !== 'non_urgent') {
        return false;
    }

    $recStatus = (string) ($row['recommendation_status'] ?? '');
    $complaint = trim((string) ($row['chief_complaint'] ?? ''));
    $assignedId = (int) ($row['assigned_provider_id'] ?? 0);

    if ($recStatus === 'hidden') {
        try {
            $pdo->prepare("
                UPDATE triage_results
                SET recommendation_status = 'pending_approval'
                WHERE id = ? AND patient_id = ? AND recommendation_status = 'hidden'
            ")->execute([$triageId, $patientId]);
            $recStatus = 'pending_approval';
        } catch (PDOException $e) {
            // keep hidden
        }
    }

    try {
        $pdo->prepare("
            UPDATE triage_results
            SET status = 'pending',
                outcome = 'waiting_for_slot'
            WHERE id = ? AND patient_id = ?
        ")->execute([$triageId, $patientId]);
    } catch (PDOException $e) {
        try {
            $pdo->prepare("UPDATE triage_results SET status = 'pending' WHERE id = ? AND patient_id = ?")
                ->execute([$triageId, $patientId]);
        } catch (PDOException $e2) {
            // non-fatal
        }
    }

    $existing = $pdo->prepare("
        SELECT id FROM patient_slot_waitlist
        WHERE patient_id = ? AND triage_result_id = ?
        LIMIT 1
    ");
    $existing->execute([$patientId, $triageId]);
    $waitId = (int) ($existing->fetchColumn() ?: 0);
    $providerName = triage_provider_display_name($pdo, $assignedId);

    if ($waitId > 0) {
        $pdo->prepare("
            UPDATE patient_slot_waitlist
            SET status = 'waiting',
                waiting_since = NOW(),
                slot_available_at = NULL,
                notified_at = NULL,
                availability_key = NULL,
                booked_consultation_id = NULL,
                assigned_provider_id = ?,
                eligible_provider_id = ?,
                eligible_provider_name = ?,
                complaint = ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([
            $assignedId > 0 ? $assignedId : null,
            $assignedId > 0 ? $assignedId : null,
            $providerName !== '' ? $providerName : null,
            $complaint,
            $waitId,
        ]);
    } else {
        patient_slot_waitlist_enqueue($pdo, $patientId, $triageId, $assignedId, $complaint, $triageLevel);
    }

    return true;
}

/**
 * Dashboard / live API state for one patient.
 *
 * @return array<string, mixed>
 */
function patient_slot_waitlist_dashboard_state(PDO $pdo, int $patientId): array
{
    $empty = [
        'active' => false,
        'status' => 'none',
        'id' => 0,
        'triage_id' => 0,
        'complaint' => '',
        'triage_level' => 'non_urgent',
        'waiting_since' => '',
        'waiting_since_label' => '',
        'assigned_provider_id' => 0,
        'assigned_provider_name' => '',
        'eligible_provider_id' => 0,
        'eligible_provider_name' => '',
        'care_tips_status' => '',
        'care_tips_label' => '',
        'reviewed_by_name' => '',
        'queue_position' => 0,
        'waiting_count' => 0,
        'has_scheduled_consultation' => false,
        'alternate_available' => false,
        'book_url' => '',
        'care_tips_url' => '',
        'consultations_url' => '',
    ];
    if ($patientId <= 0 || !patient_slot_waitlist_table_ready($pdo)) {
        return $empty;
    }

    if (patient_portal_has_open_consultation($pdo, $patientId)) {
        return $empty;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT w.*, tr.recommendation_status, tr.chief_complaint AS triage_complaint,
                   tr.assigned_provider_id AS triage_assigned_id,
                   tr.recommendation_approved_by
            FROM patient_slot_waitlist w
            LEFT JOIN triage_results tr ON tr.id = w.triage_result_id
            WHERE w.patient_id = ?
              AND w.status IN ('waiting', 'slot_available')
            ORDER BY w.waiting_since DESC, w.id DESC
            LIMIT 1
        ");
        $stmt->execute([$patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return $empty;
    }

    if (!$row) {
        return $empty;
    }

    $status = (string) ($row['status'] ?? 'waiting');
    $triageId = (int) ($row['triage_result_id'] ?? 0);
    $assignedId = (int) ($row['triage_assigned_id'] ?? $row['assigned_provider_id'] ?? 0);
    $eligibleId = (int) ($row['eligible_provider_id'] ?? 0);
    $eligibleName = trim((string) ($row['eligible_provider_name'] ?? ''));
    $assignedName = triage_provider_display_name($pdo, $assignedId);
    if ($eligibleName === '' && $eligibleId > 0) {
        $eligibleName = triage_provider_display_name($pdo, $eligibleId);
    }
    if ($eligibleName === '') {
        $eligibleName = $assignedName;
    }

    $recStatus = (string) ($row['recommendation_status'] ?? '');
    $reviewerId = (int) ($row['recommendation_approved_by'] ?? 0);
    $reviewerName = $reviewerId > 0 ? triage_provider_display_name($pdo, $reviewerId) : '';
    $careLabel = 'Pending Provider Review';
    if ($recStatus === 'approved') {
        $careLabel = $reviewerName !== ''
            ? ('Reviewed by ' . $reviewerName)
            : 'Reviewed by your provider';
    } elseif ($recStatus === 'rejected') {
        $careLabel = $reviewerName !== ''
            ? ('Reviewed by ' . $reviewerName . ' — guidance withheld')
            : 'Guidance withheld';
    } elseif ($recStatus !== 'pending_approval') {
        $careLabel = '';
    }

    $waitingSince = (string) ($row['waiting_since'] ?? '');
    $sinceLabel = '';
    if ($waitingSince !== '') {
        $ts = strtotime($waitingSince);
        if ($ts) {
            $sinceLabel = date('M j, Y g:i A', $ts);
        }
    }

    $queuePosition = 0;
    $waitingCount = 0;
    try {
        $waitingCount = (int) $pdo->query("
            SELECT COUNT(*) FROM patient_slot_waitlist WHERE status = 'waiting'
        ")->fetchColumn();
        if ($status === 'waiting' && $waitingSince !== '') {
            $pos = $pdo->prepare("
                SELECT COUNT(*) FROM patient_slot_waitlist
                WHERE status = 'waiting'
                  AND (waiting_since < ? OR (waiting_since = ? AND id <= ?))
            ");
            $pos->execute([$waitingSince, $waitingSince, (int) $row['id']]);
            $queuePosition = (int) $pos->fetchColumn();
        }
    } catch (PDOException $e) {
        $waitingCount = 0;
    }

    $asset = defined('ASSET_BASE') ? ASSET_BASE : '';
    $complaint = trim((string) ($row['complaint'] ?? ''));
    if ($complaint === '') {
        $complaint = trim((string) ($row['triage_complaint'] ?? ''));
    }

    $alternateAvailable = false;

    return [
        'active' => true,
        'status' => $status,
        'id' => (int) ($row['id'] ?? 0),
        'triage_id' => $triageId,
        'complaint' => $complaint,
        'triage_level' => (string) ($row['triage_level'] ?? 'non_urgent'),
        'waiting_since' => $waitingSince,
        'waiting_since_label' => $sinceLabel,
        'assigned_provider_id' => $assignedId,
        'assigned_provider_name' => $assignedName,
        'eligible_provider_id' => $eligibleId > 0 ? $eligibleId : $assignedId,
        'eligible_provider_name' => $eligibleName,
        'care_tips_status' => $recStatus,
        'care_tips_label' => $careLabel,
        'reviewed_by_name' => $reviewerName,
        'queue_position' => $queuePosition,
        'waiting_count' => $waitingCount,
        'has_scheduled_consultation' => false,
        'alternate_available' => $alternateAvailable,
        'book_url' => $asset . '/views/patient/triage.php' . ($triageId > 0 ? ('?triage_id=' . $triageId) : ''),
        'care_tips_url' => $asset . '/views/patient/my_health.php?tab=care-tips',
        'consultations_url' => $asset . '/views/patient/consultations.php',
    ];
}

/**
 * @param list<int> $triageIds
 * @return array<int, array<string, mixed>>
 */
function patient_slot_waitlist_map_for_triages(PDO $pdo, array $triageIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $triageIds))));
    if ($ids === [] || !patient_slot_waitlist_table_ready($pdo)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM patient_slot_waitlist
            WHERE triage_result_id IN ({$placeholders})
              AND status IN ('waiting', 'slot_available')
        ");
        $stmt->execute($ids);
    } catch (PDOException $e) {
        return [];
    }

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tid = (int) ($row['triage_result_id'] ?? 0);
        if ($tid > 0) {
            $map[$tid] = $row;
        }
    }

    return $map;
}

function patient_slot_waitlist_count_for_provider(PDO $pdo, int $providerId): int
{
    if ($providerId <= 0 || !patient_slot_waitlist_table_ready($pdo)) {
        return 0;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM patient_slot_waitlist
            WHERE status IN ('waiting', 'slot_available')
              AND (assigned_provider_id = ? OR eligible_provider_id = ?)
        ");
        $stmt->execute([$providerId, $providerId]);

        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function patient_slot_waitlist_fingerprint(PDO $pdo, int $patientId): string
{
    if ($patientId <= 0 || !patient_slot_waitlist_table_ready($pdo)) {
        return '0';
    }
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*), COALESCE(MAX(id),0),
                   COALESCE(MAX(UNIX_TIMESTAMP(updated_at)),0),
                   COALESCE(MAX(UNIX_TIMESTAMP(notified_at)),0),
                   GROUP_CONCAT(status ORDER BY id)
            FROM patient_slot_waitlist
            WHERE patient_id = ?
        ");
        $stmt->execute([$patientId]);
        $row = $stmt->fetch(PDO::FETCH_NUM);

        return $row ? implode(':', array_map(static fn ($v) => (string) ($v ?? '0'), $row)) : '0';
    } catch (Throwable $e) {
        return '0';
    }
}

function patient_slot_waitlist_provider_fingerprint(PDO $pdo, int $providerId): string
{
    if ($providerId <= 0 || !patient_slot_waitlist_table_ready($pdo)) {
        return '0';
    }
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*), COALESCE(MAX(id),0),
                   COALESCE(MAX(UNIX_TIMESTAMP(updated_at)),0),
                   COALESCE(SUM(CASE status WHEN 'waiting' THEN 1 WHEN 'slot_available' THEN 2 ELSE 0 END),0)
            FROM patient_slot_waitlist
            WHERE status IN ('waiting', 'slot_available')
              AND (assigned_provider_id = ? OR eligible_provider_id = ?)
        ");
        $stmt->execute([$providerId, $providerId]);
        $row = $stmt->fetch(PDO::FETCH_NUM);

        return $row ? implode(':', array_map(static fn ($v) => (string) ($v ?? '0'), $row)) : '0';
    } catch (Throwable $e) {
        return '0';
    }
}
