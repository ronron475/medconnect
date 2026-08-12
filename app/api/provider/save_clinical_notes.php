<?php
/**
 * API: Save SOAP Clinical Notes
 * URL: /app/api/provider/save_clinical_notes.php
 *
 * Draft (default): saves notes without ending the consultation.
 * Finalize (finalize=1): requires signature, completes consult in one transaction.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_patient_access.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/clinical_tables.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_consultation_records.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/clinical_note_signature.php';

clinical_tables_ensure($pdo);
patient_consultation_records_schema_ensure($pdo);
clinical_note_signature_schema_ensure($pdo);

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

$providerId = (int) $_SESSION['user_id'];
$consultationId = (int) ($_POST['consultation_id'] ?? 0);
$patientId = (int) ($_POST['patient_id'] ?? 0);

$data = [
    'consultation_id' => $consultationId,
    'patient_id'      => $patientId,
    'provider_id'     => $providerId,
    'subjective'      => $_POST['subjective']      ?? '',
    'objective'       => $_POST['objective']       ?? '',
    'assessment'      => $_POST['assessment']      ?? '',
    'plan'            => $_POST['plan']            ?? '',
    'diagnosis'       => $_POST['diagnosis']       ?? '',
    'treatment_plan'  => $_POST['treatment_plan']  ?? '',
    'prescription'    => $_POST['prescription']    ?? '',
    'signature'       => (string) ($_POST['signature_data'] ?? ''),
    'signature_method'=> strtolower(trim((string) ($_POST['signature_method'] ?? ''))),
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

$cStmt = $pdo->prepare('SELECT status, provider_name FROM consultations WHERE id = ? AND provider_id = ? LIMIT 1');
$cStmt->execute([(int) $data['consultation_id'], (int) $data['provider_id']]);
$consultRow = $cStmt->fetch(PDO::FETCH_ASSOC);
if (!$consultRow) {
    echo json_encode(['success' => false, 'message' => 'Consultation not found.']);
    exit;
}

$currentStatus = strtolower(trim((string) ($consultRow['status'] ?? '')));

$existingStmt = $pdo->prepare('SELECT * FROM clinical_notes WHERE consultation_id = ? LIMIT 1');
$existingStmt->execute([(int) $data['consultation_id']]);
$existingNote = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
if ($existingNote && (int) ($existingNote['provider_id'] ?? 0) !== (int) $data['provider_id']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You are not authorized to sign or finalize this SOAP note.']);
    exit;
}
$alreadyFinalized = trim((string) ($existingNote['finalized_at'] ?? '')) !== ''
    || trim((string) ($existingNote['signature_data'] ?? '')) !== '';

if ($alreadyFinalized) {
    echo json_encode(['success' => false, 'message' => 'This SOAP note has already been finalized and cannot be edited.']);
    exit;
}

if ($finalize) {
    foreach (['subjective', 'objective', 'assessment', 'plan'] as $soapField) {
        if (trim((string) ($data[$soapField] ?? '')) === '') {
            echo json_encode([
                'success' => false,
                'message' => 'Please complete all SOAP sections (Subjective, Objective, Assessment, and Plan) before finalizing.',
            ]);
            exit;
        }
    }

    $confirmed = in_array(strtolower(trim((string) ($_POST['soap_confirm'] ?? ''))), ['1', 'true', 'yes', 'on'], true);
    if (!$confirmed) {
        echo json_encode([
            'success' => false,
            'message' => 'Please confirm that you reviewed and completed this SOAP note.',
        ]);
        exit;
    }

    $method = $data['signature_method'];
    if ($method !== 'typed' && $method !== 'drawn') {
        echo json_encode([
            'success' => false,
            'message' => 'Please provide your electronic signature before finalizing the SOAP note.',
        ]);
        exit;
    }

    $identity = clinical_note_provider_identity($pdo, (int) $data['provider_id']);
    $signatureName = $identity['legal_name'] !== '' ? $identity['legal_name'] : $identity['full_name'];
    if ($signatureName === '') {
        echo json_encode(['success' => false, 'message' => 'Provider identity could not be verified.']);
        exit;
    }

    if ($method === 'typed') {
        $typed = trim((string) ($_POST['signature_name'] ?? $data['signature']));
        if ($typed === '' || !clinical_note_typed_name_matches($typed, $identity)) {
            echo json_encode([
                'success' => false,
                'message' => 'The typed name must match your authenticated provider account.',
            ]);
            exit;
        }
        $data['signature'] = $typed;
        $data['signature_name'] = $signatureName;
    } else {
        $drawn = clinical_note_drawn_signature_valid((string) $data['signature']);
        if (!$drawn['ok']) {
            echo json_encode([
                'success' => false,
                'message' => $drawn['message'],
            ]);
            exit;
        }
        $data['signature_name'] = $signatureName;
    }
}

try {
    if (!$finalize) {
        $stmt = $pdo->prepare("
            INSERT INTO clinical_notes
            (consultation_id, patient_id, provider_id, subjective, objective, assessment, plan, diagnosis, treatment_plan, prescription, signature_data)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '')
            ON DUPLICATE KEY UPDATE
                subjective = VALUES(subjective),
                objective = VALUES(objective),
                assessment = VALUES(assessment),
                plan = VALUES(plan),
                diagnosis = VALUES(diagnosis),
                treatment_plan = VALUES(treatment_plan),
                prescription = VALUES(prescription)
        ");
        $stmt->execute([
            $data['consultation_id'], $data['patient_id'], $data['provider_id'],
            $data['subjective'], $data['objective'], $data['assessment'], $data['plan'],
            $data['diagnosis'], $data['treatment_plan'], $data['prescription'],
        ]);

        echo json_encode([
            'success'          => true,
            'message'          => 'Draft SOAP saved. The patient cannot see this note until you finalize it.',
            'consultation_id'  => (int) $data['consultation_id'],
            'status'           => $currentStatus,
            'finalized'        => false,
        ]);
        exit;
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO clinical_notes
        (consultation_id, patient_id, provider_id, subjective, objective, assessment, plan, diagnosis, treatment_plan, prescription, signature_data, signature_method, signature_name, signed_at, finalized_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            subjective = VALUES(subjective),
            objective = VALUES(objective),
            assessment = VALUES(assessment),
            plan = VALUES(plan),
            diagnosis = VALUES(diagnosis),
            treatment_plan = VALUES(treatment_plan),
            prescription = VALUES(prescription),
            signature_data = VALUES(signature_data),
            signature_method = VALUES(signature_method),
            signature_name = VALUES(signature_name),
            signed_at = NOW(),
            finalized_at = NOW()
    ");
    $stmt->execute([
        $data['consultation_id'], $data['patient_id'], $data['provider_id'],
        $data['subjective'], $data['objective'], $data['assessment'], $data['plan'],
        $data['diagnosis'], $data['treatment_plan'], $data['prescription'], $data['signature'],
        $data['signature_method'], $data['signature_name'],
    ]);

    $diag = trim((string) ($data['diagnosis'] ?? ''));
    if ($diag === '') {
        $diag = trim((string) ($data['assessment'] ?? ''));
    }
    $recommendation = trim((string) ($data['treatment_plan'] ?? ''));
    if ($recommendation === '') {
        $recommendation = trim((string) ($data['plan'] ?? ''));
    }

    $pdo->prepare("
        UPDATE consultations
        SET status = 'completed',
            completed_at = NOW(),
            diagnosis = CASE WHEN ? <> '' THEN ? ELSE diagnosis END,
            recommendation = CASE WHEN ? <> '' THEN ? ELSE recommendation END
        WHERE id = ? AND provider_id = ?
    ")->execute([
        $diag, $diag,
        $recommendation, $recommendation,
        $data['consultation_id'], $data['provider_id'],
    ]);

    require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/appointment_slots.php';
    appointment_slot_set_consultation_status($pdo, (int) $data['consultation_id'], 'completed');

    $rxIssued = false;
    $rxText = trim((string) ($data['prescription'] ?? ''));
    if ($rxText !== '') {
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
    }

    $pdo->commit();

    $providerName = trim((string) ($consultRow['provider_name'] ?? ''));
    if ($providerName === '') {
        $pStmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) AS name FROM users WHERE id = ? LIMIT 1");
        $pStmt->execute([(int) $data['provider_id']]);
        $providerName = trim((string) ($pStmt->fetchColumn() ?: 'your healthcare provider'));
    }
    if (stripos($providerName, 'dr.') !== 0) {
        $providerName = 'Dr. ' . $providerName;
    }

    require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_clinical_support.php';
    $clinicalSupport = provider_consultation_clinical_support(
        $pdo,
        (int) $data['consultation_id'],
        (int) $data['patient_id']
    );
    if (!empty($clinicalSupport['available'])) {
        provider_clinical_support_save_event(
            $pdo,
            (int) $data['consultation_id'],
            (int) $data['provider_id'],
            (int) $data['patient_id'],
            'consultation_finalized',
            $clinicalSupport,
            'Consultation finalized with SOAP.',
            $providerName
        );
    }

    $finalCaseLevel = '';
    if (!empty($clinicalSupport['available'])) {
        $finalCaseLevel = patient_case_level_label((string) ($clinicalSupport['risk_bucket'] ?? ''));
    }

    require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';
    NotificationEvents::consultationCompleted(
        $pdo,
        (int) $data['consultation_id'],
        (int) $data['patient_id'],
        (int) $data['provider_id'],
        (int) $data['provider_id'],
        $providerName,
        $finalCaseLevel
    );
    if ($rxIssued) {
        NotificationEvents::prescriptionAvailable(
            $pdo,
            (int) $data['patient_id'],
            (int) $data['provider_id'],
            (int) $data['provider_id'],
            (int) $data['consultation_id']
        );
    }

    require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_patient_workflow.php';
    BhwPatientWorkflow::onConsultationCompleted($pdo, (int) $data['patient_id'], 'provider_notes');

    require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_booking_status.php';
    patient_triage_close_cases_for_consultation($pdo, (int) $data['consultation_id']);

    $msg = 'SOAP note finalized successfully. The patient can now view this record in My Health.';
    if ($rxIssued) {
        $msg .= ' Prescription saved to the patient record.';
    }
    echo json_encode([
        'success'         => true,
        'message'         => $msg,
        'consultation_id' => (int) $data['consultation_id'],
        'status'          => 'completed',
        'finalized'       => true,
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save clinical notes.']);
}
