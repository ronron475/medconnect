<?php
/**
 * API: Export operational reports as CSV
 * URL: /app/api/admin/export_report.php
 */

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/api/admin/_auth.php';
require_once BASE_PATH . '/app/includes/audit_log.php';
require_once BASE_PATH . '/app/includes/admin_operational_reports.php';
require_once BASE_PATH . '/app/includes/csv_export.php';

$type = admin_operational_report_resolve((string) ($_GET['type'] ?? 'appointments'));
$definition = admin_operational_report_definition($type);
$barangayId = max(0, (int) ($_GET['barangay_id'] ?? 0));
$filename = 'medConnect_Report_' . ucfirst($type) . '_' . date('Y-m-d') . '.csv';
$auditDescription = "Admin exported $type report.";
$barangay = null;

if ($type === 'users') {
    $barangay = admin_demographics_selected_barangay($pdo, $barangayId);
    $barangayId = $barangay['id'] ?? 0;
    if ($barangay !== null) {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $barangay['name']), '_');
        $filename = 'medConnect_Report_Demographics_' . ($slug !== '' ? $slug . '_' : '') . date('Y-m-d') . '.csv';
        $auditDescription = 'Admin exported patient demographics report for Brgy. ' . $barangay['name'] . '.';
    }
}

audit_log($pdo, [
    'patient_id'  => $_SESSION['user_id'],
    'action_type' => AuditAction::REPORT_EXPORT,
    'description' => $auditDescription,
]);

try {
    $rows = admin_operational_report_all($pdo, $type, $barangayId);
} catch (Throwable $e) {
    error_log('export_report: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Report unavailable.';
    exit;
}

$output = csv_export_begin($filename);
csv_export_row($output, ['medConnect ' . $definition['title']]);
if ($barangay !== null) {
    csv_export_row($output, ['Barangay', $barangay['name']]);
}
csv_export_row($output, ['Generated', csv_export_generated_at()]);
csv_export_row($output, ['Total Records', count($rows)]);
csv_export_row($output, []);
csv_export_row($output, $definition['headers']);
foreach ($rows as $row) {
    csv_export_row($output, $row);
}
fclose($output);
exit;
