<?php
/**
 * Patient API: submit symptoms for non-urgent AI self-care review (no booking).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_symptoms_review_submit.php';

Api::startJson();
Api::requirePatientReady($pdo);
Api::requirePost();
Api::requireCsrf();

$patientId = (int) $_SESSION['user_id'];
$complaint = trim((string) ($_POST['chief_complaint'] ?? ''));
$symptoms = $_POST['symptoms'] ?? [];
if (!is_array($symptoms)) {
    $symptoms = [];
}
$symptomList = array_values(array_filter(array_map(static function ($s) {
    return is_string($s) ? trim($s) : '';
}, $symptoms)));

$regNlpRaw = trim((string) ($_POST['registration_nlp_json'] ?? ''));

$result = patient_submit_symptoms_for_review($pdo, $patientId, $complaint, $symptomList, $regNlpRaw);

if (!$result['ok']) {
    $code = !empty($result['payload']['duplicate_pending']) ? 409 : 400;
    Api::error($result['message'], $code, $result['payload']);
}

Api::success($result['payload'], $result['message']);
