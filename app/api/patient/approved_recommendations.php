<?php
/**
 * Patient API: Care Tips / Care Assistant state.
 *
 * Active window: 24 hours from doctor approval.
 * Auto-open once per approval until patient dismisses (DB-persisted).
 * Expired tips remain in My Health → Care Tips History.
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

const CARE_TIPS_ACTIVE_HOURS = 24;

const CARE_TIPS_EXPIRED_MESSAGE =
    'Your Care Tips have expired. You can view your previous Care Tips anytime in My Health → Care Tips History.';

$patientId = (int) $_SESSION['user_id'];
$assetBase = defined('ASSET_BASE') ? ASSET_BASE : '';
$historyUrl = $assetBase . '/views/patient/my_health.php?tab=care-tips';

/**
 * Mark Care Tips notifications read/disabled after expiry.
 */
function care_tips_disable_notifications(PDO $pdo, int $patientId, int $triageId): void
{
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1, updated_at = NOW()
            WHERE user_id = ?
              AND related_table = 'triage_results'
              AND related_id = ?
              AND is_read = 0
              AND status = 'active'
              AND (
                title LIKE '%Self-Care Guidance%'
                OR title LIKE '%Care Tips%'
                OR message LIKE '%Care tips%'
                OR message LIKE '%self-care%'
              )
        ");
        $stmt->execute([$patientId, $triageId]);
    } catch (Throwable $e) {
        // Non-fatal — assistant expiry still proceeds.
    }
}

try {
    // Most recent approved tip (active or expired) for this patient.
    $approved = $pdo->prepare("
        SELECT tr.id, tr.chief_complaint, tr.recommendations, tr.recommendation_approved_at, tr.assessed_at,
               tr.triage_level, tr.urgency_label, tr.assigned_provider_id,
               tr.recommendation_patient_ack_at,
               tr.recommendation_assistant_first_opened_at,
               tr.recommendation_assistant_dismissed_at,
               tr.recommendation_last_viewed_at,
               CONCAT(u.first_name, ' ', u.last_name) AS reviewer_name
        FROM triage_results tr
        LEFT JOIN users u ON u.id = tr.recommendation_approved_by
        WHERE tr.patient_id = ?
          AND tr.recommendation_status = 'approved'
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
            $tipId = (int) $row['id'];
            $approvedAt = (string) ($row['recommendation_approved_at'] ?? '');
            $approvedTs = $approvedAt !== '' ? strtotime($approvedAt) : false;
            $expiresTs = $approvedTs ? ($approvedTs + (CARE_TIPS_ACTIVE_HOURS * 3600)) : false;
            $now = time();
            $isExpired = $expiresTs !== false && $now >= $expiresTs;
            $firstOpened = !empty($row['recommendation_assistant_first_opened_at']);
            $dismissed = !empty($row['recommendation_assistant_dismissed_at']);
            $acked = !empty($row['recommendation_patient_ack_at']);

            if ($isExpired) {
                care_tips_disable_notifications($pdo, $patientId, $tipId);

                Api::success([
                    'item' => null,
                    'awaiting_provider' => null,
                    'expired' => [
                        'id' => $tipId,
                        'message' => CARE_TIPS_EXPIRED_MESSAGE,
                        'history_url' => $historyUrl,
                        'history_label' => 'My Health → Care Tips History',
                        'approved_at' => $approvedAt,
                        'expired_at' => $expiresTs ? date('c', $expiresTs) : null,
                    ],
                    'tips_cancel_prompt' => null,
                ], 'Care Tips expired.');
            }

            // Within 24h — auto-open once until first open or close is persisted.
            // After that, patient reopens via floating Care tips button only.
            $shouldAutoOpen = !$firstOpened && !$dismissed;
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

            $hoursLeft = $expiresTs ? max(0, (int) ceil(($expiresTs - $now) / 3600)) : CARE_TIPS_ACTIVE_HOURS;

            Api::success([
                'item' => [
                    'id' => $tipId,
                    'status' => 'approved',
                    'chief_complaint' => trim((string) ($row['chief_complaint'] ?? '')),
                    'recommendations' => $list,
                    'approved_at' => $approvedAt,
                    'approved_at_label' => $approvedAt !== ''
                        ? date('M j, Y g:i A', strtotime($approvedAt))
                        : '',
                    'expires_at' => $expiresTs ? date('c', $expiresTs) : null,
                    'hours_remaining' => $hoursLeft,
                    'should_auto_open' => $shouldAutoOpen,
                    'fab_visible' => true,
                    'is_active' => true,
                    'dismissed' => $dismissed,
                    'first_opened' => $firstOpened,
                    'acked' => $acked,
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
                    'history_url' => $historyUrl,
                ],
                'awaiting_provider' => null,
                'expired' => null,
                'tips_cancel_prompt' => ($shouldAutoOpen && $upcoming !== null)
                    ? [
                        'tip_id' => $tipId,
                        'chief_complaint' => trim((string) ($row['chief_complaint'] ?? '')),
                        'upcoming_consultation' => $upcoming,
                    ]
                    : ($shouldAutoOpen ? patient_tips_ready_cancel_prompt($pdo, $patientId) : null),
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
            'expired' => null,
            'tips_cancel_prompt' => null,
        ], 'Waiting for provider approval.');
    }

    Api::success([
        'item' => null,
        'awaiting_provider' => null,
        'expired' => null,
        'tips_cancel_prompt' => patient_tips_ready_cancel_prompt($pdo, $patientId),
    ], 'No pending recommendations.');
} catch (Throwable $e) {
    Api::error('Could not load recommendations.', 500);
}
