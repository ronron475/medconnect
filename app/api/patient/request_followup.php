<?php
/**
 * API: Submit a post-consultation follow-up request with NLP triage.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';

Api::startJson();
Api::requirePatientReady($pdo);
Api::requirePost();
Api::requireCsrf();

require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/urgent_followup_workflow.php';

$patientId = (int) $_SESSION['user_id'];
$consultationId = (int) ($_POST['consultation_id'] ?? 0);
$complaint = trim((string) ($_POST['chief_complaint'] ?? $_POST['complaint'] ?? ''));
$symptoms = $_POST['symptoms'] ?? [];

if (is_string($symptoms)) {
    $decoded = json_decode($symptoms, true);
    $symptoms = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $symptoms)));
}
if (!is_array($symptoms)) {
    $symptoms = [];
}
$symptomList = array_values(array_filter(array_map(static function ($s) {
    return is_string($s) ? trim($s) : '';
}, $symptoms)));

if ($consultationId <= 0) {
    Api::error('Please select a consultation to follow up on.');
}
if ($complaint === '' && $symptomList === []) {
    Api::error('Please describe your updated chief complaint.');
}

try {
    $pdo->beginTransaction();
    $result = urgent_followup_submit($pdo, $patientId, $consultationId, $complaint, $symptomList);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('request_followup: ' . $e->getMessage());
    Api::error($e->getMessage());
}

$msg = 'Follow-up request submitted.';
if (!empty($result['emergency'])) {
    $msg = (string) ($result['emergency_message'] ?? 'Emergency symptoms detected. Seek emergency care immediately.');
} elseif (!empty($result['urgent'])) {
    $msg = 'Urgent follow-up case created. Your doctor has been notified and will connect when available.';
} elseif (!empty($result['can_book_followup'])) {
    $msg = 'Your symptoms were assessed as non-urgent. You may book a normal follow-up appointment.';
}

Api::success($result, $msg);
