<?php
/**
 * Urgent Follow-up Workflow — post-consultation NLP-triaged follow-up cases.
 *
 * Integrates with the centralized ChiefComplaintNlpService without duplicating NLP logic.
 * Does not interrupt ongoing consultations or alter existing appointment scheduling.
 */

declare(strict_types=1);

require_once __DIR__ . '/triage_assessment_schema.php';
require_once __DIR__ . '/triage_provider_assignment.php';
require_once __DIR__ . '/appointment_slots.php';
require_once __DIR__ . '/consultation_expiry.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/notification_events.php';
require_once __DIR__ . '/../core/TriageLevelService.php';

/**
 * Ensure urgent_followup_cases table exists (runtime bootstrap).
 */
function urgent_followup_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $migration = dirname(__DIR__, 2) . '/database/migrations/2026_08_06_urgent_followup_workflow.sql';
    if (is_file($migration)) {
        try {
            $sql = (string) file_get_contents($migration);
            if ($sql !== '') {
                $pdo->exec($sql);
            }
        } catch (PDOException $e) {
            error_log('urgent_followup_ensure_schema: ' . $e->getMessage());
        }
    }

    $done = true;
}

/**
 * Map triage classification to queue priority (lower = higher priority).
 */
function urgent_followup_queue_priority(string $classification): int
{
    return match (strtoupper(trim($classification))) {
        'EMERGENCY'   => 1,
        'URGENT'      => 2,
        default       => 3,
    };
}

/**
 * Completed consultations eligible for follow-up (no open follow-up case).
 *
 * @return list<array<string, mixed>>
 */
