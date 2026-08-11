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

    // Prefer the consultation that is still in the current cycle (future start
    // or live in_consultation). Never use ORDER BY alone for "current" complaint.
    try {
        $openStmt = $pdo->prepare("
            SELECT c.id, c.status, c.consult_date, c.consult_time,
                   s.slot_date, s.start_time AS slot_start
            FROM consultations c
            LEFT JOIN appointment_slots s
              ON s.consultation_id = c.id AND s.status = 'booked'
            WHERE c.patient_id = ?
              AND LOWER(COALESCE(c.status, '')) IN (
                'pending', 'scheduled', 'waiting', 'in_consultation'
              )
            ORDER BY c.id DESC
            LIMIT 20
        ");
        $openStmt->execute([$patientId]);
        $openRows = $openStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $activeConsult = patient_portal_select_active_consultation($openRows);
        if ($activeConsult) {
            $consultId = (int) ($activeConsult['id'] ?? 0);
            $pccForConsult = $consultId > 0
                ? patient_chief_complaint_for_consultation($pdo, $consultId)
                : null;
            $complaint = trim((string) ($pccForConsult['complaint'] ?? ''));
            $triageId = 0;
            $urgency = '';
            $triageLevel = '';
            $submittedAt = '';
            $source = trim((string) ($pccForConsult['source'] ?? 'consultation_booking'));

            if ($complaint === '' && $consultId > 0) {
                try {
                    $link = $pdo->prepare('
                        SELECT triage_result_id
                        FROM consultations
                        WHERE id = ? AND patient_id = ?
                        LIMIT 1
                    ');
                    $link->execute([$consultId, $patientId]);
                    $triageId = (int) ($link->fetchColumn() ?: 0);
                } catch (Throwable $e) {
                    $triageId = 0;
                }
                if ($triageId > 0) {
                    try {
                        $tStmt = $pdo->prepare('
                            SELECT chief_complaint, triage_level, triage_classification, assessed_at
                            FROM triage_results
                            WHERE id = ? AND patient_id = ?
                            LIMIT 1
                        ');
                        $tStmt->execute([$triageId, $patientId]);
                        $tRow = $tStmt->fetch(PDO::FETCH_ASSOC);
                        if ($tRow) {
                            $complaint = trim((string) ($tRow['chief_complaint'] ?? ''));
                            $triageLevel = (string) ($tRow['triage_level'] ?? '');
                            $urgency = patient_portal_normalize_urgency(
                                $triageLevel,
                                (string) ($tRow['triage_classification'] ?? ''),
                                ''
                            );
                            $submittedAt = (string) ($tRow['assessed_at'] ?? '');
                            $source = 'triage';
                        }
                    } catch (Throwable $e) {
                        // non-fatal
                    }
                }
            }

            if ($complaint !== '') {
                return [
                    'complaint'    => $complaint,
                    'source'       => $source !== '' ? $source : 'consultation_booking',
                    'triage_id'    => $triageId,
                    'urgency'      => $urgency,
                    'triage_level' => $triageLevel,
                    'submitted_at' => $submittedAt,
                    'locked'       => true,
                ];
            }
        }
    } catch (Throwable $e) {
        // Fall through to triage / PCC / registration paths.
    }

    $triageRow = patient_portal_find_active_triage_row($pdo, $patientId);

    // Approved care tips must remain readable in the Care Tips chatbot, but they
    // must NOT permanently lock the dashboard chief complaint. Once there is no
    // live/future consultation, the patient may start a NEW complaint cycle.
    if ($triageRow) {
        $recStatus = strtolower(trim((string) ($triageRow['recommendation_status'] ?? '')));
        if ($recStatus === 'approved' && !patient_portal_has_open_consultation($pdo, $patientId)) {
            $triageRow = null;
        }
    }

    $pccRow = null;
    try {
        // Walk recent rows until we find one still tied to an active cycle.
        $stmt = $pdo->prepare("
            SELECT complaint_text, source, triage_result_id, consultation_id, submitted_at
            FROM patient_chief_complaints
            WHERE patient_id = ?
              AND TRIM(COALESCE(complaint_text, '')) <> ''
            ORDER BY submitted_at DESC, id DESC
            LIMIT 10
        ");
        $stmt->execute([$patientId]);
        while ($pccCandidate = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (patient_chief_complaint_row_is_active($pdo, $patientId, $pccCandidate)) {
                $pccRow = $pccCandidate;
                break;
            }
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
        // Registration complaint is a one-time seed for the first cycle only.
        // After any finished/stale visit (or when a new cycle should start), do not lock it.
        // Also unlock when approved care tips remain but there is no live consultation —
        // otherwise patients who cancelled after tips stay stuck on the registration text.
        if (patient_portal_has_completed_visit($pdo, $patientId) && !$triageRow) {
            return $empty;
        }
        if (!$triageRow && patient_portal_has_stale_or_finished_consultation($pdo, $patientId)) {
            return $empty;
        }
        if (!$triageRow && !patient_portal_has_open_consultation($pdo, $patientId)) {
            // Prefer an editable new-complaint flow over permanently locking the
            // registration seed when the patient is not mid-review / mid-visit.
            $hasPriorTriage = false;
            try {
                $prior = $pdo->prepare('
                    SELECT 1
                    FROM triage_results
                    WHERE patient_id = ?
                      AND TRIM(COALESCE(chief_complaint, \'\')) <> \'\'
                    LIMIT 1
                ');
                $prior->execute([$patientId]);
                $hasPriorTriage = (bool) $prior->fetchColumn();
            } catch (Throwable $e) {
                $hasPriorTriage = false;
            }
            if ($hasPriorTriage) {
                return $empty;
            }
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

    // Registration seed only for the first cycle — never after a finished
    // or past-due consultation that should start a new complaint.
    if (patient_portal_has_completed_visit($pdo, $patientId)
        || patient_portal_has_stale_or_finished_consultation($pdo, $patientId)) {
        return '';
    }

    $registrationComplaint = patient_chief_complaint_registration_reference($pdo, $patientId);

    return $registrationComplaint !== '' ? $registrationComplaint : '';
}
