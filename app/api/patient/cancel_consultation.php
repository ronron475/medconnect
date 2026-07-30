<?php
/**
 * Patient API: cancel upcoming consultation and free the appointment slot.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_consultation_cancel.php';

Api::startJson();
Api::requirePatientReady($pdo);
Api::requirePost();
Api::requireCsrf();

$patientId = (int) $_SESSION['user_id'];
$consultationId = (int) ($_POST['consultation_id'] ?? 0);
$reason = trim((string) ($_POST['reason'] ?? ''));

$result = patient_cancel_consultation($pdo, $patientId, $consultationId, $reason);

if (!$result['ok']) {
    Api::error($result['message'], 400);
}

Api::success([
    'consultation_id' => (int) ($result['consultation_id'] ?? $consultationId),
    'slots_freed' => (int) ($result['slots_freed'] ?? 0),
], $result['message']);
