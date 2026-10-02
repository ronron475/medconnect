<?php
/**
 * BHW barangay-scoped report export (CSV).
 * Sections mirror resources/views/bhw/reports/index.php and bhw-reports.js.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_scope.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_reports.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_activity.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/csv_export.php';

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'bhw') {
    http_response_code(403);
    exit('Unauthorized.');
}

$ctx = bhw_resolve_context($pdo);
if (!$ctx['allowed']) {
    http_response_code(403);
    exit('Sector not assigned.');
}

$reportNames = [
    'patients'      => 'Patient Registration',
    'consultations' => 'Consultations',
    'triage'        => 'AI Triage',
    'referrals'     => 'Referrals',
    'followups'     => 'Follow-up',
    'disease'       => 'Disease Statistics',
    'summary'       => 'Summary',
];
$type = trim((string) ($_GET['type'] ?? 'summary'));
if (!isset($reportNames[$type])) {
    $type = 'summary';
}
$filters = BhwReports::parseFilters($_GET);
$barangay = $ctx['barangay_name'] ?? 'Sector';

BhwReports::logExport($pdo, $ctx, $type, 'csv', $filters);

$ageGroupNames = [
    'children' => 'Children (0–12)',
    'teens'    => 'Teens (13–17)',
    'adults'   => 'Adults (18–59)',
    'seniors'  => 'Seniors (60+)',
];
$filterParts = [];
if ($filters['date_from'] !== '' || $filters['date_to'] !== '') {
    $filterParts[] = 'Date: ' . csv_export_date($filters['date_from'], 'start') . ' to ' . csv_export_date($filters['date_to'], 'today');
}
if ($filters['month'] !== '' && preg_match('/^\d{4}-\d{2}$/', $filters['month'])) {
    $filterParts[] = 'Month: ' . date('F Y', (int) strtotime($filters['month'] . '-01'));
} elseif ($filters['year'] !== '' && preg_match('/^\d{4}$/', $filters['year'])) {
    $filterParts[] = 'Year: ' . $filters['year'];
}
if (in_array($filters['gender'], ['male', 'female'], true)) {
    $filterParts[] = 'Sex: ' . csv_export_sex($filters['gender']);
}
if (isset($ageGroupNames[$filters['age_group']])) {
    $filterParts[] = 'Age group: ' . $ageGroupNames[$filters['age_group']];
}

$monthLabel = static function (string $ym): string {
    return preg_match('/^\d{4}-\d{2}$/', $ym) ? date('M Y', (int) strtotime($ym . '-01')) : ($ym !== '' ? $ym : '—');
};

$out = csv_export_begin('BHW_Report_' . str_replace(' ', '_', $reportNames[$type]) . '_' . date('Y-m-d') . '.csv');

/**
 * @param list<array{label:string,value:int}> $rows
 */
$section = static function (string $title, string $labelHeader, array $rows, ?callable $format = null) use ($out): void {
    csv_export_row($out, [$title]);
    csv_export_row($out, [$labelHeader, 'Count']);
    if ($rows === []) {
        csv_export_row($out, ['No data', 0]);
    }
    foreach ($rows as $row) {
        $label = (string) ($row['label'] ?? '');
        csv_export_row($out, [$format ? $format($label) : ($label !== '' ? $label : '—'), (int) ($row['value'] ?? 0)]);
    }
    csv_export_row($out, []);
};

csv_export_row($out, ['medConnect BHW Report']);
csv_export_row($out, ['Barangay', $barangay]);
csv_export_row($out, ['Report', $reportNames[$type]]);
csv_export_row($out, ['Filters', $filterParts !== [] ? implode('; ', $filterParts) : 'None']);
csv_export_row($out, ['Generated', csv_export_generated_at()]);
csv_export_row($out, []);

