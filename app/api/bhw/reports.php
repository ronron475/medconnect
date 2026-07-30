<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_reports.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_activity.php';

$ctx = bhw_api_bootstrap($pdo);
$action = $_GET['action'] ?? 'summary';
$filters = BhwReports::parseFilters($_GET);

try {
    switch ($action) {
        case 'summary':
            bhw_activity_log($pdo, 'bhw_report_viewed', 'BHW viewed healthcare reports dashboard.');
            Api::success([
                'summary' => BhwReports::getSummary($pdo, $ctx, $_GET),
                'puroks'  => BhwReports::listPuroks($pdo, $ctx, $_GET),
                'barangay' => $ctx['barangay_name'] ?? '',
                'assigned_barangay_id' => (int) ($ctx['barangay_id'] ?? 0),
            ]);
            break;
        case 'patients':
            Api::success(['report' => BhwReports::getPatientRegistration($pdo, $ctx, $_GET)]);
            break;
        case 'consultations':
            Api::success(['report' => BhwReports::getConsultations($pdo, $ctx, $_GET)]);
            break;
        case 'triage':
            Api::success(['report' => BhwReports::getTriage($pdo, $ctx, $_GET)]);
            break;
        case 'referrals':
            Api::success(['report' => BhwReports::getReferrals($pdo, $ctx, $_GET)]);
            break;
        case 'followups':
            Api::success(['report' => BhwReports::getFollowups($pdo, $ctx, $_GET)]);
            break;
        case 'disease':
            Api::success(['report' => BhwReports::getDiseaseStats($pdo, $ctx, $_GET)]);
            break;
        case 'puroks':
            Api::success(['puroks' => BhwReports::listPuroks($pdo, $ctx, $_GET)]);
            break;
        default:
            Api::error('Unknown report action.', 400);
    }
} catch (Throwable $e) {
    Api::error('Report failed: ' . $e->getMessage(), 500);
}
