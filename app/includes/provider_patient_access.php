<?php
/**
 * Provider ↔ patient/consultation access checks to prevent IDOR.
 */

declare(strict_types=1);

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

    return ['allowed' => false, 'message' => 'Access denied.'];
}

