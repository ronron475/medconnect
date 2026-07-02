<?php
/**
 * API: Export GIS patient location records (CSV).
 */
session_start();

$role = (string) ($_SESSION['user_role'] ?? '');
if (!in_array($role, ['admin', 'provider', 'superadmin'], true)) {
    http_response_code(403);
    die('Unauthorized.');
}

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/BagoBarangayCentroids.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/GisDashboardService.php';
require_once BASE_PATH . '/app/includes/audit_log.php';

$format = strtolower((string) ($_GET['format'] ?? 'csv'));
$gis = new GisDashboardService($pdo);

$filters = [
    'search'       => trim((string) ($_GET['search'] ?? '')),
    'province'     => trim((string) ($_GET['province'] ?? '')),
    'municipality' => trim((string) ($_GET['municipality'] ?? '')),
    'barangay'     => trim((string) ($_GET['barangay'] ?? '')),
    'status'       => trim((string) ($_GET['status'] ?? '')),
    'date_from'    => trim((string) ($_GET['date_from'] ?? '')),
    'date_to'      => trim((string) ($_GET['date_to'] ?? '')),
];

$rows = $gis->getPatientRecords($filters);
$filename = 'medConnect_GIS_Report_' . date('Y-m-d') . '.' . ($format === 'xlsx' ? 'xls' : 'csv');

audit_log($pdo, [
    'patient_id'  => (int) ($_SESSION['user_id'] ?? 0),
    'action_type' => AuditAction::REPORT_EXPORT,
    'description' => 'GIS location report exported (' . $format . ').',
]);

if ($format === 'xlsx' || $format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
} else {
    header('Content-Type: text/csv; charset=utf-8');
}
header('Content-Disposition: attachment; filename=' . $filename);

$out = fopen('php://output', 'w');
fputcsv($out, [
    'Patient ID', 'Patient Name', 'Barangay', 'Municipality', 'Province',
    'Address', 'Latitude', 'Longitude', 'Registration Date', 'Status', 'Triage Level', 'Emergency',
]);

foreach ($rows as $row) {
    fputcsv($out, [
        $row['patient_id'] ?? '',
        $row['patient_name'] ?? '',
        $row['barangay'] ?? '',
        $row['municipality'] ?? '',
        $row['province'] ?? '',
        $row['address'] ?? '',
        $row['latitude'] ?? '',
        $row['longitude'] ?? '',
        $row['registration_date'] ?? '',
        $row['patient_status'] ?? '',
        $row['triage_level'] ?? 'non_urgent',
        !empty($row['is_emergency']) ? 'Yes' : 'No',
    ]);
}

fclose($out);
exit;
