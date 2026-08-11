<?php
declare(strict_types=1);

define('APP_TIMEZONE', 'Asia/Manila');
date_default_timezone_set(APP_TIMEZONE);

require_once dirname(__DIR__, 2) . '/app/includes/patient_booking_status.php';

// Simulate: approved tips with no visit yet must stay "active" in SQL wording.
// (SQL itself needs DB; here we assert schedule lock helpers still allow new cycle
// only after visits end.)

$now = new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
$fail = 0;

$future = [
    'status' => 'scheduled',
    'consult_date' => $now->modify('+1 day')->format('Y-m-d'),
    'consult_time' => '10:00:00',
];
$past = [
    'status' => 'scheduled',
    'consult_date' => $now->format('Y-m-d'),
    'consult_time' => $now->modify('-20 minutes')->format('H:i:s'),
];
$done = [
    'status' => 'completed',
    'consult_date' => $now->modify('-1 day')->format('Y-m-d'),
    'consult_time' => '10:00:00',
];

$cases = [
    ['future scheduled keeps lock', $future, true],
    ['past scheduled unlocks', $past, false],
    ['completed unlocks', $done, false],
];

foreach ($cases as [$label, $row, $expect]) {
    $got = patient_consultation_keeps_chief_complaint_locked($row);
    $ok = $got === $expect;
    if (!$ok) {
        $fail++;
    }
    echo ($ok ? 'PASS' : 'FAIL') . " {$label}\n";
}

exit($fail > 0 ? 1 : 0);
