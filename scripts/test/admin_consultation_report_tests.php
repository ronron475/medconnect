<?php
/**
 * Consultation & Appointment Report filters, paging, and CSV safety.
 * Read-only against the database when MySQL is reachable. No inserts.
 *
 * CLI: php scripts/test/admin_consultation_report_tests.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/includes/admin_operational_reports.php';
require_once $root . '/app/includes/csv_export.php';

date_default_timezone_set('Asia/Manila');

$pass = 0;
$fail = 0;

function report_assert(bool $ok, string $label): void
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

function manila(string $when): DateTimeImmutable
{
    return new DateTimeImmutable($when, new DateTimeZone('Asia/Manila'));
}

$saturday = manila('2026-10-17 15:00:00');
$bounds = admin_consultation_report_date_bounds('today', '', '', $saturday);
report_assert($bounds['from'] === '2026-10-17' && $bounds['to'] === '2026-10-17', 'today is the Manila calendar day');

$bounds = admin_consultation_report_date_bounds('yesterday', '2026-01-01', '2026-01-02', $saturday);
report_assert($bounds['from'] === '2026-10-16' && $bounds['to'] === '2026-10-16', 'yesterday ignores a custom range');

$bounds = admin_consultation_report_date_bounds('this_week', '', '', $saturday);
report_assert($bounds['from'] === '2026-10-12' && $bounds['to'] === '2026-10-18', 'this week is Monday through Sunday');

$bounds = admin_consultation_report_date_bounds('this_week', '', '', manila('2026-10-12 00:30:00'));
report_assert($bounds['from'] === '2026-10-12' && $bounds['to'] === '2026-10-18', 'Monday is the first day of this week');

$bounds = admin_consultation_report_date_bounds('this_week', '', '', manila('2026-10-18 23:30:00'));
report_assert($bounds['from'] === '2026-10-12' && $bounds['to'] === '2026-10-18', 'Sunday is the last day of this week');

$bounds = admin_consultation_report_date_bounds('last_week', '', '', $saturday);
report_assert($bounds['from'] === '2026-10-05' && $bounds['to'] === '2026-10-11', 'last week is the previous Monday through Sunday');

$bounds = admin_consultation_report_date_bounds('this_week', '', '', manila('2026-01-01 10:00:00'));
report_assert($bounds['from'] === '2025-12-29' && $bounds['to'] === '2026-01-04', 'a week can cross the year boundary');

$bounds = admin_consultation_report_date_bounds('last_month', '', '', manila('2026-03-01 00:00:00'));
report_assert($bounds['from'] === '2026-02-01' && $bounds['to'] === '2026-02-28', 'last month in a non-leap year ends on Feb 28');

$bounds = admin_consultation_report_date_bounds('last_month', '', '', manila('2024-03-01 00:00:00'));
report_assert($bounds['from'] === '2024-02-01' && $bounds['to'] === '2024-02-29', 'last month in a leap year ends on Feb 29');

$bounds = admin_consultation_report_date_bounds('this_month', '', '', manila('2026-01-31 23:00:00'));
report_assert($bounds['from'] === '2026-01-01' && $bounds['to'] === '2026-01-31', 'this month covers the first and last day');

$bounds = admin_consultation_report_date_bounds('last_month', '', '', manila('2026-01-01 00:05:00'));
report_assert($bounds['from'] === '2025-12-01' && $bounds['to'] === '2025-12-31', 'last month from January is the previous December');

$bounds = admin_consultation_report_date_bounds('this_year', '', '', $saturday);
report_assert($bounds['from'] === '2026-01-01' && $bounds['to'] === '2026-12-31', 'this year is January 1 through December 31');

$bounds = admin_consultation_report_date_bounds('custom', '2026-01-15', '2026-01-10');
report_assert($bounds['from'] === '2026-01-10' && $bounds['to'] === '2026-01-15', 'a reversed custom range is swapped and stays inclusive');

$bounds = admin_consultation_report_date_bounds('custom', '2026-05-01', '2026-05-01');
report_assert($bounds['from'] === '2026-05-01' && $bounds['to'] === '2026-05-01', 'a one-day custom range includes that day');

$bounds = admin_consultation_report_date_bounds('custom', '2026-02-31', 'nope');
report_assert($bounds['from'] === null && $bounds['to'] === null, 'invalid custom dates add no date constraint');

$bounds = admin_consultation_report_date_bounds('all', '2026-01-01', '2026-01-31', $saturday);
report_assert($bounds['from'] === null && $bounds['to'] === null, 'all dates ignores typed dates');

$bounds = admin_consultation_report_date_bounds('not-a-preset', '', '', $saturday);
report_assert($bounds['period'] === 'all' && $bounds['from'] === null, 'an unknown preset falls back to all dates');

$utcEvening = new DateTimeImmutable('2026-10-16 18:00:00', new DateTimeZone('UTC'));
$bounds = admin_consultation_report_date_bounds('today', '', '', $utcEvening);
report_assert($bounds['from'] === '2026-10-17', 'UTC evening maps to the next Manila date');

$raw = [
    'appt_period' => 'this_month',
    'appt_from' => '2020-01-01',
    'appt_to' => '2020-01-31',
    'appt_q' => 'MC-000028',
    'appt_provider' => '12',
    'appt_bhw' => '4',
    'appt_status' => "completed; DROP TABLE consultations",
    'appt_type' => 'Follow-up',
    'appt_slot' => 'booked',
    'appt_rx' => 'yes',
    'appt_ref' => 'no',
];
$filters = admin_consultation_report_parse_filters($raw, $saturday);
report_assert($filters['status'] === '' && $filters['period'] === 'this_month', 'a status outside the allow-list is dropped');
report_assert($filters['from'] === '2026-10-01' && $filters['to'] === '2026-10-31', 'this month wins over a submitted custom range');

$caps = [
    'booked_by_bhw' => true,
    'appointment_slots' => true,
    'prescriptions' => true,
    'referrals' => true,
];
$filters['status'] = 'completed';
$built = admin_consultation_report_statements($filters, $caps);
$sql = $built['select_sql'] . "\n" . $built['count_sql'];
report_assert(substr_count($sql, '?') === count($built['params']) * 2, 'select and count use the same bound parameters');
report_assert(!str_contains($sql, 'MC-000028') && in_array(28, $built['params'], true), 'patient code search stays in parameters');
$injected = admin_consultation_report_statements([
    'appt_q' => "Robert' OR 1=1",
    'appt_status' => 'completed',
], $caps, $saturday);
report_assert(!str_contains($injected['select_sql'], 'Robert') && !str_contains($injected['count_sql'], 'Robert'), 'search text is not concatenated into SQL');
report_assert(in_array('completed', $injected['params'], true), 'a valid status is bound with the search');
report_assert(str_contains($built['select_sql'], 'c.provider_id = ?') && in_array(12, $built['params'], true), 'provider filter is parameterized');
report_assert(str_contains($built['select_sql'], 'c.booked_by_bhw_id = ?') && in_array(4, $built['params'], true), 'assigned BHW filter is parameterized');
report_assert(str_contains($built['select_sql'], 'c.status = ?') && in_array('completed', $built['params'], true), 'consultation status filter is parameterized');
report_assert(str_contains($built['select_sql'], 'c.consult_type LIKE ?'), 'consultation type filter is parameterized');
report_assert(str_contains($built['select_sql'], 'appointment_slots s') && in_array('booked', $built['params'], true), 'appointment slot status uses EXISTS');
report_assert(str_contains($built['select_sql'], 'EXISTS (SELECT 1 FROM prescriptions rx'), 'prescription filter checks presence only');
report_assert(str_contains($built['select_sql'], 'NOT EXISTS (SELECT 1 FROM digital_referrals dr'), 'referral filter checks presence only');
report_assert(!str_contains($sql, 'diagnosis') && !str_contains($sql, 'medication_name') && !str_contains($sql, 'provider_notes') && !str_contains($sql, 'subjective'), 'clinical detail is not selected');
report_assert(str_contains($built['select_sql'], 'ORDER BY c.consult_date ASC, c.consult_time ASC, c.id ASC'), 'pages order by date, time, then id');
report_assert(!str_contains($built['count_sql'], 'ORDER BY') && !str_contains($built['count_sql'], 'LIMIT'), 'the count query has no page clause');
report_assert(substr_count($built['select_sql'], 'ORDER BY') === 1, 'ordering is applied once on the outer query');

$hidden = admin_consultation_report_statements($filters, [
    'booked_by_bhw' => false,
    'appointment_slots' => false,
    'prescriptions' => false,
    'referrals' => false,
]);
report_assert(!str_contains($hidden['select_sql'], 'booked_by_bhw_id'), 'missing BHW column is not queried');
report_assert(!str_contains($hidden['select_sql'], 'appointment_slots'), 'missing appointment link is not queried');
report_assert(!str_contains($hidden['select_sql'], 'prescriptions'), 'missing prescription link is not queried');
report_assert(!str_contains($hidden['select_sql'], 'digital_referrals'), 'missing referral link is not queried');
report_assert($built['count_sql'] !== '' && str_contains($hidden['count_sql'], 'c.consult_date >= ?'), 'date bounds stay on the count query');

$ids = [10, 20, 30, 40, 50];
$page1 = admin_consultation_report_page_bounds(count($ids), 1, 2);
$page2 = admin_consultation_report_page_bounds(count($ids), 2, 2);
$page3 = admin_consultation_report_page_bounds(count($ids), 3, 2);
$clamped = admin_consultation_report_page_bounds(count($ids), 9, 2);
$slice = static function (array $rows, array $bounds): array {
    return array_slice($rows, $bounds['offset'], $bounds['per_page']);
};
$a = $slice($ids, $page1);
$b = $slice($ids, $page2);
report_assert($a === [10, 20] && $b === [30, 40], 'page windows do not overlap');
report_assert($slice($ids, $page3) === [50], 'the last page returns the remaining rows');
report_assert($clamped['page'] === 3 && $clamped['offset'] === 4, 'a page past the end clamps to the last page');
report_assert(admin_consultation_report_page_bounds(0, 4, 25)['page'] === 1, 'an empty result stays on page 1');

$cells = admin_operational_report_appointment_values([
    'consultation_id' => 5,
    'patient_id' => 28,
    'patient_name' => 'Jane Doe',
    'doctor_name' => '',
    'patient_complaint' => '=HYPERLINK("http://example.test")',
    'consult_date' => '2026-10-17',
    'consult_time' => '09:30:00',
    'status' => 'completed',
]);
report_assert($cells[1] === 'MC-000028 · Jane Doe', 'the patient code is shown beside the name');
report_assert(csv_export_safe_cell($cells[3]) === "'=HYPERLINK(\"http://example.test\")", 'a formula-like complaint is prefixed for CSV');
report_assert($cells[2] === 'Unassigned' && $cells[6] === 'Completed' && $cells[4] === 'Oct 17, 2026', 'empty doctor, status, and date stay readable');

$bare = admin_operational_report_appointment_values([
    'consultation_id' => 1,
    'patient_id' => 0,
    'patient_name' => '=Jane',
    'doctor_name' => 'Ana Cruz',
    'patient_complaint' => 'General Consultation',
    'consult_date' => '2026-10-17',
    'consult_time' => '09:30:00',
    'status' => 'scheduled',
]);
report_assert(csv_export_safe_cell($bare[1]) === "'=Jane", 'a name that starts with = is escaped when no code is present');
report_assert(str_starts_with($bare[2], 'Dr. '), 'a provider name keeps the existing doctor prefix');

foreach (['admin' => true, 'superadmin' => true, 'bhw' => false, 'provider' => false, 'patient' => false, '' => false] as $role => $allowed) {
    report_assert(admin_operational_report_export_allowed((string) $role) === $allowed, 'export role ' . ($role === '' ? '(empty)' : $role));
}

$exportSource = (string) file_get_contents($root . '/app/api/admin/operational_reports_live.php');
$exportFn = (string) file_get_contents($root . '/app/includes/admin_operational_reports.php');
report_assert(str_contains($exportSource, "require_once dirname(dirname(dirname(__DIR__))) . '/app/api/admin/_auth.php';"), 'CSV endpoint loads the admin portal gate');
report_assert(str_contains($exportSource, "admin_operational_report_send_csv(\$pdo, \$_GET)"), 'the live reports endpoint streams CSV for export=csv');
report_assert(str_contains($exportFn, 'admin_consultation_report_parse_filters($query)'), 'CSV export uses the report filters');
report_assert(str_contains($exportFn, 'csv_export_row'), 'CSV export writes through the formula-safe helper');
report_assert(!str_contains($exportFn, 'medication_name'), 'CSV export does not add medication detail');

$params = admin_consultation_report_query_params($filters);
report_assert(!isset($params['appointments_page']) && ($params['appt_period'] ?? '') === 'this_month', 'pagination keeps the date preset and not a hard-coded page');

if (!@fsockopen('127.0.0.1', 3306, $errno, $errstr, 0.3)) {
    echo "SKIP  database consistency checks (MySQL is not reachable)\n";
} else {
    require_once $root . '/config/db.php';
    $empty = admin_consultation_report_parse_filters([
        'appt_period' => 'custom',
        'appt_from' => '1900-01-01',
        'appt_to' => '1900-01-01',
        'appt_status' => 'completed',
        'appt_provider' => '2147483647',
    ], $saturday);
    $page = admin_operational_report_page($pdo, 'appointments', 3, 25, 0, $empty);
    $all = admin_operational_report_all($pdo, 'appointments', 0, $empty);
    report_assert(($page['error'] ?? null) === null && (int) $page['total'] === 0 && $page['rows'] === [], 'an impossible filter returns an empty page without an error');
    report_assert($all === [], 'CSV rows for that filter are empty too');

    $open = admin_consultation_report_parse_filters(['appt_period' => 'all'], $saturday);
    $first = admin_operational_report_page($pdo, 'appointments', 1, 2, 0, $open);
    $second = admin_operational_report_page($pdo, 'appointments', 2, 2, 0, $open);
    $ids1 = array_map(static fn (array $row): string => (string) ($row[0] ?? ''), $first['rows']);
    $ids2 = array_map(static fn (array $row): string => (string) ($row[0] ?? ''), $second['rows']);
    $overlap = array_intersect($ids1, $ids2);
    report_assert(($first['error'] ?? null) === null && $overlap === [], 'live page 1 and page 2 do not repeat consultation ids');
    $expected = min(2, (int) $first['total']);
    report_assert(count($first['rows']) === $expected, 'page 1 length matches the filtered total');
    if ((int) $first['total'] > 2) {
        report_assert(count($second['rows']) === min(2, (int) $first['total'] - 2), 'page 2 length matches the remaining rows');
    } else {
        report_assert($second['rows'] === [], 'a short result has an empty second page');
    }

    $sampleBuilt = admin_consultation_report_statements($open, admin_consultation_report_capabilities($pdo));
    $sample = $pdo->prepare($sampleBuilt['select_sql'] . ' LIMIT 2 OFFSET 0');
    $sample->execute($sampleBuilt['params']);
    $ordered = $sample->fetchAll(PDO::FETCH_ASSOC);
    $inOrder = true;
    $prev = null;
    foreach ($ordered as $row) {
        $key = (string) ($row['consult_date'] ?? '') . ' ' . (string) ($row['consult_time'] ?? '') . ' ' . str_pad((string) ($row['consultation_id'] ?? ''), 12, '0', STR_PAD_LEFT);
        if ($prev !== null && $key < $prev) {
            $inOrder = false;
        }
        $prev = $key;
    }
    report_assert($inOrder, 'the first live rows are ordered by date, time, then id');
    if ($ordered !== [] && $first['rows'] !== []) {
        report_assert((string) ($ordered[0]['consultation_id'] ?? '') === (string) ($first['rows'][0][0] ?? ''), 'the table and the export query start on the same consultation');
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
