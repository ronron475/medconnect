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
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/triage_provider_assignment.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_consultation_cancel.php';

Api::startJson();
Api::requirePatientReady($pdo);

triage_assessment_ensure_schema($pdo);

const TRIAGE_PATIENT_WAITING_REVIEW_MESSAGE =
    'Your case is currently being reviewed by a healthcare provider. Please wait while your guidance is being prepared.';

$patientId = (int) $_SESSION['user_id'];
$assetBase = defined('ASSET_BASE') ? ASSET_BASE : '';

try {
    $approved = $pdo->prepare("
        SELECT tr.id, tr.chief_complaint, tr.recommendations, tr.recommendation_approved_at, tr.assessed_at,
               tr.triage_level, tr.urgency_label, tr.assigned_provider_id,
               CONCAT(u.first_name, ' ', u.last_name) AS reviewer_name
        FROM triage_results tr
        LEFT JOIN users u ON u.id = tr.recommendation_approved_by
        WHERE tr.patient_id = ?
          AND tr.recommendation_status = 'approved'
          AND tr.recommendation_patient_ack_at IS NULL
          AND TRIM(COALESCE(tr.chief_complaint, '')) <> ''
          AND TRIM(COALESCE(tr.recommendations, '')) <> ''
        ORDER BY tr.recommendation_approved_at DESC, tr.assessed_at DESC
        LIMIT 1
    ");
    $approved->execute([$patientId]);
    $row = $approved->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $list = triage_recommendations_to_list((string) ($row['recommendations'] ?? ''));
        if ($list !== []) {
            $approvedAt = (string) ($row['recommendation_approved_at'] ?? '');
            $reviewerName = trim((string) ($row['reviewer_name'] ?? ''));
            $assignedId = (int) ($row['assigned_provider_id'] ?? 0);
            $bookUrl = $assetBase . '/views/patient/triage.php';
            if ($assignedId > 0) {
                $bookUrl .= '?provider_id=' . $assignedId;
            }

            $upcoming = patient_upcoming_cancellable_consultation($pdo, $patientId);
            $bookMessage = 'You can follow these tips at home. If you still want an online consultation, book with the same doctor who reviewed your guidance.';
            $bookCta = 'Proceed to Book Consultation';
            $bookUrlOut = $bookUrl;
            if ($upcoming !== null) {
                $bookMessage = 'Your care tips are ready. You already have a video visit booked for '
                    . $upcoming['label']
                    . ' with '
                    . $upcoming['provider_name']
                    . '. Keep it if you still want to talk, or cancel so that time slot opens for other patients.';
                $bookCta = 'Keep my video visit';
                $bookUrlOut = $assetBase . '/views/patient/consultations.php';
            }

            Api::success([
                'item' => [
                    'id' => (int) $row['id'],
                    'status' => 'approved',
                    'chief_complaint' => trim((string) ($row['chief_complaint'] ?? '')),
                    'recommendations' => $list,
                    'approved_at' => $approvedAt,
                    'approved_at_label' => $approvedAt !== ''
                        ? date('M j, Y g:i A', strtotime($approvedAt))
                        : '',
                    'reviewer_name' => $reviewerName,
                    'reviewed_by_label' => $reviewerName !== ''
                        ? ('Reviewed and approved by ' . $reviewerName
                            . ($approvedAt !== '' ? ' on ' . date('M j, Y', strtotime($approvedAt)) : ''))
                        : '',
                    'assigned_provider_id' => $assignedId,
                    'book_message' => $bookMessage,
                    'book_url' => $bookUrlOut,
                    'book_cta_label' => $bookCta,
                    'upcoming_consultation' => $upcoming,
                ],
                'awaiting_provider' => null,
                'tips_cancel_prompt' => $upcoming !== null
                    ? [
                        'tip_id' => (int) $row['id'],
                        'chief_complaint' => trim((string) ($row['chief_complaint'] ?? '')),
                        'upcoming_consultation' => $upcoming,
                    ]
                    : patient_tips_ready_cancel_prompt($pdo, $patientId),
            ], 'Approved recommendations ready.');
        }
    }

    $pending = $pdo->prepare("
        SELECT id, chief_complaint, recommendation_status, assessed_at, assigned_provider_id
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
                'message' => TRIAGE_PATIENT_WAITING_REVIEW_MESSAGE,
                'assigned_provider_id' => (int) ($wait['assigned_provider_id'] ?? 0),
            ],
            'tips_cancel_prompt' => null,
        ], 'Waiting for provider approval.');
    }

    Api::success([
        'item' => null,
        'awaiting_provider' => null,
        'tips_cancel_prompt' => patient_tips_ready_cancel_prompt($pdo, $patientId),
    ], 'No pending recommendations.');
} catch (Throwable $e) {
    Api::error('Could not load recommendations.', 500);
}
