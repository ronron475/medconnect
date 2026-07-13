<?php
/**
 * Patient permanent medical profile (registration-sourced, read-only for patients).
 */
declare(strict_types=1);

require_once __DIR__ . '/patient_settings.php';

/**
 * Parse free-text medical lists into display chips.
 *
 * @return string[]
 */
function patient_health_parse_list(?string $raw): array
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return [];
    }
    $lower = strtolower($raw);
    foreach (['none', 'n/a', 'na', 'wala', 'no known allergies', 'walang allergy', 'no maintenance medications'] as $skip) {
        if ($lower === $skip) {
            return [];
        }
    }
    $parts = preg_split('/[,;\n\r]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && !in_array(strtolower($p), ['none', 'n/a', 'na', 'wala'], true)) {
            $out[] = $p;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Load permanent health summary for a patient user id.
 *
 * @return array<string, mixed>
 */
function patient_health_summary_load(PDO $pdo, int $userId): array
{
    patient_settings_ensure_schema($pdo);

    $stmt = $pdo->prepare("
        SELECT
            pr.blood_type,
            pr.allergies,
            pr.existing_conditions,
            pr.current_medications,
            pr.medical_profile_updated_at,
            pr.medical_profile_updated_by,
            pr.created_at,
            pr.verified_at,
            u.first_name,
            u.last_name,
            CONCAT('MC-', LPAD(u.id, 6, '0')) AS patient_number
        FROM users u
        LEFT JOIN patient_registrations pr ON pr.user_id = u.id OR pr.email = u.email
        WHERE u.id = ? AND u.role = 'patient'
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $updatedAt = $row['medical_profile_updated_at'] ?? null;
    if (!$updatedAt) {
        $updatedAt = $row['verified_at'] ?? $row['created_at'] ?? null;
    }

    $updatedByName = null;
    $updatedById = (int) ($row['medical_profile_updated_by'] ?? 0);
    if ($updatedById > 0) {
        $pstmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ? AND role = 'provider' LIMIT 1");
        $pstmt->execute([$updatedById]);
        $prov = $pstmt->fetch(PDO::FETCH_ASSOC);
        if ($prov) {
            $updatedByName = 'Dr. ' . trim(($prov['first_name'] ?? '') . ' ' . ($prov['last_name'] ?? ''));
        }
    }
    if ($updatedByName === null || $updatedByName === 'Dr. ') {
        $updatedByName = 'Registration intake (pending provider verification)';
    }

    $pendingRequest = null;
    try {
        $rq = $pdo->prepare("
            SELECT id, status, created_at
            FROM patient_medical_update_requests
            WHERE patient_id = ? AND status IN ('pending', 'in_review')
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $rq->execute([$userId]);
        $pendingRequest = $rq->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        $pendingRequest = null;
    }

    $allergies = patient_health_parse_list($row['allergies'] ?? '');
    $conditions = patient_health_parse_list($row['existing_conditions'] ?? '');
    $medications = patient_health_parse_list($row['current_medications'] ?? '');

    return [
        'patient_name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
        'patient_number' => (string) ($row['patient_number'] ?? ''),
        'blood_type' => trim((string) ($row['blood_type'] ?? '')) ?: null,
        'allergies' => $allergies,
        'conditions' => $conditions,
        'medications' => $medications,
        'metadata' => [
            'last_updated_at' => $updatedAt,
            'last_updated_at_label' => $updatedAt ? date('M j, Y \a\t g:i A', strtotime((string) $updatedAt)) : 'Not available',
            'last_updated_by' => $updatedByName,
            'last_updated_by_id' => $updatedById > 0 ? $updatedById : null,
        ],
        'pending_request' => $pendingRequest,
    ];
}

/**
 * Load permanent registration profile fields for provider views (consultation, records).
 *
 * @return array<string, string>
 */
function patient_registration_profile_fields(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT
            pr.gender,
            pr.blood_type,
            pr.allergies,
            pr.existing_conditions,
            pr.current_medications,
            pr.contact_number,
            CONCAT_WS(', ', NULLIF(pr.barangay,''), NULLIF(pr.city_municipality,''), NULLIF(pr.province,'')) AS address
        FROM users u
        LEFT JOIN patient_registrations pr ON pr.user_id = u.id OR pr.email = u.email
        WHERE u.id = ? AND u.role = 'patient'
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $none = static fn (?string $v, string $fallback = 'None recorded') => trim((string) $v) !== '' ? trim((string) $v) : $fallback;

    return [
        'sex'          => $none($row['gender'] ?? '', 'Not recorded'),
        'blood_type'   => $none($row['blood_type'] ?? '', 'Not recorded'),
        'allergies'    => $none($row['allergies'] ?? '', 'None known'),
        'history'      => $none($row['existing_conditions'] ?? '', 'None recorded'),
        'medications'  => $none($row['current_medications'] ?? '', 'None recorded'),
        'contact'      => trim((string) ($row['contact_number'] ?? '')),
        'address'      => trim((string) ($row['address'] ?? '')),
    ];
}

/**
 * Provider-verified update to permanent medical profile.
 */
function patient_health_summary_provider_update(
    PDO $pdo,
    int $patientId,
    int $providerId,
    array $fields,
    ?int $requestId = null
): bool {
    patient_settings_ensure_schema($pdo);

    $allowedBlood = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Unknown'];
    $blood = trim((string) ($fields['blood_type'] ?? ''));
    if ($blood !== '' && !in_array($blood, $allowedBlood, true)) {
        return false;
    }

    $allergies = trim((string) ($fields['allergies'] ?? ''));
    $conditions = trim((string) ($fields['existing_conditions'] ?? ''));
    $medications = trim((string) ($fields['current_medications'] ?? ''));

    $emailStmt = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
    $emailStmt->execute([$patientId]);
    $email = (string) ($emailStmt->fetchColumn() ?: '');

    $pdo->prepare("
        UPDATE patient_registrations
        SET blood_type = ?,
            allergies = ?,
            existing_conditions = ?,
            current_medications = ?,
            medical_profile_updated_at = NOW(),
            medical_profile_updated_by = ?
        WHERE user_id = ? OR email = ?
    ")->execute([
        $blood ?: null,
        $allergies ?: null,
        $conditions ?: null,
        $medications ?: null,
        $providerId,
        $patientId,
        $email,
    ]);

    if ($requestId) {
        $pdo->prepare("
            UPDATE patient_medical_update_requests
            SET status = 'approved', provider_id = ?, reviewed_at = NOW()
            WHERE id = ? AND patient_id = ?
        ")->execute([$providerId, $requestId, $patientId]);
    } else {
        $pdo->prepare("
            UPDATE patient_medical_update_requests
            SET status = 'approved', provider_id = ?, reviewed_at = NOW()
            WHERE patient_id = ? AND status IN ('pending', 'in_review')
        ")->execute([$providerId, $patientId]);
    }

    require_once __DIR__ . '/audit_log.php';
    audit_log($pdo, [
        'patient_id'  => $patientId,
        'action_type' => 'medical_profile_provider_updated',
        'description' => 'Healthcare provider verified and updated permanent medical profile.',
        'meta'        => [
            'provider_id' => $providerId,
            'request_id'  => $requestId,
            'fields'      => ['blood_type', 'allergies', 'existing_conditions', 'current_medications'],
        ],
    ]);

    return true;
}
