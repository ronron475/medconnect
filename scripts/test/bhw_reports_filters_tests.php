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
