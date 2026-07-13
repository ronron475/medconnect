<?php
/**
 * BHW clinical workflow helpers — consent, emergency triage, home visits, future booking.
 */

function bhw_clinical_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $consultCols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN);
    $addConsult = [
        'teleconsult_consent'    => "ALTER TABLE consultations ADD COLUMN teleconsult_consent TINYINT(1) NOT NULL DEFAULT 0 AFTER status",
        'teleconsult_consent_at' => "ALTER TABLE consultations ADD COLUMN teleconsult_consent_at DATETIME NULL AFTER teleconsult_consent",
        'teleconsult_consent_by' => "ALTER TABLE consultations ADD COLUMN teleconsult_consent_by INT UNSIGNED NULL AFTER teleconsult_consent_at",
        'booked_by_bhw_id'       => "ALTER TABLE consultations ADD COLUMN booked_by_bhw_id INT UNSIGNED NULL AFTER teleconsult_consent_by",
        'triage_result_id'       => "ALTER TABLE consultations ADD COLUMN triage_result_id BIGINT UNSIGNED NULL AFTER booked_by_bhw_id",
    ];
    foreach ($addConsult as $col => $sql) {
        if (!in_array($col, $consultCols, true)) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) { /* non-fatal */ }
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS bhw_home_visits (
        id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        followup_id     BIGINT UNSIGNED NULL,
        patient_id      INT UNSIGNED NOT NULL,
        bhw_id          INT UNSIGNED NOT NULL,
        visit_date      DATE NOT NULL,
        visit_type      ENUM('follow_up','monitoring','emergency_check','other') NOT NULL DEFAULT 'follow_up',
        notes           TEXT NULL,
        patient_status  ENUM('improving','stable','worsening','referred','unknown') NOT NULL DEFAULT 'stable',
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_patient (patient_id),
        INDEX idx_bhw (bhw_id),
        INDEX idx_followup (followup_id),
        INDEX idx_visit_date (visit_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $triageCols = [];
    try {
        $triageCols = $pdo->query('SHOW COLUMNS FROM triage_results')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) { /* table may not exist yet */ }

    if ($triageCols && !in_array('outcome', $triageCols, true)) {
        try {
            $pdo->exec("ALTER TABLE triage_results ADD COLUMN outcome VARCHAR(40) NULL AFTER status");
        } catch (PDOException $e) { /* non-fatal */ }
    }

    $ready = true;
}

/**
 * Reject placeholder / keyboard-smash chief complaints before NLP runs.
 */
function bhw_complaint_is_substantive(string $complaint): bool
{
    $text = trim($complaint);
    if ($text === '') {
        return false;
    }

    if (mb_strlen($text) < 4) {
        return false;
    }

    if (!preg_match('/\p{L}/u', $text)) {
        return false;
    }

    $compact = preg_replace('/\s+/u', '', $text) ?? '';
    if ($compact !== '' && preg_match('/^(.)\1{2,}$/u', $compact)) {
        return false;
    }

    if (preg_match('/^(asd|qwe|zxc|jkl|hjkl|asdf|qwerty|xxx|aaa|bbb|kkk|test|blah|nnn|mmm|hhh)+$/iu', $compact)) {
        return false;
    }

    if (!preg_match('/[aeiouàáâäãåæèéêëìíîïòóôöùúûüýÿ]/iu', $text)) {
        return false;
    }

    return true;
}

/** EMERGENCY only — urgent patients may book priority teleconsult. */
function bhw_triage_is_emergency(array $assessment): bool
{
    require_once dirname(__DIR__) . '/core/TriageLevelService.php';
    return bhw_triage_resolve_tier($assessment) === TriageLevelService::EMERGENCY;
}

function bhw_triage_is_urgent(array $assessment): bool
{
    return bhw_triage_resolve_tier($assessment) === TriageLevelService::URGENT;
}

/** Canonical triage tier for workflow routing (AI is sole authority). */
function bhw_triage_resolve_tier(array $assessment): string
{
    require_once dirname(__DIR__) . '/core/TriageLevelService.php';

    $classification = strtoupper(trim((string) ($assessment['triage']['triage_classification'] ?? '')));
    if ($classification === 'EMERGENCY') {
        return TriageLevelService::EMERGENCY;
    }
    if ($classification === 'URGENT' || $classification === 'HIGH') {
        return TriageLevelService::URGENT;
    }

    $display = strtoupper(trim((string) ($assessment['triage']['triage_display'] ?? '')));
    if ($display === 'EMERGENCY') {
        return TriageLevelService::EMERGENCY;
    }
    if ($display === 'URGENT') {
        return TriageLevelService::URGENT;
    }

    $level = (string) ($assessment['db_level'] ?? '3');
    if ($level === '1') {
        return TriageLevelService::EMERGENCY;
    }
    if ($level === '2') {
        return TriageLevelService::URGENT;
    }

    $label = strtolower((string) ($assessment['urgency_label'] ?? ''));
    if (str_contains($label, 'emergency') || str_contains($label, 'immediate')) {
        return TriageLevelService::EMERGENCY;
    }
    if (str_contains($label, 'urgent') || str_contains($label, 'priority')) {
        return TriageLevelService::URGENT;
    }

    $severity = strtolower((string) ($assessment['severity']['severity'] ?? ''));
    if (in_array($severity, ['critical', 'emergency'], true)) {
        return TriageLevelService::EMERGENCY;
    }

    return TriageLevelService::fromAssessment($assessment);
}

/**
 * @return array{mode:string, tier:string, label:string, allow_booking:bool, slot_mode:string, message:string}
 */
function bhw_triage_routing_meta(array $assessment): array
{
    $tier = bhw_triage_resolve_tier($assessment);
    $label = TriageLevelService::displayLabel($tier);

    if ($tier === TriageLevelService::EMERGENCY) {
        return [
            'mode'          => 'emergency_referral',
            'tier'          => $tier,
            'label'         => $label,
            'allow_booking' => false,
            'slot_mode'     => 'none',
            'message'       => 'Emergency — online consultation is not available. Generate hospital referral immediately.',
        ];
    }

    if ($tier === TriageLevelService::URGENT) {
        return [
            'mode'          => 'priority_queue',
            'tier'          => $tier,
            'label'         => $label,
            'allow_booking' => true,
            'slot_mode'     => 'priority',
            'message'       => 'Urgent — select the earliest available priority appointment slot.',
        ];
    }

    return [
        'mode'          => 'standard_booking',
        'tier'          => $tier,
        'label'         => $label,
        'allow_booking' => true,
        'slot_mode'     => 'standard',
        'message'       => 'Non-urgent — choose any available appointment from the provider calendar.',
    ];
}

/**
 * Earliest open slots for urgent patients (limited window).
 *
 * @return array<int, array{id:int, slot_date:string, start_time:string, label:string, is_priority:bool}>
 */
function bhw_fetch_priority_slots(PDO $pdo, int $providerId, int $maxDays = 7, int $limit = 8): array
{
    $all = bhw_fetch_bookable_slots_range($pdo, $providerId, $maxDays);
    $slice = array_slice($all, 0, max(1, $limit));
    foreach ($slice as &$slot) {
        $slot['is_priority'] = true;
    }
    unset($slot);

    return $slice;
}

function bhw_triage_emergency_reason(array $assessment, string $complaint, array $symptoms): string
{
    $parts = array_filter([
        'Chief complaint: ' . $complaint,
        $symptoms !== [] ? 'Symptoms: ' . implode(', ', $symptoms) : null,
        'AI urgency: ' . ($assessment['urgency_label'] ?? 'High'),
        'Level: ' . ($assessment['db_level'] ?? '—'),
    ]);
    return 'EMERGENCY TRIAGE — Immediate in-person care required. ' . implode('. ', $parts);
}

function appointment_slots_sync_date(PDO $pdo, int $providerId, string $dateYmd): int
{
    require_once __DIR__ . '/appointment_slots.php';
    $target = DateTimeImmutable::createFromFormat('Y-m-d', $dateYmd, new DateTimeZone(APP_TIMEZONE));
    if (!$target) {
        return 0;
    }
    $today = appointment_now()->setTime(0, 0, 0);
    if ($target < $today) {
        return 0;
    }
    $daysAhead = (int) $today->diff($target)->days;
    return appointment_slots_sync_provider($pdo, $providerId, $daysAhead, $target->format('l'));
}

/** BHW may book today through the next 28 days (not limited to same-day). */
function appointment_slot_is_bookable_bhw(string $slotDate, string $startTime, ?string $endTime = null, int $maxDaysAhead = 28): bool
{
    require_once __DIR__ . '/appointment_slots.php';
    $slotStart = appointment_slot_start_datetime($slotDate, $startTime);
    $now = appointment_now();
    $maxDate = $now->modify('+' . $maxDaysAhead . ' days')->setTime(23, 59, 59);

    if ($slotStart <= $now) {
        return false;
    }
    if ($slotStart > $maxDate) {
        return false;
    }
    if ($endTime !== null && $endTime !== '') {
        $slotEnd = appointment_slot_start_datetime($slotDate, $endTime);
        if ($slotEnd <= $now) {
            return false;
        }
    }
    return true;
}

function bhw_format_slot_label(string $slotDate, string $startTime): string
{
    $ts = strtotime($slotDate . ' ' . substr($startTime, 0, 8));
    if ($ts === false) {
        return date('g:i A', strtotime($startTime));
    }
    return date('M j, Y g:i A', $ts);
}

function bhw_provider_active_weekdays(PDO $pdo, int $providerId): array
{
    require_once __DIR__ . '/provider_schedule_sessions.php';
    $order = provider_schedule_valid_days();
    $stmt = $pdo->prepare("
        SELECT DISTINCT day_of_week
        FROM provider_schedules
        WHERE provider_id = ? AND is_active = 1
        ORDER BY day_of_week
    ");
    $stmt->execute([$providerId]);
    $days = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $rank = array_flip($order);
    usort($days, static function ($a, $b) use ($rank) {
        return ($rank[$a] ?? 99) <=> ($rank[$b] ?? 99);
    });

    return array_values($days);
}

function bhw_provider_format_weekdays(array $days): string
{
    $abbr = [
        'Monday'    => 'Mon',
        'Tuesday'   => 'Tue',
        'Wednesday' => 'Wed',
        'Thursday'  => 'Thu',
        'Friday'    => 'Fri',
        'Saturday'  => 'Sat',
        'Sunday'    => 'Sun',
    ];
    $labels = array_map(static function ($day) use ($abbr) {
        return $abbr[$day] ?? $day;
    }, $days);

    return $labels !== [] ? implode(', ', $labels) : 'No schedule set';
}

/**
 * @return array<int, array{id:int, slot_date:string, start_time:string, label:string}>
 */
function bhw_fetch_bookable_slots(PDO $pdo, int $providerId, string $dateYmd, int $maxDaysAhead = 28): array
{
    appointment_slots_sync_date($pdo, $providerId, $dateYmd);

    $stmt = $pdo->prepare("
        SELECT id, slot_date, start_time, end_time, status
        FROM appointment_slots
        WHERE provider_id = ? AND slot_date = ? AND status = 'available'
        ORDER BY start_time ASC
    ");
    $stmt->execute([$providerId, $dateYmd]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $slots = [];
    foreach ($rows as $row) {
        if (!appointment_slot_is_bookable_bhw(
            (string) $row['slot_date'],
            (string) $row['start_time'],
            (string) $row['end_time'],
            $maxDaysAhead
        )) {
            continue;
        }
        $slots[] = [
            'id'         => (int) $row['id'],
            'slot_date'  => (string) $row['slot_date'],
            'start_time' => (string) $row['start_time'],
            'label'      => bhw_format_slot_label((string) $row['slot_date'], (string) $row['start_time']),
        ];
    }

    return $slots;
}

function bhw_provider_next_bookable_date(PDO $pdo, int $providerId, int $maxDaysAhead = 28): ?string
{
    $start = appointment_now()->setTime(0, 0, 0);
    for ($i = 0; $i <= $maxDaysAhead; $i++) {
        $date = $start->modify('+' . $i . ' days')->format('Y-m-d');
        if (bhw_fetch_bookable_slots($pdo, $providerId, $date, $maxDaysAhead) !== []) {
            return $date;
        }
    }

    return null;
}

function bhw_provider_day_is_active(PDO $pdo, int $providerId, string $dateYmd): bool
{
    $target = DateTimeImmutable::createFromFormat('Y-m-d', $dateYmd, new DateTimeZone(APP_TIMEZONE));
    if (!$target) {
        return false;
    }
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM provider_schedules
        WHERE provider_id = ? AND day_of_week = ? AND is_active = 1
    ");
    $stmt->execute([$providerId, $target->format('l')]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * @return array<int, array{id:int, slot_date:string, start_time:string, label:string}>
 */
function bhw_fetch_bookable_slots_range(PDO $pdo, int $providerId, int $maxDaysAhead = 28): array
{
    $start = appointment_now()->setTime(0, 0, 0);
    $slots = [];

    for ($i = 0; $i <= $maxDaysAhead; $i++) {
        $date = $start->modify('+' . $i . ' days')->format('Y-m-d');
        foreach (bhw_fetch_bookable_slots($pdo, $providerId, $date, $maxDaysAhead) as $slot) {
            $slots[] = $slot;
        }
    }

    return $slots;
}
