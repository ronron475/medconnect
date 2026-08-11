<?php
declare(strict_types=1);

define('APP_TIMEZONE', 'Asia/Manila');
date_default_timezone_set(APP_TIMEZONE);

require_once dirname(__DIR__, 2) . '/app/includes/patient_booking_status.php';

$tz = new DateTimeZone(APP_TIMEZONE);
$now = new DateTimeImmutable('now', $tz);
echo 'TZ=' . APP_TIMEZONE . ' now=' . $now->format('Y-m-d H:i:s') . PHP_EOL;

$cases = [
    [
        'label' => 'tomorrow',
        'status' => 'scheduled',
        'date' => $now->modify('+1 day')->format('Y-m-d'),
        'time' => '10:00:00',
        'expect' => true,
    ],
    [
        'label' => 'today future',
        'status' => 'scheduled',
        'date' => $now->format('Y-m-d'),
        'time' => $now->modify('+30 minutes')->format('H:i:s'),
        'expect' => true,
    ],
    [
        'label' => 'today past',
        'status' => 'scheduled',
        'date' => $now->format('Y-m-d'),
        'time' => $now->modify('-30 minutes')->format('H:i:s'),
        'expect' => false,
    ],
    [
        'label' => 'in_progress past',
        'status' => 'in_consultation',
        'date' => $now->format('Y-m-d'),
        'time' => $now->modify('-30 minutes')->format('H:i:s'),
        'expect' => true,
    ],
    [
        'label' => 'completed',
        'status' => 'completed',
        'date' => $now->modify('-1 day')->format('Y-m-d'),
        'time' => '10:00:00',
        'expect' => false,
    ],
    [
        'label' => 'cancelled',
        'status' => 'cancelled',
        'date' => $now->format('Y-m-d'),
        'time' => '10:00:00',
        'expect' => false,
    ],
];

$fail = 0;
foreach ($cases as $c) {
    $row = [
        'status' => $c['status'],
        'consult_date' => $c['date'],
        'consult_time' => $c['time'],
    ];
    $got = patient_consultation_keeps_chief_complaint_locked($row);
    $ok = $got === $c['expect'];
    if (!$ok) {
        $fail++;
    }
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $c['label']
        . ' locked=' . ($got ? '1' : '0')
        . ' expect=' . ($c['expect'] ? '1' : '0') . PHP_EOL;
}

$select = patient_portal_select_active_consultation([
    [
        'id' => 1,
        'status' => 'scheduled',
        'consult_date' => $now->format('Y-m-d'),
        'consult_time' => $now->modify('-10 minutes')->format('H:i:s'),
    ],
    [
        'id' => 2,
        'status' => 'scheduled',
        'consult_date' => $now->modify('+1 day')->format('Y-m-d'),
        'consult_time' => '09:00:00',
    ],
    [
        'id' => 3,
        'status' => 'completed',
        'consult_date' => $now->modify('-2 day')->format('Y-m-d'),
        'consult_time' => '09:00:00',
    ],
]);
$sid = (int) ($select['id'] ?? 0);
echo ($sid === 2 ? 'PASS' : 'FAIL') . " select_active got id={$sid} expect=2" . PHP_EOL;
if ($sid !== 2) {
    $fail++;
}

$selectPastOnly = patient_portal_select_active_consultation([
    [
        'id' => 10,
        'status' => 'scheduled',
        'consult_date' => $now->format('Y-m-d'),
        'consult_time' => $now->modify('-5 minutes')->format('H:i:s'),
    ],
]);
echo ($selectPastOnly === null ? 'PASS' : 'FAIL') . ' past-only select=null' . PHP_EOL;
if ($selectPastOnly !== null) {
    $fail++;
}

$live = patient_portal_select_active_consultation([
    [
        'id' => 20,
        'status' => 'scheduled',
        'consult_date' => $now->modify('+1 day')->format('Y-m-d'),
        'consult_time' => '09:00:00',
    ],
    [
        'id' => 21,
        'status' => 'in_consultation',
        'consult_date' => $now->format('Y-m-d'),
        'consult_time' => $now->modify('-15 minutes')->format('H:i:s'),
    ],
]);
$lid = (int) ($live['id'] ?? 0);
echo ($lid === 21 ? 'PASS' : 'FAIL') . " prefer in_consultation got id={$lid} expect=21" . PHP_EOL;
if ($lid !== 21) {
    $fail++;
}

exit($fail > 0 ? 1 : 0);
