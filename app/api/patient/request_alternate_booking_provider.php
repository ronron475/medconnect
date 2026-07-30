<?php
/**
 * Patient API: request another assigned doctor when the current one has no slots today.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/triage_provider_assignment.php';

Api::startJson();
Api::requirePatientReady($pdo);
Api::requirePost();
Api::requireCsrf();

$patientId = (int) $_SESSION['user_id'];
$result = triage_patient_request_alternate_booking_provider($pdo, $patientId);

if (!$result['ok']) {
    Api::error($result['message'], 400);
}

Api::success([
    'provider_id' => (int) ($result['provider_id'] ?? 0),
    'provider_name' => (string) ($result['provider_name'] ?? ''),
], $result['message']);
