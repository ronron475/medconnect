<?php
/**
 * Patient API: fetch Care tips state.
 * - Approved + unacknowledged → full tips for chat
 * - Pending provider approval → waiting state (FAB visible, no tips yet)
 * Requires chief complaint; never returns tips for empty complaint.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/triage_assessment_schema.php';

Api::startJson();
Api::requirePatientReady($pdo);

triage_assessment_ensure_schema($pdo);

$patientId = (int) $_SESSION['user_id'];

try {
    $approved = $pdo->prepare("
        SELECT id, chief_complaint, recommendations, recommendation_approved_at, assessed_at, triage_level, urgency_label
        FROM triage_results
        WHERE patient_id = ?
          AND recommendation_status = 'approved'
          AND recommendation_patient_ack_at IS NULL
          AND TRIM(COALESCE(chief_complaint, '')) <> ''
          AND TRIM(COALESCE(recommendations, '')) <> ''
        ORDER BY recommendation_approved_at DESC, assessed_at DESC
        LIMIT 1
    ");
    $approved->execute([$patientId]);
    $row = $approved->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $list = triage_recommendations_to_list((string) ($row['recommendations'] ?? ''));
        if ($list !== []) {
            Api::success([
                'item' => [
                    'id' => (int) $row['id'],
                    'status' => 'approved',
                    'chief_complaint' => trim((string) ($row['chief_complaint'] ?? '')),
                    'recommendations' => $list,
                    'approved_at' => (string) ($row['recommendation_approved_at'] ?? ''),
                    'book_message' => 'You can follow these tips on your own. If you would like to consult a licensed doctor, you may book an appointment anytime.',
                    'book_url' => (defined('ASSET_BASE') ? ASSET_BASE : '') . '/views/patient/triage.php',
                ],
                'awaiting_provider' => null,
            ], 'Approved recommendations ready.');
        }
    }

    $pending = $pdo->prepare("
        SELECT id, chief_complaint, recommendation_status, assessed_at
        FROM triage_results
        WHERE patient_id = ?
          AND recommendation_status = 'pending_approval'
          AND TRIM(COALESCE(chief_complaint, '')) <> ''
          AND TRIM(COALESCE(recommendations, '')) <> ''
        ORDER BY assessed_at DESC
        LIMIT 1
    ");
    $pending->execute([$patientId]);
    $wait = $pending->fetch(PDO::FETCH_ASSOC);

    if ($wait) {
        Api::success([
            'item' => null,
            'awaiting_provider' => [
                'id' => (int) $wait['id'],
                'status' => 'pending_approval',
                'chief_complaint' => trim((string) ($wait['chief_complaint'] ?? '')),
                'message' => 'Your self-care tips are ready for provider review. They will appear here after your healthcare provider approves them.',
            ],
        ], 'Waiting for provider approval.');
    }

    Api::success([
        'item' => null,
        'awaiting_provider' => null,
    ], 'No pending recommendations.');
} catch (Throwable $e) {
    Api::error('Could not load recommendations.', 500);
}
