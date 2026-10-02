<?php
/**
 * Queue timing: the appointment slot is the patient's place in line, not a hard stop.
 *
 * Actual start/end stay on video_sessions.started_at / ended_at.
 * The booked slot start/end are not rewritten when a visit runs long.
 */
declare(strict_types=1);

require_once __DIR__ . '/appointment_slots.php';

function consultation_timing_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $videoCols = $pdo->query('SHOW COLUMNS FROM video_sessions')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('patient_joined_at', $videoCols, true)) {
            $pdo->exec('ALTER TABLE video_sessions ADD COLUMN patient_joined_at DATETIME NULL DEFAULT NULL AFTER patient_left_at');
        }
    } catch (Throwable $e) {
        try {
            $videoCols = $pdo->query('SHOW COLUMNS FROM video_sessions')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (!in_array('patient_joined_at', $videoCols, true)) {
                $pdo->exec('ALTER TABLE video_sessions ADD COLUMN patient_joined_at DATETIME NULL DEFAULT NULL');
            }
        } catch (Throwable $e2) {
            error_log('consultation_timing patient_joined_at: ' . $e2->getMessage());
        }
    }

    $consultCols = [
        'early_start_offered_at' => 'DATETIME NULL DEFAULT NULL',
        'early_start_response' => 'VARCHAR(20) NULL DEFAULT NULL',
        'early_start_responded_at' => 'DATETIME NULL DEFAULT NULL',
    ];
    try {
        $have = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($consultCols as $name => $ddl) {
            if (!in_array($name, $have, true)) {
                $pdo->exec('ALTER TABLE consultations ADD COLUMN ' . $name . ' ' . $ddl);
            }
        }
    } catch (Throwable $e) {
        error_log('consultation_timing early-start columns: ' . $e->getMessage());
    }
}

function consultation_timing_parse_ts(?string $date, ?string $time): ?int
{
    $date = trim((string) $date);
    $time = trim((string) $time);
    if ($date === '') {
        return null;
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $date, $m)) {
        $date = $m[1];
    }
    if ($time === '') {
        $time = '00:00:00';
    } elseif (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
        $time .= ':00';
    }
    $ts = strtotime($date . ' ' . $time);

    return $ts ?: null;
}

/**
 * @param array<string, mixed> $row
 * @return array{start:?int,end:?int}
 */
function consultation_timing_slot_bounds(array $row): array
{
    $date = (string) ($row['slot_date'] ?? $row['consult_date'] ?? '');
    $startTime = (string) ($row['slot_start'] ?? $row['start_time'] ?? $row['consult_time'] ?? '');
    $endTime = (string) ($row['slot_end'] ?? $row['end_time'] ?? '');
    $start = consultation_timing_parse_ts($date, $startTime);
    $end = $endTime !== '' ? consultation_timing_parse_ts($date, $endTime) : null;
    if ($end === null && $start !== null) {
        $end = $start + (30 * 60);
    }

    return ['start' => $start, 'end' => $end];
}

function consultation_timing_patient_ever_joined(array $row): bool
{
    return trim((string) ($row['patient_joined_at'] ?? '')) !== '';
}

/**
 * Pure rule: a patient who never entered during their slot, and was not held
 * behind an earlier visit, cannot join after the slot ends.
 *
 * @return array{missed:bool,blocked:bool}
 */
function consultation_timing_missed_decision(
    int $now,
    ?int $slotEnd,
    bool $patientJoined,
    bool $blockedByEarlier,
    string $status
): array {
    $status = strtolower(trim($status));
    if ($patientJoined || $blockedByEarlier || $slotEnd === null || $now <= $slotEnd) {
        return ['missed' => false, 'blocked' => $blockedByEarlier];
    }
    if (in_array($status, ['completed', 'cancelled', 'canceled', 'ended', 'closed'], true)) {
        return ['missed' => false, 'blocked' => false];
    }

    return ['missed' => true, 'blocked' => false];
}

/**
 * Ignoring or declining an early-start offer is never a no-show.
 * No-show stays the existing slot-end rule for a patient the doctor was free to see.
 */
function consultation_timing_should_noshow(
    int $now,
    ?int $slotEnd,
    string $status,
    bool $patientJoined,
    bool $blockedByEarlier,
    ?string $earlyResponse
): bool {
    $status = strtolower(trim($status));
    $earlyResponse = strtolower(trim((string) $earlyResponse));
    if ($patientJoined || $blockedByEarlier) {
        return false;
    }
    if (!in_array($status, ['pending', 'scheduled', 'in_consultation'], true)) {
        return false;
    }
    if ($slotEnd === null || $now <= $slotEnd) {
        return false;
    }
    // keep_time / join_early / unanswered all wait until the slot itself ends.
    if (in_array($earlyResponse, ['keep_time', 'join_early', ''], true) && $now <= $slotEnd) {
        return false;
    }

    return true;
}

