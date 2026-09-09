<?php
/**
 * Patient API: cancel unfinished active triage (interview / preliminary) and start clean.
 * Does not delete completed consultations, prescriptions, referrals, or medical history.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_symptoms_review_submit.php';

Api::startJson();
Api::requirePatientReady($pdo);
Api::requirePost();
Api::requireCsrf();

$patientId = (int) ($_SESSION['user_id'] ?? 0);
$triageId = (int) ($_POST['triage_id'] ?? 0);

$result = patient_cancel_active_triage_session($pdo, $patientId, $triageId);

if (!$result['ok']) {
    Api::error($result['message'], 400, [
        'cancelled_ids' => $result['cancelled_ids'] ?? [],
    ]);
}

Api::success([
    'cancelled_ids' => array_values(array_map('intval', $result['cancelled_ids'] ?? [])),
    'active_cleared' => true,
], $result['message']);
