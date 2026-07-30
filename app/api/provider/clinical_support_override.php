<?php
/**
 * Provider: manually override AI urgency for clinical support (with audit note).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/audit_log.php';
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

    $support = provider_consultation_clinical_support($pdo, $consultationId, $patientId);
    if (empty($support['available'])) {
        echo json_encode(['success' => false, 'message' => 'Run AI re-assessment before overriding urgency.']);
        exit;
    }

    if (empty($support['ai_urgency_bucket'])) {
        $support['ai_urgency_bucket'] = $support['risk_bucket'] ?? 'unknown';
    }
    if (empty($support['ai_urgency'])) {
        $support['ai_urgency'] = provider_clinical_support_urgency_label((string) $support['ai_urgency_bucket']);
    }

    $support['risk_bucket'] = $urgencyBucket;
    $support['final_urgency'] = provider_clinical_support_urgency_label($urgencyBucket);
    $support['risk_level'] = $support['final_urgency'] . ' (doctor override)';
    $support['manual_urgency'] = true;
    $support['manual_override_note'] = $note;
    $support['doctor_override'] = true;
    $support['assessed_at'] = date('Y-m-d H:i:s');
    $support['assessed_label'] = date('M j, Y g:i A');

    provider_clinical_support_save_event(
        $pdo,
        $consultationId,
        $providerId,
        $patientId,
        'urgency_override',
        $support,
        $note,
        $providerName
    );

    audit_log($pdo, [
        'patient_id' => $patientId,
        'action_type' => 'CLINICAL_SUPPORT_URGENCY_OVERRIDE',
        'description' => 'Provider overrode urgency for consultation #' . $consultationId
            . ' to ' . $support['final_urgency'] . ': ' . $note,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Urgency override saved.',
        'support' => $support,
        'audit' => provider_clinical_support_audit_trail($pdo, $consultationId),
    ]);
} catch (Throwable $e) {
    error_log('clinical_support_override: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save urgency override.']);
}