/**
 * @return array{allowed:bool,code:string,reason:string}
 */
function consultation_timing_provider_start_decision(
    int $now,
    ?int $slotStart,
    ?int $slotEnd,
    string $status,
    bool $otherVideoActive,
    bool $patientJoined,
    bool $blockedByEarlier,
    ?string $earlyResponse,
    bool $alreadyThisVideo,
    bool $otherClinicallyActive = false
): array {
    $status = strtolower(trim($status));
    $earlyResponse = strtolower(trim((string) $earlyResponse));
    $missed = consultation_timing_missed_decision($now, $slotEnd, $patientJoined, $blockedByEarlier, $status);

    if (in_array($status, ['completed', 'cancelled', 'canceled', 'ended', 'closed'], true)) {
        return ['allowed' => false, 'code' => 'ended', 'reason' => 'This consultation has already ended.'];
    }
    if ($otherClinicallyActive) {
        return [
            'allowed' => false,
            'code' => 'clinical_active',
            'reason' => 'Finish the open consultation, including the SOAP note and final assessment, before starting the next patient.',
        ];
    }
    if ($otherVideoActive && !$alreadyThisVideo) {
        return [
            'allowed' => false,
            'code' => 'doctor_busy',
            'reason' => 'Finish the consultation already in progress before starting the next patient.',
        ];
    }
    if ($missed['missed']) {
        return [
            'allowed' => false,
            'code' => 'missed',
            'reason' => 'This patient did not join during their scheduled time and cannot be started ahead of later patients.',
        ];
    }
    if ($alreadyThisVideo || $status === 'in_consultation') {
        return ['allowed' => true, 'code' => 'resume', 'reason' => ''];
    }
    if ($slotStart !== null && $now < $slotStart && $earlyResponse !== 'join_early') {
        return [
            'allowed' => false,
            'code' => 'before_start',
            'reason' => 'This session opens at the scheduled time unless the patient agrees to join early.',
        ];
    }

    return ['allowed' => true, 'code' => 'ok', 'reason' => ''];
}

