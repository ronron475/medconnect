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

/**
 * @param list<string> $symptomList
 * @return array{ok:bool,message:string,payload:array<string,mixed>}
 */
function patient_submit_symptoms_for_review(
    PDO $pdo,
    int $patientId,
    string $complaint,
    array $symptomList
): array {
    triage_assessment_ensure_schema($pdo);
    BhwPatientWorkflow::ensure_schema($pdo);
    consultations_auto_expire($pdo, $patientId);

    $complaint = trim($complaint);
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
    if ($dup->fetchColumn()) {
        return [
            'ok' => false,
            'message' => 'You already have a case awaiting provider review. Open Care tips or wait for your doctor to finish the review.',
            'payload' => ['duplicate_pending' => true],
        ];
    }

    $assessment = ChiefComplaintNlpService::assessWithFallback($complaint, $symptomList);

    $level = (string) ($assessment['triage']['db_level'] ?? $assessment['db_level'] ?? '3');
    $label = (string) ($assessment['triage']['urgency_label'] ?? $assessment['urgency_label'] ?? 'Routine');
    $triageLevel = TriageLevelService::fromAssessment($assessment);

    $isEmergency = $triageLevel === TriageLevelService::EMERGENCY
        || strtoupper((string) ($assessment['triage']['triage_classification'] ?? '')) === 'EMERGENCY';

    $nameStmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) FROM users WHERE id = ? LIMIT 1');
    $nameStmt->execute([$patientId]);
    $patientName = trim((string) ($nameStmt->fetchColumn() ?: 'Patient'));

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO triage_results
                (patient_id, symptoms, chief_complaint, level, urgency_label, status, assessed_at,
                 confidence_score, severity, triage_level, triage_classification, english_complaint,
                 detected_symptoms_json, possible_conditions_json, recommendations,
                 assessment_payload, engine)
            VALUES (?, ?, ?, ?, ?, 'pending', NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $patientId,
            json_encode($symptomList),
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
            (string) ($assessment['engine'] ?? MedicalAssessmentEngine::VERSION),
        ]);

        $triageId = (int) $pdo->lastInsertId();
        $recText = implode("\n", $assessment['recommendations'] ?? []);
        $recStatus = triage_recommendation_status_for_insert(
            $triageLevel,
            $complaint,
            $recText,
            (string) ($assessment['triage']['triage_classification'] ?? '')
        );
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
            $pdo->commit();

            return [
                'ok' => true,
                'message' => 'Your symptoms may need prompt medical attention. Please book an urgent consultation.',
                'payload' => [
                    'urgent' => true,
                    'triage_id' => $triageId,
                    'book_url' => (defined('ASSET_BASE') ? ASSET_BASE : '') . '/views/patient/triage.php',
                ],
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
        if ($assignedId <= 0) {
            throw new RuntimeException('No healthcare provider is available to review your case. Please try again later.');
        }
        triage_bind_assigned_provider($pdo, $triageId, $assignedId);
        try {
            $pdo->prepare("UPDATE triage_results SET outcome = 'awaiting_provider_review' WHERE id = ?")->execute([$triageId]);
        } catch (PDOException $e) {
            // optional column
        }

        $pdo->commit();

        NotificationEvents::aiSelfCareReviewRequired(
            $pdo,
            $assignedId,
            $patientId,
            $patientName,
            $triageId,
            $patientId
        );

        $waitingForSlot = false;
        $waitStatus = '';
        try {
            require_once __DIR__ . '/patient_slot_waitlist.php';
            $queued = patient_slot_waitlist_enqueue_if_no_assigned_slot(
                $pdo,
                $patientId,
                $triageId,
                $assignedId,
                $complaint,
                $triageLevel
            );
            $waitStatus = (string) ($queued['status'] ?? '');
            $waitingForSlot = in_array($waitStatus, ['waiting', 'slot_available'], true);
        } catch (Throwable $e) {
            error_log('patient_submit_symptoms_for_review waitlist: ' . $e->getMessage());
        }

        $msg = $waitingForSlot
            ? 'No suitable doctor schedule is currently available. You are in the waiting queue and will be notified by email when a consultation slot becomes available.'
            : 'Your case is currently being reviewed by a healthcare provider. Please wait while your guidance is being prepared.';

        return [
            'ok' => true,
            'message' => $msg,
            'payload' => [
                'awaiting_provider_review' => true,
                'waiting_for_slot' => $waitingForSlot,
                'waitlist_status' => $waitStatus,
                'triage_id' => $triageId,
                'assigned_provider_id' => $assignedId,
            ],
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
