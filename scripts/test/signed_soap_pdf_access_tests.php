<?php
/**
 * Signed SOAP PDF access checks that can run without MySQL.
 * Synthetic files and the endpoint's authorization expression only.
 * Does not include the download script, so it does not open the application database.
 *
 * CLI: php scripts/test/signed_soap_pdf_access_tests.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mc_soap_pdf_' . bin2hex(random_bytes(4));

if (!defined('BASE_PATH')) {
    define('BASE_PATH', $root);
}
if (!defined('STORAGE_PATH')) {
    define('STORAGE_PATH', $storage);
}

require_once $root . '/app/includes/clinical_note_signature.php';
require_once $root . '/app/includes/clinical_note_pdf.php';
require_once $root . '/app/includes/patient_consultation_records.php';

$pass = 0;
$fail = 0;

function pdf_assert(bool $ok, string $label): void
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

function pdf_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            pdf_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * Evaluate the endpoint's own $allowed expression. The download script cannot
 * be included here because it boots the application database.
 */
function pdf_endpoint_allows(string $expression, int $uid, string $role, array $row): bool
{
    $isFinalized = patient_consultation_is_finalized(
        (string) ($row['status'] ?? ''),
        isset($row['signature_data']) ? (string) $row['signature_data'] : '',
        isset($row['finalized_at']) ? (string) $row['finalized_at'] : null
    );
    if (!preg_match('/^\(\$role === \'provider\'[\s\S]+\$isFinalized\)$/', $expression)) {
        throw new RuntimeException('Refusing to evaluate an unexpected authorization expression.');
    }

    return (bool) eval('return ' . $expression . ';');
}

$endpoint = (string) file_get_contents($root . '/app/api/consultations/view_signed_soap_pdf.php');
$matched = preg_match(
    '/\$allowed\s*=\s*(\(\$role === \'provider\'.*\$isFinalized\));/s',
    $endpoint,
    $exprMatch
) === 1;
pdf_assert($matched, 'the download endpoint still has one owner-and-patient authorization expression');
$allowedExpr = $matched ? trim((string) $exprMatch[1]) : '';

$owningPatient = 101;
$otherPatient = 102;
$owningProvider = 201;
$otherProvider = 202;
$finalizedRow = [
    'patient_id' => $owningPatient,
    'provider_id' => $owningProvider,
    'note_provider_id' => $owningProvider,
    'status' => 'completed',
    'signature_data' => 'signed_pdf:' . str_repeat('a', 64),
    'finalized_at' => '2026-10-01 08:00:00',
    'signed_pdf_path' => 'uploads/soap_signed/consult_501_test.pdf',
];

if ($allowedExpr !== '') {
    pdf_assert(
        str_contains($allowedExpr, '&& $isFinalized') && substr_count($allowedExpr, '$isFinalized') === 2,
        'provider and patient access both require a finalized note'
    );
    pdf_assert(
        pdf_endpoint_allows($allowedExpr, $owningProvider, 'provider', $finalizedRow),
        'owning provider is allowed for a finalized note when both provider ids match'
    );
    pdf_assert(
        pdf_endpoint_allows($allowedExpr, $owningPatient, 'patient', $finalizedRow),
        'owning patient is allowed for a completed finalized note'
    );

    $incomplete = $finalizedRow;
    $incomplete['status'] = 'in_consultation';
    $incomplete['finalized_at'] = '';
    $incomplete['signature_data'] = '';
    pdf_assert(
        !pdf_endpoint_allows($allowedExpr, $owningPatient, 'patient', $incomplete),
        'owning patient is denied while the consultation is not completed'
    );
    pdf_assert(
        !pdf_endpoint_allows($allowedExpr, $owningProvider, 'provider', $incomplete),
        'owning provider is denied while the consultation is not finalized'
    );

    $completedWithoutSignature = $finalizedRow;
    $completedWithoutSignature['signature_data'] = '';
    $completedWithoutSignature['finalized_at'] = '';
    pdf_assert(
        !patient_consultation_is_finalized('completed', '', null),
        'a completed consultation without a signature or finalized time is not finalized'
    );
    pdf_assert(
        !pdf_endpoint_allows($allowedExpr, $owningPatient, 'patient', $completedWithoutSignature),
        'owning patient is denied when the note is not finalized'
    );
    pdf_assert(
        !pdf_endpoint_allows($allowedExpr, $owningProvider, 'provider', $completedWithoutSignature),
        'owning provider is denied when the note is not finalized'
    );

    pdf_assert(
        !pdf_endpoint_allows($allowedExpr, $otherPatient, 'patient', $finalizedRow),
        'another patient is denied'
    );
    pdf_assert(
        !pdf_endpoint_allows($allowedExpr, $otherProvider, 'provider', $finalizedRow),
        'another provider is denied'
    );

    $noteOwnedByOther = $finalizedRow;
    $noteOwnedByOther['note_provider_id'] = $otherProvider;
    pdf_assert(
        !pdf_endpoint_allows($allowedExpr, $owningProvider, 'provider', $noteOwnedByOther),
        'a provider who does not own the clinical note is denied'
    );

    pdf_assert(
        !pdf_endpoint_allows($allowedExpr, 301, 'bhw', $finalizedRow),
        'a BHW is denied'
    );
    pdf_assert(
        !pdf_endpoint_allows($allowedExpr, 401, 'admin', $finalizedRow),
        'an admin is denied'
    );
    pdf_assert(
        !pdf_endpoint_allows($allowedExpr, $owningPatient, 'bhw', $finalizedRow),
        'a BHW session is denied even when the user id equals the patient id'
    );
    pdf_assert(
        !pdf_endpoint_allows($allowedExpr, $owningProvider, 'admin', $finalizedRow),
        'an admin session is denied even when the user id equals the provider id'
    );
} else {
    pdf_assert(false, 'authorization expression could not be read from the endpoint');
}

