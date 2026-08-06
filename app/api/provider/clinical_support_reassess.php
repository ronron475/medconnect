<?php
/**
 * Provider: re-assess clinical support from a doctor-finalized chief complaint.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/audit_log.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/MedicalAssessmentEngine.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_clinical_support.php';

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
$complaint = trim((string) ($_POST['chief_complaint'] ?? ''));
$symptomsRaw = trim((string) ($_POST['symptoms'] ?? ''));

if ($consultationId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Consultation ID is required.']);
    exit;
}
if ($complaint === '') {
    echo json_encode(['success' => false, 'message' => 'Enter the final chief complaint before re-assessing.']);
    exit;
}

$symptoms = [];
if ($symptomsRaw !== '') {
    $decoded = json_decode($symptomsRaw, true);
    if (is_array($decoded)) {
        foreach ($decoded as $item) {
            if (is_string($item) && trim($item) !== '') {
                $symptoms[] = trim($item);
            }
        }
    } else {
        foreach (preg_split('/\s*,\s*/', $symptomsRaw) ?: [] as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $symptoms[] = $part;
            }
        }
    }
}

try {
    $stmt = $pdo->prepare('SELECT id, patient_id FROM consultations WHERE id = ? AND provider_id = ? LIMIT 1');
    $stmt->execute([$consultationId, (int) $_SESSION['user_id']]);
    $consult = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$consult) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Consultation not found or access denied.']);
        exit;
    }

    $patientId = (int) $consult['patient_id'];
    $providerId = (int) $_SESSION['user_id'];
    $providerName = trim((string) (($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')));
    if ($providerName === '') {
        $u = $pdo->prepare('SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1');
        $u->execute([$providerId]);
        $urow = $u->fetch(PDO::FETCH_ASSOC) ?: [];
        $providerName = trim((string) (($urow['first_name'] ?? '') . ' ' . ($urow['last_name'] ?? ''))) ?: 'Provider';
    }

    $original = provider_clinical_support_patient_original($pdo, $consultationId, $patientId);
    $assessment = ChiefComplaintNlpService::assess($complaint, $symptoms);
    $support = provider_clinical_support_from_assessment($assessment);
    $support['patient_original_complaint'] = $original['complaint'];
    $support['patient_original_english'] = $original['english'];
    $support['doctor_override'] = true;
    $support['manual_urgency'] = false;
    $support['manual_override_note'] = '';
    $support['ai_urgency'] = $support['final_urgency'];
    $support['ai_urgency_bucket'] = $support['risk_bucket'];

    provider_clinical_support_save_event(
        $pdo,
        $consultationId,
        $providerId,
        $patientId,
        'reassess',
        $support,
        'Doctor finalized chief complaint and requested AI re-assessment.',
        $providerName
    );

    audit_log($pdo, [
        'patient_id' => $patientId,
        'action_type' => 'CLINICAL_SUPPORT_REASSESS',
        'description' => 'Provider re-assessed clinical support for consultation #' . $consultationId
            . ' → ' . ($support['final_urgency'] ?? 'unknown'),
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Clinical support updated from the finalized chief complaint.',
        'support' => $support,
        'audit' => provider_clinical_support_audit_trail($pdo, $consultationId),
    ]);
} catch (Throwable $e) {
    error_log('clinical_support_reassess: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not re-assess clinical support.']);
}
