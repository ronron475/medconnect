<?php
/**
 * Patient-controlled BHW prescription sharing.
 * Uses SQLite only. Does not connect to MySQL or change the application database.
 *
 * CLI: php scripts/test/bhw_prescription_sharing_tests.php
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
require_once $root . '/app/includes/bhw_prescription_access.php';

$pass = 0;
$fail = 0;

function rx_assert(bool $ok, string $label): void
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

final class RxShareTestPdo extends PDO
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

        return parent::exec($statement);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = preg_replace('/\s+ON DUPLICATE KEY UPDATE.+$/is', '', $query) ?? $query;
        $query = preg_replace('/^INSERT INTO/i', 'INSERT OR IGNORE INTO', $query) ?? $query;
        $query = preg_replace('/CONVERT\((.+?) USING utf8mb4\)/i', '$1', $query) ?? $query;
        $query = str_replace(' COLLATE utf8mb4_unicode_ci', '', $query);

        return parent::prepare($query, $options);
    }
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    rx_assert(false, 'sqlite is available');
    echo "\n{$pass} passed, {$fail} failed\n";
    exit(1);
}

$pdo = new RxShareTestPdo('sqlite::memory:');
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
    CREATE TABLE patient_privacy_preferences (
        user_id INTEGER PRIMARY KEY,
        share_medical_records INTEGER NOT NULL DEFAULT 1,
        emergency_access_consent INTEGER NOT NULL DEFAULT 1,
        data_privacy_acknowledged INTEGER NOT NULL DEFAULT 1,
        share_prescriptions_with_barangay_bhw INTEGER NOT NULL DEFAULT 0
    )
');
$pdo->exec('
    CREATE TABLE consultations (
        id INTEGER PRIMARY KEY,
        patient_id INTEGER NOT NULL,
        status TEXT NOT NULL
    )
');
$pdo->exec('
    CREATE TABLE prescriptions (
        id INTEGER PRIMARY KEY,
        consultation_id INTEGER,
        patient_id INTEGER NOT NULL,
        provider_id INTEGER NOT NULL,
        medication_name TEXT NOT NULL,
        dosage TEXT NOT NULL,
        frequency TEXT NOT NULL,
        duration TEXT NOT NULL,
        notes TEXT,
        verification_token TEXT,
        status TEXT,
        created_at TEXT
    )
');

$pdo->prepare('INSERT INTO users (id, role, email, barangay_id) VALUES (?, ?, ?, ?)')->execute([1, 'patient', 'patient@example.test', 5]);
$pdo->prepare('INSERT INTO patient_registrations (id, user_id, email, barangay, barangay_id) VALUES (?, ?, ?, ?, ?)')->execute([1, 1, 'patient@example.test', 'Test Norte', 5]);
$pdo->prepare("INSERT INTO consultations (id, patient_id, status) VALUES (?, ?, ?)")->execute([10, 1, 'completed']);
$pdo->prepare("INSERT INTO consultations (id, patient_id, status) VALUES (?, ?, ?)")->execute([11, 1, 'completed']);
$pdo->prepare("INSERT INTO consultations (id, patient_id, status) VALUES (?, ?, ?)")->execute([12, 1, 'in_consultation']);
$pdo->prepare("INSERT INTO consultations (id, patient_id, status) VALUES (?, ?, ?)")->execute([13, 1, 'completed']);

$insertRx = $pdo->prepare('
    INSERT INTO prescriptions
        (id, consultation_id, patient_id, provider_id, medication_name, dosage, frequency, duration, notes, verification_token, status, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
');
$insertRx->execute([1, 10, 1, 9, 'Amoxicillin', '500 mg', '3x daily', '7 days', 'Private note', 'abc123token', 'active', '2026-10-01 09:00:00']);
$insertRx->execute([2, 11, 1, 9, 'RevokedMed', '10 mg', 'once', '1 day', 'Do not share', 'revokedtoken', 'revoked', '2026-10-02 09:00:00']);
$insertRx->execute([3, 12, 1, 9, 'DraftMed', '5 mg', 'once', '1 day', 'Not finalized', 'drafttoken', 'active', '2026-10-03 09:00:00']);
$insertRx->execute([4, 13, 1, 9, 'LegacyMed', '20 mg', 'daily', '5 days', 'Legacy note', null, null, '2026-09-01 09:00:00']);

$sameBarangay = ['barangay_id' => 5, 'barangay_name' => 'Test Norte'];
$otherBarangay = ['barangay_id' => 9, 'barangay_name' => 'Other Barangay'];

$missing = bhw_records_prescription_view($pdo, $sameBarangay, 1);
rx_assert($missing['denied'] === false && $missing['shared'] === false && $missing['prescriptions'] === [], 'a patient with no sharing row gives the BHW no prescription details');
rx_assert(!str_contains(json_encode($missing), 'Amoxicillin'), 'the no-preference response does not contain a medication name');

$pdo->prepare('INSERT INTO patient_privacy_preferences (user_id, share_prescriptions_with_barangay_bhw) VALUES (?, ?)')->execute([1, 0]);
$off = bhw_records_prescription_view($pdo, $sameBarangay, 1);
rx_assert($off['shared'] === false && $off['prescriptions'] === [], 'an explicit off preference gives the BHW no prescription details');

$pdo->prepare('UPDATE patient_privacy_preferences SET share_prescriptions_with_barangay_bhw = 1 WHERE user_id = ?')->execute([1]);
$on = bhw_records_prescription_view($pdo, $sameBarangay, 1);
$names = array_column($on['prescriptions'], 'medication_name');
rx_assert($on['denied'] === false && $on['shared'] === true, 'sharing on allows the same-barangay BHW request');
rx_assert($names === ['Amoxicillin', 'LegacyMed'] || $names === ['LegacyMed', 'Amoxicillin'], 'the same-barangay BHW sees completed active and legacy prescriptions');
rx_assert(!in_array('RevokedMed', $names, true), 'a revoked prescription is excluded');
rx_assert(!in_array('DraftMed', $names, true), 'a prescription on a consultation that is not completed is excluded');
$visible = $on['prescriptions'][0];
rx_assert(
    array_keys($visible) === ['medication_name', 'dosage', 'frequency', 'duration', 'created_at'],
    'the BHW payload keeps only dispensing fields'
);
rx_assert(!str_contains(json_encode($on), 'Private note') && !str_contains(json_encode($on), 'abc123token'), 'provider notes and verification tokens are not in the BHW payload');

$pdo->prepare('UPDATE patient_privacy_preferences SET share_prescriptions_with_barangay_bhw = 0 WHERE user_id = ?')->execute([1]);
$withdrawn = bhw_records_prescription_view($pdo, $sameBarangay, 1);
rx_assert($withdrawn['shared'] === false && $withdrawn['prescriptions'] === [], 'withdrawing permission removes prescription details from the next request');
rx_assert(!str_contains(json_encode($withdrawn), 'Amoxicillin'), 'a withdrawn response does not contain a medication name');

$pdo->prepare('UPDATE patient_privacy_preferences SET share_prescriptions_with_barangay_bhw = 1 WHERE user_id = ?')->execute([1]);
$outsider = bhw_records_prescription_view($pdo, $otherBarangay, 1);
rx_assert($outsider['denied'] === true && $outsider['prescriptions'] === [], 'a BHW from another barangay is denied even when sharing is on');
rx_assert(!str_contains(json_encode($outsider), 'Amoxicillin'), 'a denied barangay response does not contain prescription details');

$records = (string) file_get_contents($root . '/app/api/bhw/records.php');
$scope = (string) file_get_contents($root . '/app/includes/bhw_scope.php');
$sectorAt = strpos($records, 'bhw_api_require_patient_in_sector');
$viewAt = strpos($records, 'bhw_records_prescription_view');
rx_assert(str_contains($scope, "Api::requireRole('bhw')"), 'the BHW records bootstrap still requires the BHW role');
rx_assert($sectorAt !== false && $viewAt !== false && $sectorAt < $viewAt, 'the records endpoint checks the barangay before loading prescriptions');
rx_assert(substr_count($records, 'bhw_api_require_patient_in_sector') === 1, 'the records list checks the barangay once');
rx_assert(!str_contains($records, "FROM prescriptions"), 'the records endpoint does not query prescriptions on its own');

$notify = (string) file_get_contents($root . '/app/includes/notification_events.php');
$notifyStart = strpos($notify, 'function prescriptionAvailable');
$notifyEnd = strpos($notify, 'function gisHotspotDetected');
$notifyFn = substr($notify, $notifyStart, $notifyEnd - $notifyStart);
$patientAt = strpos($notifyFn, 'notifyPatient');
$providerAt = strpos($notifyFn, 'notifyProvider');
$gateAt = strpos($notifyFn, 'patient_shares_prescriptions_with_barangay_bhw');
$bhwAt = strpos($notifyFn, 'notifyBhwForPatient');
rx_assert($patientAt !== false && $providerAt !== false && $gateAt !== false && $bhwAt !== false, 'prescription notifications still include the patient, provider, and BHW paths');
rx_assert($patientAt < $gateAt && $providerAt < $gateAt && $gateAt < $bhwAt, 'the BHW prescription notice is sent only after the sharing check');
rx_assert(!str_contains($notifyFn, 'medication_name') && !str_contains($notifyFn, 'dosage'), 'prescription notifications do not include medication details');

foreach ([
    'resources/views/patient/my_health.php',
    'resources/views/patient/consultation_detail.php',
    'resources/views/provider/medical_records.php',
    'app/includes/admin_patient_records.php',
    'app/api/provider/create_prescription.php',
] as $path) {
    $source = (string) file_get_contents($root . '/' . $path);
    rx_assert(!str_contains($source, 'share_prescriptions_with_barangay_bhw'), $path . ' does not use the BHW sharing preference');
}
rx_assert(str_contains((string) file_get_contents($root . '/resources/views/patient/my_health.php'), "c.status = 'completed'"), 'patient My Health still lists prescriptions from completed consultations');
rx_assert(str_contains((string) file_get_contents($root . '/resources/views/provider/medical_records.php'), 'pr.provider_id = ?'), 'provider medical records still limit prescriptions to the issuing provider');

$settings = (string) file_get_contents($root . '/app/includes/patient_settings.php');
rx_assert(str_contains($settings, 'NOT NULL DEFAULT 0'), 'the new preference column defaults to off');
rx_assert(str_contains($settings, 'WHERE user_id = ?'), 'privacy settings are saved for the authenticated patient id');
$saveApi = (string) file_get_contents($root . '/app/api/patient/settings/save_privacy.php');
rx_assert(str_contains($saveApi, 'patient_settings_require_patient_ready') && !str_contains($saveApi, "\$_POST['user_id']"), 'the privacy save endpoint does not accept another patient id');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
