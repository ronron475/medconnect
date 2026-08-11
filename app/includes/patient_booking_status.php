<?php
declare(strict_types=1);

/**
 * Patient booking status — shared by sidebar badges and Book Consultation visit history.
 */

/**
 * Active visit booked for today or a future date.
 */
function patient_portal_has_upcoming_consultation(PDO $pdo, int $patientId): bool
{
    if ($patientId <= 0) {
        return false;
    }
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
            return false;
        }
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM consultations
            WHERE patient_id = ?
              AND consult_date >= CURDATE()
              AND LOWER(COALESCE(status, '')) IN (
                'pending', 'scheduled', 'waiting', 'in_consultation'
              )
        ");
        $stmt->execute([$patientId]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Whether a triage row already has a linked consultation on or after its assessment date.
 *
 * @return 'booked'|'completed'|'none'
 */
function patient_triage_row_booking_state(PDO $pdo, int $patientId, string $assessedAt): string
{
    if ($patientId <= 0 || trim($assessedAt) === '') {
        return 'none';
    }
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
            return 'none';
        }
        $stmt = $pdo->prepare("
            SELECT LOWER(COALESCE(status, '')) AS status
            FROM consultations
            WHERE patient_id = ?
              AND consult_date >= DATE(?)
              AND LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'canceled')
            ORDER BY consult_date DESC, consult_time DESC
            LIMIT 1
        ");
        $stmt->execute([$patientId, $assessedAt]);
        $status = (string) ($stmt->fetchColumn() ?: '');

        if (in_array($status, ['pending', 'scheduled', 'waiting', 'in_consultation'], true)) {
            return 'booked';
        }
        if ($status === 'completed') {
            return 'completed';
        }
    } catch (Throwable $e) {
        return 'none';
    }

    return 'none';
}
