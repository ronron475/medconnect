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

/**
 * Normalize triage urgency from a triage_results row or registration string.
 */
function patient_portal_normalize_urgency(?string $triageLevel, ?string $classification, ?string $registrationUrgency = ''): string
{
    $raw = strtoupper(str_replace('_', '-', trim((string) ($classification ?? ''))));
    if ($raw === '' && $triageLevel !== null) {
        $raw = strtoupper(str_replace('_', '-', trim((string) $triageLevel)));
    }
    if ($raw === '' && $registrationUrgency !== null) {
        $raw = strtoupper(str_replace('_', '-', trim((string) $registrationUrgency)));
    }
    if ($raw === 'NON URGENT') {
        $raw = 'NON-URGENT';
    }
    if (str_contains($raw, 'EMERGENCY')) {
        return 'EMERGENCY';
    }
    if (str_contains($raw, 'URGENT') && !str_contains($raw, 'NON')) {
        return 'URGENT';
    }
    if ($raw !== '') {
        return 'NON-URGENT';
    }

    return '';
}

/**
 * Shared chief complaint for patient dashboard and book consultation (single source of truth).
 *
 * @return array{
 *   complaint:string,
 *   source:string,
 *   triage_id:int,
 *   urgency:string,
 *   triage_level:string,
 *   submitted_at:string,
 *   locked:bool
 * }
 */
function patient_portal_active_chief_complaint(PDO $pdo, int $patientId): array
{
    require_once __DIR__ . '/triage_assessment_schema.php';
    require_once __DIR__ . '/patient_booking_status.php';

    $empty = [
        'complaint'     => '',
        'source'        => '',
        'triage_id'     => 0,
        'urgency'       => '',
        'triage_level'  => '',
        'submitted_at'  => '',
        'locked'        => false,
    ];
    if ($patientId <= 0) {
        return $empty;
    }

    triage_assessment_ensure_schema($pdo);
    patient_chief_complaints_ensure_schema($pdo);

    $triageRow = patient_portal_find_active_triage_row($pdo, $patientId);

    $pccRow = null;
    try {
        $stmt = $pdo->prepare("
            SELECT complaint_text, source, triage_result_id, consultation_id, submitted_at
            FROM patient_chief_complaints
            WHERE patient_id = ?
              AND TRIM(COALESCE(complaint_text, '')) <> ''
            ORDER BY submitted_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$patientId]);
        $pccCandidate = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($pccCandidate && patient_chief_complaint_row_is_active($pdo, $patientId, $pccCandidate)) {
            $pccRow = $pccCandidate;
        }
    } catch (PDOException $e) {
        $pccRow = null;
    }

    $reg = patient_registration_load_pending_complaint($pdo, $patientId);
    $regUrgency = (string) ($reg['urgency'] ?? '');

    $triageTs = $triageRow ? strtotime((string) ($triageRow['assessed_at'] ?? '')) : 0;
    $pccTs = $pccRow ? strtotime((string) ($pccRow['submitted_at'] ?? '')) : 0;

    if ($triageRow && (!$pccRow || $triageTs >= $pccTs)) {
        $complaint = trim((string) ($triageRow['chief_complaint'] ?? ''));
        if ($complaint === '') {
            return $empty;
        }
        $triageLevel = (string) ($triageRow['triage_level'] ?? '');
        $classification = (string) ($triageRow['triage_classification'] ?? '');

        return [
            'complaint'    => $complaint,
            'source'       => 'triage',
            'triage_id'    => (int) ($triageRow['id'] ?? 0),
            'urgency'      => patient_portal_normalize_urgency($triageLevel, $classification, $regUrgency),
            'triage_level' => $triageLevel,
            'submitted_at' => (string) ($triageRow['assessed_at'] ?? ''),
            'locked'       => true,
        ];
    }

    if ($pccRow) {
        $complaint = trim((string) ($pccRow['complaint_text'] ?? ''));
        if ($complaint === '') {
            return $empty;
        }
        $triageId = (int) ($pccRow['triage_result_id'] ?? 0);
        $urgency = $regUrgency;
        $triageLevel = '';
        if ($triageId > 0) {
            try {
                $tStmt = $pdo->prepare('
                    SELECT triage_level, triage_classification
                    FROM triage_results
                    WHERE id = ? AND patient_id = ?
                    LIMIT 1
                ');
                $tStmt->execute([$triageId, $patientId]);
                $tRow = $tStmt->fetch(PDO::FETCH_ASSOC);
                if ($tRow) {
                    $triageLevel = (string) ($tRow['triage_level'] ?? '');
                    $urgency = patient_portal_normalize_urgency(
                        $triageLevel,
                        (string) ($tRow['triage_classification'] ?? ''),
                        $regUrgency
                    );
                }
            } catch (PDOException $e) { /* non-fatal */ }
        }

        return [
            'complaint'    => $complaint,
            'source'       => trim((string) ($pccRow['source'] ?? 'portal')),
            'triage_id'    => $triageId,
            'urgency'      => $urgency,
            'triage_level' => $triageLevel,
            'submitted_at' => (string) ($pccRow['submitted_at'] ?? ''),
            'locked'       => true,
        ];
    }

    $regComplaint = trim((string) ($reg['complaint'] ?? ''));
    if ($regComplaint !== '') {
        if (patient_portal_has_completed_visit($pdo, $patientId) && !$triageRow) {
            return $empty;
        }

        return [
            'complaint'    => $regComplaint,
            'source'       => 'registration',
            'triage_id'    => 0,
            'urgency'      => patient_portal_normalize_urgency('', '', $regUrgency),
            'triage_level' => '',
            'submitted_at' => '',
            'locked'       => true,
        ];
    }

    return $empty;
}

/**
 * Human-readable label for where the active chief complaint came from.
 */
function patient_portal_complaint_source_label(string $source): string
{
    return match (trim($source)) {
        'registration' => 'from registration',
        'consultation_booking' => 'on file',
        'care_tips_review' => 'on file',
        'triage' => 'on file',
        default => 'on file',
    };
}

/**
 * Use an active (locked) chief complaint while a case is open.
 * After COMPLETED/CANCELLED visits, do not reuse previous complaints —
 * the patient must enter a new chief complaint for a new consultation.
 */
function patient_portal_resolve_chief_complaint(PDO $pdo, int $patientId, string $submittedComplaint): string
{
    $submitted = trim($submittedComplaint);
    $active = patient_portal_active_chief_complaint($pdo, $patientId);
    if (!empty($active['locked']) && ($active['complaint'] ?? '') !== '') {
        // Mid-flow lock only — never pull a completed visit's complaint into a new case.
        return (string) $active['complaint'];
    }
    if ($submitted !== '') {
        return $submitted;
    }

    // Registration seed only when the patient has never completed a visit
    // and has no active triage/case yet.
    if (patient_portal_has_completed_visit($pdo, $patientId)) {
        return '';
    }

    $registrationComplaint = patient_chief_complaint_registration_reference($pdo, $patientId);

    return $registrationComplaint !== '' ? $registrationComplaint : '';
}
