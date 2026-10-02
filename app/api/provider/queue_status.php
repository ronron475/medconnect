<?php
/**
 * Provider consultation queue status (poll for schedule-based session unlock).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';

Api::startJson();
Api::requireRole('provider');

require_once dirname(dirname(dirname(__DIR__))) . '/resources/views/provider/partials/queue_helpers.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/consultation_expiry.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/consultation_queue_timing.php';

$providerId = (int) ($_SESSION['user_id'] ?? 0);
if ($providerId <= 0) {
    Api::error('Authentication required.', 401);
}

header('Cache-Control: no-store');

try {
    consultations_auto_expire($pdo, null, $providerId);

    $joinedSql = consultation_timing_latest_patient_joined_sql('c');
    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.patient_id,
            c.provider_id,
            c.consult_date,
            c.consult_time,
            c.status,
            c.early_start_offered_at,
            c.early_start_response,
            c.early_start_responded_at,
            vs.room_token,
            {$joinedSql} AS patient_joined_at,
            " . queue_documentation_flags_sql('c') . ",
            s.slot_date,
            s.start_time AS slot_start,
            s.end_time AS slot_end,
            CONCAT(TRIM(u.first_name), ' ', TRIM(u.last_name)) AS patient_name
        FROM consultations c
        JOIN users u ON u.id = c.patient_id
        LEFT JOIN video_sessions vs ON vs.consultation_id = c.id AND vs.status = 'active'
        LEFT JOIN appointment_slots s ON s.consultation_id = c.id AND s.status = 'booked'
        WHERE c.provider_id = ?
          AND c.consult_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
          AND c.status NOT IN ('cancelled', 'canceled')
        ORDER BY c.consult_date DESC, c.consult_time DESC
        LIMIT 50
    ");
    $stmt->execute([$providerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    $stats = [
        'today'     => 0,
        'waiting'   => 0,
        'active'    => 0,
        'completed' => 0,
    ];
    $today = date('Y-m-d');

    consultation_timing_sync_delay_notices($pdo, $providerId);
    $nextWaiting = consultation_timing_next_waiting_patient($pdo, $providerId);
    $activeVideoId = consultation_timing_active_video_consultation_id($pdo, $providerId);
    $openClinicalId = consultation_timing_other_open_consultation_id($pdo, $providerId);

    foreach ($rows as $row) {
        $row = consultation_timing_decorate_row($pdo, $row);
        $ctx = queue_session_context($row);
        $access = queue_session_access($row);
        $status = queue_normalize_status((string) ($row['status'] ?? 'pending'));
        $consultDate = queue_normalize_date($row['consult_date'] ?? null);
        $documentationPending = queue_is_documentation_pending($row);

        if ($consultDate === $today) {
            $stats['today']++;
        }
        if (in_array($status, ['pending', 'scheduled'], true)) {
            $stats['waiting']++;
        }
        if ($status === 'in_consultation') {
            $stats['active']++;
        }
        if ($status === 'completed') {
            $stats['completed']++;
        }

        $items[] = [
            'id'               => (int) ($row['id'] ?? 0),
            'status'           => $status,
            'status_label'     => $documentationPending ? 'Documentation Pending' : ucwords(str_replace('_', ' ', $status)),
            'documentation_pending' => $documentationPending,
            'session_allowed'  => (bool) $access['allowed'],
            'session_reason'   => (string) ($access['reason'] ?? ''),
            'scheduled_label'  => (string) ($access['scheduled_label'] ?? ''),
            'scheduled_start'  => $ctx['scheduled_start'] ? (int) $ctx['scheduled_start'] : null,
            'scheduled_end'    => $ctx['scheduled_end'] ? (int) $ctx['scheduled_end'] : null,
            'opens_at_label'   => (string) ($ctx['opens_at_label'] ?? ''),
            'room_token'       => (string) ($row['room_token'] ?? ''),
            'session_url'      => ASSET_BASE . '/views/provider/consultation_session.php?id=' . (int) ($row['id'] ?? 0),
            'live_room_url'    => !empty($row['room_token'])
                ? (ASSET_BASE . '/views/consultation/video_room.php?token=' . urlencode((string) $row['room_token']))
                : '',
            'early_start_response' => (string) ($row['early_start_response'] ?? ''),
            'patient_name'    => (string) ($row['patient_name'] ?? ''),
            'timing_missed'   => !empty($row['timing_missed']),
            'patient_ever_joined' => !empty($row['patient_ever_joined']),
            'waiting_label'   => consultation_timing_provider_wait_label($row),
        ];
    }

    $nextPayload = null;
    if ($nextWaiting) {
        $response = strtolower(trim((string) ($nextWaiting['early_start_response'] ?? '')));
        $responseLabel = match ($response) {
            'join_early' => 'Start Early',
            'keep_time' => 'Keep Scheduled Time',
            default => $nextWaiting['early_start_offered_at'] ? 'Waiting for the patient' : '',
        };
        $nextStart = $nextWaiting['timing_slot_start'] ?? null;
        $nextPayload = [
            'id' => (int) $nextWaiting['id'],
            'patient_name' => trim((string) ($nextWaiting['patient_name'] ?? '')),
            'scheduled_label' => $nextStart ? date('g:i A', (int) $nextStart) : '',
            'early_start_response' => $response,
            'early_start_response_label' => $responseLabel,
            'can_offer_early' => $activeVideoId === 0
                && $openClinicalId === 0
                && $nextStart !== null
                && time() < (int) $nextStart
                && $response === ''
                && trim((string) ($nextWaiting['early_start_offered_at'] ?? '')) === '',
        ];
    }

    Api::success([
        'items'      => $items,
        'stats'      => $stats,
        'server_now' => time(),
        'active_video_consultation_id' => $activeVideoId,
        'next_patient' => $nextPayload,
    ]);
} catch (Throwable $e) {
    error_log('queue_status.php: ' . $e->getMessage());
    Api::error('Could not load queue status.', 500);
}