// Summary cards shown above every tab (same groups and labels as SUMMARY_GROUPS in bhw-reports.js).
$s = BhwReports::getSummary($pdo, $ctx, $_GET);
$summaryGroups = [
    'Patients' => [
        'total_patients' => 'Registered',
        'new_patients_month' => 'New this month',
        'male_patients' => 'Male',
        'female_patients' => 'Female',
    ],
    'At-risk groups' => [
        'senior_citizens' => 'Seniors',
        'children' => 'Children',
        'high_risk_patients' => 'High risk',
        'ai_emergency_cases' => 'AI emergency',
    ],
    'Consultations' => [
        'pending_consultations' => 'Pending',
        'completed_consultations' => 'Completed',
        'cancelled_consultations' => 'Cancelled',
    ],
    'Referrals & follow-up' => [
        'pending_referrals' => 'Pending referrals',
        'completed_referrals' => 'Completed referrals',
        'home_visits_completed' => 'Home visits',
        'overdue_followups' => 'Overdue follow-ups',
    ],
];
csv_export_row($out, ['Summary']);
csv_export_row($out, ['Group', 'Metric', 'Value']);
foreach ($summaryGroups as $group => $metrics) {
    foreach ($metrics as $key => $label) {
        csv_export_row($out, [$group, $label, (int) ($s[$key] ?? 0)]);
    }
}
csv_export_row($out, []);

switch ($type) {
    case 'patients':
        $r = BhwReports::getPatientRegistration($pdo, $ctx, $_GET);
        csv_export_row($out, ['Total Registered', (int) $r['total']]);
        csv_export_row($out, []);
        $section('Monthly Registration Trend', 'Month', $r['monthly'], $monthLabel);
        $section('Gender Distribution', 'Sex', $r['genderDist'], 'csv_export_sex');
        $section('Age Distribution', 'Age Group', $r['ageDist']);
        $section('Purok Distribution', 'Purok', $r['purokDist']);
        break;

    case 'consultations':
        $r = BhwReports::getConsultations($pdo, $ctx, $_GET);
        $section('By Status', 'Status', $r['by_status'], 'csv_export_label');
        $section('Monthly Consultations', 'Month', $r['monthly_trend'], $monthLabel);
        $section('Provider Distribution', 'Provider', $r['provider_dist']);
        break;

    case 'triage':
        $r = BhwReports::getTriage($pdo, $ctx, $_GET);
        $section('Risk Levels', 'Risk Level', [
            ['label' => 'Low Risk', 'value' => (int) $r['low_risk']],
            ['label' => 'Moderate', 'value' => (int) $r['moderate_risk']],
            ['label' => 'High Risk', 'value' => (int) $r['high_risk']],
            ['label' => 'Emergency', 'value' => (int) $r['emergency']],
        ]);
        $section('AI Classifications', 'Classification', $r['classifications'], 'csv_export_label');
        $section('Most Common Symptoms', 'Symptom', $r['top_symptoms']);
        break;

    case 'referrals':
        $r = BhwReports::getReferrals($pdo, $ctx, $_GET);
        $section('Referral Types', 'Type', $r['by_type'], 'csv_export_label');
        $section('Referral Status', 'Status', $r['by_status'], 'csv_export_label');
        break;

    case 'followups':
        $r = BhwReports::getFollowups($pdo, $ctx, $_GET);
        $section('Follow-up Overview', 'Metric', [
            ['label' => 'Home Visits', 'value' => (int) $r['homeVisits']],
            ['label' => 'Completed', 'value' => (int) $r['completed']],
            ['label' => 'Pending', 'value' => (int) $r['pending']],
            ['label' => 'Overdue', 'value' => (int) $r['overdue']],
        ]);
        $section('Home Visit Types', 'Visit Type', $r['visitTypes'], 'csv_export_label');
        csv_export_row($out, ['Patients Requiring Follow-up']);
        csv_export_row($out, ['Patient', 'Date', 'Status']);
        if ($r['requiring'] === []) {
            csv_export_row($out, ['No patients currently requiring follow-up.']);
        }
        foreach ($r['requiring'] as $row) {
            $name = trim((string) ($row['patient_name'] ?? ''));
            csv_export_row($out, [
                $name !== '' ? $name : '—',
                csv_export_date((string) ($row['followup_date'] ?? '')),
                csv_export_label((string) ($row['status'] ?? '')),
            ]);
        }
        break;

    case 'disease':
        $r = BhwReports::getDiseaseStats($pdo, $ctx, $_GET);
        $section('Top Conditions', 'Condition', $r['top_diseases']);
        $section('Top Symptoms (Triage)', 'Symptom', $r['top_symptoms']);
        $section('Age Groups', 'Age Group', $r['age_groups']);
        $section('Monthly Triage Trend', 'Month', $r['monthly_trends'], $monthLabel);
        break;

    case 'summary':
    default:
        break;
}

fclose($out);
exit;
