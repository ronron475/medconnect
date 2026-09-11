<?php
/**
 * Patient/BHW-recorded clinical data for a consultation (vitals + complaint snapshot).
 * Kept separate from doctor SOAP / clinical assessment.
 */

function consultation_recorded_data_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS consultation_recorded_data (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            patient_id INT UNSIGNED NOT NULL,
            consultation_id INT UNSIGNED NULL,
            triage_result_id BIGINT UNSIGNED NULL,
            recorded_by INT UNSIGNED NOT NULL,
            recorder_role ENUM('patient','bhw') NOT NULL,
            chief_complaint TEXT NULL,
            symptoms TEXT NULL,
            temperature_c DECIMAL(4,1) NULL,
            blood_pressure VARCHAR(32) NULL,
            pulse_bpm SMALLINT UNSIGNED NULL,
            respiratory_rate SMALLINT UNSIGNED NULL,
            spo2_percent DECIMAL(5,2) NULL,
            weight_kg DECIMAL(6,2) NULL,
            height_cm DECIMAL(6,2) NULL,
            notes TEXT NULL,
            status ENUM('pending','attached') NOT NULL DEFAULT 'pending',
            origin ENUM('consultation','pre_consultation') NOT NULL DEFAULT 'pre_consultation',
            attached_at DATETIME NULL,
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_crd_consultation (consultation_id),
            INDEX idx_crd_patient (patient_id),
            INDEX idx_crd_patient_status (patient_id, status),
            INDEX idx_crd_recorded_at (recorded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM consultation_recorded_data')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $byName = [];
        foreach ($cols as $col) {
            $byName[(string) ($col['Field'] ?? '')] = $col;
        }

        if (isset($byName['consultation_id']) && strtoupper((string) ($byName['consultation_id']['Null'] ?? '')) === 'NO') {
            $pdo->exec('ALTER TABLE consultation_recorded_data MODIFY consultation_id INT UNSIGNED NULL');
        }
        if (!isset($byName['status'])) {
            $pdo->exec("ALTER TABLE consultation_recorded_data ADD COLUMN status ENUM('pending','attached') NOT NULL DEFAULT 'pending' AFTER notes");
            $pdo->exec("UPDATE consultation_recorded_data SET status = 'attached' WHERE consultation_id IS NOT NULL");
        }
        if (!isset($byName['origin'])) {
            $pdo->exec("ALTER TABLE consultation_recorded_data ADD COLUMN origin ENUM('consultation','pre_consultation') NOT NULL DEFAULT 'pre_consultation' AFTER status");
            $pdo->exec("UPDATE consultation_recorded_data SET origin = 'consultation' WHERE consultation_id IS NOT NULL");
        }
        if (!isset($byName['attached_at'])) {
            $pdo->exec('ALTER TABLE consultation_recorded_data ADD COLUMN attached_at DATETIME NULL AFTER origin');
            $pdo->exec('UPDATE consultation_recorded_data SET attached_at = COALESCE(recorded_at, NOW()) WHERE consultation_id IS NOT NULL AND attached_at IS NULL');
        }
    } catch (Throwable $e) {
        error_log('consultation_recorded_data schema migrate: ' . $e->getMessage());
    }

    $ready = true;
}

/**
 * Statuses that still accept patient/BHW recorded updates for a visit.
 *
 * @return list<string>
 */
function consultation_recorded_data_open_statuses(): array
{
    return [
        'scheduled',
        'pending',
        'confirmed',
        'waiting',
        'in_consultation',
        'in_progress',
        'in-progress',
        'active',
        'ongoing',
    ];
}

function consultation_recorded_data_status_is_open(string $status): bool
{
    return in_array(strtolower(trim($status)), consultation_recorded_data_open_statuses(), true);
}

/**
 * @param array<string, mixed> $input
 * @return array{success: bool, message?: string, id?: int, errors?: array<string, string>, mode?: string, status?: string}
 */
