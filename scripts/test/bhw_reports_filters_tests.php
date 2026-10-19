<?php
/**
 * BHW healthcare report filters, CSV shape, and activity-log precedence.
 * Does not connect to MySQL and does not call schema-ensure helpers.
 *
 * CLI: php scripts/test/bhw_reports_filters_tests.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bootstrap.php';
require_once $root . '/app/includes/bhw_reports.php';
require_once $root . '/app/includes/bhw_activity.php';
require_once $root . '/app/includes/csv_export.php';

$pass = 0;
$fail = 0;

function bhw_assert(bool $ok, string $label): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS  {$label}\n";
        return;
    }
    $fail++;
    echo "FAIL  {$label}\n";
}

function bhw_source(string $relative): string
{
    global $root;
    $text = file_get_contents($root . '/' . $relative);
    bhw_assert(is_string($text) && $text !== '', 'readable ' . $relative);

    return is_string($text) ? $text : '';
}

$filters = BhwReports::parseFilters([
    'date_from' => '2026-03-10',
    'date_to' => '2026-03-01',
    'month' => '2026-03',
    'year' => '2025',
    'gender' => 'Female',
    'age_group' => 'adults',
    'purok' => "Purok\n1",
    'consultation_status' => 'completed',
    'referral_status' => 'not-a-status',
]);
bhw_assert($filters['date_from'] === '2026-03-10' && $filters['date_to'] === '2026-03-01', 'valid dates are kept');
bhw_assert($filters['gender'] === 'female' && $filters['age_group'] === 'adults', 'gender and age are normalized');
bhw_assert($filters['consultation_status'] === 'completed', 'consultation status is whitelisted');
bhw_assert($filters['referral_status'] === '', 'unknown referral status is dropped');
bhw_assert(!str_contains($filters['purok'], "\n"), 'purok control characters are removed');

$bad = BhwReports::parseFilters([
    'date_from' => '2026-02-31',
    'month' => '2026-13',
    'year' => '26',
    'consultation_status' => 'deleted',
]);
bhw_assert($bad['date_from'] === '' && $bad['month'] === '' && $bad['year'] === '' && $bad['consultation_status'] === '', 'invalid calendar and status values are dropped');

$params = [];
$sql = BhwReports::filterCalendarSql($filters, $params, 'tr.assessed_at', true);
bhw_assert(str_contains($sql, 'YEAR(tr.assessed_at) = ?') && str_contains($sql, 'MONTH(tr.assessed_at) = ?'), 'year and month are applied together');
bhw_assert($params === ['2026-03-01 00:00:00', '2026-03-10 23:59:59', 2025, 3], 'from/to swap and explicit year win over the month input year');

$params = [];
$monthOnly = BhwReports::filterCalendarSql(['month' => '2026-03', 'year' => ''], $params, 'fu.followup_date', false);
bhw_assert(str_contains($monthOnly, "DATE_FORMAT(fu.followup_date, '%Y-%m') = ?") && $params === ['2026-03'], 'month alone uses the full month key');
bhw_assert(!str_contains($monthOnly, '00:00:00'), 'date columns are not given a time suffix');

$params = [];
$yearOnly = BhwReports::filterCalendarSql(['year' => '2024'], $params, 'c.consult_date', false);
bhw_assert(str_contains($yearOnly, 'YEAR(c.consult_date) = ?') && $params === [2024], 'year alone filters that year');

$params = [];
bhw_assert(BhwReports::filterCalendarSql(['year' => '2024'], $params, 'c.consult_date; DROP', false) === '', 'date column names are not interpolated from free text');

[$riskCol, $riskDateTime] = BhwReports::metricDateColumn('high_risk');
[$emergencyCol] = BhwReports::metricDateColumn('emergency');
bhw_assert($riskCol === 'tr.assessed_at' && $emergencyCol === 'tr.assessed_at' && $riskDateTime === true, 'high-risk and emergency use the triage assessment time');
bhw_assert(BhwReports::metricDateColumn('consultations') === ['c.consult_date', false], 'consultations use the consultation date');
bhw_assert(BhwReports::metricDateColumn('referrals')[0] === 'dr.created_at', 'referrals use the referral created time');
bhw_assert(BhwReports::metricDateColumn('followups')[0] === 'fu.followup_date', 'follow-ups use the follow-up date');

$reports = bhw_source('app/includes/bhw_reports.php');
bhw_assert(!str_contains($reports, 'consultDateWhere'), 'the undefined consultation-date helper is gone');
bhw_assert(str_contains($reports, "metricDateColumn('high_risk')"), 'summary high-risk counts use the triage date column');
bhw_assert(substr_count($reports, 'bhw_patient_sector_clause') >= 1, 'event queries keep the barangay scope clause');
$scope = bhw_source('app/includes/bhw_scope.php');
bhw_assert(str_contains($scope, "return ['1 = 0', []];"), 'an unassigned barangay scope matches no rows');
bhw_assert(str_contains($scope, 'Api::requireRole(\'bhw\')'), 'BHW APIs require the bhw role');
bhw_assert(str_contains($scope, 'BHW sector not assigned'), 'an unassigned BHW is denied when sector is required');

$caption = BhwReports::filterCaption($filters);
bhw_assert(str_contains($caption, 'Year 2025') && str_contains($caption, 'Month 2026-03') && str_contains($caption, 'Consultation status completed'), 'active filters are listed together');
bhw_assert(!str_contains($caption, 'Referral status'), 'a dropped referral status is absent from the caption');

$summary = BhwReports::exportTable('summary', [
    'barangay' => 'Sample',
    'total_patients' => 4,
    'high_risk' => 1,
    'emergency' => 2,
], 'Sample', $filters);
$flat = [];
foreach ($summary['rows'] as $row) {
    $flat[] = implode('|', $row);
}
$joined = implode("\n", $flat);
bhw_assert(str_contains($joined, 'Registered') === false, 'summary export uses the supplied count labels');
bhw_assert(str_contains($joined, 'total_patients|4') && str_contains($joined, 'high_risk|1') && str_contains($joined, 'emergency|2'), 'summary totals are exported as counts');
bhw_assert(!str_contains($joined, 'barangay|0'), 'the barangay name is not turned into a zero count');

$disease = BhwReports::exportTable('disease', [
    'top_diseases' => [['label' => 'Recorded condition', 'value' => 3]],
    'diagnoses' => [['label' => 'Hidden diagnosis', 'value' => 9]],
    'top_symptoms' => [['label' => 'Hidden complaint', 'value' => 8]],
    'age_groups' => [['label' => 'Adults', 'value' => 3]],
    'monthly_trends' => [],
], 'Sample', []);
$diseaseText = json_encode($disease['rows']);
bhw_assert(is_string($diseaseText) && str_contains($diseaseText, 'Adults'), 'disease export keeps non-clinical age counts');
bhw_assert(is_string($diseaseText) && !str_contains($diseaseText, 'Recorded condition') && !str_contains($diseaseText, 'Hidden diagnosis') && !str_contains($diseaseText, 'Hidden complaint'), 'recorded conditions, diagnoses, and complaints stay out of the CSV');

$follow = BhwReports::exportTable('followups', [
    'homeVisits' => 5,
    'completed' => 1,
    'pending' => 2,
    'overdue' => 0,
    'requiring' => [['patient_name' => 'Hidden Patient', 'followup_date' => '2026-01-01']],
    'visitTypes' => [['label' => 'Home', 'value' => 5]],
], 'Sample', []);
$followText = json_encode($follow['rows']);
bhw_assert(is_string($followText) && str_contains($followText, 'Home visits') && str_contains($followText, '"5"'), 'follow-up totals are exported');
bhw_assert(is_string($followText) && !str_contains($followText, 'Hidden Patient'), 'follow-up patient names are not exported');

$handle = fopen('php://temp', 'w+');
bhw_assert(is_resource($handle), 'csv buffer opens');
if (is_resource($handle)) {
    fwrite($handle, "\xEF\xBB\xBF");
    csv_export_row($handle, $summary['headers']);
    foreach ($summary['rows'] as $row) {
        csv_export_row($handle, $row);
    }
    csv_export_row($handle, ['Label', '=1+1', '2']);
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    bhw_assert(is_string($csv) && str_starts_with($csv, "\xEF\xBB\xBF"), 'csv starts with a UTF-8 BOM');
    bhw_assert(is_string($csv) && str_contains($csv, "'=1+1"), 'formula text is prefixed');
    bhw_assert(is_string($csv) && str_contains($csv, 'total_patients'), 'filtered summary rows are written');
}

$today = bhw_activity_resolve_dates([
    'period' => 'today',
    'date_from' => '2020-01-01',
    'date_to' => '2020-01-02',
    'q' => '  visit  ',
]);
bhw_assert($today['period'] === 'today' && $today['date_from'] === '' && $today['date_to'] === '', 'a selected period overrides the custom range');
bhw_assert($today['q'] === 'visit', 'search text is trimmed');

$custom = bhw_activity_resolve_dates([
    'period' => '',
    'date_from' => '2026-02-10',
    'date_to' => '2026-02-01',
]);
bhw_assert($custom['period'] === '' && $custom['date_from'] === '2026-02-01' && $custom['date_to'] === '2026-02-10', 'all-time custom dates are swapped onto an inclusive range');
bhw_assert(bhw_activity_resolve_dates(['period' => 'year', 'date_from' => 'bad'])['date_from'] === '', 'unknown periods do not keep an invalid date');

$search = bhw_activity_search_clause('patient');
bhw_assert(str_contains($search['sql'], '$.patient_name') && str_contains($search['sql'], '$.module') && str_contains($search['sql'], 'first_name'), 'search covers patient name and module');
bhw_assert(str_contains($search['sql'], "ESCAPE '!'"), 'search wildcards are escaped');
$emptySearch = bhw_activity_search_clause('   ');
bhw_assert($emptySearch['sql'] === '' && $emptySearch['params'] === [], 'blank search adds no predicate');

$activity = bhw_source('app/includes/bhw_activity.php');
$listStart = strpos($activity, 'function bhw_activity_list');
$listEnd = strpos($activity, 'function bhw_activity_send_csv');
$list = ($listStart !== false && $listEnd !== false) ? substr($activity, $listStart, $listEnd - $listStart) : '';
$partsStart = strpos($activity, 'function bhw_activity_query_parts');
$parts = ($partsStart !== false && $listStart !== false) ? substr($activity, $partsStart, $listStart - $partsStart) : '';
bhw_assert(str_contains($list, "\$exporting ? '' : ' LIMIT '"), 'the on-screen activity list stays paged');
bhw_assert(str_contains($parts, '} else {') && strpos($parts, 'date_from') > strpos($parts, '} else {'), 'custom dates are only applied when no period is selected');
bhw_assert(!str_contains($activity, '10000'), 'the old export cap is not used');

$activityApi = bhw_source('app/api/bhw/activity.php');
$reportsApi = bhw_source('app/api/bhw/reports.php');
bhw_assert(str_contains($activityApi, 'bhw_api_bootstrap') && str_contains($reportsApi, 'bhw_api_bootstrap'), 'both endpoints bootstrap an authenticated BHW');
bhw_assert(str_contains($activityApi, "case 'export'") && str_contains($reportsApi, "case 'export'"), 'csv downloads use the existing authenticated endpoints');
bhw_assert(
    !preg_match('/Api::error\([^)]*getMessage\(/', $activityApi)
    && !preg_match('/Api::error\([^)]*getMessage\(/', $reportsApi),
    'database errors are logged and not returned to the browser'
);
bhw_assert(str_contains($reportsApi, 'BhwReports::accessAllowed'), 'report export checks the assigned barangay');
bhw_assert(str_contains($activity, "\$role !== 'bhw'"), 'activity export rejects every role except bhw');

foreach (['admin', 'superadmin', 'provider', 'patient', '', 'bhw'] as $role) {
    $allowed = BhwReports::accessAllowed($role, $role === 'bhw' ? 4 : 9);
    bhw_assert($allowed === ($role === 'bhw'), $role === '' ? 'empty role is denied' : $role . ' report access follows the bhw rule');
}
bhw_assert(BhwReports::accessAllowed('bhw', 0) === false, 'an unassigned bhw cannot export healthcare reports');

$nav = bhw_source('app/includes/nav/bhw_nav.php');
bhw_assert(str_contains($nav, "'file' => 'reports/index.php', 'label' => 'Healthcare Reports'") && str_contains($nav, "'sidebar' => false"), 'healthcare reports stay hidden in the sidebar');
bhw_assert(str_contains($nav, "'file' => 'activity/index.php'") && str_contains($nav, "'sidebar' => true"), 'activity log stays in the sidebar');

$reportView = bhw_source('resources/views/bhw/reports/index.php');
foreach (['rf_date_from', 'rf_date_to', 'rf_year', 'rf_month', 'rf_gender', 'rf_age', 'rf_purok', 'rf_consult_status', 'rf_referral_status', 'rf_filter_hint'] as $id) {
    bhw_assert(str_contains($reportView, 'id="' . $id . '"'), 'report filter ' . $id . ' is rendered');
}
$activityView = bhw_source('resources/views/bhw/activity/index.php');
bhw_assert(str_contains($activityView, 'id="act_range_hint"') && str_contains($activityView, 'overrides the custom date range'), 'activity page states the date precedence');
bhw_assert(str_contains($activityView, 'Patient name, action, module'), 'activity search placeholder matches the query');

$reportJs = bhw_source('public/assets/js/bhw-reports.js');
$activityJs = bhw_source('public/assets/js/bhw-activity.js');
bhw_assert(str_contains($reportJs, '/app/api/bhw/reports.php') && str_contains($reportJs, "action: 'export'"), 'report csv uses the reports endpoint');
bhw_assert(!str_contains($reportJs, 'export_report.php'), 'report csv no longer points at the missing file');
bhw_assert(str_contains($activityJs, '/app/api/bhw/activity.php') && str_contains($activityJs, "action', 'export'"), 'activity csv uses the activity endpoint');
bhw_assert(!str_contains($activityJs, 'export_activity.php'), 'activity csv no longer points at the missing file');
bhw_assert(str_contains($reportJs, 'el.disabled'), 'disabled report filters are omitted');

bhw_assert(bhw_activity_display_module([], 'bhw_records_viewed') === 'Records', 'a missing module uses the action fallback');
bhw_assert(bhw_activity_display_module(['module' => ''], 'bhw_records_viewed') === 'Records', 'an empty module uses the action fallback');
bhw_assert(bhw_activity_display_module(['module' => '   '], 'bhw_records_viewed') === 'Records', 'a blank module uses the action fallback');
bhw_assert(bhw_activity_display_module(['module' => 'Custom Desk'], 'bhw_records_viewed') === 'Custom Desk', 'a stored module is shown as entered');
[$moduleSql] = bhw_activity_query_parts(4, ['module' => 'Records']);
bhw_assert(str_contains($moduleSql, bhw_activity_displayed_module_sql()) && str_contains($moduleSql, 'bhw_records_viewed'), 'the module filter uses the displayed-module fallback');

bhw_assert(str_contains($reports, "patientWhere(\$pdo, \$ctx, \$f, \$monthParams, 'pr', false)"), 'new this month ignores the selected report dates');
bhw_assert(str_contains($reports, 'new_patients_month_note'), 'new this month explains that it is the current calendar month');
bhw_assert(substr_count($reports, 'self::ensureSchema($pdo)') === 0, 'report queries do not call schema setup');
bhw_assert(!str_contains($reports, 'upsertPatientPurok') && !str_contains($reports, 'BagoBarangayPuroks::ensureSchema'), 'purok lists do not create or insert purok rows');
bhw_assert(str_contains($reports, "bhw_patient_sector_clause(\$pdo, \$ctx, \$pr, false)"), 'report scope does not backfill barangay ids');
bhw_assert(str_contains($reportsApi, 'bhw_api_bootstrap($pdo, false, true, false)'), 'report requests skip schema helpers');
bhw_assert(str_contains($activityApi, 'bhw_api_bootstrap($pdo, ($_SERVER[\'REQUEST_METHOD\'] ?? \'\') === \'POST\', true, false)'), 'activity requests skip schema helpers');
bhw_assert(str_contains($activityApi, 'Activity log is unavailable.'), 'a failed activity query is not returned as an empty log');
bhw_assert(str_contains($reportsApi, 'Report data is unavailable.'), 'a failed report query is not returned as zero');

$pageBootstrap = bhw_source('resources/views/bhw/partials/bhw_bootstrap.php');
bhw_assert(str_contains($pageBootstrap, 'reports/index.php') && str_contains($pageBootstrap, 'activity/index.php') && str_contains($pageBootstrap, 'if (!$bhw_read_only_page)'), 'report and activity pages skip barangay backfill');
bhw_assert(str_contains($pageBootstrap, 'profile_picture_sync_session_if_present'), 'report and activity pages do not add the profile-picture column');

$migration = bhw_source('database/migrations/2026_10_17_bhw_reports_read_only.sql');
bhw_assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS') && !preg_match('/\bUPDATE\b/i', $migration) && !preg_match('/\bDROP\b/i', $migration), 'the report migration creates missing objects without rewriting data');

$sendStart = strpos($activity, 'function bhw_activity_send_csv');
$send = $sendStart !== false ? substr($activity, $sendStart) : '';
bhw_assert(str_contains($send, "fopen('php://temp'") && str_contains($send, 'LIMIT {$batchSize}') && !str_contains($send, 'bhw_activity_list('), 'activity csv streams every matching row in bounded batches');

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $sqlite = new PDO('sqlite::memory:');
    BhwReports::beginQueries();
    $scalar = new ReflectionMethod(BhwReports::class, 'scalar');
    $scalar->setAccessible(true);
    $failedValue = $scalar->invoke(null, $sqlite, 'SELECT missing_column FROM missing_table', []);
    bhw_assert($failedValue === 0 && BhwReports::queryFailed(), 'a failed count is marked unavailable instead of a real zero');
    BhwReports::beginQueries();
    $zeroValue = $scalar->invoke(null, $sqlite, 'SELECT 0', []);
    bhw_assert($zeroValue === 0 && !BhwReports::queryFailed(), 'a successful zero stays a real zero');
} else {
    echo "SKIP  sqlite query-failure check (pdo_sqlite is not loaded)\n";
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
