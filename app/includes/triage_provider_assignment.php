<?php
/**
 * Provider assignment for Care Tips review and consultation booking.
 *
 * NON-URGENT: real bookable slots today → weighted workload.
 * URGENT: earliest valid bookable slot today → weighted workload as tie-breaker.
 * EMERGENCY: no teleconsult assignment (existing hospital referral workflow).
 *
 * The assigned doctor is the same doctor who reviews Care Tips and sees the patient.
 */

declare(strict_types=1);

require_once __DIR__ . '/triage_assessment_schema.php';
require_once dirname(__DIR__) . '/core/TriageLevelService.php';

/**
 * Patient-facing label for a provider account (avoids blank / placeholder names).
 */
function triage_provider_display_name(PDO $pdo, int $providerId): string
{
    if ($providerId <= 0) {
        return '';
    }

    $stmt = $pdo->prepare('
        SELECT first_name, last_name, email
        FROM users
        WHERE id = ? AND role = \'provider\'
        LIMIT 1
    ');
    $stmt->execute([$providerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return '';
    }

    $first = trim((string) ($row['first_name'] ?? ''));
    $last = trim((string) ($row['last_name'] ?? ''));
    $full = trim($first . ' ' . $last);

    $isGeneric = $full === ''
        || preg_match('/^(healthcare\s+provider|health\s+care\s+provider|provider|doctor)$/i', $full);

    if (!$isGeneric) {
        return triage_provider_format_doctor_name($full);
    }

    $email = trim((string) ($row['email'] ?? ''));
    if ($email !== '' && str_contains($email, '@')) {
        $local = (string) explode('@', $email, 2)[0];
        $local = trim(str_replace(['.', '_', '-'], ' ', $local));
        if (strlen($local) > 2) {
            return triage_provider_format_doctor_name(ucwords(strtolower($local)));
        }
    }

    return 'Assigned doctor (ID ' . $providerId . ')';
}

function triage_provider_format_doctor_name(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    if ($name === '') {
        return '';
    }
    if (preg_match('/^dr\.?\s+/i', $name)) {
        return $name;
    }
    return 'Dr. ' . $name;
}

/**
 * @return array{provider_id:int,provider_name:string,locked:bool,triage_id:int,triage_level:string}
 */
function triage_patient_review_booking_context(PDO $pdo, int $patientId): array
{
    triage_assessment_ensure_schema($pdo);
    require_once __DIR__ . '/patient_booking_status.php';

    $empty = [
        'provider_id' => 0,
        'provider_name' => '',
        'locked' => false,
        'triage_id' => 0,
        'triage_level' => '',
    ];
    if ($patientId <= 0) {
        return $empty;
    }

    $stmt = $pdo->prepare("
        SELECT tr.id, tr.assigned_provider_id, tr.triage_level,
               CONCAT(u.first_name, ' ', u.last_name) AS provider_name
        FROM triage_results tr
        LEFT JOIN users u ON u.id = tr.assigned_provider_id
        WHERE tr.patient_id = ?
          AND tr.assigned_provider_id IS NOT NULL
          AND tr.assigned_provider_id > 0
          AND TRIM(COALESCE(tr.chief_complaint, '')) <> ''
          AND tr.assessed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
          AND (
            (" . preg_replace('/^\s*AND\s+/i', '', patient_triage_sql_active_only('tr')) . ")
            OR (
              LOWER(COALESCE(tr.triage_level, '')) = 'urgent'
              AND tr.assessed_at >= CURDATE()
              AND NOT EXISTS (
                SELECT 1 FROM consultations c_urg
                WHERE c_urg.patient_id = tr.patient_id
                  AND (
                    c_urg.triage_result_id = tr.id
                    OR TIMESTAMP(
                      c_urg.consult_date,
                      COALESCE(c_urg.consult_time, '23:59:59')
                    ) > tr.assessed_at
                  )
                  AND LOWER(COALESCE(c_urg.status, '')) IN (
                    'pending', 'scheduled', 'waiting', 'in_consultation', 'completed'
                  )
              )
            )
          )
        ORDER BY tr.assessed_at DESC
        LIMIT 1
    ");
    $stmt->execute([$patientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $pid = (int) ($row['assigned_provider_id'] ?? 0);
        if ($pid > 0) {
            $displayName = triage_provider_display_name($pdo, $pid);
            if ($displayName === '') {
                $displayName = trim((string) ($row['provider_name'] ?? ''));
            }

            return [
                'provider_id'   => $pid,
                'provider_name' => $displayName,
                'locked'        => true,
                'triage_id'     => (int) ($row['id'] ?? 0),
                'triage_level'  => triage_normalize_assignment_level((string) ($row['triage_level'] ?? '')),
            ];
        }
    }

    return triage_patient_waitlist_booking_lock($pdo, $patientId, $empty);
}

/**
 * Lock booking while a NON-URGENT case is waiting for a real slot (possibly unassigned).
 *
 * @param array{provider_id:int,provider_name:string,locked:bool,triage_id:int,triage_level:string} $empty
 * @return array{provider_id:int,provider_name:string,locked:bool,triage_id:int,triage_level:string}
 */
function triage_patient_waitlist_booking_lock(PDO $pdo, int $patientId, array $empty): array
{
    try {
        if ($pdo->query("SHOW TABLES LIKE 'patient_slot_waitlist'")->rowCount() === 0) {
            return $empty;
        }
        $stmt = $pdo->prepare("
            SELECT w.triage_result_id, w.status, w.assigned_provider_id, w.eligible_provider_id,
                   tr.assigned_provider_id AS triage_assigned_id, tr.triage_level
            FROM patient_slot_waitlist w
            LEFT JOIN triage_results tr ON tr.id = w.triage_result_id
            WHERE w.patient_id = ?
              AND w.status IN ('waiting', 'slot_available')
            ORDER BY w.id DESC
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

    $status = (string) ($row['status'] ?? '');
    $triageId = (int) ($row['triage_result_id'] ?? 0);
    $pid = (int) ($row['triage_assigned_id'] ?? 0);
    if ($pid <= 0) {
        $pid = (int) ($row['assigned_provider_id'] ?? 0);
    }
    if ($status === 'slot_available') {
        $eligible = (int) ($row['eligible_provider_id'] ?? 0);
        if ($eligible > 0) {
            $pid = $eligible;
        }
    }

    return [
        'provider_id'   => $pid,
        'provider_name' => $pid > 0 ? triage_provider_display_name($pdo, $pid) : '',
        'locked'        => true,
        'triage_id'     => $triageId,
        'triage_level'  => triage_normalize_assignment_level((string) ($row['triage_level'] ?? 'non_urgent')),
    ];
}

function triage_normalize_assignment_level(string $level): string
{
    $level = strtolower(trim(str_replace(['-', ' '], '_', $level)));
    if ($level === 'nonurgent' || $level === '') {
        $level = TriageLevelService::NON_URGENT;
    }

    return TriageLevelService::isValid($level) ? $level : TriageLevelService::NON_URGENT;
}

/**
 * Weighted workload from existing tables (never invents work).
 * Pending Care Tips reviews, today's booked consults, live consults, and waiters.
 */
function triage_provider_weighted_workload(PDO $pdo, int $providerId): int
{
    if ($providerId <= 0) {
        return 0;
    }

    $reviews = 0;
    $todayConsults = 0;
    $inConsult = 0;
    $waiters = 0;

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM triage_results
            WHERE assigned_provider_id = ?
              AND recommendation_status = 'pending_approval'
              AND assessed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute([$providerId]);
        $reviews = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        $reviews = 0;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM consultations
            WHERE provider_id = ?
              AND consult_date = CURDATE()
              AND status IN ('scheduled', 'pending', 'waiting')
        ");
        $stmt->execute([$providerId]);
        $todayConsults = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        $todayConsults = 0;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM consultations
            WHERE provider_id = ?
              AND status = 'in_consultation'
        ");
        $stmt->execute([$providerId]);
        $inConsult = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        $inConsult = 0;
    }

    try {
        if ($pdo->query("SHOW TABLES LIKE 'patient_slot_waitlist'")->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM patient_slot_waitlist
                WHERE assigned_provider_id = ?
                  AND status IN ('waiting', 'slot_available')
            ");
            $stmt->execute([$providerId]);
            $waiters = (int) $stmt->fetchColumn();
        }
    } catch (PDOException $e) {
        $waiters = 0;
    }

    return ($reviews * 2) + ($todayConsults * 3) + ($inConsult * 4) + $waiters;
}

/**
 * @return list<int>
 */
function triage_active_provider_ids(PDO $pdo): array
{
    try {
        $ids = $pdo->query("
            SELECT id FROM users
            WHERE role = 'provider' AND is_active = 1
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $e) {
        return [];
    }

    $out = [];
    foreach ($ids as $raw) {
        $id = (int) $raw;
        if ($id > 0) {
            $out[] = $id;
        }
    }

    return $out;
}

function triage_provider_is_active(PDO $pdo, int $providerId): bool
{
    if ($providerId <= 0) {
        return false;
    }
    $chk = $pdo->prepare("
        SELECT id FROM users
        WHERE id = ? AND role = 'provider' AND is_active = 1
        LIMIT 1
    ");
    $chk->execute([$providerId]);

    return (bool) $chk->fetchColumn();
}

/**
 * Real earliest bookable slot today (schedule + generated slots + current time).
 *
 * @return array{id:int,slot_date:string,start_time:string,end_time:string}|null
 */
function triage_provider_earliest_bookable_slot_today(PDO $pdo, int $providerId): ?array
{
    if ($providerId <= 0) {
        return null;
    }
    require_once __DIR__ . '/appointment_slots.php';
    appointment_slots_sync_today($pdo, $providerId);
    $bookable = appointment_slots_bookable_sql('s');
    $stmt = $pdo->prepare("
        SELECT s.id, s.slot_date, s.start_time, s.end_time
        FROM appointment_slots s
        WHERE s.provider_id = ?
          AND s.status = 'available'
          AND {$bookable}
        ORDER BY s.start_time ASC, s.id ASC
        LIMIT 1
    ");
    $stmt->execute([$providerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $start = (string) ($row['start_time'] ?? '');
    $end = (string) ($row['end_time'] ?? '');
    $date = (string) ($row['slot_date'] ?? '');
    if (!appointment_slot_is_bookable($date, $start, $end)) {
        return null;
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'slot_date' => $date,
        'start_time' => $start,
        'end_time' => $end,
    ];
}

/**
 * Providers who currently have at least one real bookable slot today.
 *
 * @return list<array{provider_id:int,slot_count:int,earliest_start:string,workload:int}>
 */
function triage_collect_bookable_candidates(PDO $pdo, int $excludeProviderId = 0): array
{
    $out = [];
    foreach (triage_active_provider_ids($pdo) as $id) {
        if ($id === $excludeProviderId) {
            continue;
        }
        $count = triage_provider_bookable_slot_count_today($pdo, $id);
        if ($count <= 0) {
            continue;
        }
        $earliest = triage_provider_earliest_bookable_slot_today($pdo, $id);
        if ($earliest === null) {
            continue;
        }
        $out[] = [
            'provider_id' => $id,
            'slot_count' => $count,
            'earliest_start' => (string) ($earliest['start_time'] ?? ''),
            'workload' => triage_provider_weighted_workload($pdo, $id),
        ];
    }

    return $out;
}

/**
 * Pure selector used by live assignment and tests. Never picks a doctor with no slots.
 *
 * @param list<array{provider_id:int,slot_count?:int,earliest_start?:string,workload?:int}> $candidates
 */
function triage_select_provider_from_candidates(string $level, array $candidates): int
{
    $level = triage_normalize_assignment_level($level);
    if ($level === TriageLevelService::EMERGENCY) {
        return 0;
    }

    $eligible = [];
    foreach ($candidates as $row) {
        $id = (int) ($row['provider_id'] ?? 0);
        $slots = (int) ($row['slot_count'] ?? 0);
        if ($id <= 0 || $slots <= 0) {
            continue;
        }
        $eligible[] = [
            'provider_id' => $id,
            'earliest_start' => (string) ($row['earliest_start'] ?? ''),
            'workload' => (int) ($row['workload'] ?? 0),
        ];
    }
    if ($eligible === []) {
        return 0;
    }

    usort($eligible, static function (array $a, array $b) use ($level): int {
        if ($level === TriageLevelService::URGENT) {
            $ta = (string) $a['earliest_start'];
            $tb = (string) $b['earliest_start'];
            if ($ta !== '' && $tb !== '' && $ta !== $tb) {
                return $ta <=> $tb;
            }
            if ($ta !== $tb) {
                if ($ta === '') {
                    return 1;
                }
                if ($tb === '') {
                    return -1;
                }
            }
        }
        $load = ((int) $a['workload']) <=> ((int) $b['workload']);
        if ($load !== 0) {
            return $load;
        }

        return ((int) $a['provider_id']) <=> ((int) $b['provider_id']);
    });

    return (int) $eligible[0]['provider_id'];
}

/**
 * Select one doctor for this triage level. Returns 0 when nobody can provide care now.
 */
function triage_select_provider_for_level(
    PDO $pdo,
    int $patientId,
    string $triageLevel,
    int $excludeProviderId = 0,
    int $preferProviderId = 0
): int {
    unset($patientId);
    triage_assessment_ensure_schema($pdo);
    $level = triage_normalize_assignment_level($triageLevel);
    if ($level === TriageLevelService::EMERGENCY) {
        return 0;
    }

    if (
        $preferProviderId > 0
        && $preferProviderId !== $excludeProviderId
        && triage_provider_is_active($pdo, $preferProviderId)
        && triage_provider_bookable_slot_count_today($pdo, $preferProviderId) > 0
        && $level === TriageLevelService::NON_URGENT
    ) {
        return $preferProviderId;
    }

    return triage_select_provider_from_candidates(
        $level,
        triage_collect_bookable_candidates($pdo, $excludeProviderId)
    );
}

/**
 * NON-URGENT Care Tips assignment: available doctors only, then weighted workload.
 * Returns 0 when no doctor has a real bookable slot (caller must use the waiting queue).
 */
function triage_assign_review_provider(PDO $pdo, int $patientId, int $preferProviderId = 0): int
{
    return triage_select_provider_for_level(
        $pdo,
        $patientId,
        TriageLevelService::NON_URGENT,
        0,
        $preferProviderId
    );
}

function triage_fallback_provider_id(PDO $pdo, int $patientId): int
{
    if (function_exists('patient_resolve_provider_id')) {
        $resolved = (int) patient_resolve_provider_id($pdo, $patientId);
        if ($resolved > 0) {
            return $resolved;
        }
    }

    return (int) ($pdo->query("
        SELECT id FROM users
        WHERE role = 'provider' AND is_active = 1
        ORDER BY id ASC
        LIMIT 1
    ")->fetchColumn() ?: 0);
}

function triage_bind_assigned_provider(PDO $pdo, int $triageId, int $providerId): void
{
    if ($triageId <= 0 || $providerId <= 0) {
        return;
    }
    triage_assessment_ensure_schema($pdo);
    $pdo->prepare("
        UPDATE triage_results
        SET assigned_provider_id = ?,
            assigned_at = COALESCE(assigned_at, NOW())
        WHERE id = ?
    ")->execute([$providerId, $triageId]);
}

/**
 * Count bookable (today, future time) slots for a provider.
 */
function triage_provider_bookable_slot_count_today(PDO $pdo, int $providerId): int
{
    if ($providerId <= 0) {
        return 0;
    }
    require_once __DIR__ . '/appointment_slots.php';
    appointment_slots_sync_today($pdo, $providerId);
    $bookable = appointment_slots_bookable_sql('s');
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM appointment_slots s
        WHERE s.provider_id = ?
          AND s.status = 'available'
          AND {$bookable}
    ");
    $stmt->execute([$providerId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Active provider (excluding one) with bookable slots today, using NON-URGENT assignment rules.
 */
function triage_find_provider_with_slots_today(PDO $pdo, int $excludeProviderId = 0): int
{
    return triage_select_provider_for_level(
        $pdo,
        0,
        TriageLevelService::NON_URGENT,
        $excludeProviderId
    );
}

/**
 * Booking context + today's slot availability for care-tips-locked patients.
 *
 * @return array{
 *   locked:bool,
 *   provider_id:int,
 *   provider_name:string,
 *   triage_id:int,
 *   assigned_has_slots_today:bool,
 *   alternate_available:bool,
 *   triage_level:string
 * }
 */
function triage_patient_booking_slot_status(PDO $pdo, int $patientId): array
{
    $ctx = triage_patient_review_booking_context($pdo, $patientId);
    $providerId = (int) ($ctx['provider_id'] ?? 0);
    $out = [
        'locked' => !empty($ctx['locked']),
        'provider_id' => $providerId,
        'provider_name' => trim((string) ($ctx['provider_name'] ?? '')),
        'triage_id' => (int) ($ctx['triage_id'] ?? 0),
        'assigned_has_slots_today' => true,
        'alternate_available' => false,
        'triage_level' => (string) ($ctx['triage_level'] ?? ''),
    ];
    if (!$out['locked']) {
        return $out;
    }
    if ($providerId <= 0) {
        $out['assigned_has_slots_today'] = false;
        $out['alternate_available'] = false;

        return $out;
    }
    if ($out['provider_name'] === '') {
        $out['provider_name'] = triage_provider_display_name($pdo, $providerId);
    }
    $out['assigned_has_slots_today'] = triage_provider_bookable_slot_count_today($pdo, $providerId) > 0;
    if (!$out['assigned_has_slots_today']) {
        $out['alternate_available'] = triage_find_provider_with_slots_today($pdo, $providerId) > 0;
    }

    return $out;
}

/**
 * Patient-initiated reassignment when assigned doctor has no slots today.
 *
 * @return array{ok:bool,message:string,provider_id?:int,provider_name?:string}
 */
function triage_patient_request_alternate_booking_provider(PDO $pdo, int $patientId): array
{
    triage_assessment_ensure_schema($pdo);
    $status = triage_patient_booking_slot_status($pdo, $patientId);
    if (!$status['locked'] || $status['triage_id'] <= 0) {
        return ['ok' => false, 'message' => 'No active care tips assignment to update.'];
    }

    $triageId = (int) $status['triage_id'];
    $currentId = (int) $status['provider_id'];
    if ($status['assigned_has_slots_today']) {
        return [
            'ok' => false,
            'message' => 'Your assigned doctor still has open slots today. Refresh this page to book.',
        ];
    }

    $level = (string) ($status['triage_level'] ?? '');
    if ($level === '' && $triageId > 0) {
        try {
            $lvlStmt = $pdo->prepare('SELECT triage_level FROM triage_results WHERE id = ? LIMIT 1');
            $lvlStmt->execute([$triageId]);
            $level = (string) ($lvlStmt->fetchColumn() ?: '');
        } catch (PDOException $e) {
            $level = '';
        }
    }
    $newId = triage_select_provider_for_level($pdo, $patientId, $level, $currentId);
    if ($newId <= 0) {
        return [
            'ok' => false,
            'message' => 'No other doctor has open clinic slots today. Please contact the City Health Office or try again tomorrow.',
        ];
    }

    $prov = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'provider' AND is_active = 1 LIMIT 1");
    $prov->execute([$newId]);
    if (!$prov->fetchColumn()) {
        return ['ok' => false, 'message' => 'Selected doctor is no longer available.'];
    }

    triage_bind_assigned_provider($pdo, $triageId, $newId);

    try {
        require_once __DIR__ . '/patient_slot_waitlist.php';
        if (patient_slot_waitlist_table_ready($pdo)) {
            $displayName = triage_provider_display_name($pdo, $newId);
            $pdo->prepare("
                UPDATE patient_slot_waitlist
                SET assigned_provider_id = ?,
                    eligible_provider_id = ?,
                    eligible_provider_name = ?,
                    status = 'waiting',
                    waiting_since = NOW(),
                    slot_available_at = NULL,
                    notified_at = NULL,
                    availability_key = NULL,
                    updated_at = NOW()
                WHERE patient_id = ?
                  AND triage_result_id = ?
                  AND status IN ('waiting', 'slot_available')
            ")->execute([
                $newId,
                $newId,
                $displayName !== '' ? $displayName : null,
                $patientId,
                $triageId,
            ]);
            patient_slot_waitlist_after_slots_changed($pdo);
        }
    } catch (Throwable $e) {
        error_log('triage_patient_request_alternate_booking_provider waitlist: ' . $e->getMessage());
    }

    require_once __DIR__ . '/audit_log.php';
    audit_log($pdo, [
        'patient_id' => $patientId,
        'action' => 'triage_patient_alternate_provider',
        'description' => "Patient #{$patientId} reassigned triage #{$triageId} from provider #{$currentId} to #{$newId} (no slots today).",
    ]);

    require_once __DIR__ . '/notification_events.php';
    $nameStmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) FROM users WHERE id = ? LIMIT 1');
    $nameStmt->execute([$patientId]);
    $patientName = trim((string) ($nameStmt->fetchColumn() ?: 'Patient'));
    NotificationEvents::aiSelfCareReviewRequired($pdo, $newId, $patientId, $patientName, $triageId, $patientId);

    $display = triage_provider_display_name($pdo, $newId);

    return [
        'ok' => true,
        'message' => 'You are now assigned to ' . $display . ' for today\'s booking. Care tips review will continue with this doctor.',
        'provider_id' => $newId,
        'provider_name' => $display,
    ];
}

/**
 * Enforce same-doctor booking after AI review assignment.
 */
function triage_assert_patient_may_book_provider(PDO $pdo, int $patientId, int $providerId): void
{
    try {
        if ($pdo->query("SHOW TABLES LIKE 'patient_slot_waitlist'")->rowCount() > 0) {
            $wait = $pdo->prepare("
                SELECT status, eligible_provider_id
                FROM patient_slot_waitlist
                WHERE patient_id = ?
                  AND status IN ('waiting', 'slot_available')
                ORDER BY id DESC
                LIMIT 1
            ");
            $wait->execute([$patientId]);
            $waitRow = $wait->fetch(PDO::FETCH_ASSOC);
            if ($waitRow && (string) ($waitRow['status'] ?? '') === 'waiting') {
                throw new RuntimeException(
                    'You are in the waiting queue. We will notify you when a consultation slot becomes available.'
                );
            }
        }
    } catch (RuntimeException $e) {
        throw $e;
    } catch (Throwable $e) {
        // keep assignment lock checks
    }

    $ctx = triage_patient_review_booking_context($pdo, $patientId);
    if (empty($ctx['locked']) || (int) $ctx['provider_id'] <= 0) {
        return;
    }
    if ((int) $ctx['provider_id'] === $providerId) {
        return;
    }

    try {
        require_once __DIR__ . '/patient_slot_waitlist.php';
        if (patient_slot_waitlist_patient_may_book_provider($pdo, $patientId, $providerId)) {
            return;
        }
    } catch (Throwable $e) {
        // keep assigned-doctor lock
    }

    $name = trim((string) ($ctx['provider_name'] ?? ''));
    if ($name === '') {
        $name = 'the doctor assigned to review your case';
    }

    throw new RuntimeException(
        'Please book your consultation with ' . $name
        . ', who reviewed your self-care guidance. Contact an administrator if you need a different provider.'
    );
}

/**
 * @return list<string>
 */
function triage_assessment_suggested_questions(?string $assessmentPayloadJson): array
{
    $payload = json_decode((string) $assessmentPayloadJson, true);
    if (!is_array($payload)) {
        return [];
    }

    $out = [];
    foreach (['suggested_questions', 'clarifying_questions', 'follow_up_questions'] as $key) {
        if (!empty($payload[$key]) && is_array($payload[$key])) {
            foreach ($payload[$key] as $q) {
                $text = is_string($q) ? trim($q) : trim((string) ($q['text'] ?? $q['question'] ?? ''));
                if ($text !== '') {
                    $out[] = $text;
                }
            }
        }
    }

    $ml = $payload['ml_layer'] ?? null;
    if (is_array($ml) && !empty($ml['precautions']) && is_array($ml['precautions'])) {
        foreach ($ml['precautions'] as $p) {
            $text = is_string($p) ? trim($p) : trim((string) ($p['text'] ?? ''));
            if ($text !== '') {
                $out[] = 'Clarify: ' . $text;
            }
        }
    }

    $complaint = trim((string) ($payload['chief_complaint'] ?? ''));
    if ($complaint !== '' && $out === []) {
        $out[] = 'How long have you had these symptoms?';
        $out[] = 'Have your symptoms worsened or improved since onset?';
        $out[] = 'Any fever, chest pain, difficulty breathing, or other red-flag symptoms?';
    }

    return array_values(array_unique($out));
}
