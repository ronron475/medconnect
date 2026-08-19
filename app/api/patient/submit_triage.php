<?php
/**
 * API: Submit patient triage and book a scheduled appointment slot.
 * Uses AI Assessment Engine for NLP-driven triage classification.
 *
 * Emergency: saves triage + hospital referral; never books teleconsult (aligned with BHW).
 * Booking: same-day only; auto-accepts triage; creates a new consultation when a separate case is open.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/appointment_slots.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/consultation_expiry.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/triage_assessment_schema.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/TriageLevelService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_patient_workflow.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/triage_provider_assignment.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_symptoms_review_submit.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_chief_complaints.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_booking_status.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/user_account_status.php';

Api::startJson();
Api::requirePatientReady($pdo);

$submissionCheck = patient_account_may_submit_consultation($pdo, (int) $_SESSION['user_id']);
if (!$submissionCheck['allowed']) {
    Api::error($submissionCheck['message'], 403, ['code' => $submissionCheck['code'] ?? 'account_blocked']);
}
Api::requirePost();
Api::requireCsrf();

$patient_id = (int) $_SESSION['user_id'];
appointment_schedule_ensure_schema($pdo);
consultations_auto_expire($pdo, $patient_id);
triage_assessment_ensure_schema($pdo);
BhwPatientWorkflow::ensure_schema($pdo);

$symptoms   = $_POST['symptoms'] ?? [];
$submittedComplaint = trim((string) ($_POST['chief_complaint'] ?? ''));
$forceNewConcern = ($_POST['new_concern'] ?? '') === '1';
$slot_id    = (int) ($_POST['slot_id'] ?? 0);
$reuseTriageIdEarly = (int) ($_POST['triage_id'] ?? 0);

if (!is_array($symptoms)) {
    $symptoms = [];
}

$openCareTipsPreview = null;
if (!$forceNewConcern) {
    $openCareTipsPreview = patient_find_open_care_tips_triage($pdo, $patient_id, false);
    if ($openCareTipsPreview) {
        $existingCc = trim((string) ($openCareTipsPreview['chief_complaint'] ?? ''));
        if ($submittedComplaint !== '' && $existingCc !== '' && !patient_complaints_are_same($submittedComplaint, $existingCc)) {
            $openCareTipsPreview = null;
            $forceNewConcern = true;
        }
    }
}

// Explicit booking of an existing approved/pending case (Care Plan Accepted → Book Consultation).
if ($openCareTipsPreview === null && !$forceNewConcern && $reuseTriageIdEarly > 0 && $slot_id > 0) {
    $earlyReuseStmt = $pdo->prepare("
        SELECT id, patient_id, symptoms, chief_complaint, level, urgency_label, status,
               triage_level, triage_classification, recommendation_status,
               assigned_provider_id, recommendations, english_complaint,
               detected_symptoms_json, possible_conditions_json, assessment_payload, engine
        FROM triage_results
        WHERE id = ?
          AND patient_id = ?
          AND COALESCE(recommendation_status, 'hidden') IN ('pending_approval', 'approved')
        LIMIT 1
    ");
    $earlyReuseStmt->execute([$reuseTriageIdEarly, $patient_id]);
    $earlyReuseRow = $earlyReuseStmt->fetch(PDO::FETCH_ASSOC);
    if ($earlyReuseRow) {
        $existingCc = trim((string) ($earlyReuseRow['chief_complaint'] ?? ''));
        if ($submittedComplaint !== '' && $existingCc !== '' && !patient_complaints_are_same($submittedComplaint, $existingCc)) {
            $forceNewConcern = true;
        } else {
            $openCareTipsPreview = $earlyReuseRow;
        }
    }
}

$reuseExistingForBooking = $openCareTipsPreview !== null && $slot_id > 0 && !$forceNewConcern;

if ($forceNewConcern) {
    $complaint = $submittedComplaint;
} elseif ($reuseExistingForBooking) {
    $complaint = trim((string) ($openCareTipsPreview['chief_complaint'] ?? '')) !== ''
        ? trim((string) $openCareTipsPreview['chief_complaint'])
        : patient_portal_resolve_chief_complaint($pdo, $patient_id, $submittedComplaint);
} else {
    $complaint = patient_portal_resolve_chief_complaint($pdo, $patient_id, $submittedComplaint);
}

if (empty($symptoms) && $complaint === '') {
    Api::error('Please provide symptoms or a complaint.');
}

$symptomList = array_values(array_filter(array_map(static function ($s) {
    return is_string($s) ? trim($s) : '';
}, $symptoms)));

$assessment = [];
$level = '3';
$label = 'Routine';
$triageLevel = TriageLevelService::NON_URGENT;
$isEmergency = false;

if ($reuseExistingForBooking) {
    $level = (string) ($openCareTipsPreview['level'] ?? '3');
    $label = (string) ($openCareTipsPreview['urgency_label'] ?? 'Routine');
    $triageLevel = (string) ($openCareTipsPreview['triage_level'] ?? TriageLevelService::NON_URGENT);
    $isEmergency = triage_doctor_final_is_emergency($openCareTipsPreview);
} else {
    try {
        $assessment = ChiefComplaintNlpService::assessWithFallback($complaint, $symptomList);
    } catch (Throwable $e) {
        error_log('submit_triage assess: ' . $e->getMessage());
        Api::error('Unable to analyze symptoms. Please try again.');
    }

    $level = (string) ($assessment['triage']['db_level'] ?? $assessment['db_level'] ?? '3');
    $label = (string) ($assessment['triage']['urgency_label'] ?? $assessment['urgency_label'] ?? 'Routine');
    $triageLevel = TriageLevelService::fromAssessment($assessment);

    $isEmergency = $triageLevel === TriageLevelService::EMERGENCY
        || strtoupper((string) ($assessment['triage']['triage_classification'] ?? '')) === 'EMERGENCY';
}

$consult_type = $complaint !== ''
    ? $complaint
    : ($symptomList !== [] ? implode(', ', $symptomList) : 'General Consultation');

$nameStmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) FROM users WHERE id = ? LIMIT 1');
$nameStmt->execute([$patient_id]);
$patientName = trim((string) ($nameStmt->fetchColumn() ?: 'Patient'));
$registrationComplaintRef = patient_chief_complaint_registration_reference($pdo, $patient_id);

try {
    $pdo->beginTransaction();

    $openCareTipsRow = null;
    $reviewerBeforeBooking = 0;
    $reuseTriageId = $reuseTriageIdEarly;
    $reusedExistingTriage = false;

    if ($isEmergency && $reuseExistingForBooking && is_array($openCareTipsPreview)) {
        $openCareTipsRow = $openCareTipsPreview;
    }

    if (!$isEmergency && !$forceNewConcern) {
        $openCareTipsRow = patient_find_open_care_tips_triage($pdo, $patient_id, true);
        if ($openCareTipsRow) {
            $existingCc = trim((string) ($openCareTipsRow['chief_complaint'] ?? ''));
            if ($submittedComplaint !== '' && $existingCc !== '' && !patient_complaints_are_same($submittedComplaint, $existingCc)) {
                $openCareTipsRow = null;
            }
        }
    }

    // Reuse the posted triage row (approved care tips or today's urgent case).
    if ($openCareTipsRow === null && $reuseTriageId > 0 && $slot_id > 0 && !$isEmergency && !$forceNewConcern) {
        $reuseStmt = $pdo->prepare("
            SELECT id, patient_id, symptoms, chief_complaint, level, urgency_label, status,
                   triage_level, triage_classification, recommendation_status,
                   assigned_provider_id, recommendations, english_complaint,
                   detected_symptoms_json, possible_conditions_json, assessment_payload, engine
            FROM triage_results
            WHERE id = ?
              AND patient_id = ?
              AND (
                    (triage_level = 'urgent' AND assessed_at >= CURDATE())
                 OR (
                      COALESCE(triage_level, 'non_urgent') = 'non_urgent'
                      AND COALESCE(recommendation_status, 'hidden') IN ('pending_approval', 'approved')
                    )
              )
            LIMIT 1
            FOR UPDATE
        ");
        $reuseStmt->execute([$reuseTriageId, $patient_id]);
        $reuseRow = $reuseStmt->fetch(PDO::FETCH_ASSOC);
        if ($reuseRow) {
            $openCareTipsRow = $reuseRow;
        }
    }

    if ($openCareTipsRow !== null && $slot_id <= 0) {
        $existingRec = (string) ($openCareTipsRow['recommendation_status'] ?? '');
        if ($existingRec === 'pending_approval') {
            $pdo->commit();

            $existingTriageId = (int) ($openCareTipsRow['id'] ?? 0);
            $assignedId = (int) ($openCareTipsRow['assigned_provider_id'] ?? 0);

            $waitingForSlot = false;
            $waitStatus = '';
            try {
                require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_slot_waitlist.php';
                $queued = patient_slot_waitlist_enqueue_if_no_assigned_slot(
                    $pdo,
                    $patient_id,
                    $existingTriageId,
                    $assignedId,
                    (string) ($openCareTipsRow['chief_complaint'] ?? $complaint),
                    (string) ($openCareTipsRow['triage_level'] ?? $triageLevel)
                );
                $waitStatus = (string) ($queued['status'] ?? '');
                $waitingForSlot = in_array($waitStatus, ['waiting', 'slot_available'], true);
            } catch (Throwable $e) {
                error_log('submit_triage existing waitlist enqueue: ' . $e->getMessage());
            }

            $reuseMsg = $waitingForSlot
                ? 'No suitable doctor schedule is currently available. You are in the waiting queue and will be notified by email when a consultation slot becomes available.'
                : 'You already have a care tips case in review. Book a video consultation with your assigned doctor, or wait for approved tips in Care tips.';

            Api::success([
                'booked'                   => false,
                'awaiting_provider_review' => true,
                'waiting_for_slot'         => $waitingForSlot,
                'waitlist_status'          => $waitStatus,
                'emergency'                => false,
                'triage_id'                => $existingTriageId,
                'assigned_provider_id'     => $assignedId,
                'reused_existing_triage'   => true,
                'level'                    => (string) ($openCareTipsRow['level'] ?? $level),
                'label'                    => (string) ($openCareTipsRow['urgency_label'] ?? $label),
            ], $reuseMsg);
        }
        // Urgent reuse without a slot should fall through to normal validation (slot required).
        $openCareTipsRow = null;
    }

    // Book against the existing open care-tips / urgent triage — do NOT create a
    // second triage that would keep "Doctor reviewing" alive after scheduling.
    if ($openCareTipsRow !== null && $slot_id > 0) {
        $triageId = (int) ($openCareTipsRow['id'] ?? 0);
        $reviewerBeforeBooking = (int) ($openCareTipsRow['assigned_provider_id'] ?? 0);
        $reusedExistingTriage = $triageId > 0;
        $recStatus = (string) ($openCareTipsRow['recommendation_status'] ?? 'hidden');
        $complaint = trim((string) ($openCareTipsRow['chief_complaint'] ?? '')) !== ''
            ? trim((string) $openCareTipsRow['chief_complaint'])
            : $complaint;
        $level = (string) ($openCareTipsRow['level'] ?? $level);
        $label = (string) ($openCareTipsRow['urgency_label'] ?? $label);
        $triageLevel = (string) ($openCareTipsRow['triage_level'] ?? $triageLevel);
        if (triage_doctor_final_is_emergency($openCareTipsRow)) {
            $isEmergency = true;
        }
        $consult_type = $complaint !== ''
            ? $complaint
            : ($symptomList !== [] ? implode(', ', $symptomList) : 'General Consultation');
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO triage_results
                (patient_id, symptoms, chief_complaint, level, urgency_label, status, assessed_at,
                 confidence_score, severity, triage_level, triage_classification, english_complaint,
                 detected_symptoms_json, possible_conditions_json, recommendations,
                 assessment_payload, engine)
            VALUES (?, ?, ?, ?, ?, 'pending', NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $patient_id,
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
    }

    // ── Emergency: hospital referral only (no teleconsult booking) ─────────
    if ($isEmergency) {
        try {
            $pdo->prepare("UPDATE triage_results SET outcome = 'emergency_referral', status = 'completed' WHERE id = ?")
                ->execute([$triageId]);
        } catch (PDOException $e) {
            $pdo->prepare("UPDATE triage_results SET status = 'completed' WHERE id = ?")
                ->execute([$triageId]);
        }

        $providerId = patient_resolve_provider_id($pdo, $patient_id);
        $reason = patient_emergency_referral_reason($assessment, $complaint, $symptomList);
        $referralId = 0;
        if ($providerId > 0) {
            $referralId = patient_create_emergency_hospital_referral($pdo, $patient_id, $providerId, $reason);
        }

        $pdo->commit();

        patient_chief_complaint_record(
            $pdo,
            $patient_id,
            $complaint,
            'emergency_referral',
            $triageId,
            null,
            null,
            $registrationComplaintRef !== '' ? $registrationComplaintRef : null
        );

        try {
            BhwPatientWorkflow::onPatientPortalEmergency($pdo, $patient_id, [
                'triage_id'   => $triageId,
                'referral_id' => $referralId,
            ]);
        } catch (Throwable $e) {
            error_log('submit_triage emergency workflow: ' . $e->getMessage());
        }

        // highRiskPatient only (aiTriageCompleted would duplicate the emergency alert).
        try {
            NotificationEvents::highRiskPatient($pdo, $patient_id, $patientName, $label, $patient_id);
            if ($referralId > 0) {
                NotificationEvents::referralCreated($pdo, $referralId, $patient_id, $providerId, $patient_id);
            }
        } catch (Throwable $e) {
            error_log('submit_triage emergency notify: ' . $e->getMessage());
        }

        $msg = ($reuseExistingForBooking && $isEmergency)
            ? 'Your healthcare provider determined this case is an EMERGENCY. Teleconsultation is not available — please go to the nearest hospital or emergency department.'
            : 'Emergency symptoms detected. Teleconsultation is not available — please go to the nearest hospital or emergency department.';
        if ($referralId > 0) {
            $msg .= ' A hospital referral has been recorded for your care team.';
        }

        Api::success([
            'emergency'    => true,
            'booked'       => false,
            'triage_id'    => $triageId,
            'referral_id'  => $referralId,
            'level'        => $level,
            'label'        => $label,
        ], $msg);
    }

    $awaitingProviderReview = $recStatus === 'pending_approval'
        && $triageLevel === TriageLevelService::NON_URGENT;

    if ($awaitingProviderReview && $slot_id <= 0) {
        $assignedId = triage_assign_review_provider($pdo, $patient_id);
        if ($assignedId > 0) {
            triage_bind_assigned_provider($pdo, $triageId, $assignedId);
            try {
                $pdo->prepare("UPDATE triage_results SET outcome = 'awaiting_provider_review' WHERE id = ?")
                    ->execute([$triageId]);
            } catch (PDOException $e) {
                // outcome column optional on legacy schemas
            }
        } else {
            try {
                $pdo->prepare("UPDATE triage_results SET outcome = 'waiting_for_slot' WHERE id = ?")
                    ->execute([$triageId]);
            } catch (PDOException $e) {
                // outcome column optional
            }
        }

        $pdo->commit();

        patient_chief_complaint_record(
            $pdo,
            $patient_id,
            $complaint,
            'care_tips_review',
            $triageId,
            null,
            null,
            $registrationComplaintRef !== '' ? $registrationComplaintRef : null
        );

        if ($assignedId > 0) {
            NotificationEvents::aiSelfCareReviewRequired(
                $pdo,
                $assignedId,
                $patient_id,
                $patientName,
                $triageId,
                $patient_id
            );
        }

        $waitingForSlot = $assignedId <= 0;
        $waitStatus = $waitingForSlot ? 'waiting' : '';
        try {
            require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_slot_waitlist.php';
            $queued = $assignedId <= 0
                ? patient_slot_waitlist_enqueue(
                    $pdo,
                    $patient_id,
                    $triageId,
                    0,
                    $complaint,
                    $triageLevel
                )
                : patient_slot_waitlist_enqueue_if_no_assigned_slot(
                    $pdo,
                    $patient_id,
                    $triageId,
                    $assignedId,
                    $complaint,
                    $triageLevel
                );
            if ($assignedId <= 0 && !empty($queued['queued'])) {
                patient_slot_waitlist_process($pdo);
            }
            try {
                $refresh = $pdo->prepare("
                    SELECT w.status, COALESCE(tr.assigned_provider_id, w.assigned_provider_id, 0) AS assigned_id
                    FROM patient_slot_waitlist w
                    LEFT JOIN triage_results tr ON tr.id = w.triage_result_id
                    WHERE w.triage_result_id = ?
                    LIMIT 1
                ");
                $refresh->execute([$triageId]);
                $refRow = $refresh->fetch(PDO::FETCH_ASSOC);
                if ($refRow) {
                    $waitStatus = (string) ($refRow['status'] ?? $waitStatus);
                    $refAssigned = (int) ($refRow['assigned_id'] ?? 0);
                    if ($refAssigned > 0) {
                        $assignedId = $refAssigned;
                    }
                } elseif (!empty($queued['status'])) {
                    $waitStatus = (string) $queued['status'];
                }
            } catch (PDOException $e) {
                $waitStatus = (string) ($queued['status'] ?? $waitStatus);
            }
            $waitingForSlot = $assignedId <= 0 || $waitStatus === 'waiting';
        } catch (Throwable $e) {
            error_log('submit_triage waitlist enqueue: ' . $e->getMessage());
        }

        $successMsg = $assignedId <= 0
            ? 'No suitable doctor schedule is currently available. You are in the waiting queue and will be notified by email when a consultation slot becomes available.'
            : 'Your symptoms were submitted. A healthcare provider will review your case and prepare self-care guidance.';

        Api::success([
            'booked'                   => false,
            'awaiting_provider_review' => $assignedId > 0,
            'waiting_for_slot'         => $assignedId <= 0 || $waitingForSlot,
            'waitlist_status'          => $waitStatus,
            'emergency'                => false,
            'triage_id'                => $triageId,
            'assigned_provider_id'     => $assignedId,
            'level'                    => $level,
            'label'                    => $label,
        ], $successMsg);
    }

    if ($slot_id <= 0) {
        throw new RuntimeException('Please select an available appointment slot.');
    }

    $slot_stmt = $pdo->prepare("
        SELECT s.id, s.provider_id, s.slot_date, s.start_time, s.end_time, s.status,
               CONCAT(u.first_name, ' ', u.last_name) AS provider_name
        FROM appointment_slots s
        JOIN users u ON u.id = s.provider_id
        WHERE s.id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $slot_stmt->execute([$slot_id]);
    $slot = $slot_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$slot) {
        throw new RuntimeException('Selected appointment slot was not found.');
    }
    if ($slot['status'] !== 'available') {
        throw new RuntimeException('This consultation slot is no longer available. Please choose another available time.');
    }
    if ((int) $slot['provider_id'] <= 0) {
        throw new RuntimeException('Invalid provider for this slot.');
    }
    if (!appointment_slot_is_today((string) $slot['slot_date'])) {
        throw new RuntimeException('Appointments can only be booked for today.');
    }
    if (!appointment_slot_is_bookable((string) $slot['slot_date'], (string) $slot['start_time'], (string) $slot['end_time'])) {
        throw new RuntimeException('That appointment time has already passed. Please choose a later slot today.');
    }

    $provider_id   = (int) $slot['provider_id'];

    if ($triageLevel === TriageLevelService::URGENT) {
        $urgentAssigned = $reviewerBeforeBooking;
        if ($urgentAssigned <= 0 && is_array($openCareTipsRow)) {
            $urgentAssigned = (int) ($openCareTipsRow['assigned_provider_id'] ?? 0);
        }
        if ($urgentAssigned <= 0) {
            $urgentAssigned = triage_select_provider_for_level(
                $pdo,
                $patient_id,
                TriageLevelService::URGENT
            );
            if ($urgentAssigned > 0) {
                triage_bind_assigned_provider($pdo, $triageId, $urgentAssigned);
            }
        }
        if ($urgentAssigned > 0 && $urgentAssigned !== $provider_id) {
            $urgentName = triage_provider_display_name($pdo, $urgentAssigned);
            if ($urgentName === '') {
                $urgentName = 'the doctor who can see you soonest';
            }
            throw new RuntimeException(
                'Please book your urgent consultation with ' . $urgentName
                . ', who has the earliest available appointment.'
            );
        }
        if ($urgentAssigned <= 0) {
            triage_bind_assigned_provider($pdo, $triageId, $provider_id);
        }
    }

    triage_assert_patient_may_book_provider($pdo, $patient_id, $provider_id);

    if ($awaitingProviderReview || $triageLevel === TriageLevelService::URGENT) {
        triage_bind_assigned_provider($pdo, $triageId, $provider_id);
    }

    $consult_date  = (string) $slot['slot_date'];
    $consult_time  = (string) $slot['start_time'];
    $provider_name = (string) $slot['provider_name'];
    $booking_note  = 'Appointment scheduled for '
        . date('M j, Y', strtotime($consult_date))
        . ' at '
        . date('g:i A', strtotime($consult_time))
        . ' with '
        . $provider_name
        . '.';

    $consultCols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN);
    $hasTriageLink = in_array('triage_result_id', $consultCols, true);
    $existingSelect = $hasTriageLink
        ? 'SELECT id, status, consult_date, consult_time, triage_result_id'
        : 'SELECT id, status, consult_date, consult_time';

    $existing_stmt = $pdo->prepare("
        {$existingSelect}
        FROM consultations
        WHERE patient_id = ?
          AND status IN ('pending', 'scheduled', 'in_consultation', 'waiting')
        ORDER BY
          CASE LOWER(COALESCE(status, ''))
            WHEN 'in_consultation' THEN 0
            ELSE 1
          END,
          consult_date ASC,
          consult_time ASC,
          id ASC
        LIMIT 10
        FOR UPDATE
    ");
    $existing_stmt->execute([$patient_id]);
    $existingCandidates = $existing_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $existing_consult = null;
    foreach ($existingCandidates as $candidate) {
        if (strtolower((string) ($candidate['status'] ?? '')) === 'in_consultation') {
            $existing_consult = $candidate;
            break;
        }
        if (patient_consultation_keeps_chief_complaint_locked($candidate)) {
            $existing_consult = $candidate;
            break;
        }
    }

    if ($existing_consult && $existing_consult['status'] === 'in_consultation') {
        // Do not leave an orphan pending triage when booking is blocked.
        $pdo->rollBack();
        Api::error('You have a consultation in progress — finish it before booking a new appointment slot.');
    }

    // Past-due scheduled visits must not be overwritten — start a new consultation instead.
    if ($existing_consult && !patient_consultation_keeps_chief_complaint_locked($existing_consult)) {
        $existing_consult = null;
    }

    // Keep follow-up / prior-case appointments intact — only reuse today's open slot for the same case.
    if ($existing_consult && !patient_consultation_may_be_rebooked_in_place($existing_consult, $triageId)) {
        $existing_consult = null;
    }

    $hasPriorityCol = in_array('consult_priority', $consultCols, true);
    $hasOriginalCols = in_array('original_consult_date', $consultCols, true)
        && in_array('original_consult_time', $consultCols, true);
    $consultPriority = $triageLevel === TriageLevelService::URGENT ? 'urgent' : 'standard';

    if ($existing_consult) {
        $consultation_id = (int) $existing_consult['id'];

        $release = $pdo->prepare("
            UPDATE appointment_slots
            SET status = 'available', patient_id = NULL, consultation_id = NULL
            WHERE consultation_id = ?
              AND status = 'booked'
        ");
        $release->execute([$consultation_id]);

        if ($hasTriageLink && $hasPriorityCol) {
            $upd = $pdo->prepare("
                UPDATE consultations
                SET provider_id = ?,
                    provider_name = ?,
                    consult_type = ?,
                    consult_date = ?,
                    consult_time = ?,
                    status = 'scheduled',
                    consult_priority = ?,
                    triage_result_id = ?
                WHERE id = ?
                  AND patient_id = ?
            ");
            $upd->execute([
                $provider_id,
                $provider_name,
                $consult_type,
                $consult_date,
                $consult_time,
                $consultPriority,
                $triageId,
                $consultation_id,
                $patient_id,
            ]);
        } elseif ($hasTriageLink) {
            $upd = $pdo->prepare("
                UPDATE consultations
                SET provider_id = ?,
                    provider_name = ?,
                    consult_type = ?,
                    consult_date = ?,
                    consult_time = ?,
                    status = 'scheduled',
                    triage_result_id = ?
                WHERE id = ?
                  AND patient_id = ?
            ");
            $upd->execute([
                $provider_id,
                $provider_name,
                $consult_type,
                $consult_date,
                $consult_time,
                $triageId,
                $consultation_id,
                $patient_id,
            ]);
        } else {
            $upd = $pdo->prepare("
                UPDATE consultations
                SET provider_id = ?,
                    provider_name = ?,
                    consult_type = ?,
                    consult_date = ?,
                    consult_time = ?,
                    status = 'scheduled'
                WHERE id = ?
                  AND patient_id = ?
            ");
            $upd->execute([
                $provider_id,
                $provider_name,
                $consult_type,
                $consult_date,
                $consult_time,
                $consultation_id,
                $patient_id,
            ]);
        }
    } else {
        if ($hasTriageLink && $hasPriorityCol) {
            $ins = $pdo->prepare("
                INSERT INTO consultations
                    (patient_id, provider_id, provider_name, consult_type, consult_date, consult_time,
                     status, consult_priority, triage_result_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?, ?, NOW())
            ");
            $ins->execute([
                $patient_id,
                $provider_id,
                $provider_name,
                $consult_type,
                $consult_date,
                $consult_time,
                $consultPriority,
                $triageId,
            ]);
        } elseif ($hasTriageLink) {
            $ins = $pdo->prepare("
                INSERT INTO consultations
                    (patient_id, provider_id, provider_name, consult_type, consult_date, consult_time,
                     status, triage_result_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?, NOW())
            ");
            $ins->execute([
                $patient_id,
                $provider_id,
                $provider_name,
                $consult_type,
                $consult_date,
                $consult_time,
                $triageId,
            ]);
        } else {
            $ins = $pdo->prepare("
                INSERT INTO consultations
                    (patient_id, provider_id, provider_name, consult_type, consult_date, consult_time, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'scheduled', NOW())
            ");
            $ins->execute([
                $patient_id,
                $provider_id,
                $provider_name,
                $consult_type,
                $consult_date,
                $consult_time,
            ]);
        }
        $consultation_id = (int) $pdo->lastInsertId();

        if ($hasOriginalCols) {
            $pdo->prepare("
                UPDATE consultations
                SET original_consult_date = ?,
                    original_consult_time = ?
                WHERE id = ?
                  AND patient_id = ?
            ")->execute([$consult_date, $consult_time, $consultation_id, $patient_id]);
        }
    }

    if (!appointment_slot_claim_available($pdo, $slot_id, $provider_id, $patient_id, $consultation_id)) {
        throw new RuntimeException('This consultation slot is no longer available. Please choose another available time.');
    }

    // Match BHW: triage is accepted when a consult is booked.
    try {
        $pdo->prepare("UPDATE triage_results SET outcome = 'consultation_booked', status = 'accepted' WHERE id = ?")
            ->execute([$triageId]);
    } catch (PDOException $e) {
        $pdo->prepare("UPDATE triage_results SET status = 'accepted' WHERE id = ?")
            ->execute([$triageId]);
    }

    $pdo->commit();

    try {
        require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_slot_waitlist.php';
        patient_slot_waitlist_mark_booked($pdo, $patient_id, $triageId, $consultation_id);
        patient_slot_waitlist_after_slots_changed($pdo);
    } catch (Throwable $e) {
        error_log('submit_triage waitlist booked: ' . $e->getMessage());
    }

    patient_chief_complaint_record(
        $pdo,
        $patient_id,
        $complaint,
        'consultation_booking',
        $triageId,
        $consultation_id,
        $slot_id,
        $registrationComplaintRef !== '' ? $registrationComplaintRef : null
    );

    try {
        BhwPatientWorkflow::onPatientPortalBooking($pdo, $patient_id, $triageLevel);
    } catch (Throwable $e) {
        // Booking already committed — do not fail the patient response.
        error_log('submit_triage workflow: ' . $e->getMessage());
    }

    $when = date('M j, Y', strtotime($consult_date)) . ' at ' . date('g:i A', strtotime($consult_time));
    try {
        NotificationEvents::appointmentCreated($pdo, $consultation_id, $patient_id, $provider_id, $when, $patient_id);
        NotificationEvents::aiTriageCompleted($pdo, $patient_id, $label, $patient_id);
        if ($awaitingProviderReview) {
            $notifyReviewer = $reviewerBeforeBooking !== $provider_id;
            if ($notifyReviewer) {
                NotificationEvents::aiSelfCareReviewRequired(
                    $pdo,
                    $provider_id,
                    $patient_id,
                    $patientName,
                    $triageId,
                    $patient_id
                );
            }
        }
    } catch (Throwable $e) {
        error_log('submit_triage notify: ' . $e->getMessage());
    }

    Api::success([
        'level'            => $level,
        'label'            => $label,
        'booked'           => true,
        'emergency'        => false,
        'triage_id'        => $triageId,
        'reused_existing_triage' => false,
        'consultation_id'  => $consultation_id,
        'consult_date'     => $consult_date,
        'consult_time'     => $consult_time,
        'provider_name'    => $provider_name,
        'booking_note'     => $booking_note,
    ], 'Your appointment has been booked successfully. ' . $booking_note);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    Api::error($e->getMessage());
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if (stripos($e->getMessage(), 'Unknown column') !== false) {
        triage_assessment_ensure_schema($pdo);
        Api::error('Assessment schema was updated. Please submit again.', 409);
    }

    Api::error('Database error while booking. Please try again.', 500);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('submit_triage: ' . $e->getMessage());
    Api::error('Could not complete booking. Please try again.', 500);
}
