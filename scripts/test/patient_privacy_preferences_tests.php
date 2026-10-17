<?php
/**
 * Privacy preference save values and ON/OFF enforcement.
 * Uses SQLite only. Does not connect to MySQL or change the application database.
 *
 * CLI: php scripts/test/patient_privacy_preferences_tests.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/includes/patient_privacy_access.php';

$pass = 0;
$fail = 0;

function privacy_assert(bool $ok, string $label): void
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

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    privacy_assert(false, 'sqlite is available');
    echo "\n{$pass} passed, {$fail} failed\n";
    exit(1);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('
    CREATE TABLE patient_privacy_preferences (
        user_id INTEGER PRIMARY KEY,
        share_medical_records INTEGER NOT NULL DEFAULT 1,
        emergency_access_consent INTEGER NOT NULL DEFAULT 1,
        data_privacy_acknowledged INTEGER NOT NULL DEFAULT 1,
        share_prescriptions_with_barangay_bhw INTEGER NOT NULL DEFAULT 0
    )
');
privacy_assert(patient_shares_medical_records_with_providers($pdo, 1) === true, 'a patient with no privacy row still allows provider record access');
privacy_assert(patient_allows_emergency_record_access($pdo, 1) === true, 'a patient with no privacy row still allows the emergency access path');

$off = patient_privacy_values_from_input([]);
privacy_assert($off === [
    'share_medical_records' => 0,
    'emergency_access_consent' => 0,
    'data_privacy_acknowledged' => 0,
    'share_prescriptions_with_barangay_bhw' => 0,
], 'an empty privacy form stores every toggle as off, including acknowledgment');

$pdo->prepare('INSERT INTO patient_privacy_preferences (user_id, share_medical_records, emergency_access_consent, data_privacy_acknowledged, share_prescriptions_with_barangay_bhw) VALUES (1, ?, ?, ?, ?)')
    ->execute(array_values($off));

privacy_assert(patient_shares_medical_records_with_providers($pdo, 1) === false, 'provider record access is off after it is saved off');
privacy_assert(patient_allows_emergency_record_access($pdo, 1) === false, 'emergency record access is off after it is saved off');
privacy_assert(patient_privacy_flag($pdo, 1, 'data_privacy_acknowledged', true) === false, 'acknowledgment off is reloaded as off');

$on = patient_privacy_values_from_input([
    'share_medical_records' => '1',
    'emergency_access_consent' => '1',
    'data_privacy_acknowledged' => '1',
    'share_prescriptions_with_barangay_bhw' => '1',
]);
$pdo->prepare('UPDATE patient_privacy_preferences SET share_medical_records = ?, emergency_access_consent = ?, data_privacy_acknowledged = ?, share_prescriptions_with_barangay_bhw = ? WHERE user_id = 1')
    ->execute(array_values($on));
$reloaded = $pdo->query('SELECT share_medical_records, emergency_access_consent, data_privacy_acknowledged, share_prescriptions_with_barangay_bhw FROM patient_privacy_preferences WHERE user_id = 1')->fetch(PDO::FETCH_ASSOC);
privacy_assert(array_map('intval', $reloaded) === $on, 'turning every toggle on persists and reloads as on');
privacy_assert(patient_shares_medical_records_with_providers($pdo, 1) === true, 'provider record access is on after it is saved on');
privacy_assert(patient_allows_emergency_record_access($pdo, 1) === true, 'emergency record access is on after it is saved on');
privacy_assert(patient_privacy_flag($pdo, 1, 'share_prescriptions_with_barangay_bhw', false) === true, 'BHW prescription access on is reloaded as on');

$pdo->prepare('UPDATE patient_privacy_preferences SET share_medical_records = 0, emergency_access_consent = 1, data_privacy_acknowledged = 1, share_prescriptions_with_barangay_bhw = 0 WHERE user_id = 1')->execute();
$withheld = patient_privacy_withheld_record_ids($pdo, [1, 2]);
privacy_assert($withheld === [1 => true], 'only the patient who turned provider access off is withheld');
$blanked = patient_privacy_blank_clinical_fields([
    'patient_id' => 1,
    'diagnosis' => 'Hypertension',
    'chief_complaint' => 'Headache',
    'clinical_note_id' => 9,
    'consult_date' => '2026-10-01',
]);
privacy_assert($blanked['diagnosis'] === '' && $blanked['chief_complaint'] === '' && $blanked['clinical_note_id'] === null, 'care-record fields are removed when provider access is off');
privacy_assert($blanked['consult_date'] === '2026-10-01', 'a visit date is not treated as a clinical record field');
privacy_assert(patient_privacy_flag($pdo, 1, 'share_prescriptions_with_barangay_bhw', true) === false, 'BHW prescription access off is reloaded as off');
privacy_assert(patient_allows_emergency_record_access($pdo, 1) === true, 'emergency consent can stay on while provider record access is off');

$settings = (string) file_get_contents($root . '/app/includes/patient_settings.php');
privacy_assert(str_contains($settings, 'patient_privacy_values_from_input') && !str_contains($settings, 'You must acknowledge the data privacy policy'), 'saving privacy stores acknowledgment off instead of rejecting the form');
$access = (string) file_get_contents($root . '/app/includes/provider_patient_access.php');
privacy_assert(str_contains($access, 'patient_allows_emergency_record_access'), 'the emergency referral access path checks emergency consent');
$records = (string) file_get_contents($root . '/resources/views/provider/medical_records.php');
privacy_assert(str_contains($records, 'patient_shares_medical_records_with_providers') && str_contains($records, 'provider_patient_has_consultation_history($pdo, $provider_id, $requested_id)'), 'medical records still require a consultation and also the provider-access toggle');
$session = (string) file_get_contents($root . '/app/api/consultations/session_context.php');
privacy_assert(str_contains($session, 'patient_shares_medical_records_with_providers'), 'the consultation session API withholds the permanent profile when provider access is off');
$recorded = (string) file_get_contents($root . '/app/includes/consultation_recorded_data.php');
privacy_assert(str_contains($recorded, 'patient_shares_medical_records_with_providers'), 'the recorded-data API withholds care records when provider access is off');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
