<?php
/**
 * Patient permanent medical profile (registration-sourced, read-only for patients).
 */
declare(strict_types=1);

require_once __DIR__ . '/patient_settings.php';

/**
 * Display label for a provider user id.
 */
function patient_medical_provider_label(PDO $pdo, int $providerId): string
{
    if ($providerId <= 0) {
        return 'your healthcare provider';
    }
    $stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ? AND role = 'provider' LIMIT 1");
    $stmt->execute([$providerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 'your healthcare provider';
    }
    $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    return $name !== '' ? ('Dr. ' . $name) : 'your healthcare provider';
}

/**
 * Assign reviewing provider: last consulting doctor, else next available verified provider.
 */
function patient_medical_resolve_assigned_provider(PDO $pdo, int $patientId): int
{
    try {
        $last = $pdo->prepare("
            SELECT provider_id
            FROM consultations
            WHERE patient_id = ?
              AND provider_id IS NOT NULL
              AND provider_id > 0
            ORDER BY consult_date DESC, consult_time DESC, id DESC
            LIMIT 1
        ");
        $last->execute([$patientId]);
        $lastId = (int) ($last->fetchColumn() ?: 0);
        if ($lastId > 0) {
            $active = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'provider' AND is_active = 1 LIMIT 1");
            $active->execute([$lastId]);
            if ($active->fetchColumn()) {
                return $lastId;
            }
        }
    } catch (PDOException $e) { /* continue */ }

    try {
        require_once __DIR__ . '/provider_verification.php';
        require_once __DIR__ . '/appointment_slots.php';
        provider_verification_ensure_schema($pdo);
        $bookable = appointment_slots_bookable_sql('s');
        $avail = $pdo->query("
            SELECT u.id
            FROM users u
            INNER JOIN provider_profiles pp ON pp.user_id = u.id AND pp.verification_status = 'verified'
            WHERE u.role = 'provider' AND u.is_active = 1
            ORDER BY (
                SELECT COUNT(*)
                FROM appointment_slots s
                WHERE s.provider_id = u.id
                  AND s.slot_date >= CURDATE()
                  AND s.status = 'available'
                  AND {$bookable}
            ) DESC, u.first_name ASC, u.last_name ASC
            LIMIT 1
        ");
        $nextId = (int) ($avail->fetchColumn() ?: 0);
        if ($nextId > 0) {
            return $nextId;
        }
    } catch (Throwable $e) { /* continue */ }

    try {
        $any = $pdo->query("
            SELECT id FROM users
            WHERE role = 'provider' AND is_active = 1
            ORDER BY id ASC
            LIMIT 1
        ");
        return (int) ($any->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Normalize pending request row for API/UI.
 *
 * @param array<string, mixed>|null $row
 * @return array<string, mixed>|null
 */
function patient_medical_format_request_row(PDO $pdo, ?array $row): ?array
{
    if (!$row) {
        return null;
    }
    $providerId = (int) ($row['provider_id'] ?? 0);
    return [
        'id' => (int) ($row['id'] ?? 0),
        'status' => (string) ($row['status'] ?? 'pending'),
        'patient_note' => (string) ($row['patient_note'] ?? ''),
        'provider_note' => (string) ($row['provider_note'] ?? ''),
        'proposed' => [
            'blood_type' => (string) ($row['proposed_blood_type'] ?? ''),
            'allergies' => (string) ($row['proposed_allergies'] ?? ''),
            'existing_conditions' => (string) ($row['proposed_conditions'] ?? ''),
            'current_medications' => (string) ($row['proposed_medications'] ?? ''),
        ],
        'assigned_provider_id' => $providerId,
        'assigned_provider_label' => patient_medical_provider_label($pdo, $providerId),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'created_at_label' => !empty($row['created_at'])
            ? date('M j, Y g:i A', strtotime((string) $row['created_at']))
            : '',
        'reviewed_at_label' => !empty($row['reviewed_at'])
            ? date('M j, Y g:i A', strtotime((string) $row['reviewed_at']))
            : '',
    ];
}

/**
 * Assert provider may act on a medical update request.
 */
function patient_medical_provider_can_review_request(PDO $pdo, int $providerId, array $requestRow, int $patientId): bool
{
    if ($providerId <= 0 || $patientId <= 0) {
        return false;
    }
    $assigned = (int) ($requestRow['provider_id'] ?? 0);
    if ($assigned > 0 && $assigned === $providerId) {
        return true;
    }
    require_once __DIR__ . '/provider_patient_access.php';
    $access = provider_patient_assert_access($pdo, $providerId, $patientId, 0);
    return !empty($access['allowed']);
}

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
    $lastRejected = null;
    try {
        $rq = $pdo->prepare("
            SELECT id, status, patient_note, provider_note, provider_id,
                   proposed_blood_type, proposed_allergies, proposed_conditions, proposed_medications,
                   created_at, reviewed_at
            FROM patient_medical_update_requests
            WHERE patient_id = ? AND status IN ('pending', 'in_review')
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $rq->execute([$userId]);
        $pendingRequest = patient_medical_format_request_row($pdo, $rq->fetch(PDO::FETCH_ASSOC) ?: null);

        $rej = $pdo->prepare("
            SELECT id, status, patient_note, provider_note, provider_id,
                   proposed_blood_type, proposed_allergies, proposed_conditions, proposed_medications,
                   created_at, reviewed_at
            FROM patient_medical_update_requests
            WHERE patient_id = ? AND status = 'rejected'
            ORDER BY reviewed_at DESC, created_at DESC
            LIMIT 1
        ");
        $rej->execute([$userId]);
        $lastRejected = patient_medical_format_request_row($pdo, $rej->fetch(PDO::FETCH_ASSOC) ?: null);
    } catch (PDOException $e) {
        $pendingRequest = null;
        $lastRejected = null;
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
        'last_rejected_request' => $lastRejected,
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

    try {
        require_once dirname(__DIR__) . '/core/NotificationManager.php';
        NotificationManager::create($pdo, $patientId, [
            'type'       => 'clinical',
            'title'      => 'Health Summary Update Approved',
            'message'    => 'Your healthcare provider approved and updated your permanent Health Summary.',
            'priority'   => 'normal',
            'action_url' => '/views/patient/health_summary.php',
        ]);
    } catch (Throwable $e) { /* non-fatal */ }

    return true;
}

/**
 * Provider rejects a pending medical profile update request.
 */
function patient_health_summary_provider_reject(
    PDO $pdo,
    int $patientId,
    int $providerId,
    ?int $requestId,
    string $providerNote = ''
): bool {
    patient_settings_ensure_schema($pdo);

    $sql = "
        SELECT id, provider_id, status
        FROM patient_medical_update_requests
        WHERE patient_id = ? AND status IN ('pending', 'in_review')
    ";
    $params = [$patientId];
    if ($requestId) {
        $sql .= ' AND id = ?';
        $params[] = $requestId;
    }
    $sql .= ' ORDER BY created_at DESC LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    if (!patient_medical_provider_can_review_request($pdo, $providerId, $row, $patientId)) {
        return false;
    }

    $pdo->prepare("
        UPDATE patient_medical_update_requests
        SET status = 'rejected',
            provider_id = ?,
            provider_note = ?,
            reviewed_at = NOW()
        WHERE id = ? AND patient_id = ?
    ")->execute([
        $providerId,
        $providerNote !== '' ? $providerNote : null,
        (int) $row['id'],
        $patientId,
    ]);

    require_once __DIR__ . '/audit_log.php';
    audit_log($pdo, [
        'patient_id'  => $patientId,
        'action_type' => 'medical_update_rejected',
        'description' => 'Healthcare provider rejected a Health Summary update request.',
        'meta'        => [
            'provider_id' => $providerId,
            'request_id'  => (int) $row['id'],
            'provider_note' => $providerNote,
        ],
    ]);

    try {
        require_once dirname(__DIR__) . '/core/NotificationManager.php';
        $msg = 'Your Health Summary update request was not approved.';
        if ($providerNote !== '') {
            $msg .= ' Note: ' . $providerNote;
        }
        NotificationManager::create($pdo, $patientId, [
            'type'       => 'clinical',
            'title'      => 'Health Summary Update Rejected',
            'message'    => $msg,
            'priority'   => 'normal',
            'action_url' => '/views/patient/health_summary.php',
        ]);
    } catch (Throwable $e) { /* non-fatal */ }

    return true;
}

/**
 * Load active pending request for provider review (with proposed values).
 *
 * @return array<string, mixed>|null
 */
function patient_medical_pending_request_for_patient(PDO $pdo, int $patientId): ?array
{
    patient_settings_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        SELECT id, status, patient_note, provider_note, provider_id,
               proposed_blood_type, proposed_allergies, proposed_conditions, proposed_medications,
               created_at, reviewed_at
        FROM patient_medical_update_requests
        WHERE patient_id = ? AND status IN ('pending', 'in_review')
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$patientId]);
    return patient_medical_format_request_row($pdo, $stmt->fetch(PDO::FETCH_ASSOC) ?: null);
}

/**
 * Map patient_id => pending update request row for provider directory badges.
 *
 * @param int[] $patientIds
 * @return array<int, array<string, mixed>>
 */
function patient_medical_pending_requests_map(PDO $pdo, array $patientIds): array
{
    patient_settings_ensure_schema($pdo);
    $patientIds = array_values(array_filter(array_map('intval', $patientIds)));
    if ($patientIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($patientIds), '?'));
    $stmt = $pdo->prepare("
        SELECT id, patient_id, patient_note, provider_id, status, created_at,
               proposed_blood_type, proposed_allergies, proposed_conditions, proposed_medications
        FROM patient_medical_update_requests
        WHERE patient_id IN ($placeholders)
          AND status IN ('pending', 'in_review')
        ORDER BY created_at DESC
    ");
    $stmt->execute($patientIds);

    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int) ($row['patient_id'] ?? 0);
        if ($pid > 0 && !isset($map[$pid])) {
            $map[$pid] = $row;
        }
    }

    return $map;
}
