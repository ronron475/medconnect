<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_patient_access.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/triage_assessment_schema.php';
require_once BASE_PATH . '/app/core/TriageLevelService.php';
require_once BASE_PATH . '/app/includes/notification_events.php';
require_once BASE_PATH . '/app/includes/case_reports.php';

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'provider') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

auth_csrf_require();

$id     = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';
$level  = $_POST['level'] ?? '';

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Triage ID is required.']);
    exit;
}

try {
    // IDOR protection: triage must belong to a patient this provider is allowed to act on.
    $t = $pdo->prepare('SELECT patient_id, assessed_at, status, outcome, triage_level, urgency_label FROM triage_results WHERE id = ? LIMIT 1');
    $t->execute([$id]);
    $triageRow = $t->fetch(PDO::FETCH_ASSOC);
    if (!$triageRow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Triage record not found.']);
        exit;
    }
    $patientId = (int) ($triageRow['patient_id'] ?? 0);
    if ($patientId <= 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Triage record not found.']);
        exit;
    }
    $access = provider_patient_assert_access($pdo, (int) $_SESSION['user_id'], $patientId, 0);
    if (!$access['allowed']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }

    if (triage_case_is_terminated_row($triageRow)) {
        echo json_encode(['success' => false, 'message' => 'This case has been terminated and cannot continue through the clinical workflow.']);
        exit;
    }

    triage_assessment_ensure_schema($pdo);

    if ($action === 'accept') {
        if (!triage_case_can_accept((string) ($triageRow['assessed_at'] ?? ''), (string) ($triageRow['status'] ?? ''))) {
            $msg = triage_case_is_expired((string) ($triageRow['assessed_at'] ?? ''))
                ? 'This triage case has expired. Only same-day submissions can be accepted.'
                : 'This triage case cannot be accepted.';
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE triage_results SET status = 'accepted' WHERE id = ? AND status = 'pending'");
        $stmt->execute([$id]);
        
        audit_log($pdo, [
            'patient_id'  => $patientId,
            'action_type' => 'TRIAGE_ACCEPTED',
            'description' => "Provider accepted triage case ID: $id"
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Triage case accepted.']);
    } 
    else if ($action === 'override') {
        if (!in_array((string) $level, ['1', '2', '3', '4', '5'], true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid level.']);
            exit;
        }
        $label = match($level) {
            '1' => 'Urgent (Priority 1)',
            '2' => 'Urgent (Priority 2)',
            '3' => 'Non-Urgent (Priority 3)',
            '4' => 'Routine (Priority 4)',
            '5' => 'Routine (Priority 5)',
            default => 'Routine'
        };
        $triageLevel = TriageLevelService::fromDbLevel((string) $level);

        $stmt = $pdo->prepare("UPDATE triage_results SET level = ?, urgency_label = ?, triage_level = ?, assessed_at = NOW() WHERE id = ?");
        $stmt->execute([$level, $label, $triageLevel, $id]);

        // Keep patient remedy gate in sync with clinical override.
        $meta = $pdo->prepare('SELECT chief_complaint, recommendations, triage_classification FROM triage_results WHERE id = ? LIMIT 1');
        $meta->execute([$id]);
        $metaRow = $meta->fetch(PDO::FETCH_ASSOC) ?: [];
        $recStatus = triage_recommendation_status_for_insert(
            $triageLevel,
            (string) ($metaRow['chief_complaint'] ?? ''),
            (string) ($metaRow['recommendations'] ?? ''),
            (string) ($metaRow['triage_classification'] ?? '')
        );
        $pdo->prepare("
            UPDATE triage_results
            SET recommendation_status = ?,
                recommendation_approved_by = NULL,
                recommendation_approved_at = NULL,
                recommendation_patient_ack_at = NULL
            WHERE id = ?
        ")->execute([$recStatus, $id]);

        audit_log($pdo, [
            'patient_id'  => $patientId,
            'action_type' => 'TRIAGE_OVERRIDE',
            'description' => "Provider manually overrode triage ID: $id to Level $level ($label)"
        ]);

        echo json_encode(['success' => true, 'message' => 'Priority level updated.']);
    }
    else if ($action === 'approve_recommendations' || $action === 'reject_recommendations') {
        $meta = $pdo->prepare("
            SELECT chief_complaint, recommendations, recommendation_status, triage_level, triage_classification,
                   urgency_label, level, assigned_provider_id
            FROM triage_results WHERE id = ? LIMIT 1
        ");
        $meta->execute([$id]);
        $metaRow = $meta->fetch(PDO::FETCH_ASSOC);
        if (!$metaRow) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Triage record not found.']);
            exit;
        }

        $assignedReviewer = (int) ($metaRow['assigned_provider_id'] ?? 0);
        $actingProvider = (int) $_SESSION['user_id'];
        if ($assignedReviewer > 0 && $assignedReviewer !== $actingProvider) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'This AI review case is assigned to another provider. Contact an administrator to reassign.',
            ]);
            exit;
        }

        $complaint = trim((string) ($metaRow['chief_complaint'] ?? ''));
        if ($complaint === '') {
            echo json_encode([
                'success' => false,
                'message' => 'No chief complaint on this case. NLP recommendations cannot be released to the patient.',
            ]);
            exit;
        }

        if (!triage_provider_may_release_recommendations($metaRow)) {
            echo json_encode([
                'success' => false,
                'message' => 'Only non-urgent cases with a chief complaint can release self-care recommendations to the patient.',
            ]);
            exit;
        }

        if ($action === 'reject_recommendations') {
            $pdo->prepare("
                UPDATE triage_results
                SET recommendation_status = 'rejected',
                    recommendation_approved_by = ?,
                    recommendation_approved_at = NOW()
                WHERE id = ?
            ")->execute([(int) $_SESSION['user_id'], $id]);

            triage_mark_provider_review_complete($pdo, $id);

            audit_log($pdo, [
                'patient_id'  => $patientId,
                'action_type' => 'TRIAGE_RECOMMENDATIONS_REJECTED',
                'description' => "Provider rejected patient-facing NLP remedies for triage ID: $id",
            ]);

            NotificationEvents::careTipsReviewUpdatedForPatient(
                $pdo,
                $patientId,
                (int) $_SESSION['user_id'],
                false,
                $id
            );

            echo json_encode(['success' => true, 'message' => 'Review complete. Self-care guidance was withheld from the patient.']);
            exit;
        }

        $edited = trim((string) ($_POST['recommendations'] ?? ''));
        if ($edited === '') {
            $edited = trim((string) ($metaRow['recommendations'] ?? ''));
        }
        // Prefer symptom-specific library tips when the posted text is still a legacy one-liner.
        if (triage_recommendations_need_self_care_refresh($edited)) {
            $symStmt = $pdo->prepare('SELECT english_complaint, detected_symptoms_json, possible_conditions_json FROM triage_results WHERE id = ? LIMIT 1');
            $symStmt->execute([$id]);
            $symRow = $symStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $detected = [];
            $decodedSym = json_decode((string) ($symRow['detected_symptoms_json'] ?? ''), true);
            if (is_array($decodedSym)) {
                $detected = $decodedSym;
            }
            $conditions = [];
            $decodedCond = json_decode((string) ($symRow['possible_conditions_json'] ?? ''), true);
            if (is_array($decodedCond)) {
                $conditions = $decodedCond;
            }
            $edited = triage_build_self_care_recommendations_text(
                $complaint,
                trim((string) ($symRow['english_complaint'] ?? '')),
                $detected,
                $conditions
            );
        }
        $list = triage_recommendations_to_list($edited);
        if ($list === []) {
            echo json_encode(['success' => false, 'message' => 'Add at least one self-care recommendation before approving.']);
            exit;
        }
        $savedText = triage_recommendations_from_list($list);

        // New approval resets Care Assistant lifecycle so it auto-opens once again.
        $pdo->prepare("
            UPDATE triage_results
            SET recommendations = ?,
                recommendation_status = 'approved',
                recommendation_approved_by = ?,
                recommendation_approved_at = NOW(),
                recommendation_patient_ack_at = NULL,
                recommendation_assistant_first_opened_at = NULL,
                recommendation_assistant_dismissed_at = NULL,
                recommendation_last_viewed_at = NULL,
                assigned_provider_id = COALESCE(assigned_provider_id, ?),
                assigned_at = COALESCE(assigned_at, NOW())
            WHERE id = ?
        ")->execute([$savedText, (int) $_SESSION['user_id'], (int) $_SESSION['user_id'], $id]);

        triage_mark_provider_review_complete($pdo, $id);

        audit_log($pdo, [
            'patient_id'  => $patientId,
            'action_type' => 'TRIAGE_RECOMMENDATIONS_APPROVED',
            'description' => "Provider approved patient-facing NLP remedies for triage ID: $id",
        ]);

        $reviewerStmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) FROM users WHERE id = ? LIMIT 1');
        $reviewerStmt->execute([(int) $_SESSION['user_id']]);
        $reviewerName = trim((string) ($reviewerStmt->fetchColumn() ?: 'Your healthcare provider'));

        NotificationEvents::careTipsApprovedForPatient(
            $pdo,
            $patientId,
            (int) $_SESSION['user_id'],
            $reviewerName,
            $id
        );

        echo json_encode([
            'success' => true,
            'message' => 'Review complete. Self-care recommendations are now available to the patient.',
            'data' => [
                'recommendation_status' => 'approved',
                'recommendations' => $savedText,
                'recommendations_list' => $list,
            ],
        ]);
    }
    else {
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    }

} catch (Throwable $e) {
    error_log('update_triage error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Could not complete triage update. Please refresh the page and try again.',
    ]);
}
