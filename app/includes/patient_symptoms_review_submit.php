<?php
/**
 * Shared patient symptoms submission for AI self-care review (no appointment booking).
 */

declare(strict_types=1);

require_once __DIR__ . '/triage_assessment_schema.php';
require_once __DIR__ . '/triage_provider_assignment.php';
require_once __DIR__ . '/bhw_patient_workflow.php';
require_once __DIR__ . '/notification_events.php';
require_once __DIR__ . '/consultation_expiry.php';
require_once __DIR__ . '/patient_booking_status.php';
require_once dirname(__DIR__) . '/core/TriageLevelService.php';

function patient_symptoms_review_same_complaint(string $a, string $b): bool
{
    $norm = static function (string $s): string {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return $s;
    };
    $left = $norm($a);
    $right = $norm($b);

    return $left !== '' && $left === $right;
}

function patient_symptoms_review_classification_label(string $triageLevel, string $classification = ''): string
{
    $raw = strtoupper(str_replace('_', '-', trim($classification)));
    if (str_contains($raw, 'EMERGENCY') || $triageLevel === TriageLevelService::EMERGENCY) {
        return 'EMERGENCY';
    }
    if ((str_contains($raw, 'URGENT') && !str_contains($raw, 'NON')) || $triageLevel === TriageLevelService::URGENT) {
        return 'URGENT';
    }

    return 'NON-URGENT';
}

/**
 * Open first-step triage: AI result saved, no doctor and no appointment yet.
 *
 * @return array<string, mixed>|null
 */
