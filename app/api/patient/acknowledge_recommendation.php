<?php
/**
 * Patient API: acknowledge / dismiss approved self-care recommendations popup.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/triage_assessment_schema.php';

Api::startJson();
Api::requirePatientReady($pdo);
Api::requirePost();
Api::requireCsrf();

triage_assessment_ensure_schema($pdo);

$patientId = (int) $_SESSION['user_id'];
$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    Api::error('Recommendation id is required.');
}

try {
    $stmt = $pdo->prepare("
        UPDATE triage_results
        SET recommendation_patient_ack_at = NOW()
        WHERE id = ?
          AND patient_id = ?
          AND recommendation_status = 'approved'
          AND recommendation_patient_ack_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$id, $patientId]);

    if ($stmt->rowCount() < 1) {
        Api::error('Recommendation not found or already acknowledged.', 404);
    }

    Api::success(['id' => $id], 'Recommendation acknowledged.');
} catch (Throwable $e) {
    Api::error('Could not save acknowledgment.', 500);
}
