<?php
/**
 * Provider ↔ patient/consultation access checks to prevent IDOR.
 */

declare(strict_types=1);

require_once __DIR__ . '/triage_assessment_schema.php';

/**
 * Assert that the provider is allowed to act on a patient/consultation.
 *
 * Rules:
 * - If a consultation_id is provided, it MUST belong to the provider, and MUST match patient_id when provided.
 * - If no consultation_id is provided, a prior provider↔patient consultation OR a booked appointment slot
 *   must exist; the most recent consultation id (if any) is returned.
 *
 * @return array{allowed:bool,message:string,consultation_id?:int}
 */
function provider_patient_assert_access(PDO $pdo, int $providerId, int $patientId, int $consultationId = 0): array
{
    if ($providerId <= 0 || $patientId <= 0) {
        return ['allowed' => false, 'message' => 'Invalid provider or patient.'];
    }

    if ($consultationId > 0) {
        $stmt = $pdo->prepare('
            SELECT id, patient_id
            FROM consultations
            WHERE id = ? AND provider_id = ?
            LIMIT 1
        ');
        $stmt->execute([$consultationId, $providerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['allowed' => false, 'message' => 'Access denied.'];
        }
        if ((int)($row['patient_id'] ?? 0) !== $patientId) {
            return ['allowed' => false, 'message' => 'Access denied.'];
        }
        return ['allowed' => true, 'message' => 'ok', 'consultation_id' => (int)$row['id']];
    }

    // Existing relationship (previous consult)
    $s = $pdo->prepare('
        SELECT id
        FROM consultations
        WHERE patient_id = ? AND provider_id = ?
        ORDER BY id DESC
        LIMIT 1
    ');
    $s->execute([$patientId, $providerId]);
    $existing = (int)($s->fetchColumn() ?: 0);
    if ($existing > 0) {
        return ['allowed' => true, 'message' => 'ok', 'consultation_id' => $existing];
    }

    // Allow if there is a booked appointment between the provider and patient (recent/present).
    try {
        $a = $pdo->prepare("
            SELECT id
            FROM appointment_slots
            WHERE provider_id = ? AND patient_id = ? AND status = 'booked'
              AND slot_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY slot_date DESC, start_time DESC
            LIMIT 1
        ");
        $a->execute([$providerId, $patientId]);
        if ($a->fetchColumn()) {
            return ['allowed' => true, 'message' => 'ok', 'consultation_id' => 0];
        }
    } catch (PDOException $e) {
        // appointment_slots may not exist in all schemas
    }

    // Allow emergency / hospital referral relationship (no consultation yet).
    try {
        $r = $pdo->prepare("
            SELECT id
            FROM digital_referrals
            WHERE provider_id = ? AND patient_id = ?
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY id DESC
            LIMIT 1
        ");
        $r->execute([$providerId, $patientId]);
        if ($r->fetchColumn()) {
            return ['allowed' => true, 'message' => 'ok', 'consultation_id' => 0];
        }
    } catch (PDOException $e) {
        // digital_referrals may not exist in all schemas
    }

    // Assigned reviewer for non-urgent AI self-care workflow (no consultation required yet).
    try {
        triage_assessment_ensure_schema($pdo);
        $assign = $pdo->prepare("
            SELECT id
            FROM triage_results
            WHERE patient_id = ?
              AND assigned_provider_id = ?
              AND recommendation_status IN ('pending_approval', 'approved', 'rejected')
              AND UPPER(COALESCE(assessment_status, '')) NOT IN ('CANCELLED', 'CANCELED')
              AND LOWER(COALESCE(outcome, '')) <> 'cancelled'
              AND assessed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY assessed_at DESC
            LIMIT 1
        ");
        $assign->execute([$patientId, $providerId]);
        if ($assign->fetchColumn()) {
            return ['allowed' => true, 'message' => 'ok', 'consultation_id' => 0];
        }
    } catch (PDOException $e) {
        // assigned_provider_id may not exist until schema migration runs
    }

    // Assigned reviewer for pending Health Summary update requests.
    try {
        require_once __DIR__ . '/patient_settings.php';
        patient_settings_ensure_schema($pdo);
        $hs = $pdo->prepare("
            SELECT id
            FROM patient_medical_update_requests
            WHERE patient_id = ?
              AND provider_id = ?
              AND status IN ('pending', 'in_review')
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $hs->execute([$patientId, $providerId]);
        if ($hs->fetchColumn()) {
            return ['allowed' => true, 'message' => 'ok', 'consultation_id' => 0];
        }
    } catch (PDOException $e) {
        // patient_medical_update_requests may not exist until migration runs
    }

    return ['allowed' => false, 'message' => 'Access denied.'];
}

/**
 * True only when this doctor has a consultation row with this patient.
 *
 * Registration, active status, availability, barangay, booked slots, triage
 * assignment, referrals, and health-summary assignment do not count.
 */
function provider_patient_has_consultation_history(PDO $pdo, int $providerId, int $patientId): bool
{
    if ($providerId <= 0 || $patientId <= 0) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('
            SELECT 1
            FROM consultations
            WHERE patient_id = ? AND provider_id = ?
            LIMIT 1
        ');
        $stmt->execute([$patientId, $providerId]);

        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Doctor may read this patient's BHW-added clinical record only with consultation history.
 */
function provider_may_view_bhw_clinical_data(PDO $pdo, int $providerId, int $patientId): bool
{
    return provider_patient_has_consultation_history($pdo, $providerId, $patientId);
}

/**
 * Current permanent clinical profile was last saved by a BHW.
 */
function patient_clinical_profile_authored_by_bhw(PDO $pdo, int $patientId): bool
{
    if ($patientId <= 0) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT LOWER(COALESCE(author.role, ''))
            FROM users patient
            INNER JOIN patient_registrations pr
                ON pr.user_id = patient.id OR pr.email = patient.email
            LEFT JOIN users author ON author.id = pr.medical_profile_updated_by
            WHERE patient.id = ? AND patient.role = 'patient'
            LIMIT 1
        ");
        $stmt->execute([$patientId]);

        return (string) ($stmt->fetchColumn() ?: '') === 'bhw';
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * @return array{blood_type: string, allergies: string, existing_conditions: string, current_medications: string}
 */
function patient_clinical_profile_values(PDO $pdo, int $patientId): array
{
    $empty = [
        'blood_type' => '',
        'allergies' => '',
        'existing_conditions' => '',
        'current_medications' => '',
    ];
    if ($patientId <= 0) {
        return $empty;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT pr.blood_type, pr.allergies, pr.existing_conditions, pr.current_medications
            FROM users patient
            INNER JOIN patient_registrations pr
                ON pr.user_id = patient.id OR pr.email = patient.email
            WHERE patient.id = ? AND patient.role = 'patient'
            LIMIT 1
        ");
        $stmt->execute([$patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return $empty;
    }

    return [
        'blood_type' => trim((string) ($row['blood_type'] ?? '')),
        'allergies' => trim((string) ($row['allergies'] ?? '')),
        'existing_conditions' => trim((string) ($row['existing_conditions'] ?? '')),
        'current_medications' => trim((string) ($row['current_medications'] ?? '')),
    ];
}

/**
 * Blank BHW-authored clinical profile fields when this doctor has no consultation with the patient.
 * Patient- or provider-authored profile values stay under the caller's existing access check.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function provider_redact_bhw_clinical_profile(PDO $pdo, int $providerId, int $patientId, array $row): array
{
    if (provider_may_view_bhw_clinical_data($pdo, $providerId, $patientId)) {
        return $row;
    }
    if (!patient_clinical_profile_authored_by_bhw($pdo, $patientId)) {
        return $row;
    }

    foreach (['blood_type', 'allergies', 'existing_conditions', 'history', 'current_medications', 'medications'] as $key) {
        if (array_key_exists($key, $row)) {
            $row[$key] = '';
        }
    }
    $row['_bhw_clinical_hidden'] = true;

    return $row;
}

/**
 * BHW uploads use a bhw_ stored name. Other documents follow the caller's existing access check.
 */
function provider_may_view_patient_document(PDO $pdo, int $providerId, int $patientId, string $storedFileName): bool
{
    $name = strtolower(trim($storedFileName));
    if (!str_starts_with($name, 'bhw_')) {
        return true;
    }

    return provider_may_view_bhw_clinical_data($pdo, $providerId, $patientId);
}

/**
 * Empty posted clinical fields must not wipe a BHW profile the doctor is not allowed to see.
 */
function provider_preserve_unseen_bhw_clinical_profile(PDO $pdo, int $providerId, int $patientId): bool
{
    return !provider_may_view_bhw_clinical_data($pdo, $providerId, $patientId)
        && patient_clinical_profile_authored_by_bhw($pdo, $patientId);
}

/**
 * Patients this doctor may open in the Patient List.
 * Membership is a consultation row only: consultations.provider_id = this doctor.
 *
 * @return list<array<string, mixed>>
 */
function provider_patient_caseload_directory(PDO $pdo, int $providerId): array
{
    if ($providerId <= 0) {
        return [];
    }

    $sql = "
        SELECT DISTINCT
            u.id,
            u.first_name,
            u.last_name,
            TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS name,
            CONCAT(
                UPPER(LEFT(COALESCE(NULLIF(u.first_name, ''), '?'), 1)),
                UPPER(LEFT(COALESCE(NULLIF(u.last_name, ''), ''), 1))
            ) AS initials,
            COALESCE(pr.age, '') AS age,
            COALESCE(pr.gender, '') AS sex,
            COALESCE(pr.contact_number, '') AS contact,
            COALESCE(CONCAT_WS(', ',
                NULLIF(pr.barangay, ''),
                NULLIF(pr.city_municipality, '')
            ), '') AS address,
            COALESCE(pr.blood_type, '') AS blood_type,
            COALESCE(pr.existing_conditions, '') AS history,
            COALESCE(pr.allergies, '') AS allergies,
            COALESCE(pr.current_medications, '') AS medications,
            COALESCE(rel.last_touch, '') AS last_consult,
            CASE WHEN u.is_active = 1 THEN 'Active' ELSE 'Inactive' END AS status,
            CONCAT('MC-', LPAD(u.id, 6, '0')) AS patient_number
        FROM users u
        INNER JOIN (
            SELECT patient_id, MAX(consult_date) AS last_touch
            FROM consultations
            WHERE provider_id = ?
            GROUP BY patient_id
        ) rel ON rel.patient_id = u.id
        LEFT JOIN patient_registrations pr ON pr.user_id = u.id
        WHERE u.role = 'patient'
        ORDER BY rel.last_touch DESC, u.last_name ASC
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$providerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('provider_patient_caseload_directory failed: ' . $e->getMessage());
        return [];
    }

    $rows = provider_patient_strip_hidden_bhw_clinical($pdo, $providerId, $rows);

    foreach ($rows as &$row) {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $row['name'] = 'Patient ' . (string) ($row['patient_number'] ?? ('MC-' . str_pad((string) ($row['id'] ?? 0), 6, '0', STR_PAD_LEFT)));
        }
        if (trim((string) ($row['initials'] ?? '')) === '' || (string) ($row['initials'] ?? '') === '?') {
            $row['initials'] = 'P';
        }
    }
    unset($row);

    return $rows;
}

/**
 * SQL predicate: triage_results row appears on this provider's AI Triage Case Review.
 *
 * Only cases explicitly assigned to the authenticated provider
 * (triage_results.assigned_provider_id = session provider id).
 * Bind provider_id once.
 */
function provider_triage_row_visibility_sql(string $trAlias = 'tr'): string
{
    $tr = preg_replace('/[^a-zA-Z0-9_]/', '', $trAlias) ?: 'tr';

    return "(
        {$tr}.assigned_provider_id IS NOT NULL
        AND {$tr}.assigned_provider_id > 0
        AND {$tr}.assigned_provider_id = ?
        AND UPPER(COALESCE({$tr}.assessment_status, '')) NOT IN ('CANCELLED', 'CANCELED')
        AND LOWER(COALESCE({$tr}.outcome, '')) NOT IN ('cancelled', 'canceled')
    )";
}

/**
 * Drop BHW-authored clinical columns from caseload rows this doctor has not consulted.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function provider_patient_strip_hidden_bhw_clinical(PDO $pdo, int $providerId, array $rows): array
{
    if ($providerId <= 0 || $rows === []) {
        return $rows;
    }

    $ids = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if ($ids === []) {
        return $rows;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT pr.user_id
            FROM patient_registrations pr
            INNER JOIN users author ON author.id = pr.medical_profile_updated_by AND author.role = 'bhw'
            WHERE pr.user_id IN ({$placeholders})
              AND NOT EXISTS (
                  SELECT 1
                  FROM consultations c
                  WHERE c.patient_id = pr.user_id
                    AND c.provider_id = ?
              )
        ");
        $stmt->execute(array_merge(array_values($ids), [$providerId]));
        $hidden = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $patientId) {
            $hidden[(int) $patientId] = true;
        }
    } catch (PDOException $e) {
        return $rows;
    }

    if ($hidden === []) {
        return $rows;
    }

    foreach ($rows as &$row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || empty($hidden[$id])) {
            continue;
        }
        foreach (['blood_type', 'allergies', 'history', 'existing_conditions', 'medications', 'current_medications'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = '';
            }
        }
    }
    unset($row);

    return $rows;
}

/**
 * Safe patient display name from first/last (avoids MySQL CONCAT NULL).
 */
function provider_patient_display_name(?string $firstName, ?string $lastName, int $patientId = 0): string
{
    $name = trim(trim((string) $firstName) . ' ' . trim((string) $lastName));
    if ($name !== '') {
        return $name;
    }
    if ($patientId > 0) {
        return 'Patient MC-' . str_pad((string) $patientId, 6, '0', STR_PAD_LEFT);
    }

    return 'Patient';
}

