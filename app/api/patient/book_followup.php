<?php
/**
 * API: Book a non-urgent follow-up appointment from an open follow-up case.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';

Api::startJson();
Api::requirePatientReady($pdo);
Api::requirePost();
Api::requireCsrf();

require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/urgent_followup_workflow.php';

$patientId = (int) $_SESSION['user_id'];
$caseId = (int) ($_POST['case_id'] ?? 0);
$slotId = (int) ($_POST['slot_id'] ?? 0);

if ($caseId <= 0) {
    Api::error('Follow-up case ID is required.');
}
if ($slotId <= 0) {
    Api::error('Please select an available appointment slot.');
}

try {
    $pdo->beginTransaction();
    $result = urgent_followup_book_appointment($pdo, $patientId, $caseId, $slotId);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('book_followup: ' . $e->getMessage());
    Api::error($e->getMessage());
}

Api::success($result, 'Follow-up appointment booked successfully.');
