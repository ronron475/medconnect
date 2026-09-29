<?php
/**
 * Single source of truth for consultation scheduled vs actual duration.
 *
 * - Scheduled duration comes from appointment configuration (slot end − slot start).
 * - Actual duration is exact ended_at − started_at (never from rounded display times).
 * - The slot window is the queue position. It is not a hard stop once the patient has joined.
 * - Actual start/end are video_sessions.started_at and video_sessions.ended_at.
 */
declare(strict_types=1);

require_once __DIR__ . '/appointment_slots.php';

/**
 * Exact elapsed seconds between two datetime strings. Null when either is missing/invalid.
 */
function consultation_actual_duration_seconds(?string $startedAt, ?string $endedAt): ?int
{
    $start = ($startedAt !== null && trim($startedAt) !== '') ? strtotime($startedAt) : false;
    $end = ($endedAt !== null && trim($endedAt) !== '') ? strtotime($endedAt) : false;
    if ($start === false || $end === false || $end < $start) {
        return null;
    }

    return (int) ($end - $start);
}

/**
 * Human label from exact seconds (floor minutes — never round up).
 * Examples: "less than 1 minute", "14 minutes", "1 hour 2 minutes".
 * Sub-minute spans stay in minutes so user-facing labels do not show seconds.
 */
function consultation_format_duration_seconds(?int $seconds): string
{
    if ($seconds === null || $seconds < 0) {
        return '';
    }

    if ($seconds < 60) {
        return 'less than 1 minute';
    }

    $mins = intdiv($seconds, 60);
    if ($mins < 60) {
        return $mins . ' minute' . ($mins === 1 ? '' : 's');
    }

    $hours = intdiv($mins, 60);
    $rem = $mins % 60;
    $label = $hours . ' hour' . ($hours === 1 ? '' : 's');
    if ($rem > 0) {
        $label .= ' ' . $rem . ' minute' . ($rem === 1 ? '' : 's');
    }

    return $label;
}

/**
 * User-facing clock label: "10:11 PM". Seconds stay in the stored timestamp.
 */
function consultation_format_clock_time(?string $datetime): string
{
    $raw = trim((string) $datetime);
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return '';
    }

    return date('g:i A', $ts);
}

/**
 * Configured scheduled duration in seconds from slot window (exact timestamps).
 * Falls back to allowed slot lengths when only a duration minutes value is known.
 */
function consultation_scheduled_duration_seconds(
    ?string $slotStartTime,
    ?string $slotEndTime,
    ?int $fallbackMinutes = null
): int {
    $start = appointment_slot_normalize_time((string) $slotStartTime);
    $end = appointment_slot_normalize_time((string) $slotEndTime);
    if ($start !== '' && $end !== '') {
        $startTs = strtotime('1970-01-01 ' . $start);
        $endTs = strtotime('1970-01-01 ' . $end);
        if ($startTs !== false && $endTs !== false && $endTs > $startTs) {
            return (int) ($endTs - $startTs);
        }
        // Overnight window (rare for consult slots)
        if ($startTs !== false && $endTs !== false && $endTs <= $startTs) {
            return (int) (($endTs + 86400) - $startTs);
        }
    }

    $mins = appointment_slot_duration_minutes((int) ($fallbackMinutes ?? 30));

    return $mins * 60;
}

/**
 * Load scheduled duration for a consultation from its booked appointment slot.
 */
function consultation_scheduled_duration_seconds_for_id(PDO $pdo, int $consultationId): int
{
    if ($consultationId <= 0) {
        return appointment_slot_duration_minutes(30) * 60;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT start_time, end_time
            FROM appointment_slots
            WHERE consultation_id = ? AND status = 'booked'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$consultationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row) {
            return consultation_scheduled_duration_seconds(
                (string) ($row['start_time'] ?? ''),
                (string) ($row['end_time'] ?? ''),
                null
            );
        }
    } catch (Throwable $e) {
        error_log('consultation_scheduled_duration_seconds_for_id: ' . $e->getMessage());
    }

    return appointment_slot_duration_minutes(30) * 60;
}

/**
 * Official end of the allotted window.
 * appointment_slots.end_time wins. Actual video start does not move that end.
 * started_at + slot length is only a fallback when no slot end is stored.
 */