function urgent_followup_eligible_consultations(PDO $pdo, int $patientId): array
{
    urgent_followup_ensure_schema($pdo);
    if ($patientId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.provider_id,
            c.provider_name,
            c.consult_date,
            c.consult_time,
            c.consult_type,
            c.diagnosis,
            tr.chief_complaint AS triage_complaint,
            tr.english_complaint
        FROM consultations c
        LEFT JOIN triage_results tr ON tr.id = c.triage_result_id
        WHERE c.patient_id = ?
          AND c.status = 'completed'
          AND c.consult_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
          AND NOT EXISTS (
              SELECT 1 FROM urgent_followup_cases ufc
              WHERE ufc.original_consultation_id = c.id
                AND ufc.status IN ('pending', 'waiting', 'accepted', 'in_consultation', 'booked')
          )
        ORDER BY c.consult_date DESC, c.consult_time DESC
        LIMIT 20
    ");
    $stmt->execute([$patientId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $complaint = trim((string) ($row['triage_complaint'] ?? ''));
        if ($complaint === '') {
            $complaint = trim((string) ($row['english_complaint'] ?? ''));
        }
        if ($complaint === '') {
            $complaint = trim((string) ($row['consult_type'] ?? 'General Consultation'));
        }
        $row['previous_chief_complaint'] = $complaint;
    }
    unset($row);

    return $rows;
}

/**
 * True when provider has an active in_consultation visit (must not be interrupted).
 */
function urgent_followup_provider_in_consultation(PDO $pdo, int $providerId): bool
{
    if ($providerId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM consultations
        WHERE provider_id = ?
          AND status = 'in_consultation'
    ");
    $stmt->execute([$providerId]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * True when provider has no active consultation and has bookable slots today.
 */
function urgent_followup_provider_is_available(PDO $pdo, int $providerId): bool
{
    if ($providerId <= 0) {
        return false;
    }
    if (urgent_followup_provider_in_consultation($pdo, $providerId)) {
        return false;
    }

    require_once __DIR__ . '/triage_provider_assignment.php';

    return triage_provider_bookable_slot_count_today($pdo, $providerId) > 0
        || !urgent_followup_provider_has_waiting_queue($pdo, $providerId);
}

/**
 * Whether provider already has scheduled/waiting patients today (busy schedule).
 */
function urgent_followup_provider_has_waiting_queue(PDO $pdo, int $providerId): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM consultations
        WHERE provider_id = ?
          AND consult_date = CURDATE()
          AND status IN ('scheduled', 'pending', 'waiting')
    ");
    $stmt->execute([$providerId]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Find an available provider for urgent reassignment (workload-balanced).
 */
function urgent_followup_find_available_provider(PDO $pdo, int $preferProviderId, int $patientId): int
{
    if ($preferProviderId > 0 && urgent_followup_provider_is_available($pdo, $preferProviderId)) {
        return $preferProviderId;
    }

    $sql = "
        SELECT u.id,
               (
                 SELECT COUNT(*) FROM urgent_followup_cases ufc
                 WHERE ufc.provider_id = u.id
                   AND ufc.status IN ('waiting', 'accepted')
                   AND ufc.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
               ) AS open_cases,
               (
                 SELECT COUNT(*) FROM consultations c
                 WHERE c.provider_id = u.id AND c.status = 'in_consultation'
               ) AS in_consult
        FROM users u
        WHERE u.role = 'provider' AND u.is_active = 1
        HAVING in_consult = 0
        ORDER BY open_cases ASC, u.id ASC
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0 && $id !== $preferProviderId) {
            return $id;
        }
    }

    return $preferProviderId > 0 ? $preferProviderId : triage_fallback_provider_id($pdo, $patientId);
}

/**
 * Persist triage result for follow-up assessment (reuses existing schema).
 */
function urgent_followup_save_triage(
    PDO $pdo,
    int $patientId,
    string $complaint,
    array $symptomList,
    array $assessment
): int {
    triage_assessment_ensure_schema($pdo);

    $level = (string) ($assessment['triage']['db_level'] ?? $assessment['db_level'] ?? '3');
    $label = (string) ($assessment['triage']['urgency_label'] ?? $assessment['urgency_label'] ?? 'Routine');
    $triageLevel = TriageLevelService::fromAssessment($assessment);

    $stmt = $pdo->prepare("
        INSERT INTO triage_results
            (patient_id, symptoms, chief_complaint, level, urgency_label, status, assessed_at,
             confidence_score, severity, triage_level, triage_classification, english_complaint,
             detected_symptoms_json, possible_conditions_json, recommendations,
             assessment_payload, engine, outcome)
        VALUES (?, ?, ?, ?, ?, 'completed', NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'urgent_followup')
    ");
    $params = [
        $patientId,
        json_encode($symptomList, JSON_UNESCAPED_UNICODE),
        $complaint,
        $level,
        $label,
        (int) ($assessment['confidence']['score'] ?? 0),
        (string) ($assessment['severity']['severity'] ?? ''),
        $triageLevel,
        (string) ($assessment['triage']['triage_classification'] ?? ''),
        (string) ($assessment['english_translation'] ?? ''),
        json_encode($assessment['detected_symptoms'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($assessment['possible_conditions'] ?? [], JSON_UNESCAPED_UNICODE),
        implode("\n", $assessment['recommendations'] ?? []),
        json_encode($assessment, JSON_UNESCAPED_UNICODE),
        (string) ($assessment['engine'] ?? 'ChiefComplaintNlpService'),
    ];

    try {
        $stmt->execute($params);
    } catch (PDOException $e) {
        $stmt = $pdo->prepare("
            INSERT INTO triage_results
                (patient_id, symptoms, chief_complaint, level, urgency_label, status, assessed_at,
                 confidence_score, severity, triage_level, triage_classification, english_complaint,
                 detected_symptoms_json, possible_conditions_json, recommendations,
                 assessment_payload, engine)
            VALUES (?, ?, ?, ?, ?, 'completed', NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute($params);
    }

    return (int) $pdo->lastInsertId();
}

/**
 * Log follow-up event to patient EMR audit trail.
 *
 * @param array<string, mixed>|null $meta
 */
function urgent_followup_audit(
    PDO $pdo,
    int $patientId,
    string $actionType,
    string $description,
    ?array $meta = null
): void {
    audit_log($pdo, [
        'patient_id'  => $patientId,
        'action_type' => $actionType,
        'description' => $description,
        'meta'        => $meta,
    ]);
}

/**
 * Submit a follow-up request after a completed consultation.
 *
 * @return array<string, mixed>
 */
function urgent_followup_submit(
    PDO $pdo,
    int $patientId,
    int $consultationId,
    string $complaint,
    array $symptomList = []
): array {
    urgent_followup_ensure_schema($pdo);
    triage_assessment_ensure_schema($pdo);

    if ($patientId <= 0 || $consultationId <= 0) {
        throw new InvalidArgumentException('Invalid patient or consultation.');
    }
    if (trim($complaint) === '' && $symptomList === []) {
        throw new InvalidArgumentException('Please describe your updated chief complaint.');
    }

    $stmt = $pdo->prepare("
        SELECT c.id, c.patient_id, c.provider_id, c.provider_name, c.consult_date, c.consult_time,
               c.status, c.consult_type, tr.chief_complaint AS triage_complaint,
               tr.english_complaint
        FROM consultations c
        LEFT JOIN triage_results tr ON tr.id = c.triage_result_id
        WHERE c.id = ? AND c.patient_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$consultationId, $patientId]);
    $consult = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$consult) {
        throw new RuntimeException('Consultation not found.');
    }
    if ((string) ($consult['status'] ?? '') !== 'completed') {
        throw new RuntimeException('Follow-up requests are only available after a completed video consultation.');
    }

    $openCheck = $pdo->prepare("
        SELECT id FROM urgent_followup_cases
        WHERE original_consultation_id = ?
          AND status IN ('pending', 'waiting', 'accepted', 'in_consultation', 'booked')
        LIMIT 1
    ");
    $openCheck->execute([$consultationId]);
    if ($openCheck->fetchColumn()) {
        throw new RuntimeException('You already have an open follow-up request for this consultation.');
    }

    $assessment = ChiefComplaintNlpService::assessWithFallback($complaint, $symptomList);

    $triageLevel = TriageLevelService::fromAssessment($assessment);
    $classification = strtoupper((string) ($assessment['triage']['triage_classification'] ?? ''));
    if ($classification === '') {
        $classification = match ($triageLevel) {
            TriageLevelService::EMERGENCY => 'EMERGENCY',
            TriageLevelService::URGENT    => 'URGENT',
            default                       => 'NON_URGENT',
        };
    }
    $triageDisplay = str_replace('_', '-', $classification);
    if ($triageDisplay === 'NON-URGENT') {
        $triageDisplay = 'NON-URGENT';
    }
    $confidence = (float) ($assessment['confidence']['score'] ?? 0);

    $previousComplaint = trim((string) ($consult['triage_complaint'] ?? ''));
    if ($previousComplaint === '') {
        $previousComplaint = trim((string) ($consult['english_complaint'] ?? ''));
    }
    if ($previousComplaint === '') {
        $previousComplaint = trim((string) ($consult['consult_type'] ?? 'General Consultation'));
    }

    $originalProviderId = (int) ($consult['provider_id'] ?? 0);
    $triageId = urgent_followup_save_triage($pdo, $patientId, $complaint, $symptomList, $assessment);

    $nameStmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) FROM users WHERE id = ? LIMIT 1');
    $nameStmt->execute([$patientId]);
    $patientName = trim((string) ($nameStmt->fetchColumn() ?: 'Patient'));

    $isEmergency = $triageLevel === TriageLevelService::EMERGENCY
        || $classification === 'EMERGENCY';
    $isUrgent = !$isEmergency && (
        $triageLevel === TriageLevelService::URGENT || $classification === 'URGENT'
    );

    $assignedProviderId = $originalProviderId;
    $reassignedFrom = null;
    $providerAvailable = false;
    $canStartImmediately = false;
    $status = 'pending';
    $referralId = 0;

    if ($isEmergency) {
        $status = 'emergency_referral';
        $reason = patient_emergency_referral_reason($assessment, $complaint, $symptomList);
        if ($assignedProviderId > 0) {
            $referralId = patient_create_emergency_hospital_referral($pdo, $patientId, $assignedProviderId, $reason);
        }
        try {
            $pdo->prepare("UPDATE triage_results SET outcome = 'emergency_referral' WHERE id = ?")
                ->execute([$triageId]);
        } catch (PDOException $e) { /* optional column */ }
    } elseif ($isUrgent) {
        $status = 'waiting';
        $providerAvailable = urgent_followup_provider_is_available($pdo, $originalProviderId);

        if (!$providerAvailable) {
            $alternateId = urgent_followup_find_available_provider($pdo, $originalProviderId, $patientId);
            if ($alternateId > 0 && $alternateId !== $originalProviderId
                && urgent_followup_provider_is_available($pdo, $alternateId)) {
                $reassignedFrom = $originalProviderId;
                $assignedProviderId = $alternateId;
            }
        } else {
            $canStartImmediately = true;
        }
    } else {
        $status = 'pending';
    }

    $queuePriority = urgent_followup_queue_priority($classification);

    $ins = $pdo->prepare("
        INSERT INTO urgent_followup_cases
            (patient_id, provider_id, original_consultation_id, triage_result_id,
             previous_chief_complaint, updated_chief_complaint, previous_consult_date,
             triage_classification, triage_display, confidence_score, assessment_payload,
             status, queue_priority, provider_available_at_submit, can_start_immediately,
             reassigned_from_provider_id, referral_id, notified_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $ins->execute([
        $patientId,
        $assignedProviderId,
        $consultationId,
        $triageId,
        $previousComplaint,
        $complaint,
        $consult['consult_date'] ?? null,
        $classification,
        $triageDisplay,
        $confidence,
        json_encode($assessment, JSON_UNESCAPED_UNICODE),
        $status,
        $queuePriority,
        $providerAvailable ? 1 : 0,
        $canStartImmediately ? 1 : 0,
        $reassignedFrom,
        $referralId > 0 ? $referralId : null,
    ]);
    $caseId = (int) $pdo->lastInsertId();

    urgent_followup_audit($pdo, $patientId, AuditAction::URGENT_FOLLOWUP_SUBMITTED, sprintf(
        'Follow-up request submitted after consultation #%d. AI classification: %s (confidence %.0f%%).',
        $consultationId,
        $triageDisplay,
        $confidence
    ), [
        'case_id'              => $caseId,
        'consultation_id'      => $consultationId,
        'triage_id'            => $triageId,
        'classification'       => $classification,
        'confidence'           => $confidence,
        'provider_id'          => $assignedProviderId,
        'reassigned_from'      => $reassignedFrom,
        'previous_complaint'   => $previousComplaint,
        'updated_complaint'    => $complaint,
    ]);

    $providerDisplayName = triage_provider_display_name($pdo, $assignedProviderId);
    $prevDateLabel = !empty($consult['consult_date'])
        ? date('M j, Y', strtotime((string) $consult['consult_date']))
        : 'N/A';

    if ($isEmergency) {
        NotificationEvents::urgentFollowupEmergencyReferral(
            $pdo,
            $assignedProviderId,
            $patientId,
            $patientName,
            $caseId,
            $prevDateLabel,
            $previousComplaint,
            $complaint,
            $triageDisplay,
            $confidence
        );
        urgent_followup_audit($pdo, $patientId, AuditAction::URGENT_FOLLOWUP_EMERGENCY_REFERRAL, sprintf(
            'Patient advised to seek emergency care. Case #%d marked Emergency Referral.',
            $caseId
        ), ['case_id' => $caseId, 'referral_id' => $referralId]);
    } elseif ($isUrgent) {
        NotificationEvents::urgentFollowupCaseCreated(
            $pdo,
            $assignedProviderId,
            $patientId,
            $patientName,
            $caseId,
            $prevDateLabel,
            $previousComplaint,
            $complaint,
            $triageDisplay,
            $confidence,
            $canStartImmediately,
            $reassignedFrom !== null
        );
        urgent_followup_audit($pdo, $patientId, AuditAction::URGENT_FOLLOWUP_URGENT_QUEUED, sprintf(
            'Urgent follow-up case #%d queued for provider. Immediate start: %s.',
            $caseId,
            $canStartImmediately ? 'yes' : 'no'
        ), ['case_id' => $caseId, 'can_start_immediately' => $canStartImmediately]);
    } else {
        urgent_followup_audit($pdo, $patientId, AuditAction::URGENT_FOLLOWUP_NON_URGENT, sprintf(
            'Non-urgent follow-up case #%d — patient may book a normal appointment.',
            $caseId
        ), ['case_id' => $caseId]);
    }

    return [
        'case_id'               => $caseId,
        'triage_id'             => $triageId,
        'classification'        => $classification,
        'triage_display'        => $triageDisplay,
        'confidence'            => $confidence,
        'emergency'             => $isEmergency,
        'urgent'                => $isUrgent,
        'non_urgent'            => !$isEmergency && !$isUrgent,
        'provider_id'           => $assignedProviderId,
        'provider_name'         => $providerDisplayName,
        'can_book_followup'     => !$isEmergency && !$isUrgent,
        'can_start_immediately' => $canStartImmediately,
        'referral_id'           => $referralId,
        'status'                => $status,
        'previous_complaint'    => $previousComplaint,
        'updated_complaint'     => $complaint,
        'previous_consult_date' => $prevDateLabel,
        'reassigned'            => $reassignedFrom !== null,
        'emergency_message'     => $isEmergency
            ? 'Emergency symptoms detected. Do not wait for an appointment. Proceed to the nearest Emergency Department or call your local emergency services immediately.'
            : null,
    ];
}

/**
 * Book a non-urgent follow-up appointment (does not interrupt provider schedule).
 *
 * @return array<string, mixed>
 */
function urgent_followup_book_appointment(PDO $pdo, int $patientId, int $caseId, int $slotId): array
{
    urgent_followup_ensure_schema($pdo);
    consultations_auto_expire($pdo, $patientId);

    $caseStmt = $pdo->prepare("
        SELECT * FROM urgent_followup_cases
        WHERE id = ? AND patient_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $caseStmt->execute([$caseId, $patientId]);
    $case = $caseStmt->fetch(PDO::FETCH_ASSOC);

    if (!$case) {
        throw new RuntimeException('Follow-up case not found.');
    }
    if ((string) ($case['status'] ?? '') !== 'pending') {
        throw new RuntimeException('This follow-up case cannot be booked.');
    }
    if (strtoupper((string) ($case['triage_classification'] ?? '')) !== 'NON_URGENT') {
        throw new RuntimeException('Only non-urgent follow-ups can be booked as a normal appointment.');
    }
    if ($slotId <= 0) {
        throw new RuntimeException('Please select an available appointment slot.');
    }

    $providerId = (int) ($case['provider_id'] ?? 0);
    triage_assert_patient_may_book_provider($pdo, $patientId, $providerId);

    $slot_stmt = $pdo->prepare("
        SELECT s.id, s.provider_id, s.slot_date, s.start_time, s.end_time, s.status,
               CONCAT(u.first_name, ' ', u.last_name) AS provider_name
        FROM appointment_slots s
        JOIN users u ON u.id = s.provider_id
        WHERE s.id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $slot_stmt->execute([$slotId]);
    $slot = $slot_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$slot || $slot['status'] !== 'available') {
        throw new RuntimeException('That appointment slot is no longer available.');
    }
    if ((int) ($slot['provider_id'] ?? 0) !== $providerId) {
        throw new RuntimeException('Please book with your assigned doctor from the previous consultation.');
    }
    if (!appointment_slot_is_today((string) $slot['slot_date'])) {
        throw new RuntimeException('Appointments can only be booked for today.');
    }
    if (!appointment_slot_is_bookable((string) $slot['slot_date'], (string) $slot['start_time'], (string) $slot['end_time'])) {
        throw new RuntimeException('That appointment time has already passed.');
    }

    $existing_stmt = $pdo->prepare("
        SELECT id, status FROM consultations
        WHERE patient_id = ? AND status = 'in_consultation'
        LIMIT 1
    ");
    $existing_stmt->execute([$patientId]);
    if ($existing_stmt->fetchColumn()) {
        throw new RuntimeException('You have a consultation in progress — finish it before booking.');
    }

    $complaint = trim((string) ($case['updated_chief_complaint'] ?? 'Follow-up'));
    $triageId = (int) ($case['triage_result_id'] ?? 0);
    $consult_date = (string) $slot['slot_date'];
    $consult_time = (string) $slot['start_time'];
    $provider_name = (string) $slot['provider_name'];

    $consultCols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN);
    $hasTriageLink = in_array('triage_result_id', $consultCols, true);
    $hasPriorityCol = in_array('consult_priority', $consultCols, true);

    if ($hasTriageLink && $hasPriorityCol) {
        $pdo->prepare("
            INSERT INTO consultations
                (patient_id, provider_id, provider_name, consult_date, consult_time,
                 consult_type, status, triage_result_id, consult_priority)
            VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?, 'standard')
        ")->execute([$patientId, $providerId, $provider_name, $consult_date, $consult_time, $complaint, $triageId]);
    } elseif ($hasTriageLink) {
        $pdo->prepare("
            INSERT INTO consultations
                (patient_id, provider_id, provider_name, consult_date, consult_time,
                 consult_type, status, triage_result_id)
            VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?)
        ")->execute([$patientId, $providerId, $provider_name, $consult_date, $consult_time, $complaint, $triageId]);
    } else {
        $pdo->prepare("
            INSERT INTO consultations
                (patient_id, provider_id, provider_name, consult_date, consult_time,
                 consult_type, status)
            VALUES (?, ?, ?, ?, ?, ?, 'scheduled')
        ")->execute([$patientId, $providerId, $provider_name, $consult_date, $consult_time, $complaint]);
    }

    $consultationId = (int) $pdo->lastInsertId();

    $pdo->prepare("
        UPDATE appointment_slots
        SET status = 'booked', patient_id = ?, consultation_id = ?
        WHERE id = ?
    ")->execute([$patientId, $consultationId, $slotId]);

    $pdo->prepare("
        UPDATE urgent_followup_cases
        SET status = 'booked', followup_consultation_id = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([$consultationId, $caseId]);

    urgent_followup_audit($pdo, $patientId, AuditAction::URGENT_FOLLOWUP_BOOKED, sprintf(
        'Non-urgent follow-up appointment booked for %s at %s (case #%d).',
        date('M j, Y', strtotime($consult_date)),
        date('g:i A', strtotime($consult_time)),
        $caseId
    ), [
        'case_id'         => $caseId,
        'consultation_id' => $consultationId,
        'slot_id'         => $slotId,
    ]);

    $when = date('M j, Y', strtotime($consult_date)) . ' at ' . date('g:i A', strtotime($consult_time));
    try {
        NotificationEvents::appointmentCreated($pdo, $consultationId, $patientId, $providerId, $when, $patientId);
    } catch (Throwable $e) {
        error_log('urgent_followup_book notify: ' . $e->getMessage());
    }

    return [
        'booked'          => true,
        'consultation_id' => $consultationId,
        'case_id'         => $caseId,
        'consult_date'    => $consult_date,
        'consult_time'    => $consult_time,
        'provider_name'   => $provider_name,
    ];
}

/**
 * Load urgent follow-up queue for provider dashboard.
 *
 * @return list<array<string, mixed>>
 */
function urgent_followup_queue_load(PDO $pdo, int $providerId): array
{
    urgent_followup_ensure_schema($pdo);
    if ($providerId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT
            ufc.*,
            u.first_name,
            u.last_name,
            u.email,
            pr.age,
            orig.consult_date AS orig_consult_date,
            orig.consult_time AS orig_consult_time
        FROM urgent_followup_cases ufc
        JOIN users u ON u.id = ufc.patient_id
        LEFT JOIN patient_registrations pr ON pr.email = u.email
        LEFT JOIN consultations orig ON orig.id = ufc.original_consultation_id
        WHERE ufc.provider_id = ?
          AND ufc.status IN ('waiting', 'accepted', 'emergency_referral')
          AND ufc.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY ufc.queue_priority ASC, ufc.created_at ASC
    ");
    $stmt->execute([$providerId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Provider accepts an urgent follow-up case and optionally creates a consultation.
 *
 * @return array<string, mixed>
 */
function urgent_followup_accept(PDO $pdo, int $providerId, int $caseId, bool $startVideo = false): array
{
    urgent_followup_ensure_schema($pdo);
    require_once __DIR__ . '/clinical_tables.php';
    clinical_tables_ensure($pdo);

    if (urgent_followup_provider_in_consultation($pdo, $providerId)) {
        throw new RuntimeException('Finish your current consultation before accepting an urgent follow-up.');
    }

    $stmt = $pdo->prepare("
        SELECT ufc.*, u.first_name, u.last_name
        FROM urgent_followup_cases ufc
        JOIN users u ON u.id = ufc.patient_id
        WHERE ufc.id = ? AND ufc.provider_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$caseId, $providerId]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$case) {
        throw new RuntimeException('Urgent follow-up case not found.');
    }
    if ((string) ($case['status'] ?? '') === 'emergency_referral') {
        throw new RuntimeException('This case is an emergency referral — patient has been advised to seek emergency care.');
    }
    if (!in_array((string) ($case['status'] ?? ''), ['waiting', 'accepted'], true)) {
        throw new RuntimeException('This follow-up case is no longer available.');
    }

    $patientId = (int) ($case['patient_id'] ?? 0);
    $complaint = trim((string) ($case['updated_chief_complaint'] ?? 'Urgent Follow-up'));
    $triageId = (int) ($case['triage_result_id'] ?? 0);
    $providerName = triage_provider_display_name($pdo, $providerId);

    $consultCols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN);
    $hasTriageLink = in_array('triage_result_id', $consultCols, true);
    $hasPriorityCol = in_array('consult_priority', $consultCols, true);

    $today = appointment_now()->format('Y-m-d');
    $nowTime = appointment_now()->format('H:i:s');

    if ($hasTriageLink && $hasPriorityCol) {
        $pdo->prepare("
            INSERT INTO consultations
                (patient_id, provider_id, provider_name, consult_date, consult_time,
                 consult_type, status, triage_result_id, consult_priority)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'urgent')
        ")->execute([
            $patientId, $providerId, $providerName, $today, $nowTime,
            $complaint,
            $startVideo ? 'in_consultation' : 'scheduled',
            $triageId,
        ]);
    } elseif ($hasTriageLink) {
        $pdo->prepare("
            INSERT INTO consultations
                (patient_id, provider_id, provider_name, consult_date, consult_time,
                 consult_type, status, triage_result_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $patientId, $providerId, $providerName, $today, $nowTime,
            $complaint,
            $startVideo ? 'in_consultation' : 'scheduled',
            $triageId,
        ]);
    } else {
        $pdo->prepare("
            INSERT INTO consultations
                (patient_id, provider_id, provider_name, consult_date, consult_time,
                 consult_type, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $patientId, $providerId, $providerName, $today, $nowTime,
            $complaint,
            $startVideo ? 'in_consultation' : 'scheduled',
        ]);
    }

    $consultationId = (int) $pdo->lastInsertId();
    $roomToken = '';

    if ($startVideo) {
        $roomToken = bin2hex(random_bytes(16));
        $pdo->prepare("
            INSERT INTO video_sessions (consultation_id, room_token, status)
            VALUES (?, ?, 'active')
        ")->execute([$consultationId, $roomToken]);

        try {
            NotificationEvents::consultationStarting($pdo, $consultationId, $patientId, $providerId);
        } catch (Throwable $e) {
            error_log('urgent_followup video notify: ' . $e->getMessage());
        }
    }

    $newStatus = $startVideo ? 'in_consultation' : 'accepted';
    $pdo->prepare("
        UPDATE urgent_followup_cases
        SET status = ?, followup_consultation_id = ?, accepted_at = NOW(), updated_at = NOW()
        WHERE id = ?
    ")->execute([$newStatus, $consultationId, $caseId]);

    $patientName = trim(($case['first_name'] ?? '') . ' ' . ($case['last_name'] ?? ''));
    urgent_followup_audit($pdo, $patientId, AuditAction::URGENT_FOLLOWUP_ACCEPTED, sprintf(
        'Provider accepted urgent follow-up case #%d%s.',
        $caseId,
        $startVideo ? ' and started video consultation' : ''
    ), [
        'case_id'         => $caseId,
        'consultation_id' => $consultationId,
        'provider_id'     => $providerId,
        'start_video'     => $startVideo,
    ]);

    NotificationEvents::urgentFollowupAccepted($pdo, $patientId, $patientName, $caseId, $consultationId, $startVideo, $providerId);

    return [
        'accepted'        => true,
        'case_id'         => $caseId,
        'consultation_id' => $consultationId,
        'room_token'      => $roomToken,
        'session_url'     => $roomToken !== ''
            ? '/views/consultation/video_room.php?token=' . urlencode($roomToken)
            : '/views/provider/consultation_session.php?id=' . $consultationId,
        'video_started'   => $startVideo,
    ];
}

/**
 * Count open urgent follow-up cases for provider nav badge.
 */
function urgent_followup_open_count(PDO $pdo, int $providerId): int
{
    urgent_followup_ensure_schema($pdo);
    if ($providerId <= 0) {
        return 0;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM urgent_followup_cases
            WHERE provider_id = ?
              AND status IN ('waiting', 'accepted', 'emergency_referral')
              AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$providerId]);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
