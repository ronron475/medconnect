<?php
/**
 * Stream a SOAP PDF preview from the current form values.
 * Does not write a clinical note, store a PDF, or issue a prescription.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_patient_access.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/clinical_note_signature.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/clinical_note_pdf.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/prescription_lines.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/icd10_lookup.php';

if (empty($_SESSION['user_id']) || $_SESSION['user_role'] !== 'provider') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrf = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!auth_csrf_validate($csrf)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Invalid request token.']);
    exit;
}

$providerId = (int) $_SESSION['user_id'];
$consultationId = (int) ($_POST['consultation_id'] ?? 0);
$patientId = (int) ($_POST['patient_id'] ?? 0);
if ($consultationId <= 0 || $patientId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Invalid consultation or patient ID.']);
    exit;
}

$access = provider_patient_assert_access($pdo, $providerId, $patientId, $consultationId);
if (!$access['allowed']) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'You are not authorized to preview this SOAP note.']);
    exit;
}

$identity = clinical_note_provider_identity($pdo, $providerId);
$providerName = trim((string) ($identity['full_name'] ?? ''));
if ($providerName === '') {
    $providerName = trim((string) ($identity['legal_name'] ?? ''));
}

$diagnosis = trim((string) ($_POST['diagnosis'] ?? ''));
if ($diagnosis !== '') {
    $icd = icd10_match_label($diagnosis);
    if ($icd !== null) {
        $diagnosis = (string) $icd['label'];
    }
}

$rxPosted = prescription_lines_from_post($_POST);
if ($rxPosted['error'] !== null) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $rxPosted['error']]);
    exit;
}
if ($rxPosted['provided']) {
    $prescriptionText = prescription_lines_display(
        prescription_lines_encode($rxPosted['rows'], (string) ($_POST['prescription_legacy'] ?? ''))
    );
} else {
    $prescriptionText = prescription_lines_display((string) ($_POST['prescription'] ?? ''));
}

$bytes = clinical_note_pdf_render([
    'consultation_id' => $consultationId,
    'subjective' => (string) ($_POST['subjective'] ?? ''),
    'objective' => (string) ($_POST['objective'] ?? ''),
    'assessment' => (string) ($_POST['assessment'] ?? ''),
    'plan' => (string) ($_POST['plan'] ?? ''),
    'diagnosis' => $diagnosis,
    'prescription' => $prescriptionText,
    'provider_name' => $providerName,
    'signed_at' => date('Y-m-d H:i:s'),
    'preview' => true,
]);
if ($bytes === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Could not generate the SOAP PDF preview.']);
    exit;
}

if (function_exists('session_write_close')) {
    session_write_close();
}
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($bytes));
header('Content-Disposition: inline; filename="soap-note-preview.pdf"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
echo $bytes;
exit;
