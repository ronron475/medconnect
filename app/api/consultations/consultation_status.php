<?php
/**
 * Patient consultation join status (poll while waiting for provider to start).
 *
 * GET ?consultation_id=123  → one consultation
 * GET (no id)               → all active/upcoming for this patient
 */
ob_start();
session_start();

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/resources/views/provider/partials/queue_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_settings.php';

patient_settings_require_patient_ready($pdo);
$uid = (int) $_SESSION['user_id'];

$consultationId = (int) ($_GET['consultation_id'] ?? 0);

try {
    $sql = "
        SELECT c.id, c.consult_date, c.consult_time, c.provider_name, c.consult_type, c.status,
               vs.room_token,
               s.slot_date, s.start_time AS slot_start
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

    $items = [];
    foreach ($rows as $row) {
        $join = consultation_patient_join_access($row);
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
            'join_allowed'     => (bool) $join['allowed'],
            'join_mode'        => (string) ($join['mode'] ?? 'unavailable'),
            'join_reason'      => (string) ($join['reason'] ?? ''),
            'scheduled_label'  => (string) ($join['scheduled_label'] ?? ''),
            'join_url'         => (!empty($join['allowed']) && !empty($row['room_token']))
                ? (BASE_URL . '/views/consultation/video_room.php?token=' . $row['room_token'])
                : '',
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
