<?php
/**
 * BHW Records consultation history is limited to qualifying follow-ups.
 * Uses SQLite only. Does not connect to MySQL or change the application database.
 *
 * CLI: php scripts/test/bhw_consultation_history_tests.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $root);
}
if (!defined('VIEWS_PATH')) {
    define('VIEWS_PATH', $root . '/resources/views');
}

require_once $root . '/app/includes/bhw_scope.php';
require_once $root . '/app/includes/bhw_records_history.php';
require_once $root . '/app/includes/bhw_reports.php';

$pass = 0;
$fail = 0;

function history_assert(bool $ok, string $label): void
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

final class HistoryTestPdo extends PDO
{
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (preg_match('/SHOW COLUMNS FROM\s+(\w+)/i', $query, $match)) {
            return parent::query("SELECT name FROM pragma_table_info('" . $match[1] . "')");
        }

        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        if (
            stripos($statement, 'ENGINE=InnoDB') !== false
            || stripos($statement, 'ALTER TABLE') !== false
            || stripos($statement, 'INNER JOIN') !== false
        ) {
            return 0;
        }
        $statement = str_replace('NOW()', "datetime('now')", $statement);

        return parent::exec($statement);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = preg_replace('/\s+ON DUPLICATE KEY UPDATE.+$/is', '', $query) ?? $query;
        $query = preg_replace('/^INSERT INTO/i', 'INSERT OR IGNORE INTO', $query) ?? $query;
        $query = preg_replace('/CONVERT\((.+?) USING utf8mb4\)/i', '$1', $query) ?? $query;
        $query = str_replace(' COLLATE utf8mb4_unicode_ci', '', $query);
        $query = str_replace('NOW()', "datetime('now')", $query);
        $query = str_replace(
            "TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')))",
            "TRIM(COALESCE(u.first_name, '') || ' ' || COALESCE(u.last_name, ''))",
            $query
        );

        return parent::prepare($query, $options);
    }
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    history_assert(false, 'sqlite is available');
    echo "\n{$pass} passed, {$fail} failed\n";
    exit(1);
}

$pdo = new HistoryTestPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('
    CREATE TABLE barangays (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        city TEXT NOT NULL,
        latitude REAL,
        longitude REAL,
        psgc_code TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        archived_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )
');
$pdo->exec('
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        role TEXT NOT NULL,
        email TEXT,
        first_name TEXT,
        last_name TEXT,
        barangay_id INTEGER
    )
');
$pdo->exec('
    CREATE TABLE patient_registrations (
        id INTEGER PRIMARY KEY,
        user_id INTEGER,
        email TEXT,
        barangay TEXT,
        barangay_id INTEGER
    )
');
$pdo->exec('
    CREATE TABLE consultations (
        id INTEGER PRIMARY KEY,
        patient_id INTEGER NOT NULL,
        provider_id INTEGER,
        provider_name TEXT,
        consult_date TEXT,
        consult_time TEXT,
        status TEXT,
        diagnosis TEXT,
        recommendation TEXT,
        triage_result_id INTEGER
    )
');
$pdo->exec('
    CREATE TABLE clinical_notes (
        id INTEGER PRIMARY KEY,
        consultation_id INTEGER,
        patient_id INTEGER,
        subjective TEXT,
        assessment TEXT
    )
');
$pdo->exec('
    CREATE TABLE followups (
        id INTEGER PRIMARY KEY,
        consultation_id INTEGER,
        patient_id INTEGER NOT NULL,
        provider_id INTEGER,
        message TEXT,
        notes TEXT,
        status TEXT
    )
');

$pdo->prepare('INSERT INTO users (id, role, email, first_name, last_name, barangay_id) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([1, 'patient', 'patient@example.test', 'Ana', 'Reyes', 5]);
$pdo->prepare('INSERT INTO users (id, role, email, first_name, last_name, barangay_id) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([2, 'patient', 'other@example.test', 'Ben', 'Cruz', 5]);
$pdo->prepare('INSERT INTO users (id, role, email, first_name, last_name, barangay_id) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([9, 'provider', 'doctor@example.test', 'Liza', 'Santos', null]);
$pdo->prepare('INSERT INTO patient_registrations (id, user_id, email, barangay, barangay_id) VALUES (?, ?, ?, ?, ?)')
    ->execute([1, 1, 'patient@example.test', 'Test Norte', 5]);
$pdo->prepare('INSERT INTO patient_registrations (id, user_id, email, barangay, barangay_id) VALUES (?, ?, ?, ?, ?)')
    ->execute([2, 2, 'other@example.test', 'Test Norte', 5]);

$insertConsult = $pdo->prepare('
    INSERT INTO consultations
        (id, patient_id, provider_id, provider_name, consult_date, consult_time, status, diagnosis, recommendation)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
');
$insertConsult->execute([10, 1, 9, '', '2026-10-01', '09:00:00', 'completed', 'Secret diagnosis', 'Secret recommendation']);
$insertConsult->execute([11, 1, 9, 'Dr. Fallback', '2026-09-01', '08:00:00', 'completed', 'Unlinked diagnosis', 'Unlinked plan']);
$insertConsult->execute([12, 1, 9, '', '2026-08-01', '07:00:00', 'completed', 'Cancelled visit diagnosis', 'Cancelled visit plan']);
$insertConsult->execute([20, 2, 9, '', '2026-10-02', '10:00:00', 'completed', 'Other patient diagnosis', 'Other patient plan']);
$pdo->prepare('INSERT INTO clinical_notes (id, consultation_id, patient_id, subjective, assessment) VALUES (?, ?, ?, ?, ?)')
    ->execute([1, 10, 1, 'SOAP subjective', 'SOAP assessment']);

$insertFollow = $pdo->prepare('INSERT INTO followups (id, consultation_id, patient_id, provider_id, message, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
$insertFollow->execute([1, 10, 1, 9, 'Check blood pressure', 'Older note', 'scheduled']);
$insertFollow->execute([2, 12, 1, 9, 'Do not show', 'Cancelled note', 'cancelled']);
$insertFollow->execute([3, 20, 2, 9, '', 'Watch the wound', 'completed']);
$insertFollow->execute([5, 11, 2, 9, 'Mismatched patient follow-up', 'Wrong patient note', 'scheduled']);

$same = ['barangay_id' => 5, 'barangay_name' => 'Test Norte'];
$other = ['barangay_id' => 9, 'barangay_name' => 'Other Barangay'];

$patientTwo = bhw_records_consultations($pdo, $same, 2);
history_assert(count($patientTwo) === 1 && ($patientTwo[0]['instructions'] ?? '') === 'Watch the wound', 'a qualifying follow-up with notes supplies the instructions');

$history = bhw_records_consultations($pdo, $same, 1);
history_assert(count($history) === 1, 'only the consultation with a qualifying follow-up is returned');
$row = $history[0] ?? [];
history_assert(
    array_keys($row) === ['consult_date', 'consult_time', 'status', 'provider_name', 'instructions'],
    'the history payload keeps only the approved fields'
);
history_assert(($row['consult_date'] ?? '') === '2026-10-01' && ($row['consult_time'] ?? '') === '09:00:00', 'the visit date and time come from the linked consultation');
history_assert(($row['status'] ?? '') === 'completed', 'the consultation status is returned');
history_assert(($row['provider_name'] ?? '') === 'Liza Santos', 'the provider name is returned');
history_assert(($row['instructions'] ?? '') === 'Check blood pressure', 'follow-up instructions come from the follow-up message');
$encoded = json_encode($history);
history_assert(
    !str_contains((string) $encoded, 'Mismatched patient follow-up')
    && !str_contains((string) json_encode($patientTwo), 'Mismatched patient follow-up')
    && ($row['consult_date'] ?? '') !== '2026-09-01',
    'a follow-up whose patient id does not match the consultation is excluded'
);
history_assert(
    !str_contains((string) $encoded, 'Secret diagnosis')
    && !str_contains((string) $encoded, 'Secret recommendation')
    && !str_contains((string) $encoded, 'Unlinked diagnosis')
    && !str_contains((string) $encoded, 'Cancelled visit')
    && !str_contains((string) $encoded, 'SOAP'),
    'diagnosis, recommendation, unlinked visits, cancelled follow-ups, and SOAP notes are absent'
);

$pdo->prepare("UPDATE followups SET status = 'canceled', message = 'Still hidden' WHERE id = 2")->execute();
$still = bhw_records_consultations($pdo, $same, 1);
history_assert(count($still) === 1 && ($still[0]['consult_date'] ?? '') === '2026-10-01', 'a canceled follow-up does not add its consultation');

$pdo->prepare('DELETE FROM followups WHERE patient_id = 1')->execute();
$empty = bhw_records_consultations($pdo, $same, 1);
history_assert($empty === [], 'removing the qualifying follow-up removes the consultation history');

$pdo->prepare('INSERT INTO followups (id, consultation_id, patient_id, provider_id, message, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute([4, 10, 1, 9, 'Recheck fever', '', 'missed']);
$restored = bhw_records_consultations($pdo, $same, 1);
history_assert(count($restored) === 1 && ($restored[0]['instructions'] ?? '') === 'Recheck fever', 'a missed follow-up still qualifies');

$denied = bhw_records_consultations($pdo, $other, 1);
history_assert($denied === [], 'a BHW context from another barangay receives no consultation history');
history_assert(!str_contains((string) json_encode($denied), 'Recheck fever'), 'the denied response has no follow-up instructions');

$records = (string) file_get_contents($root . '/app/api/bhw/records.php');
$sectorAt = strpos($records, 'bhw_api_require_patient_in_sector');
$historyAt = strpos($records, 'bhw_records_consultations');
history_assert($sectorAt !== false && $historyAt !== false && $sectorAt < $historyAt, 'the records endpoint checks the barangay before loading consultation history');
history_assert(!str_contains($records, 'c.diagnosis') && !str_contains($records, 'chief_complaint'), 'the records endpoint no longer selects diagnosis or chief complaint');

$historySource = (string) file_get_contents($root . '/app/includes/bhw_records_history.php');
history_assert(!str_contains($historySource, 'clinical_notes') && !str_contains($historySource, 'c.diagnosis') && !str_contains($historySource, 'c.recommendation'), 'the history query does not read SOAP notes, diagnosis, or recommendation');
history_assert(str_contains($historySource, "NOT IN ('cancelled', 'canceled')"), 'cancelled follow-ups are excluded in the query');

foreach ([
    'app/api/bhw/followups.php',
    'app/includes/bhw_reports.php',
    'resources/views/patient/my_health.php',
    'resources/views/provider/medical_records.php',
    'app/includes/admin_patient_records.php',
] as $path) {
    $source = (string) file_get_contents($root . '/' . $path);
    history_assert(!str_contains($source, 'bhw_records_consultations'), $path . ' is not routed through the Records history gate');
}
$workflowSource = (string) file_get_contents($root . '/app/includes/bhw_workflows.php');
history_assert(!str_contains($workflowSource, 'function listConsultations') && !str_contains($workflowSource, 'function listReferrals') && !str_contains($workflowSource, 'function logHomeVisit'), 'consultation listing, referral listing, and home-visit logging are removed');
history_assert(str_contains($workflowSource, 'function getFollowup'), 'follow-up detail remains in place');
$videoRoom = (string) file_get_contents($root . '/resources/views/consultation/video_room.php');
history_assert(!str_contains($videoRoom, "\$role === 'bhw'"), 'the shared video room does not authorize a BHW');

$pdo->prepare('INSERT INTO users (id, role, email, first_name, last_name, barangay_id) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([3, 'patient', 'foreign@example.test', 'Cora', 'Diaz', 9]);
$pdo->prepare('INSERT INTO patient_registrations (id, user_id, email, barangay, barangay_id) VALUES (?, ?, ?, ?, ?)')
    ->execute([3, 3, 'foreign@example.test', 'Other Barangay', 9]);
$insertConsult->execute([30, 3, 9, '', '2026-10-03', '11:00:00', 'completed', 'Foreign diagnosis', 'Foreign plan']);
$insertFollow->execute([6, 30, 3, 9, 'Foreign instructions', '', 'scheduled']);

history_assert(bhw_assert_patient_in_sector($pdo, $other, 1) === false, 'cross-barangay patient access is denied before history is read');
history_assert(bhw_records_consultations($pdo, $other, 1) === [], 'a direct history request from another barangay returns no rows');
$foreignHistory = bhw_records_consultations($pdo, $other, 3);
history_assert(
    count($foreignHistory) === 1
    && ($foreignHistory[0]['instructions'] ?? '') === 'Foreign instructions'
    && array_keys($foreignHistory[0]) === ['consult_date', 'consult_time', 'status', 'provider_name', 'instructions'],
    'the other barangay still receives only the approved fields for its own follow-up'
);
history_assert(bhw_records_consultations($pdo, $same, 3) === [], 'this barangay does not receive the other barangay history');
$directHistory = bhw_records_consultations($pdo, $same, 1);
history_assert(
    array_keys($directHistory[0] ?? []) === ['consult_date', 'consult_time', 'status', 'provider_name', 'instructions'],
    'a direct history request returns only the approved fields'
);

$kept = BhwReports::omitSmallIdentifyingGroups([
    ['label' => 'Unique fever', 'value' => 1],
    ['label' => 'Common cough', 'value' => BhwReports::IDENTIFYING_LABEL_MIN_COUNT],
]);
history_assert(count($kept) === 1 && ($kept[0]['label'] ?? '') === 'Common cough', 'chief-complaint and diagnosis groups below the minimum size are omitted');
$csv = BhwReports::exportTable('disease', [
    'top_symptoms' => [['label' => 'Unique fever', 'value' => 1]],
    'diagnoses' => [['label' => 'Secret diagnosis label', 'value' => 1]],
    'age_groups' => [['label' => 'Adults', 'value' => 3]],
    'monthly_trends' => [],
], 'Test Norte', []);
$csvText = (string) json_encode($csv);
history_assert(!str_contains($csvText, 'Unique fever') && !str_contains($csvText, 'Secret diagnosis label') && str_contains($csvText, 'Adults'), 'disease CSV export does not write complaint or diagnosis labels');
history_assert(substr_count((string) file_get_contents($root . '/app/includes/bhw_reports.php'), 'omitSmallIdentifyingGroups') >= 4, 'triage and disease report JSON apply the group-size filter');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
