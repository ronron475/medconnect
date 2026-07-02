<?php
/**
 * BHW personal activity log export (CSV / Excel-compatible).
 */
session_start();

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'bhw') {
    http_response_code(403);
    exit('Unauthorized.');
}

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_activity.php';

$bhwId = (int) $_SESSION['user_id'];
$format = strtolower(trim($_GET['format'] ?? 'csv'));

$filters = [
    'page'      => 1,
    'per_page'  => 5000,
    'q'         => trim($_GET['q'] ?? ''),
    'module'    => trim($_GET['module'] ?? ''),
    'period'    => trim($_GET['period'] ?? ''),
    'date_from' => trim($_GET['date_from'] ?? ''),
    'date_to'   => trim($_GET['date_to'] ?? ''),
];

$result = bhw_activity_list($pdo, $bhwId, $filters);
$rows = $result['rows'] ?? [];

bhw_activity_log($pdo, 'bhw_activity_exported', 'BHW exported activity log as ' . $format . '.', [
    'module' => 'Reports',
    'status' => 'success',
    'format' => $format,
    'row_count' => count($rows),
]);

$filename = 'BHW_Activity_Log_' . date('Y-m-d') . '.' . ($format === 'excel' ? 'xls' : 'csv');

if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
} else {
    header('Content-Type: text/csv; charset=utf-8');
}
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['medConnect BHW Activity Log']);
fputcsv($out, ['Generated', date('Y-m-d H:i:s')]);
fputcsv($out, ['Total Rows', count($rows)]);
fputcsv($out, []);
fputcsv($out, ['Date', 'Time', 'Action', 'Patient', 'Module', 'IP Address', 'Device', 'Status', 'Description']);

foreach ($rows as $row) {
    fputcsv($out, [
        $row['date'] ?? '',
        $row['time'] ?? '',
        $row['action'] ?? '',
        $row['patient_name'] ?? '',
        $row['module'] ?? '',
        $row['ip_address'] ?? '',
        $row['device'] ?? '',
        $row['status'] ?? '',
        $row['description'] ?? '',
    ]);
}

fclose($out);
exit;
