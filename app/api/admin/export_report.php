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

$type = admin_operational_report_resolve((string) ($_GET['type'] ?? 'appointments'));
$definition = admin_operational_report_definition($type);
$filename = 'medConnect_Report_' . ucfirst($type) . '_' . date('Y-m-d') . '.csv';

audit_log($pdo, [
    'patient_id'  => $_SESSION['user_id'],
    'action_type' => AuditAction::REPORT_EXPORT,
    'description' => "Admin exported $type report.",
]);

try {
    $rows = admin_operational_report_all($pdo, $type);
} catch (Throwable $e) {
    error_log('export_report: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Report unavailable.';
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');
fputcsv($output, $definition['headers']);
foreach ($rows as $row) {
    fputcsv($output, $row);
}
fclose($output);
exit;