function consultation_session_deadline_ts(
    ?string $videoStartedAt,
    int $scheduledDurationSeconds,
    ?int $calendarSlotEndTs = null
): ?int {
    if ($calendarSlotEndTs !== null && $calendarSlotEndTs > 0) {
        return $calendarSlotEndTs;
    }
    $start = ($videoStartedAt !== null && trim($videoStartedAt) !== '')
        ? strtotime($videoStartedAt)
        : false;
    if ($start !== false && $scheduledDurationSeconds > 0) {
        return $start + $scheduledDurationSeconds;
    }

    return null;
}

/**
 * Seconds remaining until the session deadline (0 when expired).
 */
function consultation_seconds_remaining_until(?int $deadlineTs, ?int $nowTs = null): int
{
    if ($deadlineTs === null || $deadlineTs <= 0) {
        return 0;
    }
    $now = $nowTs ?? time();

    return max(0, $deadlineTs - $now);
}

/**
 * Elapsed call seconds from the video start. Not capped: overtime stays visible.
 */
function consultation_elapsed_capped_seconds(
    ?string $videoStartedAt,
    int $scheduledDurationSeconds,
    ?string $videoEndedAt = null,
    ?int $nowTs = null
): int {
    $start = ($videoStartedAt !== null && trim($videoStartedAt) !== '')
        ? strtotime($videoStartedAt)
        : false;
    if ($start === false) {
        return 0;
    }

    $end = ($videoEndedAt !== null && trim($videoEndedAt) !== '')
        ? strtotime($videoEndedAt)
        : false;
    $until = ($end !== false) ? $end : ($nowTs ?? time());
    return max(0, (int) ($until - $start));
}

/**
 * Unified display snapshot for details panels / history / summaries.
 *
 * @return array{
 *   scheduled_duration_seconds: int,
 *   scheduled_duration_label: string,
 *   started_at: string,
 *   ended_at: string,
 *   started_label: string,
 *   ended_label: string,
 *   actual_duration_seconds: ?int,
 *   actual_duration_label: string,
 *   ended_early: bool,
 *   status_label: string,
 *   scheduled_end_at: string,
 *   scheduled_end_label: string
 * }
 */
function consultation_duration_snapshot(
    ?string $startedAt,
    ?string $endedAt,
    int $scheduledDurationSeconds,
    string $consultationStatus = ''
): array {
    $started = trim((string) $startedAt);
    $ended = trim((string) $endedAt);
    $scheduled = max(0, $scheduledDurationSeconds);
    $actual = consultation_actual_duration_seconds($started !== '' ? $started : null, $ended !== '' ? $ended : null);
    $endedEarly = $actual !== null && $scheduled > 0 && $actual < $scheduled;

    $status = strtolower(trim(str_replace(' ', '_', $consultationStatus)));
    $statusLabel = '';
    if ($status === 'cancelled' || $status === 'canceled') {
        $statusLabel = 'Cancelled';
    } elseif (in_array($status, ['completed', 'ended'], true) || ($started !== '' && $ended !== '')) {
        $statusLabel = $endedEarly ? 'Completed — Ended early' : 'Completed';
    } elseif ($status === 'in_consultation' || ($started !== '' && $ended === '')) {
        $statusLabel = 'Ongoing';
    }

    $scheduledEndAt = '';
    $scheduledEndLabel = '';
    $startTs = $started !== '' ? strtotime($started) : false;
    if ($startTs !== false && $scheduled > 0) {
        $scheduledEndAt = date('Y-m-d H:i:s', $startTs + $scheduled);
        $scheduledEndLabel = consultation_format_clock_time($scheduledEndAt);
    }

    return [
        'scheduled_duration_seconds' => $scheduled,
        'scheduled_duration_label' => consultation_format_duration_seconds($scheduled),
        'started_at' => $started,
        'ended_at' => $ended,
        'started_label' => consultation_format_clock_time($started !== '' ? $started : null),
        'ended_label' => consultation_format_clock_time($ended !== '' ? $ended : null),
        'actual_duration_seconds' => $actual,
        'actual_duration_label' => consultation_format_duration_seconds($actual),
        'ended_early' => $endedEarly,
        'status_label' => $statusLabel,
        'scheduled_end_at' => $scheduledEndAt,
        'scheduled_end_label' => $scheduledEndLabel,
    ];
}
