<?php
/**
 * External Healthcare Visits — patient medical HISTORY (not MedConnect consultations).
 * Recorded by BHW without requiring an open consultation.
 */

function patient_external_healthcare_visits_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS patient_external_healthcare_visits (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            patient_id INT UNSIGNED NOT NULL,
            recorded_by INT UNSIGNED NOT NULL,
            recorder_role ENUM('bhw','patient','provider') NOT NULL DEFAULT 'bhw',
            visit_date DATE NOT NULL,
            facility_type ENUM(
                'hospital',
                'private_clinic',
                'health_center',
                'government_health_facility',
                'emergency_facility',
                'other'
            ) NOT NULL,
            facility_name VARCHAR(200) NOT NULL,
            reason TEXT NULL,
            reported_diagnosis TEXT NULL,
            reported_treatment TEXT NULL,
            notes TEXT NULL,
            information_source ENUM(
                'patient_reported',
                'bhw_recorded',
                'medical_document',
                'other'
            ) NOT NULL DEFAULT 'patient_reported',
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            updated_by INT UNSIGNED NULL,
            INDEX idx_pehv_patient (patient_id),
            INDEX idx_pehv_visit_date (visit_date),
            INDEX idx_pehv_recorded_at (recorded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $ready = true;
}

/**
 * @return array<string, string>
 */
function patient_external_healthcare_facility_types(): array
{
    return [
        'hospital'                   => 'Hospital',
        'private_clinic'             => 'Private Clinic',
        'health_center'              => 'Health Center',
        'government_health_facility' => 'Government Health Facility',
        'emergency_facility'         => 'Emergency Facility',
        'other'                      => 'Other',
    ];
}

/**
 * @return array<string, string>
 */
function patient_external_healthcare_info_sources(): array
{
    return [
        'patient_reported'  => 'Patient Reported',
        'bhw_recorded'      => 'BHW Recorded',
        'medical_document'  => 'Medical Document Provided',
        'other'             => 'Other',
    ];
}

function patient_external_healthcare_facility_type_label(string $type): string
{
    $map = patient_external_healthcare_facility_types();

    return $map[$type] ?? ucwords(str_replace('_', ' ', $type));
}

function patient_external_healthcare_source_label(string $source): string
{
    $map = patient_external_healthcare_info_sources();
    if ($source === 'patient_reported' || $source === 'bhw_recorded') {
        return 'Patient/BHW Reported';
    }

    return $map[$source] ?? ucwords(str_replace('_', ' ', $source));
}

/**
 * @param array<string, mixed> $input
 * @return array{ok: bool, message?: string, errors?: array<string, string>, data?: array<string, mixed>}
 */
function patient_external_healthcare_visits_parse(array $input): array
{
    $errors = [];
    $facilityType = strtolower(trim((string) ($input['facility_type'] ?? '')));
    $facilityName = trim((string) ($input['facility_name'] ?? ''));
    $visitDate = trim((string) ($input['visit_date'] ?? ''));
    $reason = trim((string) ($input['reason'] ?? ''));
    $diagnosis = trim((string) ($input['reported_diagnosis'] ?? $input['diagnosis'] ?? ''));
    $treatment = trim((string) ($input['reported_treatment'] ?? $input['treatment'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    $source = strtolower(trim((string) ($input['information_source'] ?? $input['source'] ?? 'patient_reported')));

    if (!isset(patient_external_healthcare_facility_types()[$facilityType])) {
        $errors['facility_type'] = 'Select a valid facility type.';
    }
    if ($facilityName === '' || mb_strlen($facilityName) > 200) {
        $errors['facility_name'] = 'Facility name is required (max 200 characters).';
    }
    if ($visitDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate)) {
        $errors['visit_date'] = 'Visit date is required (YYYY-MM-DD).';
    } else {
        $ts = strtotime($visitDate);
        if ($ts === false) {
            $errors['visit_date'] = 'Visit date is invalid.';
        } elseif ($ts > strtotime('today')) {
            $errors['visit_date'] = 'Visit date cannot be in the future.';
        }
    }
    if (!isset(patient_external_healthcare_info_sources()[$source])) {
        $errors['information_source'] = 'Select a valid information source.';
    }
    if ($reason === '' && $diagnosis === '' && $treatment === '' && $notes === '') {
        $errors['reason'] = 'Enter a reason, diagnosis, treatment, or notes.';
    }

    if ($errors !== []) {
        return ['ok' => false, 'message' => reset($errors), 'errors' => $errors];
    }

    return [
        'ok'   => true,
        'data' => [
            'facility_type'        => $facilityType,
            'facility_name'        => $facilityName,
            'visit_date'           => $visitDate,
            'reason'               => $reason !== '' ? $reason : null,
            'reported_diagnosis'   => $diagnosis !== '' ? $diagnosis : null,
            'reported_treatment'   => $treatment !== '' ? $treatment : null,
            'notes'                => $notes !== '' ? $notes : null,
            'information_source'   => $source,
        ],
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array{success: bool, message?: string, id?: int, errors?: array<string, string>}
 */
function patient_external_healthcare_visits_save(
    PDO $pdo,
    int $patientId,
    int $recordedBy,
    string $recorderRole,
    array $input
): array {
    patient_external_healthcare_visits_ensure_schema($pdo);

    $role = strtolower(trim($recorderRole));
    if (!in_array($role, ['bhw', 'patient', 'provider'], true)) {
        return ['success' => false, 'message' => 'Invalid recorder role.'];
    }
    if ($patientId <= 0 || $recordedBy <= 0) {
        return ['success' => false, 'message' => 'Patient and recorder are required.'];
    }

    $parsed = patient_external_healthcare_visits_parse($input);
    if (!$parsed['ok']) {
        return ['success' => false, 'message' => $parsed['message'], 'errors' => $parsed['errors'] ?? []];
    }
    $n = $parsed['data'];

    $stmt = $pdo->prepare("
        INSERT INTO patient_external_healthcare_visits (
            patient_id, recorded_by, recorder_role, visit_date, facility_type, facility_name,
            reason, reported_diagnosis, reported_treatment, notes, information_source, recorded_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $patientId,
        $recordedBy,
        $role,
        $n['visit_date'],
        $n['facility_type'],
        $n['facility_name'],
        $n['reason'],
        $n['reported_diagnosis'],
        $n['reported_treatment'],
        $n['notes'],
        $n['information_source'],
    ]);

    return [
        'success' => true,
        'message' => 'External healthcare visit saved to patient medical history.',
        'id'      => (int) $pdo->lastInsertId(),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function patient_external_healthcare_visits_list(PDO $pdo, int $patientId, int $limit = 50): array
{
    patient_external_healthcare_visits_ensure_schema($pdo);
    if ($patientId <= 0) {
        return [];
    }
    $limit = max(1, min(100, $limit));

    $stmt = $pdo->prepare("
        SELECT v.*,
               TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS recorder_name
        FROM patient_external_healthcare_visits v
        LEFT JOIN users u ON u.id = v.recorded_by
        WHERE v.patient_id = ?
        ORDER BY v.visit_date DESC, v.recorded_at DESC, v.id DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$patientId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Doctor/BHW-facing DTO list. Never treated as MedConnect consultations.
 *
 * @return list<array<string, mixed>>
 */
function patient_external_healthcare_visits_for_display(PDO $pdo, int $patientId, int $limit = 50): array
{
    $rows = patient_external_healthcare_visits_list($pdo, $patientId, $limit);
    $out = [];
    foreach ($rows as $row) {
        $out[] = patient_external_healthcare_visit_dto($row);
    }

    return $out;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function patient_external_healthcare_visit_dto(array $row): array
{
    $role = strtolower((string) ($row['recorder_role'] ?? 'bhw'));
    $roleLabel = $role === 'bhw' ? 'BHW' : ($role === 'provider' ? 'Provider' : 'Patient');
    $name = trim((string) ($row['recorder_name'] ?? ''));
    $byLabel = $name !== '' ? ($roleLabel . ' (' . $name . ')') : $roleLabel;
    $visitDate = (string) ($row['visit_date'] ?? '');
    $recordedAt = (string) ($row['recorded_at'] ?? '');

    return [
        'id'                     => (int) ($row['id'] ?? 0),
        'patient_id'             => (int) ($row['patient_id'] ?? 0),
        'record_kind'            => 'external_healthcare_visit',
        'label'                  => 'External Healthcare Visit',
        'facility_type'          => (string) ($row['facility_type'] ?? ''),
        'facility_type_label'    => patient_external_healthcare_facility_type_label((string) ($row['facility_type'] ?? '')),
        'facility_name'          => (string) ($row['facility_name'] ?? ''),
        'visit_date'             => $visitDate,
        'visit_date_label'       => patient_external_healthcare_format_date($visitDate),
        'reason'                 => (string) ($row['reason'] ?? ''),
        'reported_diagnosis'     => (string) ($row['reported_diagnosis'] ?? ''),
        'reported_treatment'     => (string) ($row['reported_treatment'] ?? ''),
        'notes'                  => (string) ($row['notes'] ?? ''),
        'information_source'     => (string) ($row['information_source'] ?? ''),
        'information_source_label' => patient_external_healthcare_source_label((string) ($row['information_source'] ?? '')),
        'recorded_by_label'      => $byLabel,
        'recorder_role'          => $role,
        'recorder_role_label'    => $roleLabel,
        'recorded_at'            => $recordedAt,
        'recorded_at_label'      => patient_external_healthcare_format_datetime($recordedAt),
    ];
}

/**
 * Authorized provider read (IDOR: must own a consultation with this patient OR prior access relationship).
 *
 * @return array{allowed: bool, message: string, visits?: list<array<string, mixed>>}
 */
function patient_external_healthcare_visits_for_authorized_provider(
    PDO $pdo,
    int $providerId,
    int $patientId,
    int $consultationId = 0
): array {
    require_once __DIR__ . '/provider_patient_access.php';
    $access = provider_patient_assert_access($pdo, $providerId, $patientId, $consultationId);
    if (empty($access['allowed'])) {
        return ['allowed' => false, 'message' => 'Access denied.'];
    }

    return [
        'allowed' => true,
        'message' => 'ok',
        'visits'  => patient_external_healthcare_visits_for_display($pdo, $patientId),
    ];
}

function patient_external_healthcare_format_date(?string $value): string
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
        return '';
    }
    $ts = strtotime($raw);

    return $ts ? date('F j, Y', $ts) : $raw;
}

function patient_external_healthcare_format_datetime(?string $value): string
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($raw))->format('F j, Y — g:i A');
    } catch (Throwable $e) {
        return $raw;
    }
}
