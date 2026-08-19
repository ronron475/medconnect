<?php
/**
 * Provider: save doctor urgency override as the authoritative final triage result.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/audit_log.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_clinical_support.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'provider') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

auth_csrf_require();

$consultationId = (int) ($_POST['consultation_id'] ?? 0);
$urgencyBucket = provider_clinical_support_normalize_bucket((string) ($_POST['urgency_bucket'] ?? ''));
$note = trim((string) ($_POST['audit_note'] ?? ''));

if ($consultationId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Consultation ID is required.']);
    exit;
}
if (!in_array($urgencyBucket, ['emergency', 'urgent', 'non_urgent'], true)) {
    echo json_encode(['success' => false, 'message' => 'Select Emergency, Urgent, or Non-Urgent.']);
    exit;
}
if ($note === '') {
    echo json_encode(['success' => false, 'message' => 'Add a brief clinical reason for the urgency override.']);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT id, patient_id, provider_id, status FROM consultations WHERE id = ? AND provider_id = ? LIMIT 1');
    $stmt->execute([$consultationId, (int) $_SESSION['user_id']]);
    $consult = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$consult) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Consultation not found or access denied.']);
        exit;
    }

    $patientId = (int) $consult['patient_id'];
    if ($patientId <= 0) {
        echo json_encode(['success' => false, 'message' => 'This consultation is not linked to a patient.']);
        exit;
    }

    $providerId = (int) $_SESSION['user_id'];
    $providerName = trim((string) (($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')));
    if ($providerName === '') {
        $u = $pdo->prepare('SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1');
        $u->execute([$providerId]);
        $urow = $u->fetch(PDO::FETCH_ASSOC) ?: [];
        $providerName = trim((string) (($urow['first_name'] ?? '') . ' ' . ($urow['last_name'] ?? ''))) ?: 'Provider';
    }

    $nameStmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) FROM users WHERE id = ? LIMIT 1');
    $nameStmt->execute([$patientId]);
    $patientName = trim((string) ($nameStmt->fetchColumn() ?: 'Patient'));
    $patientNumber = 'MC-' . str_pad((string) $patientId, 6, '0', STR_PAD_LEFT);

    $saved = provider_clinical_support_persist_doctor_override(
        $pdo,
        $consultationId,
        $providerId,
        $patientId,
        $urgencyBucket,
        $note,
        $providerName
    );

    $persisted = $saved['persisted'];
    $workflow = $saved['workflow'];
    $finalBucket = (string) ($persisted['final_bucket'] ?? '');
    $finalLabel = (string) ($persisted['final_label'] ?? '');
    $aiLabel = (string) ($persisted['ai_label'] ?? '');
    $triageId = (int) ($persisted['triage_id'] ?? 0);

    if ($finalBucket !== $urgencyBucket) {
        echo json_encode([
            'success' => false,
            'message' => 'Override did not persist as the selected urgency. Please try again.',
            'persisted' => $persisted,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    NotificationEvents::doctorFinalTriageForPatient(
        $pdo,
        $patientId,
        $providerId,
        $consultationId,
        $triageId,
        $finalBucket,
        $finalLabel,
        $aiLabel,
        $note
    );

    if ($finalBucket === 'emergency') {
        try {
            require_once BASE_PATH . '/app/includes/bhw_patient_workflow.php';
            BhwPatientWorkflow::onPatientPortalEmergency($pdo, $patientId, [
                'triage_id' => $triageId,
                'consultation_id' => $consultationId,
                'referral_id' => (int) ($workflow['referral_id'] ?? 0),
                'source' => 'provider_override',
            ]);
        } catch (Throwable $e) {
            error_log('clinical_support_override emergency workflow: ' . $e->getMessage());
        }
        NotificationEvents::highRiskPatient($pdo, $patientId, $patientName, $finalLabel, $providerId);
        if ((int) ($workflow['referral_id'] ?? 0) > 0) {
            NotificationEvents::referralCreated($pdo, (int) $workflow['referral_id'], $patientId, $providerId, $providerId);
        }
    }

    audit_log($pdo, [
        'patient_id' => $patientId,
        'action_type' => 'CLINICAL_SUPPORT_URGENCY_OVERRIDE',
        'description' => 'Provider overrode urgency for consultation #' . $consultationId
            . ' to ' . $finalLabel . ': ' . $note,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Urgency override saved.',
        'support' => $saved['support'],
        'persisted' => $persisted,
        'workflow' => [
            'bucket' => $finalBucket,
            'emergency' => $finalBucket === 'emergency',
            'urgent' => $finalBucket === 'urgent',
            'non_urgent' => $finalBucket === 'non_urgent',
            'emergency_triggered' => $finalBucket === 'emergency',
            'urgent_triggered' => $finalBucket === 'urgent',
            'referral_id' => (int) ($workflow['referral_id'] ?? 0),
            'facility' => $workflow['facility'] ?? null,
            'video_session_active' => provider_clinical_support_video_session_active($pdo, $consultationId),
        ],
        'patient' => [
            'id' => $patientId,
            'name' => $patientName,
            'patient_number' => $patientNumber,
        ],
        'consultation_id' => $consultationId,
        'audit' => provider_clinical_support_audit_trail($pdo, $consultationId),
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('clinical_support_override: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save urgency override.']);
}
