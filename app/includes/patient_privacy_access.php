<?php
/**
 * Patient privacy flags used by provider and emergency record access.
 *
 * Missing rows keep the column defaults: provider and emergency access stay
 * allowed, and a stored 0 denies that access. This file does not grant a role
 * or a barangay relationship by itself.
 */
declare(strict_types=1);

/**
 * @return array{
 *   share_medical_records:int,
 *   emergency_access_consent:int,
 *   data_privacy_acknowledged:int,
 *   share_prescriptions_with_barangay_bhw:int
 * }
 */
function patient_privacy_values_from_input(array $input): array
{
    return [
        'share_medical_records' => !empty($input['share_medical_records']) ? 1 : 0,
        'emergency_access_consent' => !empty($input['emergency_access_consent']) ? 1 : 0,
        'data_privacy_acknowledged' => !empty($input['data_privacy_acknowledged']) ? 1 : 0,
        'share_prescriptions_with_barangay_bhw' => !empty($input['share_prescriptions_with_barangay_bhw']) ? 1 : 0,
    ];
}

function patient_privacy_flag(PDO $pdo, int $patientId, string $column, bool $default): bool
{
    $allowed = [
        'share_medical_records',
        'emergency_access_consent',
        'data_privacy_acknowledged',
        'share_prescriptions_with_barangay_bhw',
    ];
    if ($patientId <= 0 || !in_array($column, $allowed, true)) {
        return $default;
    }

    try {
        $stmt = $pdo->prepare("SELECT {$column} FROM patient_privacy_preferences WHERE user_id = ? LIMIT 1");
        $stmt->execute([$patientId]);
        $value = $stmt->fetchColumn();
    } catch (PDOException $e) {
        return $default;
    }

    if ($value === false || $value === null) {
        return $default;
    }

    return (int) $value === 1;
}

function patient_shares_medical_records_with_providers(PDO $pdo, int $patientId): bool
{
    return patient_privacy_flag($pdo, $patientId, 'share_medical_records', true);
}

function patient_allows_emergency_record_access(PDO $pdo, int $patientId): bool
{
    return patient_privacy_flag($pdo, $patientId, 'emergency_access_consent', true);
}

/**
 * Patient ids whose provider-record consent is explicitly off.
 *
 * @param list<int> $patientIds
 * @return array<int, true>
 */
function patient_privacy_withheld_record_ids(PDO $pdo, array $patientIds): array
{
    $ids = [];
    foreach ($patientIds as $patientId) {
        $patientId = (int) $patientId;
        if ($patientId > 0) {
            $ids[$patientId] = $patientId;
        }
    }
    if ($ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $pdo->prepare("
            SELECT user_id
            FROM patient_privacy_preferences
            WHERE share_medical_records = 0
              AND user_id IN ({$placeholders})
        ");
        $stmt->execute(array_values($ids));
        $withheld = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $patientId) {
            $withheld[(int) $patientId] = true;
        }

        return $withheld;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function patient_privacy_blank_clinical_fields(array $row): array
{
    foreach ([
        'diagnosis',
        'chief_complaint',
        'last_diagnosis',
        'last_complaint',
        'clinical_note_signature',
        'blood_type',
        'allergies',
        'history',
        'existing_conditions',
        'medications',
        'current_medications',
        'conditions',
        'extra',
        'detail',
        'ai_classification',
        'final_classification',
    ] as $key) {
        if (array_key_exists($key, $row)) {
            $row[$key] = is_array($row[$key]) ? [] : '';
        }
    }
    if (array_key_exists('clinical_note_id', $row)) {
        $row['clinical_note_id'] = null;
    }

    return $row;
}

/**
 * Blank permanent clinical fields for patients who turned provider record access off.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function patient_privacy_strip_withheld_clinical_rows(PDO $pdo, array $rows, string $idKey): array
{
    if ($rows === []) {
        return $rows;
    }

    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (int) ($row[$idKey] ?? 0);
    }
    $withheld = patient_privacy_withheld_record_ids($pdo, $ids);
    if ($withheld === []) {
        return $rows;
    }

    foreach ($rows as &$row) {
        $id = (int) ($row[$idKey] ?? 0);
        if ($id > 0 && !empty($withheld[$id])) {
            $row = patient_privacy_blank_clinical_fields($row);
        }
    }
    unset($row);

    return $rows;
}
