<?php
/**
 * Stream the provider-signed SOAP PDF for a consultation.
 * Provider: owner of the consultation. Patient: owner, only after the consultation is finalized.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_consultation_records.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/clinical_note_signature.php';

$uid = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['user_role'] ?? '');
if ($uid <= 0 || $role === '') {
    header('Location: ' . auth_signin_required_url());
    exit;
}

$consultationId = (int) ($_GET['consultation_id'] ?? 0);
if ($consultationId <= 0) {
    http_response_code(400);
    echo 'Consultation ID is required.';
    exit;
}

patient_consultation_records_schema_ensure($pdo);

$stmt = $pdo->prepare('
    SELECT c.patient_id, c.provider_id, c.status,
           cn.provider_id AS note_provider_id, cn.signature_data, cn.finalized_at,
           cn.signed_pdf_path, cn.signed_pdf_name
    FROM consultations c
    JOIN clinical_notes cn ON cn.consultation_id = c.id
    WHERE c.id = ?
    LIMIT 1
');
$stmt->execute([$consultationId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$row) {
    http_response_code(404);
    echo 'Signed SOAP note not found.';
    exit;
}

$isFinalized = patient_consultation_is_finalized(
    (string) ($row['status'] ?? ''),
    $row['signature_data'] ?? '',
    $row['finalized_at'] ?? null
);

$allowed = ($role === 'provider'
        && $uid === (int) ($row['provider_id'] ?? 0)
        && $uid === (int) ($row['note_provider_id'] ?? 0))
    || ($role === 'patient' && $uid === (int) ($row['patient_id'] ?? 0) && $isFinalized);
if (!$allowed) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$abs = clinical_note_signed_pdf_abs_path((string) ($row['signed_pdf_path'] ?? ''));
if ($abs === null) {
    http_response_code(404);
    echo 'The signed SOAP PDF is not available for this consultation.';
    exit;
}

$downloadName = 'signed-soap-note-consultation-' . $consultationId . '.pdf';

if (function_exists('session_write_close')) {
    session_write_close();
}
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Length: ' . (int) filesize($abs));
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
readfile($abs);
exit;
