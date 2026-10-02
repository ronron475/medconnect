<?php
/**
 * BHW personal activity log export (CSV).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_activity.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/csv_export.php';

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'bhw') {
    http_response_code(403);
    exit('Unauthorized.');
}

$bhwId = (int) $_SESSION['user_id'];

$filters = [
    'page'      => 1,
    'per_page'  => 10000,
    'export'    => true,
    'q'         => trim($_GET['q'] ?? ''),
    'module'    => trim($_GET['module'] ?? ''),
    'period'    => trim($_GET['period'] ?? ''),
    'date_from' => trim($_GET['date_from'] ?? ''),
    'date_to'   => trim($_GET['date_to'] ?? ''),
];

$result = bhw_activity_list($pdo, $bhwId, $filters);
$rows = $result['rows'] ?? [];
$total = (int) ($result['total'] ?? count($rows));

bhw_activity_log($pdo, 'bhw_activity_exported', 'BHW exported activity log as csv.', [
    'module' => 'Reports',
    'status' => 'success',
    'format' => 'csv',
    'row_count' => count($rows),
]);

$periodNames = ['today' => 'Today', 'week' => 'Last 7 days', 'month' => 'Last 30 days'];
$filterParts = [];
if ($filters['q'] !== '') {
    $filterParts[] = 'Search: ' . $filters['q'];
}
if ($filters['module'] !== '') {
    $filterParts[] = 'Module: ' . $filters['module'];
}
if (isset($periodNames[$filters['period']])) {
    $filterParts[] = 'Period: ' . $periodNames[$filters['period']];
}
if ($filters['date_from'] !== '' || $filters['date_to'] !== '') {
    $filterParts[] = 'Date: ' . csv_export_date($filters['date_from'], 'start') . ' to ' . csv_export_date($filters['date_to'], 'today');
}

$out = csv_export_begin('BHW_Activity_Log_' . date('Y-m-d') . '.csv');
csv_export_row($out, ['medConnect BHW Activity Log']);
csv_export_row($out, ['Filters', $filterParts !== [] ? implode('; ', $filterParts) : 'None']);
csv_export_row($out, ['Generated', csv_export_generated_at()]);
csv_export_row($out, ['Total Rows', count($rows) < $total ? count($rows) . ' of ' . $total : count($rows)]);
csv_export_row($out, []);
csv_export_row($out, ['Date', 'Time', 'Action', 'Patient', 'Module', 'IP Address', 'Device', 'Status', 'Description']);

foreach ($rows as $row) {
    csv_export_row($out, [
        $row['date'] ?? '',
        $row['time'] ?? '',
        $row['action'] ?? '',
        $row['patient_name'] ?? '—',
        $row['module'] ?? '',
        $row['ip_address'] ?? '—',
        $row['device'] ?? '',
        csv_export_label((string) ($row['status'] ?? 'success')),
        $row['description'] ?? '',
    ]);
}

fclose($out);
exit;
