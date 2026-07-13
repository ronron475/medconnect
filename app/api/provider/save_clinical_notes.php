<?php
/**
 * API: Save SOAP Clinical Notes
 * URL: /app/api/provider/save_clinical_notes.php
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_patient_access.php';

if (empty($_SESSION['user_id']) || $_SESSION['user_role'] !== 'provider') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrf = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!auth_csrf_validate($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request token.']);
    exit;
}

$data = [
    'consultation_id' => $_POST['consultation_id'] ?? 0,
    'patient_id'      => $_POST['patient_id']      ?? 0,
    'provider_id'     => $_SESSION['user_id'],
    'subjective'      => $_POST['subjective']      ?? '',
    'objective'       => $_POST['objective']       ?? '',
    'assessment'      => $_POST['assessment']      ?? '',
    'plan'            => $_POST['plan']            ?? '',
    'diagnosis'       => $_POST['diagnosis']       ?? '',
    'treatment_plan'  => $_POST['treatment_plan']  ?? '',
    'prescription'    => $_POST['prescription']    ?? '',
    'signature'       => $_POST['signature_data']  ?? ''
];

if (!$data['consultation_id'] || !$data['patient_id']) {
    echo json_encode(['success' => false, 'message' => 'Invalid consultation or patient ID.']);
    exit;
}

// IDOR protection: consultation must belong to this provider and match patient_id.
$access = provider_patient_assert_access(
    $pdo,
    (int) $data['provider_id'],
    (int) $data['patient_id'],
    (int) $data['consultation_id']
);
if (!$access['allowed']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $access['message']]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO clinical_notes 
        (consultation_id, patient_id, provider_id, subjective, objective, assessment, plan, diagnosis, treatment_plan, prescription, signature_data)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            subjective = VALUES(subjective),
            objective = VALUES(objective),
            assessment = VALUES(assessment),
            plan = VALUES(plan),
            diagnosis = VALUES(diagnosis),
            treatment_plan = VALUES(treatment_plan),
            prescription = VALUES(prescription),
            signature_data = VALUES(signature_data)
    ");
    
    $stmt->execute([
        $data['consultation_id'], $data['patient_id'], $data['provider_id'],
        $data['subjective'], $data['objective'], $data['assessment'], $data['plan'],
        $data['diagnosis'], $data['treatment_plan'], $data['prescription'], $data['signature']
    ]);

    // Update consultation status and sync summary fields for patient My Health timeline
    $diag = trim((string) ($data['diagnosis'] ?? ''));
    if ($diag === '') {
        $diag = trim((string) ($data['assessment'] ?? ''));
    }
    $recommendation = trim((string) ($data['treatment_plan'] ?? ''));
    if ($recommendation === '') {
        $recommendation = trim((string) ($data['plan'] ?? ''));
    }
    $stmt = $pdo->prepare("
        UPDATE consultations
        SET status = 'completed',
            diagnosis = CASE WHEN ? <> '' THEN ? ELSE diagnosis END,
            recommendation = CASE WHEN ? <> '' THEN ? ELSE recommendation END
        WHERE id = ? AND provider_id = ?
    ");
    $stmt->execute([
        $diag, $diag,
        $recommendation, $recommendation,
        $data['consultation_id'], $data['provider_id'],
    ]);

    require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';
    NotificationEvents::consultationCompleted(
        $pdo,
        (int) $data['consultation_id'],
        (int) $data['patient_id'],
        (int) $data['provider_id'],
        (int) $data['provider_id']
    );
    NotificationEvents::medicalRecordUpdated($pdo, (int) $data['patient_id'], (int) $data['provider_id'], (int) $data['provider_id']);

    require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_patient_workflow.php';
    BhwPatientWorkflow::onConsultationCompleted($pdo, (int) $data['patient_id'], 'provider_notes');

    echo json_encode(['success' => true, 'message' => 'Clinical notes saved and consultation finalized.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save clinical notes.']);
}
