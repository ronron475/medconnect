<?php
/**
 * BHW Records consultation history.
 *
 * A visit is included only when this patient has a follow-up that is not
 * cancelled and points at that consultation. Follow-ups stay shared by every
 * BHW in the patient's barangay. Diagnosis, recommendation, chief complaint,
 * triage, and SOAP notes are not selected.
 */
declare(strict_types=1);

/**
 * @param array{barangay_id?:int,barangay_name?:string} $ctx
 * @return list<array{consult_date:string,consult_time:string,status:string,provider_name:string,instructions:string}>
 */
function bhw_records_consultations(PDO $pdo, array $ctx, int $patientId): array
{
    if ($patientId <= 0 || !function_exists('bhw_assert_patient_in_sector') || !bhw_assert_patient_in_sector($pdo, $ctx, $patientId)) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT c.consult_date, c.consult_time, c.status,
                   COALESCE(prv.first_name, '') AS provider_first_name,
                   COALESCE(prv.last_name, '') AS provider_last_name,
                   COALESCE(c.provider_name, '') AS provider_name_stored,
                   f.message AS followup_message,
                   f.notes AS followup_notes
            FROM consultations c
            INNER JOIN followups f ON f.id = (
                SELECT f2.id
                FROM followups f2
                WHERE f2.consultation_id = c.id
                  AND f2.patient_id = c.patient_id
                  AND LOWER(COALESCE(f2.status, '')) NOT IN ('cancelled', 'canceled')
                ORDER BY f2.id DESC
                LIMIT 1
            )
            LEFT JOIN users prv ON prv.id = c.provider_id
            WHERE c.patient_id = ?
            ORDER BY c.consult_date DESC, c.consult_time DESC, c.id DESC
            LIMIT 100
        ");
        $stmt->execute([$patientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }

    $history = [];
    foreach ($rows as $row) {
        $provider = trim(((string) ($row['provider_first_name'] ?? '')) . ' ' . ((string) ($row['provider_last_name'] ?? '')));
        if ($provider === '') {
            $provider = trim((string) ($row['provider_name_stored'] ?? ''));
        }
        $instructions = trim((string) ($row['followup_message'] ?? ''));
        if ($instructions === '') {
            $instructions = trim((string) ($row['followup_notes'] ?? ''));
        }
        $history[] = [
            'consult_date' => trim((string) ($row['consult_date'] ?? '')),
            'consult_time' => trim((string) ($row['consult_time'] ?? '')),
            'status' => trim((string) ($row['status'] ?? '')),
            'provider_name' => $provider,
            'instructions' => $instructions,
        ];
    }

    return $history;
}
