<?php
declare(strict_types=1);

/**
 * Patient booking status — shared by sidebar badges, visit history, and booking workflow.
 */

require_once __DIR__ . '/triage_assessment_schema.php';

/**
 * SQL fragment: triage row is still part of the patient's active workflow.
 * Historical rows (completed/cancelled visits, hidden/rejected) are excluded.
 *
 * Note: `approved` care tips may still be readable in the Care Tips chatbot,
 * but once a visit for that case was booked and then finished/cancelled, the
 * triage must not keep blocking a brand-new chief complaint cycle.
 */
function patient_triage_sql_active_only(string $alias = 'tr'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'tr';

    // Active care-tips triage only while doctor review is open / tips are current
    // and no visit has already been scheduled / started / finished / cancelled.
    return "
          AND COALESCE({$a}.recommendation_status, 'hidden') IN ('pending_approval', 'approved')
          AND NOT EXISTS (
            SELECT 1
            FROM consultations c_link
            WHERE c_link.patient_id = {$a}.patient_id
              AND (
                c_link.triage_result_id = {$a}.id
                OR TIMESTAMP(
                  c_link.consult_date,
                  COALESCE(c_link.consult_time, '23:59:59')
                ) > {$a}.assessed_at
              )
              AND LOWER(COALESCE(c_link.status, '')) IN (
                'pending', 'scheduled', 'waiting', 'in_consultation',
                'completed', 'cancelled', 'canceled'
              )
          )
    ";
}

/**
 * Pick the patient's current active consultation using explicit status priority.
 * Prefer live visits, then the soonest scheduled/open visit — never latest-created alone.
 * Past-start scheduled/pending visits are excluded unless the visit is in progress.
 *
 * @param list<array<string, mixed>> $consults
 * @return array<string, mixed>|null
 */
function patient_portal_select_active_consultation(array $consults): ?array
{
    $openStatuses = ['pending', 'scheduled', 'waiting', 'in_consultation'];
    $open = [];
    foreach ($consults as $c) {
        $status = strtolower(trim((string) ($c['status'] ?? '')));
        if (!in_array($status, $openStatuses, true)) {
            continue;
        }
        // Only keep consultations that still belong to the current cycle.
        if (!patient_consultation_keeps_chief_complaint_locked($c)) {
            continue;
        }
        $open[] = $c;
    }
    if ($open === []) {
        return null;
    }

    foreach ($open as $c) {
        if (strtolower((string) ($c['status'] ?? '')) === 'in_consultation') {
            return $c;
        }
    }

    usort($open, static function (array $a, array $b): int {
        $ta = patient_consultation_schedule_start_ts($a) ?? PHP_INT_MAX;
        $tb = patient_consultation_schedule_start_ts($b) ?? PHP_INT_MAX;
        if ($ta === $tb) {
            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        }

        return $ta <=> $tb;
    });

    return $open[0];
}

/**
 * Unix timestamp for a consultation's scheduled start (Asia/Manila via APP_TIMEZONE).
 *
 * @param array<string, mixed> $consult
 */
function patient_consultation_schedule_start_ts(array $consult): ?int
{
    $date = substr(trim((string) ($consult['consult_date'] ?? $consult['slot_date'] ?? '')), 0, 10);
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }
    $time = trim((string) ($consult['consult_time'] ?? $consult['slot_start'] ?? $consult['start_time'] ?? '00:00:00'));
    if ($time === '') {
        $time = '00:00:00';
    }
    if (preg_match('/^\d{2}:\d{2}$/', $time)) {
        $time .= ':00';
    }
    $time = substr($time, 0, 8);
    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
        return null;
    }

    $tzName = defined('APP_TIMEZONE') ? (string) APP_TIMEZONE : 'Asia/Manila';
    try {
        $tz = new DateTimeZone($tzName);
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' ' . $time, $tz);
        if (!$dt instanceof DateTimeImmutable) {
            return null;
        }

        return $dt->getTimestamp();
    } catch (Throwable $e) {
        $ts = strtotime($date . ' ' . $time);

        return $ts === false ? null : $ts;
    }
}

/**
 * Whether this consultation should keep the patient's chief complaint locked.
 * - IN_PROGRESS: always locked
 * - SCHEDULED/PENDING before start: locked
 * - SCHEDULED/PENDING after start (missed / waiting past due): unlocked for a NEW consultation
 * - COMPLETED/CANCELLED: unlocked
 *
 * @param array<string, mixed> $consult
 */
function patient_consultation_keeps_chief_complaint_locked(array $consult): bool
{
    $status = strtolower(trim((string) ($consult['status'] ?? '')));
    if ($status === 'in_consultation') {
        return true;
    }
    if (!in_array($status, ['pending', 'scheduled', 'waiting'], true)) {
        return false;
    }

    $startTs = patient_consultation_schedule_start_ts($consult);
    if ($startTs === null) {
        // Missing schedule data — treat as still open to avoid accidental unlock mid-flow.
        return true;
    }

    return $startTs > time();
}

