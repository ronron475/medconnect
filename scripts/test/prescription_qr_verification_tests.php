<?php
/**
 * Prescription QR verification privacy.
 * SQLite in memory and synthetic rows only. Does not connect to MySQL.
 *
 * CLI: php scripts/test/prescription_qr_verification_tests.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $root);
}
if (!defined('BASE_URL')) {
    define('BASE_URL', 'https://qr.example.test/medconnect');
}

require_once $root . '/app/includes/prescription_verification.php';

$pass = 0;
$fail = 0;

function qr_assert(bool $ok, string $label): void
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
    qr_assert(false, 'sqlite is available');
    echo "\n{$pass} passed, {$fail} failed\n";
    exit(1);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        first_name TEXT,
        last_name TEXT,
        role TEXT
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
        created_at TEXT,
        signature_data TEXT,
        signed_pdf_path TEXT
    )
');

$patientId = 101;
$otherPatientId = 102;
$providerId = 201;
$otherProviderId = 202;
$bhwId = 301;
$adminId = 401;
$activeToken = str_repeat('ab', 32);
$revokedToken = str_repeat('cd', 32);
$nullStatusToken = str_repeat('11', 32);
$noteLeak = 'NOTELEAK9f3a';
$sigLeak = 'SIGLEAK9f3a';
$pdfLeak = 'uploads/soap_signed/leak.pdf';
$patientName = 'Hidden Patient';
$medication = 'SyntheticMed';

$insertUser = $pdo->prepare('INSERT INTO users (id, first_name, last_name, role) VALUES (?, ?, ?, ?)');
$insertUser->execute([$patientId, 'Hidden', 'Patient', 'patient']);
$insertUser->execute([$otherPatientId, 'Other', 'Patient', 'patient']);
$insertUser->execute([$providerId, 'Synth', 'Provider', 'provider']);
$insertUser->execute([$otherProviderId, 'Second', 'Provider', 'provider']);
$insertUser->execute([$bhwId, 'Barangay', 'Worker', 'bhw']);
$insertUser->execute([$adminId, 'System', 'Admin', 'admin']);

$insertRx = $pdo->prepare('
    INSERT INTO prescriptions (
        id, consultation_id, patient_id, provider_id, medication_name, dosage, frequency, duration,
        notes, verification_token, status, created_at, signature_data, signed_pdf_path
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
');
$insertRx->execute([
    1, 501, $patientId, $providerId, $medication, '500 mg', 'twice daily', '7 days',
    $noteLeak, $activeToken, 'active', '2026-10-01 08:00:00', $sigLeak, $pdfLeak,
]);
$insertRx->execute([
    2, 501, $patientId, $providerId, $medication, '500 mg', 'twice daily', '7 days',
    $noteLeak, $revokedToken, 'revoked', '2026-10-02 08:00:00', $sigLeak, $pdfLeak,
]);
$insertRx->execute([
    3, 501, $patientId, $providerId, $medication, '500 mg', 'twice daily', '7 days',
    $noteLeak, $nullStatusToken, null, '2026-10-03 08:00:00', $sigLeak, $pdfLeak,
]);

$leaks = [$noteLeak, $sigLeak, $pdfLeak, $patientName, 'data:image'];

function qr_blob_has_leak(array $result, array $leaks): ?string
{
    $blob = (string) json_encode($result);
    foreach ($leaks as $leak) {
        if ($leak !== '' && str_contains($blob, $leak)) {
            return $leak;
        }
    }
    return null;
}

function qr_public_keys_only(array $result): bool
{
    $keys = array_keys($result);
    sort($keys);
    return $keys === ['issued_on', 'provider_name', 'state'];
}

$viewers = [
    'anonymous' => 0,
    'another patient' => $otherPatientId,
    'issuing provider' => $providerId,
    'another provider' => $otherProviderId,
    'BHW' => $bhwId,
    'admin' => $adminId,
];

foreach ($viewers as $label => $viewerId) {
    $result = prescription_verification_for_viewer($pdo, $activeToken, $viewerId);
    qr_assert(($result['state'] ?? '') === 'valid', $label . ' sees a valid prescription');
    qr_assert(($result['provider_name'] ?? '') === 'Synth Provider', $label . ' sees the issuing provider name');
    qr_assert(($result['issued_on'] ?? '') === 'Oct 1, 2026', $label . ' sees the issue date');
    qr_assert(!isset($result['medication_name']), $label . ' does not receive medication details');
    qr_assert(qr_public_keys_only($result), $label . ' receives only state, provider name, and issue date');
    $leak = qr_blob_has_leak($result, array_merge($leaks, [$medication]));
    qr_assert($leak === null, $label . ' result does not contain notes, identity, signature, PDF path, or medication');
}

$owner = prescription_verification_for_viewer($pdo, $activeToken, $patientId);
qr_assert(($owner['state'] ?? '') === 'valid', 'owning patient sees a valid prescription');
qr_assert(($owner['provider_name'] ?? '') === 'Synth Provider', 'owning patient sees the issuing provider name');
qr_assert(($owner['issued_on'] ?? '') === 'Oct 1, 2026', 'owning patient sees the issue date');
qr_assert(($owner['medication_name'] ?? '') === $medication, 'owning patient sees the medication name');
qr_assert(($owner['dosage'] ?? '') === '500 mg', 'owning patient sees the dosage');
qr_assert(($owner['frequency'] ?? '') === 'twice daily', 'owning patient sees the frequency');
qr_assert(($owner['duration'] ?? '') === '7 days', 'owning patient sees the duration');
$ownerLeak = qr_blob_has_leak($owner, $leaks);
qr_assert($ownerLeak === null, 'owning patient result does not contain notes, patient identity, signature data, or a PDF path');

$revokedPublic = prescription_verification_public($pdo, $revokedToken);
qr_assert(($revokedPublic['state'] ?? '') === 'revoked', 'a revoked token is reported as revoked');
qr_assert(($revokedPublic['provider_name'] ?? '') === 'Synth Provider', 'a revoked token still shows the issuing provider');
qr_assert(($revokedPublic['issued_on'] ?? '') === 'Oct 2, 2026', 'a revoked token still shows the issue date');
qr_assert(qr_public_keys_only($revokedPublic), 'a revoked public result has no medication fields');

$revokedOwner = prescription_verification_for_viewer($pdo, $revokedToken, $patientId);
qr_assert(($revokedOwner['state'] ?? '') === 'revoked', 'owning patient sees a revoked prescription as revoked');
qr_assert(($revokedOwner['medication_name'] ?? '') === $medication, 'owning patient still sees medication on a revoked prescription');
qr_assert(qr_blob_has_leak($revokedOwner, $leaks) === null, 'revoked owner result does not leak notes, identity, signature, or PDF path');

$unknown = prescription_verification_public($pdo, str_repeat('ef', 32));
qr_assert(($unknown['state'] ?? '') === 'unavailable', 'an unknown token is unavailable');
qr_assert(($unknown['provider_name'] ?? '') === '' && ($unknown['issued_on'] ?? '') === '', 'an unknown token reveals no provider or date');
qr_assert(qr_public_keys_only($unknown), 'an unknown token has only the public keys');

$invalidShapes = ['', 'abc', str_repeat('ab', 31), str_repeat('zz', 32), $activeToken . '00'];
foreach ($invalidShapes as $bad) {
    $badResult = prescription_verification_for_viewer($pdo, $bad, $patientId);
    qr_assert(($badResult['state'] ?? '') === 'unavailable', 'a malformed token is unavailable even for the owning patient');
    qr_assert(!isset($badResult['medication_name']), 'a malformed token does not reveal medication');
}

$nullStatus = prescription_verification_public($pdo, $nullStatusToken);
qr_assert(($nullStatus['state'] ?? '') === 'unavailable', 'a token with no active or revoked status is unavailable');
qr_assert(($nullStatus['provider_name'] ?? '') === '', 'an inactive token does not reveal the provider name');

$upper = prescription_verification_for_viewer($pdo, strtoupper($activeToken), 0);
qr_assert(($upper['state'] ?? '') === 'valid', 'token matching ignores hex case');
qr_assert(!isset($upper['medication_name']), 'case-normalized anonymous lookup still hides medication');

qr_assert(
    prescription_verification_classify($activeToken, 'active', $activeToken) === 'valid',
    'classify accepts an exact active token'
);
qr_assert(
    prescription_verification_classify($activeToken, 'active', str_repeat('ef', 32)) === 'unavailable',
    'classify rejects a different token of the same length'
);
qr_assert(
    prescription_verification_classify(null, 'active', $activeToken) === 'unavailable',
    'classify rejects a missing stored token'
);

$issuedId = prescription_issue(
    $pdo,
    777,
    $patientId,
    $providerId,
    'IssuedMed',
    '1 tab',
    'daily',
    '3 days',
    $noteLeak
);
qr_assert($issuedId > 0, 'prescription_issue inserts a synthetic prescription');
$issued = $pdo->query('SELECT verification_token, status, notes FROM prescriptions WHERE id = ' . (int) $issuedId)->fetch(PDO::FETCH_ASSOC);
$issuedToken = (string) ($issued['verification_token'] ?? '');
qr_assert(preg_match('/^[a-f0-9]{64}$/', $issuedToken) === 1, 'a new verification token is 64 hex characters');
qr_assert(($issued['status'] ?? '') === 'active', 'a new prescription is active');
$issuedPublic = prescription_verification_public($pdo, $issuedToken);
qr_assert(($issuedPublic['state'] ?? '') === 'valid', 'a newly issued token verifies as valid');
qr_assert(!str_contains((string) json_encode($issuedPublic), $noteLeak), 'public verification of an issued row omits notes');
qr_assert(!str_contains((string) json_encode($issuedPublic), 'IssuedMed'), 'public verification of an issued row omits medication');

$url = prescription_verification_url($activeToken);
$expectedUrl = BASE_URL . '/app/api/prescriptions/verify.php?t=' . $activeToken;
qr_assert($url === $expectedUrl, 'the QR URL is only the verification endpoint and token');
qr_assert(!str_contains($url, $medication) && !str_contains($url, $noteLeak) && !str_contains($url, 'consultation_id'), 'the QR URL does not carry medication, notes, or a consultation id');
qr_assert(
    prescription_verification_canonical_base('http://pharmacy.example/medconnect') === 'https://pharmacy.example/medconnect',
    'a non-local HTTP verification base is raised to HTTPS'
);
qr_assert(
    prescription_verification_canonical_base('http://localhost/medconnect') === 'http://localhost/medconnect',
    'a local HTTP verification base stays on HTTP'
);

$svg = prescription_verification_qr_svg($url);
qr_assert(str_starts_with($svg, '<svg'), 'the QR image is an SVG drawing');
qr_assert(!str_contains(strtolower($svg), '<script'), 'the QR SVG contains no script');
qr_assert(!str_contains($svg, $medication) && !str_contains($svg, $noteLeak) && !str_contains($svg, $sigLeak), 'the QR SVG does not contain medication, notes, or signature data');

$html = prescription_verification_patient_html($activeToken, 'active');
qr_assert(str_contains($html, '/app/api/prescriptions/verify.php?t=' . $activeToken), 'patient verification HTML links only to the token URL');
qr_assert(!str_contains($html, $noteLeak) && !str_contains($html, $sigLeak) && !str_contains($html, $pdfLeak), 'patient verification HTML does not include notes, signature data, or a PDF path');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
