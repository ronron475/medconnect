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

/** Levels 1–2 or urgent/emergency labels trigger hospital referral instead of teleconsult. */
function bhw_triage_is_emergency(array $assessment): bool
{
    $level = (string) ($assessment['db_level'] ?? '3');
    if (in_array($level, ['1', '2'], true)) {
        return true;
    }

    $label = strtolower((string) ($assessment['urgency_label'] ?? ''));
    foreach (['emergency', 'urgent', 'critical', 'immediate'] as $flag) {
        if (str_contains($label, $flag)) {
            return true;
        }
    }

    $severity = strtolower((string) ($assessment['severity']['severity'] ?? ''));
    if (in_array($severity, ['critical', 'emergency', 'severe'], true)) {
        return true;
    }

    $classification = strtolower((string) ($assessment['triage']['triage_classification'] ?? ''));
    if (str_contains($classification, 'emergency') || str_contains($classification, 'urgent')) {
        return true;
    }

    return false;
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
