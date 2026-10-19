<?php
/**
 * Patient permission for barangay BHW prescription viewing.
 *
 * Default is off. A missing row or column stays off.
 * This does not replace the BHW role or barangay check.
 */
declare(strict_types=1);

require_once __DIR__ . '/patient_consultation_records.php';

function patient_shares_prescriptions_with_barangay_bhw(PDO $pdo, int $patientId): bool
{
    if ($patientId <= 0) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('
            SELECT share_prescriptions_with_barangay_bhw
            FROM patient_privacy_preferences
            WHERE user_id = ?
            LIMIT 1
        ');
        $stmt->execute([$patientId]);
        $value = $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }

    return (int) $value === 1;
}

/**
 * Prescription rows for one BHW records request.
 *
 * The barangay gate runs again here, so sharing cannot bypass it.
 * Eligible rows match prescriptions the patient can already see in My Health:
 * the consultation is completed and its SOAP note is finalized. Revoked rows,
 * in-progress visits, and completed visits without a finalized note are omitted.
 * Provider notes and verification tokens are not selected.
 *
 * @param array{barangay_id?:int,barangay_name?:string} $ctx
 * @return array{shared:bool,denied:bool,prescriptions:list<array{medication_name:string,dosage:string,frequency:string,duration:string,created_at:string}>}
 */
function bhw_records_prescription_view(PDO $pdo, array $ctx, int $patientId): array
{
    if (!function_exists('bhw_assert_patient_in_sector') || !bhw_assert_patient_in_sector($pdo, $ctx, $patientId)) {
        return ['shared' => false, 'denied' => true, 'prescriptions' => []];
    }

    if (!patient_shares_prescriptions_with_barangay_bhw($pdo, $patientId)) {
        return ['shared' => false, 'denied' => false, 'prescriptions' => []];
    }

    $stmt = $pdo->prepare("
        SELECT p.medication_name, p.dosage, p.frequency, p.duration, p.created_at
        FROM prescriptions p
        INNER JOIN consultations c
            ON c.id = p.consultation_id
           AND c.patient_id = p.patient_id
        INNER JOIN clinical_notes cn
            ON cn.consultation_id = c.id
        WHERE p.patient_id = ?
          AND (p.status IS NULL OR p.status = 'active')
          AND " . patient_consultation_record_visible_sql('c', 'cn') . "
        ORDER BY p.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$patientId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $prescriptions = [];
    foreach ($rows as $row) {
        $prescriptions[] = [
            'medication_name' => trim((string) ($row['medication_name'] ?? '')),
            'dosage' => trim((string) ($row['dosage'] ?? '')),
            'frequency' => trim((string) ($row['frequency'] ?? '')),
            'duration' => trim((string) ($row['duration'] ?? '')),
            'created_at' => trim((string) ($row['created_at'] ?? '')),
        ];
    }

    return ['shared' => true, 'denied' => false, 'prescriptions' => $prescriptions];
}