function consultation_recorded_data_save(
    PDO $pdo,
    int $patientId,
    int $consultationId,
    int $recordedBy,
    string $recorderRole,
    array $input,
    ?int $triageResultId = null,
    bool $requireOpenConsultation = true
): array {
    consultation_recorded_data_ensure_schema($pdo);

    $role = strtolower(trim($recorderRole));
    if (!in_array($role, ['patient', 'bhw'], true)) {
        return ['success' => false, 'message' => 'Invalid recorder role.'];
    }

    if ($patientId <= 0 || $recordedBy <= 0) {
        return ['success' => false, 'message' => 'Patient and recorder are required.'];
    }

    $parsed = consultation_recorded_data_parse_input($input);
    if (!$parsed['ok']) {
        return ['success' => false, 'message' => $parsed['message'], 'errors' => $parsed['errors']];
    }
    $n = $parsed['data'];

    if (!consultation_recorded_data_has_content($n)) {
        return ['success' => false, 'message' => 'Enter at least one vital sign, complaint, or observation.'];
    }

    // CASE 2 — no consultation yet: save as pending pre-consultation data.
    if ($consultationId <= 0) {
        $stmt = $pdo->prepare("
            INSERT INTO consultation_recorded_data (
                patient_id, consultation_id, triage_result_id, recorded_by, recorder_role,
                chief_complaint, symptoms, temperature_c, blood_pressure, pulse_bpm,
                respiratory_rate, spo2_percent, weight_kg, height_cm, notes,
                status, origin, attached_at, recorded_at
            ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pre_consultation', NULL, NOW())
        ");
        $stmt->execute([
            $patientId,
            $triageResultId && $triageResultId > 0 ? $triageResultId : null,
            $recordedBy,
            $role,
            $n['chief_complaint'],
            $n['symptoms'],
            $n['temperature_c'],
            $n['blood_pressure'],
            $n['pulse_bpm'],
            $n['respiratory_rate'],
            $n['spo2_percent'],
            $n['weight_kg'],
            $n['height_cm'],
            $n['notes'],
        ]);

        return [
            'success' => true,
            'message' => 'Saved as pre-consultation data. It will be linked when a consultation is booked.',
            'id'      => (int) $pdo->lastInsertId(),
            'mode'    => 'pre_consultation',
            'status'  => 'pending',
        ];
    }

    // CASE 1 — bind to this consultation + patient (never patient_id alone for doctor visibility).
    $consult = $pdo->prepare('SELECT id, patient_id, provider_id, status, triage_result_id FROM consultations WHERE id = ? AND patient_id = ? LIMIT 1');
    $consult->execute([$consultationId, $patientId]);
    $cRow = $consult->fetch(PDO::FETCH_ASSOC);
    if (!$cRow) {
        return ['success' => false, 'message' => 'Consultation does not belong to this patient.'];
    }

    if ($requireOpenConsultation && !consultation_recorded_data_status_is_open((string) ($cRow['status'] ?? ''))) {
        return ['success' => false, 'message' => 'This consultation is closed. Recorded data must be saved on an open consultation only.'];
    }

    if ($triageResultId === null || $triageResultId <= 0) {
        $triageResultId = !empty($cRow['triage_result_id']) ? (int) $cRow['triage_result_id'] : null;
    }

    $stmt = $pdo->prepare("
        INSERT INTO consultation_recorded_data (
            patient_id, consultation_id, triage_result_id, recorded_by, recorder_role,
            chief_complaint, symptoms, temperature_c, blood_pressure, pulse_bpm,
            respiratory_rate, spo2_percent, weight_kg, height_cm, notes,
            status, origin, attached_at, recorded_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'attached', 'consultation', NOW(), NOW())
    ");
    $stmt->execute([
        $patientId,
        $consultationId,
        $triageResultId,
        $recordedBy,
        $role,
        $n['chief_complaint'],
        $n['symptoms'],
        $n['temperature_c'],
        $n['blood_pressure'],
        $n['pulse_bpm'],
        $n['respiratory_rate'],
        $n['spo2_percent'],
        $n['weight_kg'],
        $n['height_cm'],
        $n['notes'],
    ]);

    return [
        'success' => true,
        'message' => 'Recorded data saved for this consultation.',
        'id'      => (int) $pdo->lastInsertId(),
        'mode'    => 'consultation',
        'status'  => 'attached',
    ];
}

/**
 * Attach pending pre-consultation rows to a newly created consultation.
 * Only rows recorded at/before the consultation was created are linked (once).
 * Preserves original recorded_at / recorder / values.
 *
 * @return array{attached: int, ids: list<int>}
 */
function consultation_recorded_data_attach_pending_to_consultation(
    PDO $pdo,
    int $patientId,
    int $consultationId
): array {
    consultation_recorded_data_ensure_schema($pdo);

    $out = ['attached' => 0, 'ids' => []];
    if ($patientId <= 0 || $consultationId <= 0) {
        return $out;
    }

    $consult = $pdo->prepare('SELECT id, patient_id, created_at FROM consultations WHERE id = ? AND patient_id = ? LIMIT 1');
    $consult->execute([$consultationId, $patientId]);
    $cRow = $consult->fetch(PDO::FETCH_ASSOC);
    if (!$cRow) {
        return $out;
    }

    $createdAt = (string) ($cRow['created_at'] ?? '');
    if ($createdAt === '') {
        $createdAt = date('Y-m-d H:i:s');
    }

    // Link only unused pending rows for this patient that predate (or equal) consult creation.
    $select = $pdo->prepare("
        SELECT id
        FROM consultation_recorded_data
        WHERE patient_id = ?
          AND status = 'pending'
          AND consultation_id IS NULL
          AND recorded_at <= ?
        ORDER BY recorded_at ASC, id ASC
    ");
    $select->execute([$patientId, $createdAt]);
    $ids = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($ids === []) {
        return $out;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$consultationId], $ids);
    $upd = $pdo->prepare("
        UPDATE consultation_recorded_data
        SET consultation_id = ?,
            status = 'attached',
            attached_at = NOW()
        WHERE id IN ({$placeholders})
          AND status = 'pending'
          AND consultation_id IS NULL
    ");
    $upd->execute($params);

    $out['attached'] = $upd->rowCount();
    $out['ids'] = $ids;

    return $out;
}

/**
 * Snapshot triage complaint/symptoms onto a consultation (patient or BHW booking).
 * Skips if an identical latest snapshot already exists for this consultation.
 */
function consultation_recorded_data_snapshot_from_triage(
    PDO $pdo,
    int $patientId,
    int $consultationId,
    int $triageResultId,
    int $recordedBy,
    string $recorderRole
): void {
    consultation_recorded_data_ensure_schema($pdo);

    $stmt = $pdo->prepare('SELECT chief_complaint, symptoms, detected_symptoms_json FROM triage_results WHERE id = ? AND patient_id = ? LIMIT 1');
    $stmt->execute([$triageResultId, $patientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }

    $complaint = trim((string) ($row['chief_complaint'] ?? ''));
    $symptoms = consultation_recorded_data_normalize_symptoms_blob(
        (string) ($row['symptoms'] ?? ''),
        (string) ($row['detected_symptoms_json'] ?? '')
    );

    if ($complaint === '' && $symptoms === '') {
        return;
    }

    $latest = consultation_recorded_data_latest($pdo, $consultationId, $patientId);
    if ($latest
        && trim((string) ($latest['chief_complaint'] ?? '')) === $complaint
        && trim((string) ($latest['symptoms'] ?? '')) === $symptoms
        && empty($latest['temperature_c'])
        && empty($latest['blood_pressure'])
        && empty($latest['pulse_bpm'])
    ) {
        return;
    }

    consultation_recorded_data_save($pdo, $patientId, $consultationId, $recordedBy, $recorderRole, [
        'chief_complaint' => $complaint,
        'symptoms'        => $symptoms,
    ], $triageResultId);
}

/**
 * Latest pending pre-consultation row for a patient (not yet linked to any consult).
 *
 * @return array<string, mixed>|null
 */
function consultation_recorded_data_pending_latest(PDO $pdo, int $patientId): ?array
{
    consultation_recorded_data_ensure_schema($pdo);
    if ($patientId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT d.*,
               TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS recorder_name
        FROM consultation_recorded_data d
        LEFT JOIN users u ON u.id = d.recorded_by
        WHERE d.patient_id = ?
          AND d.status = 'pending'
          AND d.consultation_id IS NULL
        ORDER BY d.recorded_at DESC, d.id DESC
        LIMIT 1
    ");
    $stmt->execute([$patientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Full patient recorded-data history (pending + attached) for My Health / BHW activity.
 * Append-only rows; never mixes patients.
 *
 * @return list<array<string, mixed>>
 */
function consultation_recorded_data_history_for_patient(PDO $pdo, int $patientId, int $limit = 40): array
{
    consultation_recorded_data_ensure_schema($pdo);
    if ($patientId <= 0) {
        return [];
    }
    $limit = max(1, min(80, $limit));

    $stmt = $pdo->prepare("
        SELECT d.*,
               TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS recorder_name
        FROM consultation_recorded_data d
        LEFT JOIN users u ON u.id = d.recorded_by
        WHERE d.patient_id = ?
        ORDER BY d.recorded_at DESC, d.id DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$patientId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Pending history for BHW UI (newest first).
 *
 * @return list<array<string, mixed>>
 */
function consultation_recorded_data_pending_history(PDO $pdo, int $patientId, int $limit = 8): array
{
    consultation_recorded_data_ensure_schema($pdo);
    if ($patientId <= 0) {
        return [];
    }
    $limit = max(1, min(50, $limit));

    $stmt = $pdo->prepare("
        SELECT d.*,
               TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS recorder_name
        FROM consultation_recorded_data d
        LEFT JOIN users u ON u.id = d.recorded_by
        WHERE d.patient_id = ?
          AND d.status = 'pending'
          AND d.consultation_id IS NULL
        ORDER BY d.recorded_at DESC, d.id DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$patientId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * DTO for pending or consult-scoped recorded data (shared shape for BHW/doctor panels).
 *
 * @param array<string, mixed>|null $latest
 * @param list<array<string, mixed>> $history
 * @return array<string, mixed>
 */
function consultation_recorded_data_dto_from_rows(
    ?array $latest,
    array $history,
    int $consultationId,
    int $patientId,
    string $source
): array {
    $empty = [
        'available'            => false,
        'consultation_id'      => $consultationId,
        'patient_id'           => $patientId,
        'recorded_by_label'    => '',
        'recorder_role'        => '',
        'recorder_role_label'  => '',
        'patient_barangay'     => '',
        'recorded_at'          => null,
        'recorded_at_label'    => '',
        'fields'               => [],
        'history_count'        => 0,
        'source'               => 'none',
        'status'               => '',
        'origin'               => '',
        'status_label'         => '',
        'panel_title'          => 'BHW/Patient Pre-Consultation Information',
    ];

    if (!$latest) {
        return $empty;
    }

    $fields = consultation_recorded_data_field_list($latest);
    if ($fields === []) {
        return $empty;
    }

    $role = strtolower((string) ($latest['recorder_role'] ?? 'patient'));
    $roleLabel = $role === 'bhw' ? 'Barangay Health Worker (BHW)' : 'Patient';
    $name = trim((string) ($latest['recorder_name'] ?? ''));
    $byLabel = $name !== '' ? $name : $roleLabel;
    $status = strtolower((string) ($latest['status'] ?? ''));
    $origin = strtolower((string) ($latest['origin'] ?? ''));
    $isPre = ($status === 'pending') || ($origin === 'pre_consultation');
    $barangay = trim((string) ($latest['patient_barangay'] ?? ''));

    return [
        'available'           => true,
        'consultation_id'     => $consultationId,
        'patient_id'          => $patientId,
        'recorded_by_label'   => $byLabel,
        'recorder_role'       => $role,
        'recorder_role_label' => $roleLabel,
        'patient_barangay'    => $barangay,
        'recorded_at'         => $latest['recorded_at'] ?? null,
        'recorded_at_label'   => consultation_recorded_data_format_datetime($latest['recorded_at'] ?? null),
        'fields'              => $fields,
        'history_count'       => count($history),
        'source'              => $source,
        'status'              => $status !== '' ? $status : ($consultationId > 0 ? 'attached' : 'pending'),
        'origin'              => $origin !== '' ? $origin : ($consultationId > 0 ? 'consultation' : 'pre_consultation'),
        'status_label'        => $status === 'pending' ? 'Pre-Consultation' : ($isPre ? 'Linked from pre-consultation' : 'Consultation'),
        'panel_title'         => 'BHW/Patient Pre-Consultation Information',
    ];
}

/**
 * BHW helper: pending DTO for a patient with no open consult selected.
 *
 * @return array<string, mixed>
 */
function consultation_recorded_data_pending_for_bhw(PDO $pdo, int $patientId): array
{
    $history = consultation_recorded_data_pending_history($pdo, $patientId);
    $latest = $history[0] ?? null;
    if (is_array($latest)) {
        $latest = consultation_recorded_data_attach_patient_barangay($pdo, $patientId, $latest);
    }

    return consultation_recorded_data_dto_from_rows($latest, $history, 0, $patientId, $latest ? 'pending_table' : 'none');
}

/**
 * Latest recorded row for this consultation only (never another consult).
 *
 * @return array<string, mixed>|null
 */
function consultation_recorded_data_latest(PDO $pdo, int $consultationId, int $patientId): ?array
{
    consultation_recorded_data_ensure_schema($pdo);

    $stmt = $pdo->prepare("
        SELECT d.*,
               TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS recorder_name
        FROM consultation_recorded_data d
        LEFT JOIN users u ON u.id = d.recorded_by
        WHERE d.consultation_id = ?
          AND d.patient_id = ?
        ORDER BY d.recorded_at DESC, d.id DESC
        LIMIT 1
    ");
    $stmt->execute([$consultationId, $patientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * History for one consultation (newest first).
 *
 * @return list<array<string, mixed>>
 */
function consultation_recorded_data_history(PDO $pdo, int $consultationId, int $patientId, int $limit = 20): array
{
    consultation_recorded_data_ensure_schema($pdo);
    $limit = max(1, min(50, $limit));

    $stmt = $pdo->prepare("
        SELECT d.*,
               TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS recorder_name
        FROM consultation_recorded_data d
        LEFT JOIN users u ON u.id = d.recorded_by
        WHERE d.consultation_id = ?
          AND d.patient_id = ?
        ORDER BY d.recorded_at DESC, d.id DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$consultationId, $patientId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Load recorded data for the assigned doctor only (IDOR guard on consultations.provider_id).
 *
 * @return array{allowed: bool, message: string, recorded?: array<string, mixed>, history?: list<array<string, mixed>>}
 */
function consultation_recorded_data_for_authorized_provider(
    PDO $pdo,
    int $providerId,
    int $consultationId,
    int $patientId = 0,
    int $historyLimit = 8
): array {
    if ($providerId <= 0 || $consultationId <= 0) {
        return ['allowed' => false, 'message' => 'Access denied.'];
    }

    $stmt = $pdo->prepare('SELECT id, patient_id, provider_id FROM consultations WHERE id = ? AND provider_id = ? LIMIT 1');
    $stmt->execute([$consultationId, $providerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['allowed' => false, 'message' => 'Access denied.'];
    }

    $consultPatientId = (int) ($row['patient_id'] ?? 0);
    if ($patientId > 0 && $consultPatientId !== $patientId) {
        return ['allowed' => false, 'message' => 'Access denied.'];
    }
    $patientId = $consultPatientId;

    $recorded = consultation_recorded_data_for_doctor($pdo, $consultationId, $patientId);
    $history = !empty($recorded['available'])
        ? consultation_recorded_data_history($pdo, $consultationId, $patientId, $historyLimit)
        : [];

    return [
        'allowed'  => true,
        'message'  => 'ok',
        'recorded' => $recorded,
        'history'  => $history,
    ];
}

/**
 * Doctor-facing DTO: latest values + provenance for THIS consultation only.
 * Falls back only to triage linked via consultations.triage_result_id (never another visit).
 *
 * @return array{
 *   available: bool,
 *   consultation_id: int,
 *   patient_id: int,
 *   recorded_by_label: string,
 *   recorder_role: string,
 *   recorder_role_label: string,
 *   recorded_at: ?string,
 *   recorded_at_label: string,
 *   fields: list<array{label: string, value: string, key: string}>,
 *   history_count: int,
 *   source: string
 * }
 */
function consultation_recorded_data_for_doctor(PDO $pdo, int $consultationId, int $patientId): array
{
    consultation_recorded_data_ensure_schema($pdo);

    if ($consultationId <= 0 || $patientId <= 0) {
        return consultation_recorded_data_dto_from_rows(null, [], $consultationId, $patientId, 'none');
    }

    $history = consultation_recorded_data_history($pdo, $consultationId, $patientId);
    $latest = $history[0] ?? null;
    $source = 'recorded_table';

    if (!$latest) {
        $latest = consultation_recorded_data_synthesize_from_consult($pdo, $consultationId, $patientId);
        if (!$latest) {
            return consultation_recorded_data_dto_from_rows(null, [], $consultationId, $patientId, 'none');
        }
        $history = [];
        $source = 'triage_fallback';
        $latest['status'] = 'attached';
        $latest['origin'] = 'consultation';
    }

    $latest = consultation_recorded_data_attach_patient_barangay($pdo, $patientId, $latest);

    return consultation_recorded_data_dto_from_rows($latest, $history, $consultationId, $patientId, $source);
}

/**
 * Attach the patient's registered barangay label for display attribution.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function consultation_recorded_data_attach_patient_barangay(PDO $pdo, int $patientId, array $row): array
{
    if ($patientId <= 0) {
        return $row;
    }
    try {
        require_once __DIR__ . '/bhw_scope.php';
        $row['patient_barangay'] = bhw_patient_registered_barangay($pdo, $patientId);
    } catch (Throwable $e) {
        $row['patient_barangay'] = (string) ($row['patient_barangay'] ?? '');
    }

    return $row;
}

/**
 * Open consultations BHW can attach recorded data to.
 *
 * @return list<array<string, mixed>>
 */
function consultation_recorded_data_open_consults_for_patient(PDO $pdo, int $patientId): array
{
    $placeholders = implode(',', array_fill(0, count(consultation_recorded_data_open_statuses()), '?'));
    $params = array_merge([$patientId], consultation_recorded_data_open_statuses());
    $stmt = $pdo->prepare("
        SELECT c.id, c.status, c.consult_date, c.consult_time, c.provider_id, c.provider_name, c.triage_result_id
        FROM consultations c
        WHERE c.patient_id = ?
          AND LOWER(COALESCE(c.status, '')) IN ({$placeholders})
        ORDER BY c.consult_date DESC, c.consult_time DESC, c.id DESC
        LIMIT 10
    ");
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Verify consultation belongs to patient (used by BHW/patient APIs before read/write).
 */
function consultation_recorded_data_assert_patient_consultation(PDO $pdo, int $patientId, int $consultationId): bool
{
    if ($patientId <= 0 || $consultationId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT id FROM consultations WHERE id = ? AND patient_id = ? LIMIT 1');
    $stmt->execute([$consultationId, $patientId]);

    return (bool) $stmt->fetchColumn();
}

/**
 * @param array<string, mixed> $input
 * @return array{ok: bool, message?: string, errors?: array<string, string>, data?: array<string, mixed>}
 */
function consultation_recorded_data_parse_input(array $input): array
{
    $errors = [];
    $complaint = trim((string) ($input['chief_complaint'] ?? ''));
    $symptoms = trim((string) ($input['symptoms'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    $bp = trim((string) ($input['blood_pressure'] ?? $input['bp'] ?? ''));

    $temp = consultation_recorded_data_nullable_float($input['temperature_c'] ?? $input['temperature'] ?? null);
    $pulse = consultation_recorded_data_nullable_int($input['pulse_bpm'] ?? $input['pulse'] ?? null);
    $rr = consultation_recorded_data_nullable_int($input['respiratory_rate'] ?? $input['rr'] ?? null);
    $spo2 = consultation_recorded_data_nullable_float($input['spo2_percent'] ?? $input['spo2'] ?? null);
    $weight = consultation_recorded_data_nullable_float($input['weight_kg'] ?? $input['weight'] ?? null);
    $height = consultation_recorded_data_nullable_float($input['height_cm'] ?? $input['height'] ?? null);

    if ($bp !== '' && !preg_match('/^\d{2,3}\s*\/\s*\d{2,3}$/', $bp)) {
        $errors['blood_pressure'] = 'Use BP format like 120/80.';
    } elseif ($bp !== '') {
        $bp = preg_replace('/\s+/', '', $bp) ?? $bp;
    }

    if ($temp !== null && ($temp < 30 || $temp > 45)) {
        $errors['temperature_c'] = 'Temperature must be between 30 and 45 °C.';
    }
    if ($pulse !== null && ($pulse < 20 || $pulse > 250)) {
        $errors['pulse_bpm'] = 'Pulse must be between 20 and 250.';
    }
    if ($rr !== null && ($rr < 5 || $rr > 80)) {
        $errors['respiratory_rate'] = 'Respiratory rate must be between 5 and 80.';
    }
    if ($spo2 !== null && ($spo2 < 50 || $spo2 > 100)) {
        $errors['spo2_percent'] = 'SpO2 must be between 50 and 100.';
    }
    if ($weight !== null && ($weight < 1 || $weight > 400)) {
        $errors['weight_kg'] = 'Weight looks invalid.';
    }
    if ($height !== null && ($height < 30 || $height > 250)) {
        $errors['height_cm'] = 'Height looks invalid.';
    }

    if ($errors !== []) {
        return ['ok' => false, 'message' => reset($errors), 'errors' => $errors];
    }

    return [
        'ok'   => true,
        'data' => [
            'chief_complaint'   => $complaint !== '' ? $complaint : null,
            'symptoms'          => $symptoms !== '' ? $symptoms : null,
            'temperature_c'     => $temp,
            'blood_pressure'    => $bp !== '' ? $bp : null,
            'pulse_bpm'         => $pulse,
            'respiratory_rate'  => $rr,
            'spo2_percent'      => $spo2,
            'weight_kg'         => $weight,
            'height_cm'         => $height,
            'notes'             => $notes !== '' ? $notes : null,
        ],
    ];
}

/**
 * @param array<string, mixed> $n
 */
function consultation_recorded_data_has_content(array $n): bool
{
    foreach (['chief_complaint', 'symptoms', 'temperature_c', 'blood_pressure', 'pulse_bpm', 'respiratory_rate', 'spo2_percent', 'weight_kg', 'height_cm', 'notes'] as $key) {
        if ($n[$key] !== null && $n[$key] !== '') {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $row
 * @return list<array{label: string, value: string, key: string}>
 */
function consultation_recorded_data_field_list(array $row): array
{
    $out = [];
    $map = [
        'chief_complaint'  => 'Chief Complaint',
        'symptoms'         => 'Symptoms',
        'temperature_c'    => 'Temperature',
        'blood_pressure'   => 'Blood Pressure',
        'pulse_bpm'        => 'Pulse Rate',
        'respiratory_rate' => 'Respiratory Rate',
        'spo2_percent'     => 'SpO2',
        'weight_kg'        => 'Weight',
        'height_cm'        => 'Height',
        'notes'            => 'Other observations',
    ];

    foreach ($map as $key => $label) {
        if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
            continue;
        }
        $raw = $row[$key];
        $value = (string) $raw;
        if ($key === 'temperature_c') {
            $value = rtrim(rtrim(number_format((float) $raw, 1, '.', ''), '0'), '.') . '°C';
        } elseif ($key === 'pulse_bpm') {
            $value = (string) ((int) $raw) . ' bpm';
        } elseif ($key === 'respiratory_rate') {
            $value = (string) ((int) $raw) . ' /min';
        } elseif ($key === 'spo2_percent') {
            $value = rtrim(rtrim(number_format((float) $raw, 1, '.', ''), '0'), '.') . '%';
        } elseif ($key === 'weight_kg') {
            $value = rtrim(rtrim(number_format((float) $raw, 1, '.', ''), '0'), '.') . ' kg';
        } elseif ($key === 'height_cm') {
            $value = rtrim(rtrim(number_format((float) $raw, 1, '.', ''), '0'), '.') . ' cm';
        }
        $out[] = ['label' => $label, 'value' => $value, 'key' => $key];
    }

    return $out;
}

/**
 * @return array<string, mixed>|null
 */
function consultation_recorded_data_synthesize_from_consult(PDO $pdo, int $consultationId, int $patientId): ?array
{
    try {
        require_once __DIR__ . '/bhw_clinical.php';
        bhw_clinical_ensure_schema($pdo);
    } catch (Throwable $e) {
        /* schema helpers optional */
    }

    $hasBookedBy = false;
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $hasBookedBy = in_array('booked_by_bhw_id', $cols, true);
    } catch (Throwable $e) {
        $hasBookedBy = false;
    }

    $sql = "
        SELECT c.triage_result_id, c.created_at,
               tr.chief_complaint, tr.symptoms, tr.detected_symptoms_json, tr.assessed_at,
               TRIM(CONCAT(COALESCE(pb.first_name, ''), ' ', COALESCE(pb.last_name, ''))) AS patient_name
               " . ($hasBookedBy ? ", c.booked_by_bhw_id,
               TRIM(CONCAT(COALESCE(bb.first_name, ''), ' ', COALESCE(bb.last_name, ''))) AS bhw_name" : ", NULL AS booked_by_bhw_id, '' AS bhw_name") . "
        FROM consultations c
        LEFT JOIN triage_results tr ON tr.id = c.triage_result_id AND tr.patient_id = c.patient_id
        LEFT JOIN users pb ON pb.id = c.patient_id
        " . ($hasBookedBy ? "LEFT JOIN users bb ON bb.id = c.booked_by_bhw_id" : "") . "
        WHERE c.id = ? AND c.patient_id = ?
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$consultationId, $patientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $complaint = trim((string) ($row['chief_complaint'] ?? ''));
    $symptoms = consultation_recorded_data_normalize_symptoms_blob(
        (string) ($row['symptoms'] ?? ''),
        (string) ($row['detected_symptoms_json'] ?? '')
    );
    if ($complaint === '' && $symptoms === '') {
        return null;
    }

    $isBhw = !empty($row['booked_by_bhw_id']);

    return [
        'chief_complaint'  => $complaint !== '' ? $complaint : null,
        'symptoms'         => $symptoms !== '' ? $symptoms : null,
        'temperature_c'    => null,
        'blood_pressure'   => null,
        'pulse_bpm'        => null,
        'respiratory_rate' => null,
        'spo2_percent'     => null,
        'weight_kg'        => null,
        'height_cm'        => null,
        'notes'            => null,
        'recorder_role'    => $isBhw ? 'bhw' : 'patient',
        'recorder_name'    => $isBhw
            ? trim((string) ($row['bhw_name'] ?? ''))
            : trim((string) ($row['patient_name'] ?? '')),
        'recorded_at'      => $row['assessed_at'] ?? $row['created_at'] ?? null,
    ];
}

function consultation_recorded_data_normalize_symptoms_blob(string $symptoms, string $detectedJson): string
{
    $symptoms = trim($symptoms);
    if ($symptoms !== '') {
        $decoded = json_decode($symptoms, true);
        if (is_array($decoded)) {
            $parts = [];
            foreach ($decoded as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $parts[] = trim($item);
                } elseif (is_array($item) && !empty($item['label'])) {
                    $parts[] = trim((string) $item['label']);
                }
            }
            if ($parts !== []) {
                return implode(', ', array_values(array_unique($parts)));
            }
        }

        return $symptoms;
    }

    $decoded = json_decode($detectedJson, true);
    if (!is_array($decoded)) {
        return '';
    }
    $parts = [];
    foreach ($decoded as $item) {
        if (is_string($item) && trim($item) !== '') {
            $parts[] = trim($item);
        } elseif (is_array($item) && !empty($item['label'])) {
            $parts[] = trim((string) $item['label']);
        }
    }

    return implode(', ', array_values(array_unique($parts)));
}

function consultation_recorded_data_nullable_float(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }

    return (float) $value;
}

function consultation_recorded_data_nullable_int(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }

    return (int) round((float) $value);
}

function consultation_recorded_data_format_datetime(mixed $value): string
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        return '';
    }
    try {
        $dt = new DateTimeImmutable($raw);

        return $dt->format('F j, Y, g:i A');
    } catch (Throwable $e) {
        return $raw;
    }
}
