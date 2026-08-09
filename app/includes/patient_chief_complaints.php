<?php
/**
 * Per-consultation chief complaint records — preserves registration complaint separately.
 */

declare(strict_types=1);

function patient_chief_complaints_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS patient_chief_complaints (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            patient_id INT UNSIGNED NOT NULL,
            complaint_text TEXT NOT NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'consultation_booking',
            triage_result_id INT UNSIGNED NULL,
            consultation_id INT UNSIGNED NULL,
            appointment_slot_id INT UNSIGNED NULL,
            registration_reference TEXT NULL,
            submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_pcc_patient (patient_id),
            KEY idx_pcc_consultation (consultation_id),
            KEY idx_pcc_triage (triage_result_id),
            KEY idx_pcc_submitted (submitted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $done = true;
}

/**
 * @return array{complaint:string,submitted_label:string,source:string}|null
 */
function patient_chief_complaint_for_consultation(PDO $pdo, int $consultationId): ?array
{
    if ($consultationId <= 0) {
        return null;
    }
    patient_chief_complaints_ensure_schema($pdo);
    $stmt = $pdo->prepare('
        SELECT complaint_text, source, submitted_at
        FROM patient_chief_complaints
        WHERE consultation_id = ?
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmt->execute([$consultationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $submittedAt = (string) ($row['submitted_at'] ?? '');

    return [
        'complaint'        => trim((string) ($row['complaint_text'] ?? '')),
        'submitted_label'  => $submittedAt !== '' ? date('M j, Y g:i A', strtotime($submittedAt)) : '',
        'source'           => (string) ($row['source'] ?? ''),
    ];
}

function patient_chief_complaint_record(
    PDO $pdo,
    int $patientId,
    string $complaintText,
    string $source = 'consultation_booking',
    ?int $triageResultId = null,
    ?int $consultationId = null,
    ?int $appointmentSlotId = null,
    ?string $registrationReference = null
): int {
    patient_chief_complaints_ensure_schema($pdo);

    $complaintText = trim($complaintText);
    if ($patientId <= 0 || $complaintText === '') {
        return 0;
    }

    $regRef = $registrationReference !== null ? trim($registrationReference) : null;
    if ($regRef === '') {
        $regRef = null;
    }

    $stmt = $pdo->prepare('
        INSERT INTO patient_chief_complaints
            (patient_id, complaint_text, source, triage_result_id, consultation_id,
             appointment_slot_id, registration_reference)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $patientId,
        $complaintText,
        $source,
        $triageResultId > 0 ? $triageResultId : null,
        $consultationId > 0 ? $consultationId : null,
        $appointmentSlotId > 0 ? $appointmentSlotId : null,
        $regRef,
    ]);

    return (int) $pdo->lastInsertId();
}

function patient_chief_complaint_registration_reference(PDO $pdo, int $patientId): string
{
    require_once __DIR__ . '/triage_assessment_schema.php';
    $pending = patient_registration_load_pending_complaint($pdo, $patientId);

    return trim((string) ($pending['complaint'] ?? ''));
}
