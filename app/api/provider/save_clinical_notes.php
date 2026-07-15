<?php
/**
 * API: Save SOAP Clinical Notes
 * URL: /app/api/provider/save_clinical_notes.php
 *
 * Draft (default): saves notes without ending the consultation.
 * Finalize (finalize=1): requires signature, completes consult, ends active video room.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_patient_access.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/clinical_tables.php';

clinical_tables_ensure($pdo);

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

$finalize = in_array(strtolower(trim((string) ($_POST['finalize'] ?? ''))), ['1', 'true', 'yes'], true);

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
    'signature'       => $_POST['signature_data']  ?? '',
];

if (!$data['consultation_id'] || !$data['patient_id']) {
    echo json_encode(['success' => false, 'message' => 'Invalid consultation or patient ID.']);
    exit;
}

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

if ($finalize && trim((string) $data['signature']) === '') {
    echo json_encode(['success' => false, 'message' => 'Digital signature is required to finalize.']);
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
        $data['diagnosis'], $data['treatment_plan'], $data['prescription'], $data['signature'],
    ]);

    $diag = trim((string) ($data['diagnosis'] ?? ''));
    if ($diag === '') {
        $diag = trim((string) ($data['assessment'] ?? ''));
    }
    $recommendation = trim((string) ($data['treatment_plan'] ?? ''));
    if ($recommendation === '') {
        $recommendation = trim((string) ($data['plan'] ?? ''));
    }

    if (!$finalize) {
        // Draft: sync summary fields without ending the visit.
        $pdo->prepare("
            UPDATE consultations
            SET diagnosis = CASE WHEN ? <> '' THEN ? ELSE diagnosis END,
                recommendation = CASE WHEN ? <> '' THEN ? ELSE recommendation END
            WHERE id = ?
              AND provider_id = ?
              AND status <> 'completed'
        ")->execute([
            $diag, $diag,
            $recommendation, $recommendation,
            $data['consultation_id'], $data['provider_id'],
        ]);

        echo json_encode(['success' => true, 'message' => 'Clinical notes saved. Consultation remains in progress.']);
        exit;
    }

    $pdo->prepare("
        UPDATE consultations
        SET status = 'completed',
            diagnosis = CASE WHEN ? <> '' THEN ? ELSE diagnosis END,
            recommendation = CASE WHEN ? <> '' THEN ? ELSE recommendation END
        WHERE id = ? AND provider_id = ?
    ")->execute([
        $diag, $diag,
        $recommendation, $recommendation,
        $data['consultation_id'], $data['provider_id'],
    ]);

    // End any active video room for this consultation.
    try {
        $pdo->prepare("
            UPDATE video_sessions
            SET status = 'ended', ended_at = NOW()
            WHERE consultation_id = ?
              AND status = 'active'
        ")->execute([(int) $data['consultation_id']]);
    } catch (PDOException $e) { /* non-fatal */ }

    // Promote session Digital Prescription text into a real e-Rx row when present.
    $rxIssued = false;
    $rxText = trim((string) ($data['prescription'] ?? ''));
    if ($rxText !== '') {
        try {
            $tableOk = $pdo->query("SHOW TABLES LIKE 'prescriptions'");
            if ($tableOk && $tableOk->rowCount() > 0) {
                $firstLine = trim((string) strtok(str_replace(["\r\n", "\r"], "\n", $rxText), "\n"));
                $medication = $firstLine !== '' ? mb_substr($firstLine, 0, 180) : 'Digital prescription';
                $pdo->prepare("
                    INSERT INTO prescriptions
                        (consultation_id, patient_id, provider_id, medication_name, dosage, frequency, duration, notes, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ")->execute([
                    (int) $data['consultation_id'],
                    (int) $data['patient_id'],
                    (int) $data['provider_id'],
                    $medication,
                    'As directed',
                    'As directed',
                    'As directed',
                    $rxText,
                ]);
                $rxIssued = true;
            }
        } catch (PDOException $e) { /* non-fatal — notes already saved */ }
    }

    require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';
    NotificationEvents::consultationCompleted(
        $pdo,
        (int) $data['consultation_id'],
        (int) $data['patient_id'],
        (int) $data['provider_id'],
        (int) $data['provider_id']
    );
    NotificationEvents::medicalRecordUpdated($pdo, (int) $data['patient_id'], (int) $data['provider_id'], (int) $data['provider_id']);
    if ($rxIssued) {
        NotificationEvents::prescriptionAvailable(
            $pdo,
            (int) $data['patient_id'],
            (int) $data['provider_id'],
            (int) $data['provider_id']
        );
    }

    require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_patient_workflow.php';
    BhwPatientWorkflow::onConsultationCompleted($pdo, (int) $data['patient_id'], 'provider_notes');

    $msg = 'Clinical notes saved and consultation finalized.';
    if ($rxIssued) {
        $msg .= ' Prescription saved to the patient record.';
    }
    echo json_encode(['success' => true, 'message' => $msg]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save clinical notes.']);
}