/**
 * Whether the patient currently has an open consultation that should block
 * starting a brand-new chief-complaint / triage cycle.
 */
function patient_portal_has_open_consultation(PDO $pdo, int $patientId): bool
{
    if ($patientId <= 0) {
        return false;
    }
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
            return false;
        }
        $stmt = $pdo->prepare("
            SELECT id, status, consult_date, consult_time
            FROM consultations
            WHERE patient_id = ?
              AND LOWER(COALESCE(status, '')) IN (
                'pending', 'scheduled', 'waiting', 'in_consultation'
              )
        ");
        $stmt->execute([$patientId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (patient_consultation_keeps_chief_complaint_locked($row)) {
                return true;
            }
        }

        return false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Whether the patient has a finished visit or a past-due scheduled visit
 * that should no longer seed/lock a chief complaint.
 */
function patient_portal_has_stale_or_finished_consultation(PDO $pdo, int $patientId): bool
{
    if ($patientId <= 0) {
        return false;
    }
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
            return false;
        }
        $stmt = $pdo->prepare("
            SELECT id, status, consult_date, consult_time
            FROM consultations
            WHERE patient_id = ?
            ORDER BY id DESC
            LIMIT 20
        ");
        $stmt->execute([$patientId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $status = strtolower(trim((string) ($row['status'] ?? '')));
            if (in_array($status, ['completed', 'cancelled', 'canceled'], true)) {
                return true;
            }
            if (in_array($status, ['pending', 'scheduled', 'waiting'], true)
                && !patient_consultation_keeps_chief_complaint_locked($row)) {
                return true;
            }
        }

        return false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Whether the patient has at least one completed consultation.
 */
function patient_portal_has_completed_visit(PDO $pdo, int $patientId): bool
{
    if ($patientId <= 0) {
        return false;
    }
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
            return false;
        }
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM consultations
            WHERE patient_id = ?
              AND LOWER(COALESCE(status, '')) = 'completed'
        ");
        $stmt->execute([$patientId]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Whether the patient has a provider-scheduled follow-up task (followups table).
 */
function patient_portal_has_scheduled_followup(PDO $pdo, int $patientId): bool
{
    if ($patientId <= 0) {
        return false;
    }
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'followups'")->rowCount()) {
            return false;
        }
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM followups
            WHERE patient_id = ?
              AND LOWER(COALESCE(status, '')) = 'scheduled'
              AND followup_date >= CURDATE()
        ");
        $stmt->execute([$patientId]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Whether an open consultation can be updated in-place (same-day slot change for the same case).
 *
 * @param array<string, mixed> $consult
 */
function patient_consultation_may_be_rebooked_in_place(array $consult, int $newTriageId): bool
{
    if ($newTriageId <= 0) {
        return false;
    }
    if (strtolower((string) ($consult['status'] ?? '')) === 'in_consultation') {
        return false;
    }
    if (consultation_is_future_day((string) ($consult['consult_date'] ?? ''))) {
        return false;
    }

    $linkedTriage = (int) ($consult['triage_result_id'] ?? 0);

    return $linkedTriage <= 0 || $linkedTriage === $newTriageId;
}

/**
 * Active visit booked for today or a future date.
 */
function patient_portal_has_upcoming_consultation(PDO $pdo, int $patientId): bool
{
    if ($patientId <= 0) {
        return false;
    }
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
            return false;
        }
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM consultations
            WHERE patient_id = ?
              AND consult_date >= CURDATE()
              AND LOWER(COALESCE(status, '')) IN (
                'pending', 'scheduled', 'waiting', 'in_consultation'
              )
        ");
        $stmt->execute([$patientId]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Whether a triage row already has a linked consultation on or after its assessment date.
 *
 * @return 'booked'|'completed'|'none'
 */
function patient_triage_row_booking_state(PDO $pdo, int $patientId, string $assessedAt, int $triageId = 0): string
{
    if ($patientId <= 0 || trim($assessedAt) === '') {
        return 'none';
    }
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
            return 'none';
        }

        if ($triageId > 0) {
            $linked = $pdo->prepare("
                SELECT LOWER(COALESCE(status, '')) AS status
                FROM consultations
                WHERE patient_id = ?
                  AND triage_result_id = ?
                  AND LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'canceled')
                ORDER BY consult_date DESC, consult_time DESC
                LIMIT 1
            ");
            $linked->execute([$patientId, $triageId]);
            $linkedStatus = (string) ($linked->fetchColumn() ?: '');
            if (in_array($linkedStatus, ['pending', 'scheduled', 'waiting', 'in_consultation'], true)) {
                return 'booked';
            }
            if ($linkedStatus === 'completed') {
                return 'completed';
            }
        }

        $stmt = $pdo->prepare("
            SELECT LOWER(COALESCE(status, '')) AS status
            FROM consultations
            WHERE patient_id = ?
              AND consult_date >= DATE(?)
              AND LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'canceled')
            ORDER BY consult_date DESC, consult_time DESC
            LIMIT 1
        ");
        $stmt->execute([$patientId, $assessedAt]);
        $status = (string) ($stmt->fetchColumn() ?: '');

        if (in_array($status, ['pending', 'scheduled', 'waiting', 'in_consultation'], true)) {
            return 'booked';
        }
        if ($status === 'completed') {
            return 'completed';
        }
    } catch (Throwable $e) {
        return 'none';
    }

    return 'none';
}

/**
 * Close triage / care-tips cases when a consultation visit is completed.
 * Keeps rows in history but removes them from the active booking workflow.
 */
function patient_triage_close_cases_for_consultation(PDO $pdo, int $consultationId): void
{
    if ($consultationId <= 0) {
        return;
    }

    triage_assessment_ensure_schema($pdo);

    try {
        if (!$pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
            return;
        }

        $stmt = $pdo->prepare('
            SELECT patient_id, triage_result_id
            FROM consultations
            WHERE id = ?
            LIMIT 1
        ');
        $stmt->execute([$consultationId]);
        $consult = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$consult) {
            return;
        }

        $patientId = (int) ($consult['patient_id'] ?? 0);
        $triageIds = [];
        $linkedTriageId = (int) ($consult['triage_result_id'] ?? 0);
        if ($linkedTriageId > 0) {
            $triageIds[$linkedTriageId] = true;
        }

        if ($pdo->query("SHOW TABLES LIKE 'patient_chief_complaints'")->rowCount()) {
            require_once __DIR__ . '/patient_chief_complaints.php';
            patient_chief_complaints_ensure_schema($pdo);
            $pcc = $pdo->prepare('
                SELECT triage_result_id
                FROM patient_chief_complaints
                WHERE consultation_id = ?
                  AND triage_result_id IS NOT NULL
                  AND triage_result_id > 0
            ');
            $pcc->execute([$consultationId]);
            while ($row = $pcc->fetch(PDO::FETCH_ASSOC)) {
                $tid = (int) ($row['triage_result_id'] ?? 0);
                if ($tid > 0) {
                    $triageIds[$tid] = true;
                }
            }
        }

        if ($triageIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($triageIds), '?'));
        $params = array_merge(array_keys($triageIds), [$patientId]);
        $pdo->prepare("
            UPDATE triage_results
            SET recommendation_status = 'hidden',
                outcome = CASE
                    WHEN COALESCE(outcome, '') IN ('', 'consultation_booked') THEN 'visit_completed'
                    ELSE outcome
                END
            WHERE id IN ({$placeholders})
              AND patient_id = ?
        ")->execute($params);
    } catch (Throwable $e) {
        // non-fatal
    }
}

/**
 * Latest triage row that can still drive booking / care tips for a patient.
 *
 * @return array<string, mixed>|null
 */
function patient_portal_find_active_triage_row(PDO $pdo, int $patientId): ?array
{
    if ($patientId <= 0) {
        return null;
    }

    triage_assessment_ensure_schema($pdo);

    try {
        $sql = "
            SELECT id, chief_complaint, triage_level, triage_classification, urgency_label, assessed_at,
                   recommendation_status
            FROM triage_results tr
            WHERE tr.patient_id = ?
              AND TRIM(COALESCE(tr.chief_complaint, '')) <> ''
              " . patient_triage_sql_active_only('tr') . "
            ORDER BY tr.assessed_at DESC, tr.id DESC
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Whether a patient_chief_complaints row still belongs to an active case.
 */
function patient_chief_complaint_row_is_active(PDO $pdo, int $patientId, array $row): bool
{
    if ($patientId <= 0) {
        return false;
    }

    $consultationId = (int) ($row['consultation_id'] ?? 0);
    if ($consultationId > 0) {
        try {
            $stmt = $pdo->prepare("
                SELECT id, status, consult_date, consult_time
                FROM consultations
                WHERE id = ? AND patient_id = ?
                LIMIT 1
            ");
            $stmt->execute([$consultationId, $patientId]);
            $consult = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$consult) {
                return false;
            }

            return patient_consultation_keeps_chief_complaint_locked($consult);
        } catch (Throwable $e) {
            return false;
        }
    }

    $triageId = (int) ($row['triage_result_id'] ?? 0);
    if ($triageId > 0) {
        try {
            $stmt = $pdo->prepare('
                SELECT recommendation_status, assessed_at
                FROM triage_results
                WHERE id = ? AND patient_id = ?
                LIMIT 1
            ');
            $stmt->execute([$triageId, $patientId]);
            $triage = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$triage) {
                return false;
            }

            $recStatus = strtolower(trim((string) ($triage['recommendation_status'] ?? '')));
            // Approved tips without an open consultation no longer lock a new cycle.
            if ($recStatus === 'approved' && !patient_portal_has_open_consultation($pdo, $patientId)) {
                return false;
            }

            $active = patient_portal_find_active_triage_row($pdo, $patientId);

            return $active && (int) ($active['id'] ?? 0) === $triageId;
        } catch (Throwable $e) {
            return false;
        }
    }

    return false;
}