function consultation_timing_other_active_video_id(PDO $pdo, int $providerId, int $exceptConsultationId = 0): int
{
    if ($providerId <= 0) {
        return 0;
    }
    $sql = "
        SELECT c.id
        FROM video_sessions vs
        JOIN consultations c ON c.id = vs.consultation_id
        WHERE c.provider_id = ?
          AND vs.status = 'active'
          AND c.status NOT IN ('cancelled', 'canceled', 'completed')
    ";
    $params = [$providerId];
    if ($exceptConsultationId > 0) {
        $sql .= ' AND c.id <> ?';
        $params[] = $exceptConsultationId;
    }
    $sql .= ' ORDER BY vs.id DESC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Another consultation for this doctor that is still clinically open.
 * Ending the video does not clear this. SOAP completion does.
 */
function consultation_timing_other_open_consultation_id(PDO $pdo, int $providerId, int $exceptConsultationId = 0): int
{
    if ($providerId <= 0) {
        return 0;
    }
    $sql = "
        SELECT id
        FROM consultations
        WHERE provider_id = ?
          AND status = 'in_consultation'
    ";
    $params = [$providerId];
    if ($exceptConsultationId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $exceptConsultationId;
    }
    $sql .= ' ORDER BY id ASC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Latest real patient entry, including after the video session is ended.
 */
function consultation_timing_latest_patient_joined_sql(string $consultationAlias = 'c'): string
{
    return '(SELECT MAX(vsj.patient_joined_at) FROM video_sessions vsj WHERE vsj.consultation_id = ' . $consultationAlias . '.id)';
}

/**
 * An earlier queue position still occupies the doctor, or its joined visit overlapped this slot.
 */
function consultation_timing_earlier_visit_blocks(
    string $earlierStatus,
    bool $patientJoined,
    bool $videoActive,
    ?int $endedAt,
    int $thisSlotStart
): bool {
    $earlierStatus = strtolower(trim($earlierStatus));
    if (in_array($earlierStatus, ['cancelled', 'canceled'], true)) {
        return false;
    }
    if ($earlierStatus === 'in_consultation') {
        return true;
    }
    if (!$patientJoined) {
        return false;
    }
    if ($videoActive) {
        return true;
    }

    return $endedAt !== null && $endedAt > $thisSlotStart;
}

function consultation_timing_is_blocked_by_earlier(PDO $pdo, int $providerId, int $consultationId, ?int $slotStart): bool
{
    if ($providerId <= 0 || $consultationId <= 0 || $slotStart === null) {
        return false;
    }
    $slotStartSql = date('Y-m-d H:i:s', $slotStart);
    $stmt = $pdo->prepare("
        SELECT 1
        FROM consultations c
        LEFT JOIN appointment_slots s
          ON s.consultation_id = c.id AND s.status IN ('booked', 'blocked')
        LEFT JOIN video_sessions vs ON vs.consultation_id = c.id
          AND vs.id = (
            SELECT MAX(vs2.id) FROM video_sessions vs2 WHERE vs2.consultation_id = c.id
          )
        WHERE c.provider_id = ?
          AND c.id <> ?
          AND c.status NOT IN ('cancelled', 'canceled')
          AND TIMESTAMP(COALESCE(s.slot_date, c.consult_date), COALESCE(s.start_time, c.consult_time, '00:00:00')) < ?
          AND (
            c.status = 'in_consultation'
            OR (
              vs.patient_joined_at IS NOT NULL
              AND (
                vs.status = 'active'
                OR (vs.ended_at IS NOT NULL AND vs.ended_at > ?)
              )
            )
          )
        LIMIT 1
    ");
    $stmt->execute([$providerId, $consultationId, $slotStartSql, $slotStartSql]);

    return (bool) $stmt->fetchColumn();
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function consultation_timing_decorate_row(PDO $pdo, array $row, ?int $now = null): array
{
    consultation_timing_ensure_schema($pdo);
    $now = $now ?? time();
    $bounds = consultation_timing_slot_bounds($row);
    $providerId = (int) ($row['provider_id'] ?? 0);
    $consultationId = (int) ($row['id'] ?? $row['consultation_id'] ?? 0);
    $joined = consultation_timing_patient_ever_joined($row);
    $blocked = consultation_timing_is_blocked_by_earlier($pdo, $providerId, $consultationId, $bounds['start']);
    $status = (string) ($row['status'] ?? $row['consult_status'] ?? '');
    $missed = consultation_timing_missed_decision($now, $bounds['end'], $joined, $blocked, $status);
    $row['timing_slot_start'] = $bounds['start'];
    $row['timing_slot_end'] = $bounds['end'];
    $row['timing_blocked_by_earlier'] = $blocked;
    $row['timing_missed'] = $missed['missed'];
    $row['patient_ever_joined'] = $joined;

    return $row;
}

/**
 * Elapsed wait shown on the provider consultation queue.
 * Anchor is the scheduled slot start. Booking time (created_at) is not used.
 * Empty when the visit is not currently waiting, including before the slot starts.
 */
function consultation_timing_provider_wait_label(array $row, ?int $now = null): string
{
    $now = $now ?? time();
    $status = strtolower(trim((string) ($row['status'] ?? $row['consult_status'] ?? '')));
    $status = str_replace(' ', '_', $status);
    if ($status === 'waiting') {
        $status = 'pending';
    }
    if (!in_array($status, ['pending', 'scheduled'], true) || !empty($row['timing_missed'])) {
        return '';
    }

    $start = $row['timing_slot_start'] ?? null;
    if (!is_int($start)) {
        $start = (is_numeric($start) && (int) $start > 0)
            ? (int) $start
            : consultation_timing_slot_bounds($row)['start'];
    }
    if ($start === null || $now < $start) {
        return '';
    }

    if (!function_exists('admin_queue_minutes_phrase')) {
        require_once __DIR__ . '/admin_queue_live.php';
    }

    $minutes = (int) floor(($now - $start) / 60);

    return 'Waiting: ' . admin_queue_minutes_phrase($minutes);
}

function consultation_timing_mark_patient_joined(PDO $pdo, int $consultationId): void
{
    if ($consultationId <= 0) {
        return;
    }
    consultation_timing_ensure_schema($pdo);
    $pdo->prepare("
        UPDATE video_sessions
        SET patient_joined_at = COALESCE(patient_joined_at, NOW())
        WHERE consultation_id = ?
          AND status = 'active'
          AND patient_joined_at IS NULL
    ")->execute([$consultationId]);
}

/**
 * Close a room the patient never entered after the slot ended, without completing SOAP.
 *
 * @return int rows changed
 */
function consultation_timing_close_missed(PDO $pdo, ?int $patientId = null, ?int $providerId = null): int
{
    consultation_timing_ensure_schema($pdo);
    $scope = '';
    $params = [];
    if ($patientId !== null && $patientId > 0) {
        $scope .= ' AND c.patient_id = ?';
        $params[] = $patientId;
    }
    if ($providerId !== null && $providerId > 0) {
        $scope .= ' AND c.provider_id = ?';
        $params[] = $providerId;
    }

    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.patient_id,
            c.provider_id,
            c.status,
            c.consult_date,
            c.consult_time,
            c.early_start_response,
            s.slot_date,
            s.start_time AS slot_start,
            s.end_time AS slot_end,
            vs.patient_joined_at,
            vs.status AS video_status
        FROM consultations c
        LEFT JOIN appointment_slots s
          ON s.consultation_id = c.id AND s.status IN ('booked', 'blocked')
        LEFT JOIN video_sessions vs ON vs.consultation_id = c.id
          AND vs.id = (
            SELECT MAX(vs2.id) FROM video_sessions vs2 WHERE vs2.consultation_id = c.id
          )
        WHERE c.status IN ('pending', 'scheduled', 'in_consultation')
          {$scope}
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $updated = 0;
    $now = time();

    foreach ($rows as $row) {
        $decorated = consultation_timing_decorate_row($pdo, $row, $now);
        $noshow = consultation_timing_should_noshow(
            $now,
            $decorated['timing_slot_end'] ?? null,
            (string) ($row['status'] ?? ''),
            !empty($decorated['patient_ever_joined']),
            !empty($decorated['timing_blocked_by_earlier']),
            (string) ($row['early_start_response'] ?? '')
        );
        if (!$noshow) {
            continue;
        }

        $id = (int) $row['id'];
        $cancel = $pdo->prepare("
            UPDATE consultations
            SET status = 'cancelled'
            WHERE id = ?
              AND status IN ('pending', 'scheduled', 'in_consultation')
        ");
        $cancel->execute([$id]);
        if ($cancel->rowCount() < 1) {
            continue;
        }
        $updated += $cancel->rowCount();
        $pdo->prepare("
            UPDATE video_sessions
            SET status = 'ended',
                ended_at = COALESCE(ended_at, NOW())
            WHERE consultation_id = ?
              AND status = 'active'
        ")->execute([$id]);
        require_once __DIR__ . '/patient_consultation_cancel.php';
        consultation_release_booked_slots($pdo, $id);
        require_once __DIR__ . '/patient_booking_status.php';
        patient_triage_close_cases_for_consultation($pdo, $id);
    }

    return $updated;
}

function consultation_timing_active_video_consultation_id(PDO $pdo, int $providerId): int
{
    return consultation_timing_other_active_video_id($pdo, $providerId, 0);
}

/**
 * @return array<string, mixed>|null
 */
function consultation_timing_next_waiting_patient(PDO $pdo, int $providerId, ?int $now = null): ?array
{
    if ($providerId <= 0) {
        return null;
    }
    consultation_timing_ensure_schema($pdo);
    $now = $now ?? time();
    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.patient_id,
            c.provider_id,
            c.status,
            c.consult_date,
            c.consult_time,
            c.provider_name,
            c.early_start_offered_at,
            c.early_start_response,
            c.early_start_responded_at,
            s.slot_date,
            s.start_time AS slot_start,
            s.end_time AS slot_end,
            CONCAT(TRIM(u.first_name), ' ', TRIM(u.last_name)) AS patient_name
        FROM consultations c
        JOIN users u ON u.id = c.patient_id
        LEFT JOIN appointment_slots s
          ON s.consultation_id = c.id AND s.status = 'booked'
        WHERE c.provider_id = ?
          AND c.status IN ('pending', 'scheduled')
          AND COALESCE(s.slot_date, c.consult_date) = CURDATE()
        ORDER BY COALESCE(s.start_time, c.consult_time) ASC, c.id ASC
    ");
    $stmt->execute([$providerId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $row = consultation_timing_decorate_row($pdo, $row, $now);
        if (!empty($row['timing_missed'])) {
            continue;
        }

        return $row;
    }

    return null;
}

function consultation_timing_notify(PDO $pdo, int $userId, string $role, string $title, string $message, int $consultationId, string $url): void
{
    require_once __DIR__ . '/../core/NotificationManager.php';
    $options = [
        'type' => NotificationManager::TYPE_CONSULTATION,
        'title' => $title,
        'message' => $message,
        'priority' => 'high',
        'action_url' => $url,
        'related_table' => 'consultations',
        'related_id' => $consultationId,
        'once' => true,
        'receiver_role' => $role,
    ];
    if ($role === 'provider') {
        NotificationManager::notifyProvider($pdo, $userId, $options);
        return;
    }
    NotificationManager::notifyPatient($pdo, $userId, $options);
}

/**
 * How early the back-to-back notice may be sent, measured from the scheduled end.
 * A 12:30 end becomes eligible at 12:27 and stays eligible once 12:30 is reached.
 * The in-room countdown banner is unchanged.
 */
function consultation_timing_back_to_back_lead_seconds(): int
{
    return 180;
}

/**
 * True only for an active, not-yet-ended visit whose scheduled end matches the
 * immediately next appointment start, inside the lead window or after that end.
 */
function consultation_timing_back_to_back_should_warn(
    int $now,
    ?int $scheduledEndTs,
    ?int $nextStartTs,
    string $consultStatus,
    string $videoStatus
): bool {
    if (strtolower(trim($videoStatus)) !== 'active') {
        return false;
    }
    if (strtolower(trim($consultStatus)) !== 'in_consultation') {
        return false;
    }
    if ($scheduledEndTs === null || $scheduledEndTs <= 0 || $nextStartTs === null) {
        return false;
    }
    if ($nextStartTs !== $scheduledEndTs) {
        return false;
    }

    return $now >= ($scheduledEndTs - consultation_timing_back_to_back_lead_seconds());
}

/**
 * Soonest later appointment for this doctor. Earlier slots and other doctors are ignored.
 *
 * @param array<int, array<string, mixed>> $candidates
 * @return array<string, mixed>|null
 */
function consultation_timing_pick_immediately_next(
    array $candidates,
    int $providerId,
    int $currentConsultationId,
    int $currentStartTs
): ?array {
    $best = null;
    foreach ($candidates as $row) {
        if ((int) ($row['provider_id'] ?? 0) !== $providerId) {
            continue;
        }
        if ((int) ($row['id'] ?? 0) === $currentConsultationId) {
            continue;
        }
        $status = strtolower(trim((string) ($row['status'] ?? '')));
        if (!in_array($status, ['pending', 'scheduled'], true)) {
            continue;
        }
        $start = (int) ($row['start'] ?? 0);
        if ($start <= $currentStartTs) {
            continue;
        }
        if ($best === null
            || $start < (int) $best['start']
            || ($start === (int) $best['start'] && (int) ($row['id'] ?? 0) < (int) ($best['id'] ?? 0))
        ) {
            $best = $row;
        }
    }

    return $best;
}

/**
 * @return array<string, mixed>|null
 */
function consultation_timing_immediately_next_scheduled(
    PDO $pdo,
    int $providerId,
    int $currentConsultationId,
    int $currentStartTs
): ?array {
    if ($providerId <= 0 || $currentConsultationId <= 0 || $currentStartTs <= 0) {
        return null;
    }
    $day = date('Y-m-d', $currentStartTs);
    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.patient_id,
            c.provider_id,
            c.status,
            c.consult_date,
            c.consult_time,
            s.slot_date,
            s.start_time AS slot_start,
            s.end_time AS slot_end
        FROM consultations c
        LEFT JOIN appointment_slots s
          ON s.consultation_id = c.id AND s.status = 'booked'
        WHERE c.provider_id = ?
          AND c.id <> ?
          AND c.status IN ('pending', 'scheduled')
          AND COALESCE(s.slot_date, c.consult_date) = ?
        ORDER BY COALESCE(s.start_time, c.consult_time) ASC, c.id ASC
    ");
    $stmt->execute([$providerId, $currentConsultationId, $day]);
    $candidates = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $bounds = consultation_timing_slot_bounds($row);
        if ($bounds['start'] === null) {
            continue;
        }
        $candidates[] = [
            'id' => (int) $row['id'],
            'provider_id' => (int) ($row['provider_id'] ?? 0),
            'patient_id' => (int) ($row['patient_id'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'start' => (int) $bounds['start'],
            'end' => $bounds['end'],
        ];
    }
    $picked = consultation_timing_pick_immediately_next(
        $candidates,
        $providerId,
        $currentConsultationId,
        $currentStartTs
    );
    if ($picked === null) {
        return null;
    }

    return $picked;
}

/**
 * Doctor and current patient only. The next patient is never a recipient.
 *
 * @return array<int, array{user_id:int, role:string}>
 */
function consultation_timing_back_to_back_recipients(
    int $now,
    ?int $scheduledEndTs,
    ?int $nextStartTs,
    string $consultStatus,
    string $videoStatus,
    int $providerId,
    int $currentPatientId
): array {
    if (!consultation_timing_back_to_back_should_warn($now, $scheduledEndTs, $nextStartTs, $consultStatus, $videoStatus)) {
        return [];
    }
    $recipients = [];
    if ($providerId > 0) {
        $recipients[] = ['user_id' => $providerId, 'role' => 'provider'];
    }
    if ($currentPatientId > 0) {
        $recipients[] = ['user_id' => $currentPatientId, 'role' => 'patient'];
    }

    return $recipients;
}

/**
 * One back-to-back warning for the doctor and the patient in the active visit.
 * Does not end the video, complete the consultation, or notify the next patient.
 *
 * @param array<string, mixed> $current
 */
function consultation_timing_sync_back_to_back_warning(PDO $pdo, array $current, ?int $now = null): void
{
    $now = $now ?? time();
    $consultStatus = (string) ($current['consult_status'] ?? $current['status'] ?? '');
    $videoStatus = (string) ($current['video_status'] ?? '');
    $providerId = (int) ($current['provider_id'] ?? 0);
    $consultationId = (int) ($current['consultation_id'] ?? $current['id'] ?? 0);
    $currentPatientId = (int) ($current['patient_id'] ?? 0);
    $bounds = consultation_timing_slot_bounds($current);
    $scheduledEnd = $bounds['end'];
    $scheduledStart = $bounds['start'];
    $nextStart = null;
    if ($scheduledStart !== null) {
        $next = consultation_timing_immediately_next_scheduled($pdo, $providerId, $consultationId, $scheduledStart);
        $nextStart = isset($next['start']) ? (int) $next['start'] : null;
    }
    $recipients = consultation_timing_back_to_back_recipients(
        $now,
        $scheduledEnd,
        $nextStart,
        $consultStatus,
        $videoStatus,
        $providerId,
        $currentPatientId
    );
    if ($recipients === [] || $scheduledEnd === null) {
        return;
    }

    $endLabel = date('g:i A', $scheduledEnd);
    foreach ($recipients as $recipient) {
        if ($recipient['role'] === 'provider') {
            consultation_timing_notify(
                $pdo,
                $recipient['user_id'],
                'provider',
                'Scheduled time ending',
                'Your current consultation is approaching or has reached its scheduled end (' . $endLabel . '), and another patient is scheduled immediately afterward. This visit stays open until you end it.',
                $consultationId,
                '/views/provider/consultation_session.php?id=' . $consultationId
            );
            continue;
        }
        consultation_timing_notify(
            $pdo,
            $recipient['user_id'],
            'patient',
            'Your consultation time is ending',
            'Your scheduled consultation time is approaching or has reached its end (' . $endLabel . '), and another appointment is scheduled immediately afterward.',
            $consultationId,
            '/views/patient/consultations.php'
        );
    }
}

function consultation_timing_sync_delay_notices(PDO $pdo, int $providerId): void
{
    if ($providerId <= 0) {
        return;
    }
    consultation_timing_ensure_schema($pdo);
    $activeId = consultation_timing_active_video_consultation_id($pdo, $providerId);
    $now = time();
    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.patient_id,
            c.provider_id,
            c.status,
            c.consult_date,
            c.consult_time,
            s.slot_date,
            s.start_time AS slot_start,
            s.end_time AS slot_end
        FROM consultations c
        LEFT JOIN appointment_slots s
          ON s.consultation_id = c.id AND s.status = 'booked'
        WHERE c.provider_id = ?
          AND c.status IN ('pending', 'scheduled')
          AND COALESCE(s.slot_date, c.consult_date) = CURDATE()
          AND c.id <> ?
        ORDER BY COALESCE(s.start_time, c.consult_time) ASC, c.id ASC
    ");
    $stmt->execute([$providerId, $activeId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $nextId = 0;
    foreach ($rows as $row) {
        $decorated = consultation_timing_decorate_row($pdo, $row, $now);
        if (!empty($decorated['timing_missed'])) {
            continue;
        }
        $start = $decorated['timing_slot_start'] ?? null;
        if ($nextId === 0) {
            $nextId = (int) $row['id'];
        }
        if ($activeId > 0 && $start !== null && $now >= $start) {
            consultation_timing_notify(
                $pdo,
                (int) $row['patient_id'],
                'patient',
                'Consultation Delayed',
                'Your provider is currently finishing the previous consultation. Please remain in the waiting room. You will be notified when your provider is ready.',
                (int) $row['id'],
                '/views/patient/consultations.php'
            );
        }
    }

    if ($activeId === 0 && $nextId > 0) {
        foreach ($rows as $row) {
            if ((int) $row['id'] !== $nextId) {
                continue;
            }
            $decorated = consultation_timing_decorate_row($pdo, $row, $now);
            $start = $decorated['timing_slot_start'] ?? null;
            if ($start === null || $now < $start) {
                continue;
            }
            $hadDelay = $pdo->prepare("
                SELECT id FROM notifications
                WHERE user_id = ? AND title = 'Consultation Delayed'
                  AND related_table = 'consultations' AND related_id = ?
                  AND status = 'active'
                LIMIT 1
            ");
            $hadDelay->execute([(int) $row['patient_id'], (int) $row['id']]);
            if (!$hadDelay->fetchColumn()) {
                continue;
            }
            consultation_timing_notify(
                $pdo,
                (int) $row['patient_id'],
                'patient',
                'Doctor Available',
                'Your doctor is available. Please stay on this page. The visit will open when the doctor starts the call.',
                (int) $row['id'],
                '/views/patient/consultations.php'
            );
        }
    }
}

/**
 * @param array<string, mixed> $row decorated
 * @return array<string, mixed>|null
 */
function consultation_timing_patient_notice(PDO $pdo, array $row): ?array
{
    $providerId = (int) ($row['provider_id'] ?? 0);
    $consultationId = (int) ($row['id'] ?? 0);
    $status = strtolower((string) ($row['status'] ?? ''));
    if (!in_array($status, ['pending', 'scheduled', 'in_consultation'], true)) {
        return null;
    }
    if (!empty($row['timing_missed'])) {
        return [
            'kind' => 'missed',
            'title' => 'Scheduled time has passed',
            'message' => 'You did not join during your scheduled time, so this visit cannot move ahead of patients already in line.',
            'actions' => [],
        ];
    }

    $activeId = $providerId > 0 ? consultation_timing_active_video_consultation_id($pdo, $providerId) : 0;
    $start = $row['timing_slot_start'] ?? null;
    $now = time();
    $response = strtolower(trim((string) ($row['early_start_response'] ?? '')));
    $offered = trim((string) ($row['early_start_offered_at'] ?? '')) !== '';

    if ($activeId > 0 && $activeId !== $consultationId && $start !== null && $now >= (int) $start && in_array($status, ['pending', 'scheduled'], true)) {
        return [
            'kind' => 'delayed',
            'title' => 'Consultation Delayed',
            'message' => 'Your provider is currently finishing the previous consultation. Please remain in the waiting room. You will be notified when your provider is ready.',
            'actions' => [],
        ];
    }

    if ($offered && $response === '' && $start !== null && $now < (int) $start && in_array($status, ['pending', 'scheduled'], true)) {
        $when = date('g:i A', (int) $start);
        $end = $row['timing_slot_end'] ?? null;
        if ($end) {
            $when .= ' – ' . date('g:i A', (int) $end);
        }

        return [
            'kind' => 'early_offer',
            'title' => 'Your provider is ready early.',
            'message' => 'Your consultation is scheduled for ' . $when . ', but your provider is available now.',
            'actions' => ['join_early', 'keep_time'],
            'consultation_id' => $consultationId,
        ];
    }

    if ($response === 'join_early') {
        return [
            'kind' => 'early_accepted',
            'title' => 'Joining early',
            'message' => 'You chose to join early. Stay here until your doctor starts the call.',
            'actions' => [],
        ];
    }
    if ($response === 'keep_time') {
        $label = '';
        if ($start) {
            $label = date('g:i A', (int) $start);
        }

        return [
            'kind' => 'early_declined',
            'title' => 'Keeping your scheduled time',
            'message' => $label !== ''
                ? 'You chose to keep your scheduled time (' . $label . '). This is not a missed visit.'
                : 'You chose to keep your scheduled time. This is not a missed visit.',
            'actions' => [],
        ];
    }

    if ($activeId === 0 && $start !== null && $now >= (int) $start && in_array($status, ['pending', 'scheduled'], true)) {
        $next = consultation_timing_next_waiting_patient($pdo, $providerId, $now);
        $wasDelayed = false;
        try {
            $delayStmt = $pdo->prepare("
                SELECT id FROM notifications
                WHERE user_id = ? AND title = 'Consultation Delayed'
                  AND related_table = 'consultations' AND related_id = ?
                  AND status = 'active'
                LIMIT 1
            ");
            $delayStmt->execute([(int) ($row['patient_id'] ?? 0), $consultationId]);
            $wasDelayed = (bool) $delayStmt->fetchColumn();
        } catch (Throwable $e) {
            $wasDelayed = false;
        }
        if ($wasDelayed && $next && (int) ($next['id'] ?? 0) === $consultationId) {
            return [
                'kind' => 'ready',
                'title' => 'Doctor available',
                'message' => 'Your doctor is available. Please wait for them to start the call.',
                'actions' => [],
            ];
        }
    }

    return null;
}

/**
 * @return array{ok:bool,message:string,consultation_id:int,patient_name:string}
 */
function consultation_timing_offer_early_start(PDO $pdo, int $providerId): array
{
    consultation_timing_ensure_schema($pdo);
    if (consultation_timing_active_video_consultation_id($pdo, $providerId) > 0) {
        return [
            'ok' => false,
            'message' => 'End the current video consultation before calling the next patient.',
            'consultation_id' => 0,
            'patient_name' => '',
        ];
    }
    if (consultation_timing_other_open_consultation_id($pdo, $providerId) > 0) {
        return [
            'ok' => false,
            'message' => 'Finish the open consultation, including the SOAP note and final assessment, before calling the next patient.',
            'consultation_id' => 0,
            'patient_name' => '',
        ];
    }
    $next = consultation_timing_next_waiting_patient($pdo, $providerId);
    if ($next === null) {
        return [
            'ok' => false,
            'message' => 'No later patient is waiting in today\'s queue.',
            'consultation_id' => 0,
            'patient_name' => '',
        ];
    }
    $start = $next['timing_slot_start'] ?? null;
    if ($start === null || time() >= (int) $start) {
        return [
            'ok' => false,
            'message' => 'The next patient\'s scheduled time has already arrived. Open their session from the queue.',
            'consultation_id' => (int) $next['id'],
            'patient_name' => (string) ($next['patient_name'] ?? ''),
        ];
    }
    $existing = strtolower(trim((string) ($next['early_start_response'] ?? '')));
    if ($existing === '') {
        $pdo->prepare("
            UPDATE consultations
            SET early_start_offered_at = COALESCE(early_start_offered_at, NOW())
            WHERE id = ? AND provider_id = ? AND early_start_response IS NULL
        ")->execute([(int) $next['id'], $providerId]);
        $when = date('g:i A', (int) $start);
        $end = $next['timing_slot_end'] ?? null;
        if ($end) {
            $when .= ' – ' . date('g:i A', (int) $end);
        }
        consultation_timing_notify(
            $pdo,
            (int) $next['patient_id'],
            'patient',
            'Your provider is ready early.',
            'Your consultation is scheduled for ' . $when . ', but your provider is available now.',
            (int) $next['id'],
            '/views/patient/consultations.php'
        );
    }

    return [
        'ok' => true,
        'message' => 'The next patient was notified.',
        'consultation_id' => (int) $next['id'],
        'patient_name' => trim((string) ($next['patient_name'] ?? '')),
    ];
}

/**
 * @return array{ok:bool,message:string,response:string}
 */
function consultation_timing_record_early_response(PDO $pdo, int $patientId, int $consultationId, string $choice): array
{
    consultation_timing_ensure_schema($pdo);
    $choice = strtolower(trim($choice));
    if (!in_array($choice, ['join_early', 'keep_time'], true)) {
        return ['ok' => false, 'message' => 'Choose join early or keep your scheduled time.', 'response' => ''];
    }
    $stmt = $pdo->prepare("
        SELECT id, provider_id, status, early_start_offered_at, early_start_response
        FROM consultations
        WHERE id = ? AND patient_id = ?
        LIMIT 1
    ");
    $stmt->execute([$consultationId, $patientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'message' => 'Consultation not found.', 'response' => ''];
    }
    if (trim((string) ($row['early_start_offered_at'] ?? '')) === '') {
        return ['ok' => false, 'message' => 'There is no early-start offer for this visit.', 'response' => ''];
    }
    $existing = strtolower(trim((string) ($row['early_start_response'] ?? '')));
    if ($existing !== '') {
        return ['ok' => true, 'message' => 'Your choice was already saved.', 'response' => $existing];
    }
    $status = strtolower((string) ($row['status'] ?? ''));
    if (!in_array($status, ['pending', 'scheduled'], true)) {
        return ['ok' => false, 'message' => 'This visit is no longer waiting to start.', 'response' => ''];
    }

    $pdo->prepare("
        UPDATE consultations
        SET early_start_response = ?, early_start_responded_at = NOW()
        WHERE id = ? AND patient_id = ? AND early_start_response IS NULL
    ")->execute([$choice, $consultationId, $patientId]);

    $label = $choice === 'join_early' ? 'Start Early' : 'Keep Scheduled Time';
    $nameStmt = $pdo->prepare('SELECT CONCAT(TRIM(first_name), " ", TRIM(last_name)) FROM users WHERE id = ? LIMIT 1');
    $nameStmt->execute([$patientId]);
    $name = trim((string) ($nameStmt->fetchColumn() ?: 'The patient'));
    consultation_timing_notify(
        $pdo,
        (int) $row['provider_id'],
        'provider',
        'Patient response: ' . $label,
        $name . ' chose ' . $label . ' for the next consultation.',
        $consultationId,
        '/views/provider/queue.php'
    );

    return ['ok' => true, 'message' => 'Your choice was sent to your doctor.', 'response' => $choice];
}