pdf_assert(
    patient_consultation_is_finalized('completed', 'signed_pdf:abc', null),
    'a completed note with signature data counts as finalized'
);
pdf_assert(
    !patient_consultation_is_finalized('cancelled', 'signed_pdf:abc', '2026-10-01 08:00:00'),
    'a cancelled consultation is not finalized'
);

if (!is_dir($storage) && !mkdir($storage, 0750, true) && !is_dir($storage)) {
    pdf_assert(false, 'synthetic storage directory can be created');
} else {
    $signedDir = $storage . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'soap_signed';
    mkdir($signedDir, 0750, true);
    $inside = $signedDir . DIRECTORY_SEPARATOR . 'consult_501_test.pdf';
    file_put_contents($inside, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
    file_put_contents($storage . DIRECTORY_SEPARATOR . 'outside.txt', 'OUTSIDELEAK');

    $resolved = clinical_note_signed_pdf_abs_path('uploads/soap_signed/consult_501_test.pdf');
    pdf_assert($resolved !== null && is_file($resolved), 'a path inside the signed-PDF directory resolves');
    pdf_assert(
        $resolved !== null && hash_equals(hash('sha256', (string) file_get_contents($inside)), hash_file('sha256', $resolved)),
        'the resolved file is the synthetic PDF that was stored'
    );

    $outside = [
        '',
        '../outside.txt',
        'uploads/soap_signed/../../outside.txt',
        'uploads/soap_signed/../outside.txt',
        'uploads/soap_signed_evil/consult_501_test.pdf',
        'uploads/soap_signed/missing.pdf',
        $inside,
    ];
    foreach ($outside as $rel) {
        pdf_assert(
            clinical_note_signed_pdf_abs_path($rel) === null,
            'path is rejected: ' . ($rel === '' ? '(empty)' : $rel)
        );
    }
}

$notArray = clinical_note_signed_pdf_validate_upload(null);
pdf_assert($notArray['ok'] === false, 'a missing upload is rejected');
$noFile = clinical_note_signed_pdf_validate_upload(['error' => UPLOAD_ERR_NO_FILE]);
pdf_assert($noFile['ok'] === false, 'an empty file field is rejected');
$ini = clinical_note_signed_pdf_validate_upload(['error' => UPLOAD_ERR_INI_SIZE, 'name' => 'note.pdf', 'tmp_name' => '']);
pdf_assert($ini['ok'] === false && str_contains($ini['message'], 'too large'), 'an upload over the server limit is rejected');
$form = clinical_note_signed_pdf_validate_upload(['error' => UPLOAD_ERR_FORM_SIZE, 'name' => 'note.pdf', 'tmp_name' => '']);
pdf_assert($form['ok'] === false && str_contains($form['message'], 'too large'), 'an upload over the form limit is rejected');
$partial = clinical_note_signed_pdf_validate_upload(['error' => UPLOAD_ERR_PARTIAL, 'name' => 'note.pdf', 'tmp_name' => '']);
pdf_assert($partial['ok'] === false, 'a partial upload is rejected');

$plain = $storage . DIRECTORY_SEPARATOR . 'not-uploaded.pdf';
file_put_contents($plain, "%PDF-1.4\n");
$cliFile = clinical_note_signed_pdf_validate_upload([
    'error' => UPLOAD_ERR_OK,
    'name' => 'not-uploaded.exe',
    'tmp_name' => $plain,
    'size' => (int) filesize($plain),
]);
pdf_assert($cliFile['ok'] === false, 'a file that was not uploaded through HTTP is rejected');
$uploadSource = (string) file_get_contents($root . '/app/includes/clinical_note_signature.php');
$uploadFnAt = strpos($uploadSource, 'function clinical_note_signed_pdf_validate_upload');
$fileFnAt = strpos($uploadSource, 'function clinical_note_signed_pdf_validate_file');
pdf_assert($uploadFnAt !== false && $fileFnAt !== false, 'upload validation still has a separate file-content validator');
$uploadFn = substr($uploadSource, $uploadFnAt, $fileFnAt - $uploadFnAt);
pdf_assert(str_contains($uploadFn, 'is_uploaded_file('), 'HTTP upload validation still requires is_uploaded_file()');
pdf_assert(str_contains($uploadFn, 'clinical_note_signed_pdf_validate_file('), 'HTTP upload validation delegates content checks after the upload check');

$validPdf = $storage . DIRECTORY_SEPARATOR . 'valid-note.pdf';
$validBytes = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
file_put_contents($validPdf, $validBytes);
$validCheck = clinical_note_signed_pdf_validate_file($validPdf, 'signed-note.pdf');
pdf_assert($validCheck['ok'] === true, 'a synthetic PDF header is accepted by the file validator');

$notPdf = $storage . DIRECTORY_SEPARATOR . 'notes.txt';
file_put_contents($notPdf, 'this is not a pdf');
$notPdfCheck = clinical_note_signed_pdf_validate_file($notPdf, 'notes.pdf');
pdf_assert($notPdfCheck['ok'] === false && str_contains($notPdfCheck['message'], 'PDF'), 'a non-PDF file is rejected');

$emptyPdf = $storage . DIRECTORY_SEPARATOR . 'empty.pdf';
file_put_contents($emptyPdf, '');
$emptyCheck = clinical_note_signed_pdf_validate_file($emptyPdf, 'empty.pdf');
pdf_assert($emptyCheck['ok'] === false && str_contains($emptyCheck['message'], 'empty'), 'an empty file is rejected');

$hugePdf = $storage . DIRECTORY_SEPARATOR . 'huge.pdf';
$huge = fopen($hugePdf, 'wb');
pdf_assert($huge !== false, 'an oversized synthetic file can be created');
if ($huge !== false) {
    fwrite($huge, '%PDF-');
    $remaining = CLINICAL_NOTE_SIGNED_PDF_MAX_BYTES;
    $chunk = str_repeat('a', 1024 * 1024);
    while ($remaining > 0) {
        $write = min(strlen($chunk), $remaining);
        fwrite($huge, substr($chunk, 0, $write));
        $remaining -= $write;
    }
    fclose($huge);
    $hugeCheck = clinical_note_signed_pdf_validate_file($hugePdf, 'huge.pdf');
    pdf_assert($hugeCheck['ok'] === false && str_contains($hugeCheck['message'], 'too large'), 'a file over 10 MB is rejected');
}

$badExt = clinical_note_signed_pdf_validate_file($validPdf, 'signed-note.exe');
pdf_assert($badExt['ok'] === false && str_contains($badExt['message'], 'Only PDF'), 'a non-PDF extension is rejected');

$matchingHash = (string) hash_file('sha256', $validPdf);
pdf_assert(clinical_note_signed_pdf_hash_matches($validPdf, $matchingHash), 'a matching SHA-256 is accepted');
pdf_assert(clinical_note_signed_pdf_hash_matches($validPdf, strtoupper($matchingHash)), 'an uppercase SHA-256 of the same digest is accepted');
$flipped = $validBytes;
$flipped[strlen($flipped) - 2] = $flipped[strlen($flipped) - 2] === 'F' ? '0' : 'F';
$flippedPdf = $storage . DIRECTORY_SEPARATOR . 'flipped.pdf';
file_put_contents($flippedPdf, $flipped);
pdf_assert(!clinical_note_signed_pdf_hash_matches($flippedPdf, $matchingHash), 'a one-byte modification fails the hash check');
pdf_assert(!clinical_note_signed_pdf_hash_matches($validPdf, ''), 'an empty hash is rejected');
pdf_assert(!clinical_note_signed_pdf_hash_matches($validPdf, str_repeat('g', 64)), 'a malformed hash is rejected');
pdf_assert(!clinical_note_signed_pdf_hash_matches($validPdf, str_repeat('ab', 31)), 'a short hash is rejected');
pdf_assert(!clinical_note_signed_pdf_hash_matches($storage . DIRECTORY_SEPARATOR . 'missing.pdf', $matchingHash), 'a missing file fails the hash check');

$loginAt = strpos($endpoint, 'if ($uid <= 0 || $role === \'\')');
$sessionAt = strpos($endpoint, 'auth_ensure_session_user_valid($pdo)');
$queryAt = strpos($endpoint, 'cn.signed_pdf_sha256');
$hashAt = strpos($endpoint, 'clinical_note_signed_pdf_hash_matches(');
$readAt = strpos($endpoint, 'readfile(');
pdf_assert($loginAt !== false && $sessionAt !== false && $loginAt < $sessionAt, 'session validation runs after the existing login check');
pdf_assert($sessionAt !== false && $queryAt !== false && $sessionAt < $queryAt, 'session validation runs before the clinical-note query');
pdf_assert($queryAt !== false, 'the download query loads signed_pdf_sha256');
pdf_assert($hashAt !== false && $readAt !== false && $hashAt < $readAt, 'the hash is verified before readfile()');
pdf_assert(substr_count($endpoint, "echo 'Signed SOAP note not found.';") === 2, 'missing and unauthorized notes share one response body');
pdf_assert(substr_count($endpoint, 'http_response_code(404)') === 3, 'missing notes, unauthorized notes, and unavailable files use HTTP 404');
pdf_assert(!str_contains($endpoint, 'Access denied.'), 'unauthorized notes no longer use a distinct denial message');
pdf_assert(str_contains($endpoint, 'http_response_code(400)') && str_contains($endpoint, 'Consultation ID is required.'), 'an invalid consultation id stays HTTP 400');
pdf_assert(str_contains($endpoint, 'The signed SOAP PDF is not available for this consultation.'), 'missing files and hash failures keep the generic unavailable response');
pdf_assert(!str_contains($endpoint, 'signature_method'), 'an older uploaded PDF stays readable without a generated-pdf method check');

$save = (string) file_get_contents($root . '/app/api/provider/save_clinical_notes.php');
$txAt = strpos($save, '$pdo->beginTransaction()');
$afterTx = $txAt === false ? '' : substr($save, $txAt);
$consultLockAt = strpos($afterTx, 'FROM consultations');
$noteLockAt = strpos($afterTx, 'FROM clinical_notes');
$renderAt = strpos($afterTx, 'clinical_note_pdf_render(');
$emptyAt = strpos($afterTx, "if (\$pdfBytes === '')");
$storeAt = strpos($afterTx, 'clinical_note_signed_pdf_store_contents(');
$insertAt = $txAt === false ? false : strpos($save, 'signed_pdf_path, signed_pdf_name, signed_pdf_size, signed_pdf_sha256', $txAt);
pdf_assert($txAt !== false && $consultLockAt !== false && $noteLockAt !== false && $renderAt !== false && $storeAt !== false, 'finalize reaches a transaction, row locks, PDF generation, and storage');
pdf_assert($consultLockAt < $noteLockAt && $noteLockAt < $renderAt && $renderAt < $storeAt, 'finalize locks the consultation and note before generating and storing the PDF');
pdf_assert($emptyAt !== false && $renderAt < $emptyAt && $emptyAt < $storeAt, 'failed PDF generation rolls back before a file is stored');
pdf_assert(substr_count($save, 'FOR UPDATE') === 2, 'both locked reads use FOR UPDATE');
pdf_assert($insertAt !== false && ($txAt + $storeAt) < $insertAt, 'the PDF is stored before the note row records its path');
pdf_assert(str_contains($save, "'generated_pdf'"), 'finalization records the generated PDF method');
pdf_assert(!str_contains($save, "\$_FILES['signed_pdf']"), 'finalization does not accept an uploaded PDF');
pdf_assert(str_contains($save, 'already been finalized'), 'the locked section rechecks the finalized state');
pdf_assert(str_contains($save, "unlink(\$data['signed_pdf_abs'])") || str_contains($save, 'unlink($data[\'signed_pdf_abs\'])'), 'rollback still removes only the file created by the failed request');

$preview = (string) file_get_contents($root . '/app/api/provider/preview_soap_pdf.php');
pdf_assert(str_contains($preview, "'preview' => true"), 'preview asks for a preview document');
pdf_assert(str_contains($preview, 'clinical_note_pdf_render('), 'preview generates the PDF from the submitted note');
pdf_assert(!str_contains($preview, 'prescription_issue('), 'preview does not issue a prescription');
pdf_assert(!str_contains($preview, 'beginTransaction('), 'preview does not open a clinical-note transaction');
pdf_assert(!str_contains($preview, 'clinical_note_signed_pdf_store'), 'preview does not store a signed PDF');
pdf_assert(!str_contains($preview, 'INSERT INTO clinical_notes'), 'preview does not save a clinical note');

$generated = clinical_note_pdf_render([
    'consultation_id' => 501,
    'subjective' => 'SUBJTOKEN9f3a',
    'objective' => 'OBJTOKEN9f3a Niño',
    'assessment' => 'ASSESS9f3a',
    'plan' => 'PLANTOKEN9f3a',
    'diagnosis' => 'A09 – Infectious gastroenteritis and colitis, unspecified',
    'prescription' => 'RXTOKEN9f3a 500 mg',
    'provider_name' => 'Ana Cruz',
    'signed_at' => '2026-10-19 00:15:00',
    'preview' => false,
]);
pdf_assert(str_starts_with($generated, '%PDF-'), 'the generator returns a PDF');
pdf_assert(str_contains($generated, 'SUBJTOKEN9f3a'), 'the PDF contains the subjective text');
pdf_assert(str_contains($generated, 'OBJTOKEN9f3a'), 'the PDF contains the objective text');
pdf_assert(str_contains($generated, clinical_note_pdf_encode('Niño')), 'the PDF keeps a Latin-1 special character');
pdf_assert(str_contains($generated, 'ASSESS9f3a'), 'the PDF contains the assessment text');
pdf_assert(str_contains($generated, 'PLANTOKEN9f3a'), 'the PDF contains the plan text');
pdf_assert(str_contains($generated, 'A09'), 'the PDF contains the ICD-10 code');
pdf_assert(str_contains($generated, 'Infectious gastroenteritis and colitis, unspecified'), 'the PDF contains the diagnosis text');
pdf_assert(str_contains($generated, 'RXTOKEN9f3a 500 mg'), 'the PDF contains the prescription text');
pdf_assert(str_contains($generated, 'Dr. Ana Cruz'), 'the PDF names the provider account');
pdf_assert(str_contains($generated, 'Consultation reference: 501'), 'the PDF names the consultation');
pdf_assert(str_contains($generated, 'not a cryptographic digital signature'), 'the PDF states that the account name is not a cryptographic signature');
pdf_assert(!str_contains($generated, 'No prescription was recorded'), 'a provided prescription replaces the empty-prescription line');
pdf_assert(clinical_note_pdf_render(['consultation_id' => 0, 'subjective' => 'SUBJTOKEN9f3a']) === '', 'PDF generation fails closed without a consultation reference');

$withoutPrescription = clinical_note_pdf_render([
    'consultation_id' => 501,
    'subjective' => 'SUBJTOKEN9f3a',
    'objective' => 'OBJTOKEN9f3a',
    'assessment' => 'ASSESS9f3a',
    'plan' => 'PLANTOKEN9f3a',
    'diagnosis' => 'A09 – Infectious gastroenteritis and colitis, unspecified',
    'prescription' => '',
    'provider_name' => 'Ana Cruz',
    'signed_at' => '2026-10-19 00:15:00',
    'preview' => true,
]);
$previewHeading = clinical_note_pdf_encode('Preview — not finalized');
pdf_assert(str_contains($withoutPrescription, $previewHeading), 'the preview heading is encoded for the standard PDF font');
pdf_assert(!str_contains($withoutPrescription, "\xE2\x80\x94"), 'the preview heading does not keep a raw UTF-8 em dash');
$pesoPdf = clinical_note_pdf_render([
    'consultation_id' => 501,
    'subjective' => 'SUBJTOKEN9f3a',
    'objective' => 'Niño',
    'assessment' => 'ASSESS9f3a',
    'plan' => 'PLANTOKEN9f3a',
    'diagnosis' => 'A09 – Infectious gastroenteritis and colitis, unspecified',
    'prescription' => "₱500",
    'provider_name' => 'Ana Cruz',
    'signed_at' => '2026-10-19 00:15:00',
    'preview' => false,
]);
pdf_assert(str_contains($pesoPdf, 'PHP 500'), 'a peso amount stays visible as PHP plus the amount');
pdf_assert(!str_contains($pesoPdf, 'PHP500') && str_contains($pesoPdf, '500'), 'the peso amount is not reduced to digits alone');
pdf_assert(str_contains($pesoPdf, clinical_note_pdf_encode('Niño')), 'peso handling still keeps ñ in the same PDF');
pdf_assert(str_contains($withoutPrescription, 'No prescription was recorded on this note.'), 'an empty prescription is stated');
pdf_assert(!str_contains($withoutPrescription, 'verify.php'), 'the SOAP PDF does not link to prescription verification');

$longPlan = str_repeat("PLANLINE9f3a wraps onto another page.\n", 80);
$paged = clinical_note_pdf_render([
    'consultation_id' => 501,
    'subjective' => 'SUBJTOKEN9f3a',
    'objective' => 'OBJTOKEN9f3a',
    'assessment' => 'ASSESS9f3a',
    'plan' => $longPlan,
    'diagnosis' => 'A09 – Infectious gastroenteritis and colitis, unspecified',
    'prescription' => '',
    'provider_name' => 'Ana Cruz',
    'signed_at' => '2026-10-19 00:15:00',
    'preview' => false,
]);
pdf_assert(str_contains($paged, '/Count '), 'a long note records a page count');
pdf_assert(!str_contains($paged, '/Count 1'), 'a long note continues onto another page');

$stored = clinical_note_signed_pdf_store_contents($generated, 501);
pdf_assert($stored['ok'] === true && !empty($stored['abs']) && is_file((string) $stored['abs']), 'generated PDF bytes are stored in the private directory');
pdf_assert(
    $stored['ok'] === true && hash_equals((string) $stored['sha256'], (string) hash_file('sha256', (string) $stored['abs'])),
    'the stored SHA-256 matches the generated file'
);
pdf_assert(
    $stored['ok'] === true && clinical_note_signed_pdf_hash_matches((string) $stored['abs'], (string) $stored['sha256']),
    'the download hash check accepts the generated file'
);
if ($stored['ok'] === true && !empty($stored['abs'])) {
    $altered = (string) file_get_contents((string) $stored['abs']);
    $altered[strlen($altered) - 2] = $altered[strlen($altered) - 2] === 'F' ? '0' : 'F';
    $alteredPath = $storage . DIRECTORY_SEPARATOR . 'altered-generated.pdf';
    file_put_contents($alteredPath, $altered);
    pdf_assert(!clinical_note_signed_pdf_hash_matches($alteredPath, (string) $stored['sha256']), 'an altered generated PDF fails the hash check');
}
$rejectedStore = clinical_note_signed_pdf_store_contents('', 501);
pdf_assert($rejectedStore['ok'] === false, 'empty PDF bytes are not stored');
$notPdfStore = clinical_note_signed_pdf_store_contents('not-a-pdf', 501);
pdf_assert($notPdfStore['ok'] === false, 'non-PDF bytes are not stored');

$providerPage = (string) file_get_contents($root . '/resources/views/provider/consultation_session.php');
pdf_assert(str_contains($providerPage, 'clinical_note_is_image_payload('), 'the provider template retains the legacy image branch');
pdf_assert(str_contains($providerPage, "src=\"<?= htmlspecialchars((string) \$clinical_note['signature_data']) ?>\""), 'the legacy branch still renders a stored image payload for the provider');
foreach ([
    'resources/views/patient/consultation_detail.php',
    'resources/views/patient/partials/view_my_health_files.php',
] as $patientView) {
    $patientSource = (string) file_get_contents($root . '/' . $patientView);
    pdf_assert(!preg_match('/<img\b[^>]*signature_data/i', $patientSource), $patientView . ' does not expose signature data through an image source');
}

pdf_rrmdir($storage);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
