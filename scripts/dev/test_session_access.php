<?php
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/resources/views/provider/partials/queue_helpers.php';
require_once dirname(__DIR__, 2) . '/app/includes/consultation_expiry.php';

echo 'Now: ' . date('Y-m-d H:i:s') . PHP_EOL . PHP_EOL;

$stmt = $pdo->query("
    SELECT c.id, c.consult_date, c.consult_time, c.status,
           s.slot_date, s.start_time AS slot_start, s.end_time AS slot_end,
           TIMESTAMP(
               c.consult_date,
               COALESCE(s.end_time, ADDTIME(COALESCE(c.consult_time, '00:00:00'), '00:30:00'))
           ) AS session_end_at
    FROM consultations c
    LEFT JOIN appointment_slots s ON s.consultation_id = c.id AND s.status = 'booked'
    ORDER BY c.id DESC
    LIMIT 8
");

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $access = queue_session_access($row);
    echo 'ID ' . $row['id'] . ' | ' . $row['status'] . ' | ' . $row['consult_date'] . ' ' . $row['consult_time'];
    echo ' | end_at=' . $row['session_end_at'];
    echo ' | ' . ($access['allowed'] ? 'ALLOW' : 'DENY: ' . $access['reason']) . PHP_EOL;
}

echo PHP_EOL . 'Extra cases:' . PHP_EOL;
$extra = [
    ['status' => 'Waiting', 'consult_date' => date('Y-m-d'), 'consult_time' => date('H:i:s', time() + 600)],
    ['status' => 'scheduled', 'consult_date' => date('Y-m-d'), 'consult_time' => date('H:i:s', time() - 60)],
    ['status' => 'scheduled', 'consult_date' => date('Y-m-d'), 'consult_time' => date('H:i:s', time() + 300)],
    ['status' => 'scheduled', 'consult_date' => '2026-07-03 00:00:00', 'consult_time' => '04:05:00', 'slot_date' => '2026-07-03', 'slot_start' => '04:05:00'],
    ['status' => 'scheduled', 'consult_date' => '2026-07-04', 'consult_time' => '09:00:00'],
];
foreach ($extra as $case) {
    $access = queue_session_access($case);
    $join = consultation_patient_join_access(array_merge($case, ['room_token' => '']));
    echo json_encode($case) . ' => ' . ($access['allowed'] ? 'ALLOW' : 'DENY') . ' | patient=' . $join['mode'] . PHP_EOL;
}
