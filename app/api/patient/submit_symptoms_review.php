<?php
/**
 * Patient API: submit symptoms for non-urgent AI self-care review (no booking).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_symptoms_review_submit.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/complaint_evidence.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_chief_complaints.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/user_account_status.php';

Api::startJson();
Api::requirePatientReady($pdo);

$submissionCheck = patient_account_may_submit_consultation($pdo, (int) $_SESSION['user_id']);
if (!$submissionCheck['allowed']) {
    Api::error($submissionCheck['message'], 403, ['code' => $submissionCheck['code'] ?? 'account_blocked']);
}
Api::requirePost();
Api::requireCsrf();

$patientId = (int) $_SESSION['user_id'];
$submittedComplaint = trim((string) ($_POST['chief_complaint'] ?? ''));
$complaint = patient_portal_resolve_chief_complaint($pdo, $patientId, $submittedComplaint);
$symptoms = $_POST['symptoms'] ?? [];
if (!is_array($symptoms)) {
    $symptoms = [];
}
$symptomList = array_values(array_filter(array_map(static function ($s) {
    return is_string($s) ? trim($s) : '';
}, $symptoms)));

$evidenceFile = $_FILES['supporting_evidence'] ?? null;
$evidenceValidation = complaint_evidence_validate_upload(is_array($evidenceFile) ? $evidenceFile : null);
if ($evidenceValidation !== null) {
    Api::error((string) $evidenceValidation['error']);
}

$result = patient_submit_symptoms_for_review($pdo, $patientId, $complaint, $symptomList);

if ($result['ok']) {
    $triageId = (int) ($result['payload']['triage_id'] ?? 0);
    if ($triageId > 0) {
        complaint_evidence_try_attach(
            $pdo,
            $patientId,
            $triageId,
            is_array($evidenceFile) ? $evidenceFile : null
        );
        $registrationRef = patient_chief_complaint_registration_reference($pdo, $patientId);
        patient_chief_complaint_record(
            $pdo,
            $patientId,
            $complaint,
            'care_tips_review',
            $triageId,
            null,
            null,
            $registrationRef !== '' ? $registrationRef : null
        );
    }
}

if (!$result['ok']) {
    $code = !empty($result['payload']['duplicate_pending']) ? 409 : 400;
    Api::error($result['message'], $code, $result['payload']);
}

Api::success($result['payload'], $result['message']);
