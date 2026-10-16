<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_reports.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_activity.php';

$ctx = bhw_api_bootstrap($pdo, false, true, false);
$action = $_GET['action'] ?? 'summary';
$filters = BhwReports::parseFilters($_GET);

try {
    switch ($action) {
        case 'summary':
            bhw_activity_log($pdo, 'bhw_report_viewed', 'BHW viewed healthcare reports dashboard.');
            bhw_reports_success([
                'summary' => BhwReports::getSummary($pdo, $ctx, $_GET),
                'puroks'  => BhwReports::listPuroks($pdo, $ctx, $_GET),
                'supports_purok' => BhwReports::supportsPurok($pdo),
                'consultation_statuses' => BhwReports::consultationStatuses(),
                'referral_statuses' => BhwReports::referralStatuses(),
                'barangay' => $ctx['barangay_name'] ?? '',
                'assigned_barangay_id' => (int) ($ctx['barangay_id'] ?? 0),
            ]);
            break;
        case 'patients':
            bhw_reports_success(['report' => BhwReports::getPatientRegistration($pdo, $ctx, $_GET)]);
            break;
        case 'consultations':
            bhw_reports_success(['report' => BhwReports::getConsultations($pdo, $ctx, $_GET)]);
            break;
        case 'triage':
            bhw_reports_success(['report' => BhwReports::getTriage($pdo, $ctx, $_GET)]);
            break;
        case 'referrals':
            bhw_reports_success(['report' => BhwReports::getReferrals($pdo, $ctx, $_GET)]);
            break;
        case 'followups':
            bhw_reports_success(['report' => BhwReports::getFollowups($pdo, $ctx, $_GET)]);
            break;
        case 'disease':
            bhw_reports_success(['report' => BhwReports::getDiseaseStats($pdo, $ctx, $_GET)]);
            break;
        case 'puroks':
            bhw_reports_success(['puroks' => BhwReports::listPuroks($pdo, $ctx, $_GET)]);
            break;
        case 'export':
            bhw_reports_send_csv($pdo, $ctx, $_GET);
            break;
        default:
            Api::error('Unknown report action.', 400);
    }
} catch (Throwable $e) {
    error_log('bhw reports: ' . $e->getMessage());
    Api::error('Report failed.', 500);
}

function bhw_reports_send_csv(PDO $pdo, array $ctx, array $query): void
{
    $role = (string) ($_SESSION['user_role'] ?? '');
    $barangayId = (int) ($ctx['barangay_id'] ?? 0);
    if (!BhwReports::accessAllowed($role, $barangayId)) {
        Api::error('Forbidden.', 403);
    }
    $type = strtolower(trim((string) ($query['type'] ?? 'summary')));
    $allowed = ['summary', 'patients', 'consultations', 'triage', 'referrals', 'followups', 'disease'];
    if (!in_array($type, $allowed, true)) {
        Api::error('Unknown report.', 400);
    }
    $report = match ($type) {
        'patients' => BhwReports::getPatientRegistration($pdo, $ctx, $query),
        'consultations' => BhwReports::getConsultations($pdo, $ctx, $query),
        'triage' => BhwReports::getTriage($pdo, $ctx, $query),
        'referrals' => BhwReports::getReferrals($pdo, $ctx, $query),
        'followups' => BhwReports::getFollowups($pdo, $ctx, $query),
        'disease' => BhwReports::getDiseaseStats($pdo, $ctx, $query),
        default => BhwReports::getSummary($pdo, $ctx, $query),
    };
    if (BhwReports::queryFailed()) {
        Api::error('Report data is unavailable.', 500);
    }
    $table = BhwReports::exportTable($type, $report, (string) ($ctx['barangay_name'] ?? ''), BhwReports::parseFilters($query));
    require_once BASE_PATH . '/app/includes/csv_export.php';
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    BhwReports::logExport($pdo, $ctx, $type, 'csv', BhwReports::parseFilters($query));
    $out = csv_export_begin('bhw-' . $type . '-report.csv');
    csv_export_row($out, $table['headers']);
    foreach ($table['rows'] as $row) {
        csv_export_row($out, $row);
    }
    fclose($out);
    exit;
}

function bhw_reports_success(array $data): void
{
    if (BhwReports::queryFailed()) {
        Api::error('Report data is unavailable.', 500);
    }
    Api::success($data);
}