function patient_find_preliminary_complaint_triage(
    PDO $pdo,
    int $patientId,
    int $triageId = 0,
    string $complaint = ''
): ?array {
    if ($patientId <= 0) {
        return null;
    }

    triage_assessment_ensure_schema($pdo);

    $sql = "
        SELECT id, patient_id, symptoms, chief_complaint, level, urgency_label, status,
               triage_level, triage_classification, recommendation_status, outcome,
               assigned_provider_id, assessment_payload, recommendations, assessed_at
        FROM triage_results
        WHERE patient_id = ?
          AND TRIM(COALESCE(chief_complaint, '')) <> ''
          AND COALESCE(assigned_provider_id, 0) = 0
          AND COALESCE(recommendation_status, 'hidden') NOT IN ('pending_approval', 'approved')
          AND LOWER(COALESCE(outcome, '')) IN ('', 'preliminary_assessment')
          AND LOWER(COALESCE(status, 'pending')) NOT IN ('completed', 'cancelled', 'canceled')
          AND assessed_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
    ";
    $params = [$patientId];
    if ($triageId > 0) {
        $sql .= ' AND id = ?';
        $params[] = $triageId;
    }
    $sql .= ' ORDER BY assessed_at DESC, id DESC LIMIT 8';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return null;
    }

    $complaint = trim($complaint);
    if ($complaint !== '') {
        foreach ($rows as $row) {
            if (patient_symptoms_review_same_complaint($complaint, (string) ($row['chief_complaint'] ?? ''))) {
                return $row;
            }
        }
    }

    return $rows[0];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function patient_symptoms_review_assessment_from_row(array $row): array
{
    $raw = $row['assessment_payload'] ?? '';
    if (is_array($raw)) {
        return $raw;
    }
    $decoded = json_decode((string) $raw, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @return array<string, mixed>
 */
function patient_symptoms_review_preview_payload(
    int $triageId,
    string $triageLevel,
    string $classification = ''
): array {
    $label = patient_symptoms_review_classification_label($triageLevel, $classification);

    return [
        'preview' => true,
        'requires_second_submit' => true,
        'triage_id' => $triageId,
        'triage_level' => $triageLevel,
        'triage_classification' => $classification !== '' ? $classification : $label,
        'classification_label' => $label,
        'assigned_provider_id' => 0,
        'emergency' => $triageLevel === TriageLevelService::EMERGENCY,
        'urgent' => $triageLevel === TriageLevelService::URGENT,
    ];
}

/**
 * Patient-safe assignment summary: doctor name + that doctor's earliest real slot.
 *
 * @return array{
 *   assigned_provider_name:string,
 *   selected_slot_id:int,
 *   selected_slot_label:string,
 *   selected_slot_date:string
 * }
 */
function patient_symptoms_review_assignment_meta(PDO $pdo, int $assignedId): array
{
    $empty = [
        'assigned_provider_name' => '',
        'selected_slot_id' => 0,
        'selected_slot_label' => '',
        'selected_slot_date' => '',
    ];
    if ($assignedId <= 0) {
        return $empty;
    }

    require_once __DIR__ . '/triage_provider_assignment.php';
    $name = triage_provider_display_name($pdo, $assignedId);
    $slot = triage_provider_earliest_bookable_slot_today($pdo, $assignedId);
    $label = '';
    if ($slot && !empty($slot['start_time'])) {
        $start = date('g:i A', strtotime((string) $slot['start_time']));
        $end = !empty($slot['end_time']) ? date('g:i A', strtotime((string) $slot['end_time'])) : '';
        $label = $end !== '' ? ($start . '–' . $end) : $start;
    }

    return [
        'assigned_provider_name' => $name,
        'selected_slot_id' => (int) ($slot['id'] ?? 0),
        'selected_slot_label' => $label,
        'selected_slot_date' => (string) ($slot['slot_date'] ?? ''),
    ];
}

function patient_symptoms_review_mark_preliminary(PDO $pdo, int $triageId): void
{
    if ($triageId <= 0) {
        return;
    }
    $pdo->prepare("
        UPDATE triage_results
        SET recommendation_status = 'hidden',
            outcome = 'preliminary_assessment',
            assigned_provider_id = NULL,
            assigned_at = NULL,
            recommendation_patient_ack_at = NULL
        WHERE id = ?
    ")->execute([$triageId]);
}

/**
 * @param array<string, mixed> $assessment
 * @param list<string> $symptomList
 */
function patient_symptoms_review_bind_assessment_row(
    PDO $pdo,
    int $patientId,
    int $existingId,
    string $complaint,
    array $symptomList,
    array $assessment
): int {
    $level = (string) ($assessment['triage']['db_level'] ?? $assessment['db_level'] ?? '3');
    $label = (string) ($assessment['triage']['urgency_label'] ?? $assessment['urgency_label'] ?? 'Routine');
    $triageLevel = TriageLevelService::fromAssessment($assessment);
    $classification = (string) ($assessment['triage']['triage_classification'] ?? '');
    $recText = implode("\n", $assessment['recommendations'] ?? []);
    $engine = (string) ($assessment['engine'] ?? MedicalAssessmentEngine::VERSION);
    $params = [
        json_encode($symptomList),
        $complaint,
        $level,
        $label,
        (int) ($assessment['confidence']['score'] ?? 0),
        (string) ($assessment['severity']['severity'] ?? ''),
        $triageLevel,
        $classification,
        (string) ($assessment['english_translation'] ?? ''),
        json_encode($assessment['detected_symptoms'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($assessment['possible_conditions'] ?? [], JSON_UNESCAPED_UNICODE),
        $recText,
        json_encode($assessment, JSON_UNESCAPED_UNICODE),
        $engine,
    ];

    if ($existingId > 0) {
        $params[] = $existingId;
        $params[] = $patientId;
        $pdo->prepare("
            UPDATE triage_results
            SET symptoms = ?, chief_complaint = ?, level = ?, urgency_label = ?,
                status = 'pending', assessed_at = NOW(),
                confidence_score = ?, severity = ?, triage_level = ?, triage_classification = ?,
                english_complaint = ?, detected_symptoms_json = ?, possible_conditions_json = ?,
                recommendations = ?, assessment_payload = ?, engine = ?
            WHERE id = ? AND patient_id = ?
        ")->execute($params);

        return $existingId;
    }

    $insert = $params;
    array_unshift($insert, $patientId);
    $pdo->prepare("
        INSERT INTO triage_results
            (patient_id, symptoms, chief_complaint, level, urgency_label, status, assessed_at,
             confidence_score, severity, triage_level, triage_classification, english_complaint,
             detected_symptoms_json, possible_conditions_json, recommendations,
             assessment_payload, engine)
        VALUES (?, ?, ?, ?, ?, 'pending', NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute($insert);

    return (int) $pdo->lastInsertId();
}

/**
 * @param list<string> $symptomList
 * @return array{ok:bool,message:string,payload:array<string,mixed>}
 */
function patient_submit_symptoms_for_review(
    PDO $pdo,
    int $patientId,
    string $complaint,
    array $symptomList,
    string $stage = 'continue',
    int $reuseTriageId = 0
): array {
    triage_assessment_ensure_schema($pdo);
    BhwPatientWorkflow::ensure_schema($pdo);
    consultations_auto_expire($pdo, $patientId);

    $complaint = trim($complaint);
    $stage = strtolower(trim($stage)) === 'preview' ? 'preview' : 'continue';
    $reuseTriageId = max(0, $reuseTriageId);
    if ($complaint === '' && $symptomList === []) {
        return ['ok' => false, 'message' => 'Please describe your symptoms or health concern.', 'payload' => []];
    }

    $dup = $pdo->prepare("
        SELECT id FROM triage_results tr
        WHERE tr.patient_id = ?
          AND tr.recommendation_status = 'pending_approval'
          AND tr.assessed_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
          " . patient_triage_sql_active_only('tr') . "
        LIMIT 1
    ");
    $dup->execute([$patientId]);
    $dupId = (int) ($dup->fetchColumn() ?: 0);
    if ($dupId > 0 && !($stage === 'continue' && $reuseTriageId === $dupId)) {
        return [
            'ok' => false,
            'message' => 'You already have a case awaiting provider review. Open Care tips or wait for your doctor to finish the review.',
            'payload' => ['duplicate_pending' => true],
        ];
    }

    $prelim = patient_find_preliminary_complaint_triage($pdo, $patientId, $reuseTriageId, $complaint);
    if ($stage === 'continue' && $reuseTriageId > 0 && !$prelim) {
        $ownedStmt = $pdo->prepare("
            SELECT id, chief_complaint, triage_level, triage_classification, outcome,
                   assigned_provider_id, recommendation_status, assessment_payload, symptoms
            FROM triage_results
            WHERE id = ? AND patient_id = ?
            LIMIT 1
        ");
        $ownedStmt->execute([$reuseTriageId, $patientId]);
        $owned = $ownedStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($owned) {
            $ownedAssigned = (int) ($owned['assigned_provider_id'] ?? 0);
            $ownedOutcome = strtolower(trim((string) ($owned['outcome'] ?? '')));
            $ownedLevel = (string) ($owned['triage_level'] ?? '');
            if ($ownedOutcome === 'emergency_referral' || $ownedLevel === TriageLevelService::EMERGENCY) {
                return [
                    'ok' => true,
                    'message' => 'Emergency symptoms detected. Please go to the nearest hospital or emergency department. Online self-care review is not appropriate for this case.',
                    'payload' => [
                        'emergency' => true,
                        'triage_id' => (int) ($owned['id'] ?? 0),
                    ],
                ];
            }
            if ($ownedAssigned > 0 || $ownedOutcome === 'waiting_for_slot') {
                $isUrgent = $ownedLevel === TriageLevelService::URGENT;
                $waitOutcome = $ownedOutcome === 'waiting_for_slot' || $ownedAssigned <= 0;

                return [
                    'ok' => true,
                    'message' => $isUrgent
                        ? 'Your symptoms may need prompt medical attention. Please book an urgent consultation with your assigned doctor.'
                        : ($waitOutcome
                            ? 'No suitable doctor schedule is currently available. You are in the waiting queue and will be notified by email when a consultation slot becomes available.'
                            : 'Your case is currently being reviewed by a healthcare provider. Please wait while your guidance is being prepared.'),
                    'payload' => [
                        'urgent' => $isUrgent,
                        'awaiting_provider_review' => !$isUrgent && !$waitOutcome,
                        'waiting_for_slot' => $waitOutcome,
                        'triage_id' => (int) ($owned['id'] ?? 0),
                        'assigned_provider_id' => $ownedAssigned,
                        'book_url' => (defined('ASSET_BASE') ? ASSET_BASE : '') . '/views/patient/triage.php',
                    ],
                ];
            }
        }
    }
    $samePrelim = $prelim
        && patient_symptoms_review_same_complaint($complaint, (string) ($prelim['chief_complaint'] ?? ''));

    if ($stage === 'preview' && $samePrelim && $prelim) {
        $triageLevel = (string) ($prelim['triage_level'] ?? TriageLevelService::NON_URGENT);
        $classification = (string) ($prelim['triage_classification'] ?? '');
        if ($triageLevel === TriageLevelService::EMERGENCY) {
            // Emergency already persisted — do not wait for a second click.
        } else {
            return [
                'ok' => true,
                'message' => 'Preliminary AI Assessment: '
                    . patient_symptoms_review_classification_label($triageLevel, $classification)
                    . '. Please click "Submit patient complaint" again to continue.',
                'payload' => patient_symptoms_review_preview_payload(
                    (int) ($prelim['id'] ?? 0),
                    $triageLevel,
                    $classification
                ),
            ];
        }
    }

    $reuseExisting = $stage === 'continue' && $prelim && (
        ($reuseTriageId > 0 && $reuseTriageId === (int) ($prelim['id'] ?? 0))
        || ($reuseTriageId === 0 && $samePrelim)
    );

    $assessment = [];
    if ($reuseExisting && $prelim) {
        $assessment = patient_symptoms_review_assessment_from_row($prelim);
        if ($symptomList === []) {
            $storedSymptoms = json_decode((string) ($prelim['symptoms'] ?? ''), true);
            if (is_array($storedSymptoms)) {
                $symptomList = array_values(array_filter(array_map('strval', $storedSymptoms)));
            }
        }
    }

    if ($assessment === []) {
        $assessment = ChiefComplaintNlpService::assessWithFallback($complaint, $symptomList);
    }

    $label = (string) ($assessment['triage']['urgency_label'] ?? $assessment['urgency_label'] ?? 'Routine');
    $triageLevel = TriageLevelService::fromAssessment($assessment);
    if ($reuseExisting && $prelim) {
        $storedLevel = (string) ($prelim['triage_level'] ?? '');
        if (TriageLevelService::isValid($storedLevel)) {
            $triageLevel = $storedLevel;
        }
    }

    $isEmergency = $triageLevel === TriageLevelService::EMERGENCY
        || strtoupper((string) ($assessment['triage']['triage_classification'] ?? '')) === 'EMERGENCY';

    $nameStmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) FROM users WHERE id = ? LIMIT 1');
    $nameStmt->execute([$patientId]);
    $patientName = trim((string) ($nameStmt->fetchColumn() ?: 'Patient'));

    try {
        $pdo->beginTransaction();

        $triageId = 0;
        if ($reuseExisting && $prelim) {
            $triageId = (int) ($prelim['id'] ?? 0);
        } else {
            $existingId = ($stage === 'preview' && $prelim) ? (int) ($prelim['id'] ?? 0) : 0;
            $triageId = patient_symptoms_review_bind_assessment_row(
                $pdo,
                $patientId,
                $existingId,
                $complaint,
                $symptomList,
                $assessment
            );
        }

        $recText = implode("\n", $assessment['recommendations'] ?? []);
        $recStatus = triage_recommendation_status_for_insert(
            $triageLevel,
            $complaint,
            $recText,
            (string) ($assessment['triage']['triage_classification'] ?? '')
        );

        if ($stage === 'preview' && !$isEmergency) {
            patient_symptoms_review_mark_preliminary($pdo, $triageId);
            $pdo->commit();
            $classification = (string) ($assessment['triage']['triage_classification'] ?? '');

            return [
                'ok' => true,
                'message' => 'Preliminary AI Assessment: '
                    . patient_symptoms_review_classification_label($triageLevel, $classification)
                    . '. Please click "Submit patient complaint" again to continue.',
                'payload' => patient_symptoms_review_preview_payload(
                    $triageId,
                    $triageLevel,
                    $classification
                ),
            ];
        }

        $pdo->prepare('UPDATE triage_results SET recommendation_status = ?, recommendation_patient_ack_at = NULL WHERE id = ?')
            ->execute([$recStatus, $triageId]);

        if ($isEmergency) {
            try {
                $pdo->prepare("UPDATE triage_results SET outcome = 'emergency_referral', status = 'completed' WHERE id = ?")
                    ->execute([$triageId]);
            } catch (PDOException $e) {
                $pdo->prepare("UPDATE triage_results SET status = 'completed' WHERE id = ?")->execute([$triageId]);
            }

            $providerId = patient_resolve_provider_id($pdo, $patientId);
            $reason = patient_emergency_referral_reason($assessment, $complaint, $symptomList);
            $referralId = 0;
            if ($providerId > 0) {
                $referralId = patient_create_emergency_hospital_referral($pdo, $patientId, $providerId, $reason);
            }

            $pdo->commit();

            BhwPatientWorkflow::onPatientPortalEmergency($pdo, $patientId, [
                'triage_id'   => $triageId,
                'referral_id' => $referralId,
            ]);
            NotificationEvents::highRiskPatient($pdo, $patientId, $patientName, $label, $patientId);
            if ($referralId > 0) {
                NotificationEvents::referralCreated($pdo, $referralId, $patientId, $providerId, $patientId);
            }

            $msg = 'Emergency symptoms detected. Please go to the nearest hospital or emergency department. Online self-care review is not appropriate for this case.';
            if ($referralId > 0) {
                $msg .= ' A hospital referral has been recorded for your care team.';
            }

            return [
                'ok' => true,
                'message' => $msg,
                'payload' => [
                    'emergency' => true,
                    'triage_id' => $triageId,
                    'referral_id' => $referralId,
                ],
            ];
        }

        if ($triageLevel === TriageLevelService::URGENT) {
            $assignedId = triage_select_provider_for_level($pdo, $patientId, TriageLevelService::URGENT);
            if ($assignedId > 0) {
                triage_bind_assigned_provider($pdo, $triageId, $assignedId);
            }
            $pdo->commit();

            return [
                'ok' => true,
                'message' => $assignedId > 0
                    ? 'Your symptoms may need prompt medical attention. Please book an urgent consultation with your assigned doctor.'
                    : 'Your symptoms may need prompt medical attention. Please book an urgent consultation.',
                'payload' => array_merge([
                    'urgent' => true,
                    'triage_id' => $triageId,
                    'assigned_provider_id' => $assignedId,
                    'book_url' => (defined('ASSET_BASE') ? ASSET_BASE : '') . '/views/patient/triage.php',
                ], patient_symptoms_review_assignment_meta($pdo, $assignedId)),
            ];
        }

        if ($recStatus !== 'pending_approval') {
            $pdo->commit();

            return [
                'ok' => false,
                'message' => 'Self-care guidance review is not available for this submission. Please book a consultation if you need help.',
                'payload' => ['triage_id' => $triageId, 'recommendation_status' => $recStatus],
            ];
        }

        $assignedId = triage_assign_review_provider($pdo, $patientId);
        if ($assignedId > 0) {
            triage_bind_assigned_provider($pdo, $triageId, $assignedId);
            try {
                $pdo->prepare("UPDATE triage_results SET outcome = 'awaiting_provider_review' WHERE id = ?")->execute([$triageId]);
            } catch (PDOException $e) {
                // optional column
            }
        } else {
            try {
                $pdo->prepare("UPDATE triage_results SET outcome = 'waiting_for_slot' WHERE id = ?")->execute([$triageId]);
            } catch (PDOException $e) {
                // optional column
            }
        }

        $pdo->commit();

        if ($assignedId > 0) {
            NotificationEvents::aiSelfCareReviewRequired(
                $pdo,
                $assignedId,
                $patientId,
                $patientName,
                $triageId,
                $patientId
            );
        }

        $waitingForSlot = $assignedId <= 0;
        $waitStatus = $waitingForSlot ? 'waiting' : '';
        try {
            require_once __DIR__ . '/patient_slot_waitlist.php';
            $queued = $assignedId <= 0
                ? patient_slot_waitlist_enqueue(
                    $pdo,
                    $patientId,
                    $triageId,
                    0,
                    $complaint,
                    $triageLevel
                )
                : patient_slot_waitlist_enqueue_if_no_assigned_slot(
                    $pdo,
                    $patientId,
                    $triageId,
                    $assignedId,
                    $complaint,
                    $triageLevel
                );
            if ($assignedId <= 0 && !empty($queued['queued'])) {
                patient_slot_waitlist_process($pdo);
            }
            $waitStatus = (string) ($queued['status'] ?? $waitStatus);
            $waitingForSlot = $assignedId <= 0 || in_array($waitStatus, ['waiting', 'slot_available'], true);
        } catch (Throwable $e) {
            error_log('patient_submit_symptoms_for_review waitlist: ' . $e->getMessage());
        }

        $msg = $assignedId <= 0
            ? 'No suitable doctor schedule is currently available. You are in the waiting queue and will be notified by email when a consultation slot becomes available.'
            : 'Your case is currently being reviewed by a healthcare provider. Please wait while your guidance is being prepared.';

        return [
            'ok' => true,
            'message' => $msg,
            'payload' => array_merge([
                'awaiting_provider_review' => $assignedId > 0,
                'waiting_for_slot' => $assignedId <= 0 || $waitingForSlot,
                'waitlist_status' => $waitStatus,
                'triage_id' => $triageId,
                'assigned_provider_id' => $assignedId,
            ], patient_symptoms_review_assignment_meta($pdo, $assignedId)),
        ];
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => $e->getMessage(), 'payload' => []];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Could not save your symptoms. Please try again.', 'payload' => []];
    }
}

/**
 * @param array<string, mixed> $assessment
 * @return array<string, mixed>|null
 */
function patient_symptoms_review_merge_registration_nlp(array &$assessment, ?string $regNlpRaw): ?array
{
    $regNlpRaw = trim((string) $regNlpRaw);
    if ($regNlpRaw === '') {
        return null;
    }
    $decoded = json_decode($regNlpRaw, true);
    if (!is_array($decoded)) {
        return null;
    }
    $regNlp = $decoded;
    if (!empty($regNlp['translated_english']) && empty($assessment['english_translation'])) {
        $assessment['english_translation'] = (string) $regNlp['translated_english'];
    }
    if (!empty($regNlp['detected_symptoms']) && is_array($regNlp['detected_symptoms'])) {
        $assessment['detected_symptoms'] = array_values(array_unique(array_merge(
            $assessment['detected_symptoms'] ?? [],
            $regNlp['detected_symptoms']
        )));
    }
    if (!empty($regNlp['detected_conditions']) && is_array($regNlp['detected_conditions'])) {
        $assessment['possible_conditions'] = array_values(array_unique(array_merge(
            $assessment['possible_conditions'] ?? [],
            $regNlp['detected_conditions']
        )));
    }
    if (!empty($regNlp['confidence']) && empty($assessment['confidence']['score'])) {
        $pct = (int) preg_replace('/\D+/', '', (string) $regNlp['confidence']);
        if ($pct > 0) {
            $assessment['confidence']['score'] = $pct;
        }
    }
    $assessment['registration_nlp'] = $regNlp;

    return $regNlp;
}

/**
 * Open non-urgent care-tips triage row (pending approval or approved, no visit yet)
 * for reuse when booking the same health concern.
 *
 * @return array<string, mixed>|null
 */
function patient_find_open_care_tips_triage(PDO $pdo, int $patientId, bool $forUpdate = false): ?array
{
    triage_assessment_ensure_schema($pdo);

    if ($patientId <= 0) {
        return null;
    }

    $sql = "
        SELECT id, patient_id, symptoms, chief_complaint, level, urgency_label, status,
               triage_level, triage_classification, recommendation_status,
               assigned_provider_id, recommendations, english_complaint,
               detected_symptoms_json, possible_conditions_json, assessment_payload, engine
        FROM triage_results tr
        WHERE tr.patient_id = ?
          AND TRIM(COALESCE(tr.chief_complaint, '')) <> ''
          AND tr.assessed_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
          AND COALESCE(tr.triage_level, 'non_urgent') = 'non_urgent'
          " . patient_triage_sql_active_only('tr') . "
        ORDER BY tr.assessed_at DESC
        LIMIT 1
    ";
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$patientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @return array{has_pending:bool,triage_id:int,complaint:string,provider_id:int,provider_name:string}
 */
function patient_symptoms_review_pending_state(PDO $pdo, int $patientId): array
{
    triage_assessment_ensure_schema($pdo);
    require_once __DIR__ . '/triage_provider_assignment.php';
    require_once __DIR__ . '/patient_booking_status.php';

    $empty = [
        'has_pending' => false,
        'triage_id' => 0,
        'complaint' => '',
        'provider_id' => 0,
        'provider_name' => '',
        'recommendation_status' => '',
    ];

    // "Doctor reviewing" is only for care tips still awaiting approval —
    // never for scheduled / in-progress visits or approved tips.
    $stmt = $pdo->prepare("
        SELECT id, chief_complaint, assigned_provider_id, assessed_at, recommendation_status
        FROM triage_results tr
        WHERE tr.patient_id = ?
          AND TRIM(COALESCE(tr.chief_complaint, '')) <> ''
          AND COALESCE(tr.recommendation_status, 'hidden') = 'pending_approval'
          " . patient_triage_sql_active_only('tr') . "
        ORDER BY tr.assessed_at DESC
        LIMIT 1
    ");
    $stmt->execute([$patientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $empty;
    }

    $triageId = (int) ($row['id'] ?? 0);
    $bookingState = patient_triage_row_booking_state(
        $pdo,
        $patientId,
        (string) ($row['assessed_at'] ?? ''),
        $triageId
    );
    if ($bookingState === 'booked' || $bookingState === 'completed') {
        return $empty;
    }

    // An open visit always wins over the care-tips review card.
    if (patient_portal_has_open_consultation($pdo, $patientId)) {
        return $empty;
    }

    $providerId = (int) ($row['assigned_provider_id'] ?? 0);

    return [
        'has_pending' => true,
        'triage_id' => $triageId,
        'complaint' => trim((string) ($row['chief_complaint'] ?? '')),
        'provider_id' => $providerId,
        'provider_name' => triage_provider_display_name($pdo, $providerId),
        'recommendation_status' => (string) ($row['recommendation_status'] ?? ''),
    ];
}

/**
 * Approved care tips with no booked visit yet — patient may schedule with the
 * same reviewing doctor. This is NOT a completed consultation.
 *
 * @return array{
 *   ready:bool,
 *   triage_id:int,
 *   complaint:string,
 *   provider_id:int,
 *   provider_name:string,
 *   recommendation_status:string
 * }
 */
function patient_care_tips_ready_to_schedule_state(PDO $pdo, int $patientId): array
{
    triage_assessment_ensure_schema($pdo);
    require_once __DIR__ . '/triage_provider_assignment.php';
    require_once __DIR__ . '/patient_booking_status.php';

    $empty = [
        'ready' => false,
        'triage_id' => 0,
        'complaint' => '',
        'provider_id' => 0,
        'provider_name' => '',
        'recommendation_status' => '',
    ];

    if ($patientId <= 0) {
        return $empty;
    }

    // Live/future visits own the dashboard — not the ready-to-schedule card.
    if (patient_portal_has_open_consultation($pdo, $patientId)) {
        return $empty;
    }

    $stmt = $pdo->prepare("
        SELECT id, chief_complaint, assigned_provider_id, assessed_at, recommendation_status
        FROM triage_results tr
        WHERE tr.patient_id = ?
          AND TRIM(COALESCE(tr.chief_complaint, '')) <> ''
          AND COALESCE(tr.recommendation_status, 'hidden') = 'approved'
          " . patient_triage_sql_active_only('tr') . "
        ORDER BY tr.assessed_at DESC, tr.id DESC
        LIMIT 1
    ");
    $stmt->execute([$patientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $empty;
    }

    $triageId = (int) ($row['id'] ?? 0);
    $bookingState = patient_triage_row_booking_state(
        $pdo,
        $patientId,
        (string) ($row['assessed_at'] ?? ''),
        $triageId
    );
    if ($bookingState === 'booked' || $bookingState === 'completed') {
        return $empty;
    }

    $providerId = (int) ($row['assigned_provider_id'] ?? 0);

    return [
        'ready' => true,
        'triage_id' => $triageId,
        'complaint' => trim((string) ($row['chief_complaint'] ?? '')),
        'provider_id' => $providerId,
        'provider_name' => triage_provider_display_name($pdo, $providerId),
        'recommendation_status' => (string) ($row['recommendation_status'] ?? 'approved'),
    ];
}

/**
 * Admin: reassign AI review provider for a triage case.
 *
 * @return array{ok:bool,message:string}
 */
function triage_admin_reassign_reviewer(PDO $pdo, int $triageId, int $newProviderId, int $adminUserId): array
{
    triage_assessment_ensure_schema($pdo);

    if ($triageId <= 0 || $newProviderId <= 0) {
        return ['ok' => false, 'message' => 'Triage case and provider are required.'];
    }

    $chk = $pdo->prepare("
        SELECT id, patient_id, recommendation_status, assigned_provider_id
        FROM triage_results WHERE id = ? LIMIT 1
    ");
    $chk->execute([$triageId]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'message' => 'Triage record not found.'];
    }

    $status = (string) ($row['recommendation_status'] ?? '');
    if (!in_array($status, ['pending_approval', 'approved'], true)) {
        return ['ok' => false, 'message' => 'Only active AI review cases can be reassigned.'];
    }

    $prov = $pdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM users WHERE id = ? AND role = 'provider' AND is_active = 1 LIMIT 1");
    $prov->execute([$newProviderId]);
    $provRow = $prov->fetch(PDO::FETCH_ASSOC);
    if (!$provRow) {
        return ['ok' => false, 'message' => 'Selected provider is not active.'];
    }

    $oldId = (int) ($row['assigned_provider_id'] ?? 0);
    $pdo->prepare("
        UPDATE triage_results
        SET assigned_provider_id = ?, assigned_at = NOW()
        WHERE id = ?
    ")->execute([$newProviderId, $triageId]);

    require_once __DIR__ . '/audit_log.php';
    audit_log($pdo, [
        'patient_id'  => (int) ($row['patient_id'] ?? 0),
        'action_type' => 'AI_REVIEW_PROVIDER_REASSIGNED',
        'description' => "Triage #{$triageId} reviewer changed from provider #{$oldId} to #{$newProviderId} by admin #{$adminUserId}",
    ]);

    $patientId = (int) ($row['patient_id'] ?? 0);
    if ($patientId > 0 && $status === 'pending_approval') {
        $pstmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) FROM users WHERE id = ? LIMIT 1');
        $pstmt->execute([$patientId]);
        $pName = trim((string) ($pstmt->fetchColumn() ?: 'Patient'));
        NotificationEvents::aiSelfCareReviewRequired(
            $pdo,
            $newProviderId,
            $patientId,
            $pName,
            $triageId,
            $adminUserId
        );
    }

    return [
        'ok' => true,
        'message' => 'Reviewer reassigned to ' . trim((string) ($provRow['name'] ?? 'provider')) . '.',
    ];
}
