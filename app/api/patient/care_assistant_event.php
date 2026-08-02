<?php
/**
 * Patient API: Care Assistant lifecycle events (opened / dismissed / viewed).
 * Persists one-time auto-open and close state without removing Care Tips History.
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
$action = strtolower(trim((string) ($_POST['action'] ?? '')));

if ($id <= 0) {
    Api::error('Recommendation id is required.');
}
if (!in_array($action, ['opened', 'dismissed', 'viewed'], true)) {
    Api::error('Invalid action. Use opened, dismissed, or viewed.');
}

try {
    $check = $pdo->prepare("
        SELECT id, recommendation_status, recommendation_approved_at,
               recommendation_assistant_first_opened_at, recommendation_assistant_dismissed_at
        FROM triage_results
        WHERE id = ? AND patient_id = ? AND recommendation_status = 'approved'
        LIMIT 1
    ");
    $check->execute([$id, $patientId]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        Api::error('Care tip not found.', 404);
    }

    if ($action === 'opened') {
        // First open (auto or first panel show after approval)
        $pdo->prepare("
            UPDATE triage_results
            SET recommendation_assistant_first_opened_at = COALESCE(recommendation_assistant_first_opened_at, NOW()),
                recommendation_last_viewed_at = NOW()
            WHERE id = ? AND patient_id = ?
            LIMIT 1
        ")->execute([$id, $patientId]);
    } elseif ($action === 'dismissed') {
        $pdo->prepare("
            UPDATE triage_results
            SET recommendation_assistant_dismissed_at = COALESCE(recommendation_assistant_dismissed_at, NOW()),
                recommendation_assistant_first_opened_at = COALESCE(recommendation_assistant_first_opened_at, NOW()),
                recommendation_last_viewed_at = NOW()
            WHERE id = ? AND patient_id = ?
            LIMIT 1
        ")->execute([$id, $patientId]);
    } else { // viewed
        $pdo->prepare("
            UPDATE triage_results
            SET recommendation_last_viewed_at = NOW(),
                recommendation_assistant_first_opened_at = COALESCE(recommendation_assistant_first_opened_at, NOW())
            WHERE id = ? AND patient_id = ?
            LIMIT 1
        ")->execute([$id, $patientId]);
    }

    Api::success([
        'id' => $id,
        'action' => $action,
    ], 'Care Assistant state saved.');
} catch (Throwable $e) {
    Api::error('Could not save Care Assistant state.', 500);
}
