<?php
/**
 * Prescription consultation gate, structured SOAP lines, duplicate handling, and visibility.
 * Uses SQLite for behavior and source reads for the pages. No notifications and no patient database.
 *
 * CLI: php scripts/test/prescription_handling_tests.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/includes/prescription_lines.php';
require_once $root . '/app/includes/patient_consultation_records.php';

$passed = 0;
$failed = 0;

function rx_assert(bool $cond, string $label, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS  {$label}\n";
        return;
    }
    $failed++;
    echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function rx_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('
        CREATE TABLE consultations (
            id INTEGER PRIMARY KEY,
            patient_id INTEGER NOT NULL,
            provider_id INTEGER NOT NULL,
            status TEXT NOT NULL
        )
    ');
    $pdo->exec('
        CREATE TABLE prescriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
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
    $pdo->exec("INSERT INTO consultations (id, patient_id, provider_id, status) VALUES
        (10, 2, 1, 'in_consultation'),
        (11, 2, 1, 'cancelled'),
        (12, 3, 1, 'in_consultation'),
        (13, 2, 9, 'in_consultation'),
        (14, 2, 1, 'completed'),
        (15, 2, 1, 'completed'),
        (16, 2, 1, 'in_consultation'),
        (17, 2, 1, 'completed')
    ");
    $pdo->exec('
        CREATE TABLE clinical_notes (
            consultation_id INTEGER PRIMARY KEY,
            finalized_at TEXT,
            signature_data TEXT
        )
    ');
    $pdo->exec("INSERT INTO clinical_notes (consultation_id, finalized_at, signature_data) VALUES
        (10, NULL, ''),
        (14, '2026-10-19 02:00:00', 'signed_pdf:abc'),
        (17, NULL, '')
    ");

    return $pdo;
}

echo "Prescription handling\n";

$missing = prescription_consultation_can_issue(rx_pdo(), 1, 2, 0);
rx_assert($missing['ok'] === false, 'a missing consultation is rejected');

$pdo = rx_pdo();
$before = (int) $pdo->query('SELECT COUNT(*) FROM consultations')->fetchColumn();
$cancelled = prescription_consultation_can_issue($pdo, 1, 2, 11);
$after = (int) $pdo->query('SELECT COUNT(*) FROM consultations')->fetchColumn();
rx_assert($cancelled['ok'] === false && str_contains($cancelled['message'], 'cancelled'), 'a cancelled consultation is rejected');
rx_assert($before === $after, 'the consultation gate does not create a consultation');
rx_assert(prescription_consultation_can_issue($pdo, 1, 2, 12)['ok'] === false, 'a consultation for another patient is rejected');
rx_assert(prescription_consultation_can_issue($pdo, 1, 2, 13)['ok'] === false, 'another provider\'s consultation is rejected');
rx_assert(prescription_consultation_can_issue($pdo, 1, 2, 10)['ok'] === true, 'an in-progress consultation with a draft note can receive a prescription');
rx_assert(prescription_consultation_can_issue($pdo, 1, 2, 16)['ok'] === true, 'an in-progress consultation without a note can receive a prescription');
$finalized = prescription_consultation_can_issue($pdo, 1, 2, 14);
rx_assert($finalized['ok'] === false && str_contains($finalized['message'], 'finalized'), 'a finalized SOAP note cannot receive a new prescription');
$completedMissing = prescription_consultation_can_issue($pdo, 1, 2, 15);
rx_assert($completedMissing['ok'] === false && str_contains($completedMissing['message'], 'completed'), 'a completed consultation without a SOAP note is rejected');
$completedDraft = prescription_consultation_can_issue($pdo, 1, 2, 17);
rx_assert($completedDraft['ok'] === false && str_contains($completedDraft['message'], 'completed'), 'a completed consultation with a draft SOAP note is rejected');

$partial = prescription_lines_from_post([
    'rx_medication' => ['Amoxicillin'],
    'rx_dosage' => [''],
    'rx_frequency' => ['3 times daily'],
    'rx_duration' => ['7 days'],
]);
rx_assert($partial['error'] !== null && $partial['rows'] === [], 'a partial medication row is rejected');

$posted = prescription_lines_from_post([
    'rx_medication' => ['Amoxicillin', 'Paracetamol', ''],
    'rx_dosage' => ['500 mg', '500 mg', ''],
    'rx_frequency' => ['3 times daily', 'every 6 hours', ''],
    'rx_duration' => ['7 days', '3 days', ''],
]);
rx_assert($posted['error'] === null && count($posted['rows']) === 2, 'empty rows are ignored and two medications are kept');

$encoded = prescription_lines_encode($posted['rows'], "Legacy free text\nkept");
$decoded = prescription_lines_decode($encoded);
rx_assert(count($decoded['rows']) === 2 && $decoded['rows'][0]['dosage'] === '500 mg', 'structured lines round-trip with the real dosage');
rx_assert($decoded['legacy'] === "Legacy free text\nkept", 'historical free text is preserved beside structured lines');
$display = prescription_lines_display($encoded);
rx_assert(str_contains($display, 'Amoxicillin | 500 mg | 3 times daily | 7 days'), 'the SOAP text shows the entered directions');
rx_assert(!str_contains($display, 'As directed'), 'structured lines do not use placeholder directions');
rx_assert(!str_contains($display, 'MC-RX-1'), 'the readable prescription text hides the storage marker');
rx_assert(prescription_lines_display('RXTOKEN9f3a 500 mg') === 'RXTOKEN9f3a 500 mg', 'historical prescription text is not rewritten');

$pdo->beginTransaction();
$first = prescription_issue_lines($pdo, 10, 2, 1, $posted['rows']);
$again = prescription_issue_lines($pdo, 10, 2, 1, [
    $posted['rows'][0],
    $posted['rows'][0],
    ['medication' => 'Ibuprofen', 'dosage' => '200 mg', 'frequency' => 'twice daily', 'duration' => '5 days'],
]);
$pdo->commit();
rx_assert($first === 2, 'finalization issues each different medication');
rx_assert($again === 1, 'an active duplicate is skipped and a new medication is still issued');
$stored = $pdo->query('SELECT medication_name, dosage, frequency, duration, notes, status FROM prescriptions WHERE consultation_id = 10 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
rx_assert(count($stored) === 3, 'three prescription rows exist after the duplicate was skipped');
rx_assert(($stored[0]['dosage'] ?? '') === '500 mg' && ($stored[0]['frequency'] ?? '') === '3 times daily', 'the issued row keeps the structured dosage and frequency');
rx_assert(($stored[0]['status'] ?? '') === 'active' && ($stored[0]['notes'] ?? '') !== 'As directed', 'the issued row is active and is not stored as a placeholder');
rx_assert(prescription_active_duplicate_exists($pdo, 10, 'amoxicillin', '500  MG', '3 times daily', '7 days') === true, 'duplicate detection ignores case and extra spacing');

$pdo->prepare('UPDATE prescriptions SET status = ? WHERE medication_name = ?')->execute(['revoked', 'Ibuprofen']);
$reissued = prescription_issue_lines($pdo, 10, 2, 1, [
    ['medication' => 'Ibuprofen', 'dosage' => '200 mg', 'frequency' => 'twice daily', 'duration' => '5 days'],
]);
rx_assert($reissued === 1, 'a revoked prescription can be issued again');

$pdo->prepare('
    INSERT INTO prescriptions
        (consultation_id, patient_id, provider_id, medication_name, dosage, frequency, duration, notes, status)
    VALUES (10, 2, 1, ?, ?, ?, ?, ?, NULL)
')->execute(['LegacyMed', 'As directed', 'As directed', 'As directed', 'old free text']);
$beforeLegacy = $pdo->query("SELECT dosage, notes FROM prescriptions WHERE medication_name = 'LegacyMed'")->fetch(PDO::FETCH_ASSOC);
prescription_issue_lines($pdo, 10, 2, 1, [
    ['medication' => 'Cetirizine', 'dosage' => '10 mg', 'frequency' => 'once daily', 'duration' => '5 days'],
]);
$afterLegacy = $pdo->query("SELECT dosage, notes FROM prescriptions WHERE medication_name = 'LegacyMed'")->fetch(PDO::FETCH_ASSOC);
rx_assert($beforeLegacy === $afterLegacy, 'issuing a new medication does not rewrite an older placeholder row');

$create = (string) file_get_contents($root . '/app/api/provider/create_prescription.php');
$save = (string) file_get_contents($root . '/app/api/provider/save_clinical_notes.php');
$form = (string) file_get_contents($root . '/resources/views/provider/prescriptions.php');
$dashboard = (string) file_get_contents($root . '/resources/views/patient/dashboard.php');
$health = (string) file_get_contents($root . '/resources/views/patient/my_health.php');
$detail = (string) file_get_contents($root . '/resources/views/patient/consultation_detail.php');
rx_assert(!str_contains($create, 'INSERT INTO consultations'), 'standalone prescribing does not create a consultation');
rx_assert(!str_contains($create, "status = 'completed'") && !str_contains($create, 'SET status'), 'standalone prescribing does not complete a consultation');
rx_assert(str_contains($create, 'prescription_consultation_can_issue('), 'standalone prescribing validates the consultation');
rx_assert(str_contains($create, 'prescription_active_duplicate_exists('), 'standalone prescribing rejects an active duplicate');
rx_assert(str_contains($form, 'name="consultation_id"'), 'the prescription form submits a consultation id');
rx_assert(str_contains($form, 'cn.finalized_at') && str_contains($form, 'cn.signature_data'), 'the prescription form hides consultations with a finalized SOAP note');
rx_assert(str_contains($form, "'completed'"), 'the prescription form hides completed consultations that have no finalized SOAP note');
$revoke = (string) file_get_contents($root . '/app/includes/prescription_verification.php');
rx_assert(str_contains($revoke, "SET status = 'revoked'"), 'revocation remains available after a note is finalized');
rx_assert(!str_contains($save, 'As directed'), 'SOAP finalization does not fill placeholder directions');
rx_assert(str_contains($save, 'prescription_issue_lines('), 'SOAP finalization issues structured lines');
rx_assert(str_contains($save, 'prescription_lines_display('), 'the signed SOAP PDF receives readable prescription text');
rx_assert(substr_count($save, 'FOR UPDATE') === 2, 'SOAP finalization still uses the two existing row locks');
rx_assert(substr_count($dashboard, "patient_consultation_record_visible_sql('c', 'cn')") >= 2, 'the patient dashboard applies the finalized-note rule to prescriptions and their count');
rx_assert(str_contains($health, "c.status = 'completed'") && substr_count($health, "patient_consultation_record_visible_sql('c', 'cn')") >= 3, 'My Health keeps completed consultations and requires a finalized note');
$detailGate = strpos($detail, 'if ($isFinalized)');
$detailQuery = strpos($detail, 'SELECT * FROM prescriptions');
rx_assert($detailGate !== false && $detailQuery !== false && $detailGate < $detailQuery, 'consultation detail loads prescriptions only after the note is finalized');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
