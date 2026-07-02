<?php
/**
 * API: Export operational reports as CSV
 * URL: /app/api/admin/export_report.php
 */
session_start();

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/api/admin/_auth.php';
require_once BASE_PATH . '/app/includes/audit_log.php';

$type = $_GET['type'] ?? 'appointments';
$filename = "medConnect_Report_" . ucfirst($type) . "_" . date('Y-m-d') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');

// Log the export action
audit_log($pdo, [
    'patient_id'  => $_SESSION['user_id'],
    'action_type' => AuditAction::REPORT_EXPORT,
    'description' => "Admin exported $type report.",
]);

switch ($type) {
    case 'users':
        fputcsv($output, ['ID', 'First Name', 'Last Name', 'Email', 'Role', 'Status', 'Joined']);
        $stmt = $pdo->query("SELECT id, first_name, last_name, email, role, is_active, created_at FROM users");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['is_active'] = $row['is_active'] ? 'Active' : 'Inactive';
            fputcsv($output, $row);
        }
        break;

    case 'audit':
        fputcsv($output, ['ID', 'User ID', 'Action', 'Description', 'IP Address', 'Timestamp']);
        $stmt = $pdo->query("SELECT id, patient_id, action_type, description, ip_address, created_at FROM patient_audit_logs ORDER BY created_at DESC LIMIT 500");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        break;

    case 'appointments':
    default:
        fputcsv($output, ['ID', 'Patient ID', 'Provider', 'Type', 'Status', 'Date', 'Time']);
        if ($pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
            $stmt = $pdo->query("SELECT id, patient_id, provider_name, consult_type, status, consult_date, consult_time FROM consultations");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
        }
        break;
}

fclose($output);
exit;
