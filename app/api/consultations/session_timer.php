<?php
/**
 * API: Remaining consultation time for active video session
 * Scheduled slot remaining is informational. It does not end a joined consultation.
 * URL: /app/api/consultations/session_timer.php?token=...
 */
header('Content-Type: application/json');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/consultation_video_lifecycle.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/consultation_duration.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/consultation_queue_timing.php';

$token = trim((string) ($_GET['token'] ?? ''));
$userId = (int) ($_SESSION['user_id'] ?? 0);
// Release lock so Chrome dual-tab video rooms can poll without blocking each other.
session_write_close();

if ($token === '' || $userId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

try {
    consultation_video_sessions_ensure_patient_left_column($pdo);

    $stmt = $pdo->prepare("
        SELECT vs.consultation_id, vs.status AS video_status, vs.started_at, vs.ended_at,
               vs.patient_left_at, c.patient_id, c.provider_id,
               c.status AS consult_status, c.consult_date, c.consult_time,
               s.slot_date, s.start_time AS slot_start, s.end_time AS slot_end
        FROM video_sessions vs
        JOIN consultations c ON c.id = vs.consultation_id
        LEFT JOIN appointment_slots s ON s.consultation_id = c.id AND s.status = 'booked'
        WHERE vs.room_token = ?
          AND (c.provider_id = ? OR c.patient_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$token, $userId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Session not found.']);
        exit;
    }

    $consultationId = (int) ($row['consultation_id'] ?? 0);
    $scheduledSeconds = consultation_scheduled_duration_seconds(
        isset($row['slot_start']) ? (string) $row['slot_start'] : null,
        isset($row['slot_end']) ? (string) $row['slot_end'] : null,
        null
    );
    if ($scheduledSeconds <= 0) {
        $scheduledSeconds = consultation_scheduled_duration_seconds_for_id($pdo, $consultationId);
    }

    $slot_date = $row['slot_date'] ?: $row['consult_date'] ?: date('Y-m-d');
    $calendarEndTs = null;
    if (!empty($row['slot_end'])) {
        $calendarEndTs = strtotime($slot_date . ' ' . $row['slot_end']) ?: null;
    } elseif (!empty($row['consult_time'])) {
        $calendarEndTs = strtotime($slot_date . ' ' . $row['consult_time']) + $scheduledSeconds;
    }

    $startedAt = trim((string) ($row['started_at'] ?? ''));
    $endedAt = trim((string) ($row['ended_at'] ?? ''));
    $deadlineTs = consultation_session_deadline_ts(
        $startedAt !== '' ? $startedAt : null,
        $scheduledSeconds,
        $calendarEndTs
    );
    if ($deadlineTs === null) {
        $deadlineTs = time() + $scheduledSeconds;
    }

    $now = time();
    $seconds_remaining = consultation_seconds_remaining_until($deadlineTs, $now);
    $elapsed_seconds = 0;
    if ($startedAt !== '') {
        $elapsed_seconds = consultation_actual_duration_seconds(
            $startedAt,
            $endedAt !== '' ? $endedAt : date('Y-m-d H:i:s', $now)
        ) ?? 0;
    }
    $actual_seconds = consultation_actual_duration_seconds(
        $startedAt !== '' ? $startedAt : null,
        $endedAt !== '' ? $endedAt : null
    );
    $slot_window_elapsed = $seconds_remaining <= 0;
    $liveStatus = (string) ($row['consult_status'] ?? '');
    $videoStatus = strtolower(trim((string) ($row['video_status'] ?? '')));
    // A joined visit keeps going after the slot window. Do not complete it here.
    if ($videoStatus === 'active') {
        try {
            $providerForTiming = (int) ($row['provider_id'] ?? 0);
            consultation_timing_close_missed($pdo, null, $providerForTiming);
            consultation_timing_sync_delay_notices($pdo, $providerForTiming);
            $statusStmt = $pdo->prepare('SELECT status FROM consultations WHERE id = ? LIMIT 1');
            $statusStmt->execute([$consultationId]);
            $liveStatus = (string) ($statusStmt->fetchColumn() ?: $liveStatus);
            $videoStmt = $pdo->prepare("SELECT status FROM video_sessions WHERE consultation_id = ? ORDER BY id DESC LIMIT 1");
            $videoStmt->execute([$consultationId]);
            $videoStatus = (string) ($videoStmt->fetchColumn() ?: $videoStatus);
        } catch (Throwable $e) {
            error_log('session_timer delay notice: ' . $e->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'seconds_remaining' => $seconds_remaining,
        'overtime_seconds' => max(0, $now - (int) $deadlineTs),
        'scheduled_end_unix' => (int) $deadlineTs,
        'scheduled_duration_seconds' => $scheduledSeconds,
        'elapsed_seconds' => $elapsed_seconds,
        'actual_duration_seconds' => $actual_seconds,
        'started_at' => $startedAt,
        'ended_at' => $endedAt,
        'end_label' => date('g:i A', $deadlineTs),
        'scheduled_end_label' => consultation_format_clock_time(date('Y-m-d H:i:s', $deadlineTs)),
        'slot_expired' => false,
        'slot_window_elapsed' => $slot_window_elapsed,
        'hard_stop' => false,
        'consultation_status' => $liveStatus,
        'video_status' => $videoStatus,
        'consultation_id' => $consultationId,
        'patient_temporarily_left' => !empty($row['patient_left_at'])
            && strtolower(trim((string) ($row['video_status'] ?? ''))) === 'active',
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
