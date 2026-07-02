<?php
/**
 * BHW barangay-scoped report export (CSV / Excel-compatible).
 */
session_start();

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'bhw') {
    http_response_code(403);
    exit('Unauthorized.');
}

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_scope.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_reports.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_activity.php';

$ctx = bhw_resolve_context($pdo);
if (!$ctx['allowed']) {
    http_response_code(403);
    exit('Sector not assigned.');
}

$type = trim($_GET['type'] ?? 'summary');
$format = strtolower(trim($_GET['format'] ?? 'csv'));
$filters = BhwReports::parseFilters($_GET);
$barangay = $ctx['barangay_name'] ?? 'Sector';

BhwReports::logExport($pdo, $ctx, $type, $format, $filters);

$filename = 'BHW_Report_' . preg_replace('/[^a-z0-9_]+/i', '_', $type) . '_' . date('Y-m-d') . '.' . ($format === 'excel' ? 'xls' : 'csv');

if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
} else {
    header('Content-Type: text/csv; charset=utf-8');
}
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['medConnect BHW Report']);
fputcsv($out, ['Barangay', $barangay]);
fputcsv($out, ['Report Type', $type]);
fputcsv($out, ['Generated', date('Y-m-d H:i:s')]);
fputcsv($out, []);

switch ($type) {
    case 'patients':
        $r = BhwReports::getPatientRegistration($pdo, $ctx, $_GET);
        fputcsv($out, ['Total Registered', $r['total']]);
        fputcsv($out, []);
        fputcsv($out, ['Monthly Trend', 'Count']);
        foreach ($r['monthly'] as $row) {
            fputcsv($out, [$row['label'], $row['value']]);
        }
        fputcsv($out, []);
        fputcsv($out, ['Gender', 'Count']);
        foreach ($r['genderDist'] as $row) {
            fputcsv($out, [$row['label'], $row['value']]);
        }
        break;

    case 'consultations':
        $r = BhwReports::getConsultations($pdo, $ctx, $_GET);
        fputcsv($out, ['Status', 'Count']);
        foreach ($r['by_status'] as $row) {
            fputcsv($out, [$row['label'], $row['value']]);
        }
        break;

    case 'triage':
        $r = BhwReports::getTriage($pdo, $ctx, $_GET);
        fputcsv($out, ['Urgency', 'Count']);
        foreach ($r['by_urgency'] as $row) {
            fputcsv($out, [$row['label'], $row['value']]);
        }
        break;

    case 'referrals':
        $r = BhwReports::getReferrals($pdo, $ctx, $_GET);
        fputcsv($out, ['Type', 'Count']);
        foreach ($r['by_type'] as $row) {
            fputcsv($out, [$row['label'], $row['value']]);
        }
        fputcsv($out, []);
        fputcsv($out, ['Status', 'Count']);
        foreach ($r['by_status'] as $row) {
            fputcsv($out, [$row['label'], $row['value']]);
        }
        break;

    case 'followups':
        $r = BhwReports::getFollowups($pdo, $ctx, $_GET);
        fputcsv($out, ['Metric', 'Value']);
        fputcsv($out, ['Home Visits', $r['homeVisits']]);
        fputcsv($out, ['Completed', $r['completed']]);
        fputcsv($out, ['Pending', $r['pending']]);
        fputcsv($out, ['Overdue', $r['overdue']]);
        break;

    case 'disease':
        $r = BhwReports::getDiseaseStats($pdo, $ctx, $_GET);
        fputcsv($out, ['Top Conditions', 'Count']);
        foreach ($r['top_diseases'] as $row) {
            fputcsv($out, [$row['label'], $row['value']]);
        }
        break;

    case 'summary':
    default:
        $s = BhwReports::getSummary($pdo, $ctx, $_GET);
        fputcsv($out, ['Metric', 'Value']);
        foreach ($s as $k => $v) {
            if ($k === 'barangay') {
                continue;
            }
            fputcsv($out, [ucwords(str_replace('_', ' ', $k)), $v]);
        }
        break;
}

fclose($out);
exit;
