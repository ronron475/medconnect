<?php
/**
 * Patient consultation join status (poll while waiting for provider to start).
 *
 * GET ?consultation_id=123  → one consultation
 * GET (no id)               → all active/upcoming for this patient
 */
ob_start();

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/resources/views/provider/partials/queue_helpers.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/consultation_expiry.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/consultation_queue_timing.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_settings.php';

patient_settings_require_patient_ready($pdo);
$uid = (int) $_SESSION['user_id'];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$consultationId = (int) ($_GET['consultation_id'] ?? 0);

try {
    consultations_auto_expire($pdo, $uid);

    $joinedSql = consultation_timing_latest_patient_joined_sql('c');
    $sql = "
        SELECT c.id, c.patient_id, c.provider_id, c.consult_date, c.consult_time, c.provider_name, c.consult_type, c.status,
               c.early_start_offered_at, c.early_start_response,
               vs.room_token, {$joinedSql} AS patient_joined_at,
               s.slot_date, s.start_time AS slot_start, s.end_time AS slot_end
        FROM consultations c
        LEFT JOIN video_sessions vs ON vs.consultation_id = c.id AND vs.status = 'active'
        LEFT JOIN appointment_slots s ON s.consultation_id = c.id AND s.status = 'booked'
        WHERE c.patient_id = ?
          AND c.status NOT IN ('cancelled', 'canceled')
    ";
    $params = [$uid];

    if ($consultationId > 0) {
        $sql .= ' AND c.id = ?';
        $params[] = $consultationId;
    } else {
        $sql .= " AND c.consult_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                  AND c.status IN ('pending', 'scheduled', 'in_consultation')";
    }

    $sql .= ' ORDER BY c.consult_date ASC, c.consult_time ASC LIMIT 20';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $seenProviders = [];
    $items = [];
    foreach ($rows as $row) {
        $providerId = (int) ($row['provider_id'] ?? 0);
        if ($providerId > 0 && !isset($seenProviders[$providerId])) {
            $seenProviders[$providerId] = true;
            try {
                consultation_timing_sync_delay_notices($pdo, $providerId);
            } catch (Throwable $e) {
                error_log('consultation_status delay notice: ' . $e->getMessage());
            }
        }
        $row = consultation_timing_decorate_row($pdo, $row);
        $notice = consultation_timing_patient_notice($pdo, $row);
        $join = consultation_patient_join_access($row);
        $ctx  = queue_session_context($row);
        $items[] = [
            'id'               => (int) $row['id'],
            'consult_date'     => (string) ($row['consult_date'] ?? ''),
            'consult_time'     => (string) ($row['consult_time'] ?? ''),
            'provider_name'    => (string) ($row['provider_name'] ?? ''),
            'consult_type'     => (string) ($row['consult_type'] ?? ''),
            'status'           => (string) ($row['status'] ?? ''),
            'room_token'       => (string) ($row['room_token'] ?? ''),
            'slot_date'        => (string) ($row['slot_date'] ?? ''),
            'slot_start'       => (string) ($row['slot_start'] ?? ''),
            'scheduled_start'  => $ctx['scheduled_start'] ? (int) $ctx['scheduled_start'] : null,
            'opens_at_label'   => (string) ($ctx['opens_at_label'] ?? ''),
            'join_allowed'     => (bool) $join['allowed'],
            'join_mode'        => (string) ($join['mode'] ?? 'unavailable'),
            'join_reason'      => (string) ($join['reason'] ?? ''),
            'scheduled_label'  => (string) ($join['scheduled_label'] ?? ''),
            'join_url'         => (!empty($join['allowed']) && !empty($row['room_token']))
                ? (BASE_URL . '/views/consultation/video_room.php?token=' . $row['room_token'])
                : '',
            'queue_notice'     => $notice,
            'early_start_response' => (string) ($row['early_start_response'] ?? ''),
            'timing_missed'    => !empty($row['timing_missed']),
            'patient_ever_joined' => !empty($row['patient_ever_joined']),
        ];
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'items'   => $items,
        'item'    => $consultationId > 0 ? ($items[0] ?? null) : null,
    ]);
} catch (PDOException $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load consultation status.']);
}
