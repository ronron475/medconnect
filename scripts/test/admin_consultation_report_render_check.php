<?php
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'localhost';
require dirname(__DIR__, 2) . '/bootstrap/app.php';
require dirname(__DIR__, 2) . '/config/db.php';
require dirname(__DIR__, 2) . '/app/includes/admin_operational_reports.php';

$html = admin_operational_reports_render($pdo, [
    'appt_period' => 'today',
    'appt_status' => 'completed',
], '/medconnect/resources/views/admin/operational_reports.php');
$appt = $html['appointments'] ?? '';
$users = $html['users'] ?? '';
$checks = [
    'today option' => str_contains($appt, 'value="today" selected'),
    'custom range' => str_contains($appt, 'Custom range'),
    'reset' => str_contains($appt, 'Reset filters'),
    'csv' => str_contains($appt, 'export=csv') && str_contains($appt, 'appt_period=today') && str_contains($appt, 'appt_status=completed'),
    'provider' => str_contains($appt, 'Healthcare provider'),
    'type' => str_contains($appt, 'Consultation type'),
    'status' => str_contains($appt, 'Consultation status'),
    'patient search' => str_contains($appt, 'Name, MC- code, or ID'),
    'empty or code' => str_contains($appt, 'MC-') || str_contains($appt, 'No records found'),
    'demographics separate' => str_contains($users, 'User Demographics') && !str_contains($users, 'name="appt_period"'),
    'bhw file untouched marker' => is_file(dirname(__DIR__, 2) . '/resources/views/bhw/reports/index.php'),
];
$fail = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'OK   ' : 'BAD  ') . $label . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}
exit($fail > 0 ? 1 : 0);
