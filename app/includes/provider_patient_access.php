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
 * Patients this provider may open in Medical Records / GIS (same rules as assert_access).
 *
 * @return list<array<string, mixed>>
 */
function provider_patient_caseload_directory(PDO $pdo, int $providerId): array
{
    if ($providerId <= 0) {
        return [];
    }

    require_once __DIR__ . '/triage_assessment_schema.php';
    triage_assessment_ensure_schema($pdo);

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
            SELECT patient_id, MAX(last_touch) AS last_touch
            FROM (
                SELECT patient_id, MAX(consult_date) AS last_touch
                FROM consultations
                WHERE provider_id = ?
                GROUP BY patient_id
                UNION ALL
                SELECT patient_id, MAX(slot_date) AS last_touch
                FROM appointment_slots
                WHERE provider_id = ? AND status = 'booked'
                  AND slot_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY patient_id
                UNION ALL
                SELECT patient_id, MAX(DATE(assessed_at)) AS last_touch
                FROM triage_results
                WHERE assigned_provider_id = ?
                  AND recommendation_status IN ('pending_approval', 'approved', 'rejected')
                  AND UPPER(COALESCE(assessment_status, '')) NOT IN ('CANCELLED', 'CANCELED')
                  AND LOWER(COALESCE(outcome, '')) <> 'cancelled'
                  AND assessed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY patient_id
                UNION ALL
                SELECT patient_id, MAX(DATE(created_at)) AS last_touch
                FROM digital_referrals
                WHERE provider_id = ?
                  AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY patient_id
                UNION ALL
                SELECT patient_id, MAX(DATE(created_at)) AS last_touch
                FROM patient_medical_update_requests
                WHERE provider_id = ?
                  AND status IN ('pending', 'in_review')
                GROUP BY patient_id
            ) combined
            GROUP BY patient_id
        ) rel ON rel.patient_id = u.id
        LEFT JOIN patient_registrations pr ON pr.user_id = u.id
        WHERE u.role = 'patient'
        ORDER BY rel.last_touch DESC, u.last_name ASC
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$providerId, $providerId, $providerId, $providerId, $providerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        // Older schemas may lack one of the optional tables — fall back to consult/slot only.
        error_log('provider_patient_caseload_directory fallback: ' . $e->getMessage());
        try {
            $fallback = $pdo->prepare("
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
                    COALESCE(rel.last_consult, '') AS last_consult,
                    CASE WHEN u.is_active = 1 THEN 'Active' ELSE 'Inactive' END AS status,
                    CONCAT('MC-', LPAD(u.id, 6, '0')) AS patient_number
                FROM users u
                INNER JOIN (
                    SELECT patient_id, MAX(last_consult) AS last_consult
                    FROM (
                        SELECT patient_id, MAX(consult_date) AS last_consult
                        FROM consultations
                        WHERE provider_id = ?
                        GROUP BY patient_id
                        UNION ALL
                        SELECT patient_id, MAX(slot_date) AS last_consult
                        FROM appointment_slots
                        WHERE provider_id = ? AND status = 'booked'
                        GROUP BY patient_id
                    ) combined
                    GROUP BY patient_id
                ) rel ON rel.patient_id = u.id
                LEFT JOIN patient_registrations pr ON pr.user_id = u.id
                WHERE u.role = 'patient'
                ORDER BY rel.last_consult DESC, u.last_name ASC
            ");
            $fallback->execute([$providerId, $providerId]);
            $rows = $fallback->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e2) {
            error_log('provider_patient_caseload_directory failed: ' . $e2->getMessage());
            return [];
        }
    }

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
 * SQL predicate: triage_results row is visible on this provider's caseload.
 * Bind provider_id four times (consult, slot, referral, assigned).
 */
function provider_triage_row_visibility_sql(string $trAlias = 'tr'): string
{
    $tr = preg_replace('/[^a-zA-Z0-9_]/', '', $trAlias) ?: 'tr';

    return "(
        EXISTS (
            SELECT 1 FROM consultations c
            WHERE c.patient_id = {$tr}.patient_id AND c.provider_id = ?
        )
        OR EXISTS (
            SELECT 1 FROM appointment_slots s
            WHERE s.patient_id = {$tr}.patient_id AND s.provider_id = ?
              AND s.status = 'booked'
              AND s.slot_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        )
        OR EXISTS (
            SELECT 1 FROM digital_referrals dr
            WHERE dr.patient_id = {$tr}.patient_id AND dr.provider_id = ?
              AND dr.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        )
        OR (
            {$tr}.assigned_provider_id = ?
            AND {$tr}.recommendation_status IN ('pending_approval', 'approved', 'rejected')
            AND UPPER(COALESCE({$tr}.assessment_status, '')) NOT IN ('CANCELLED', 'CANCELED')
            AND LOWER(COALESCE({$tr}.outcome, '')) <> 'cancelled'
            AND {$tr}.assessed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        )
    )";
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

