<?php
/**
 * API: Submit patient triage and book a scheduled appointment slot.
 * Uses AI Assessment Engine for NLP-driven triage classification.
 *
 * Emergency: saves triage + hospital referral; never books teleconsult (aligned with BHW).
 * Booking: same-day only; auto-accepts triage; will not overwrite a future (multi-day) appointment.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/appointment_slots.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/consultation_expiry.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/triage_assessment_schema.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/TriageLevelService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_patient_workflow.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';

Api::startJson();
Api::requirePatientReady($pdo);
Api::requirePost();
Api::requireCsrf();

$patient_id = (int) $_SESSION['user_id'];
consultations_auto_expire($pdo, $patient_id);
triage_assessment_ensure_schema($pdo);
BhwPatientWorkflow::ensure_schema($pdo);

$symptoms   = $_POST['symptoms'] ?? [];
$complaint  = trim((string) ($_POST['chief_complaint'] ?? ''));
$slot_id    = (int) ($_POST['slot_id'] ?? 0);

if (!is_array($symptoms)) {
    $symptoms = [];
}

if (empty($symptoms) && $complaint === '') {
    Api::error('Please provide symptoms or a complaint.');
}

$symptomList = array_values(array_filter(array_map(static function ($s) {
    return is_string($s) ? trim($s) : '';
}, $symptoms)));

$assessment = MedicalAssessmentEngine::assess($complaint, $symptomList);

// Merge silent registration NLP (provider-facing detail; never shown to patient)
$regNlp = null;
$regNlpRaw = trim((string) ($_POST['registration_nlp_json'] ?? ''));
if ($regNlpRaw !== '') {
    $decoded = json_decode($regNlpRaw, true);
    if (is_array($decoded)) {
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
    }
}

$level = (string) ($assessment['db_level'] ?? '3');
$label = (string) ($assessment['urgency_label'] ?? 'Routine');
$triageLevel = TriageLevelService::fromAssessment($assessment);

// Prefer registration urgency classification when available
if (is_array($regNlp) && !empty($regNlp['urgency'])) {
    $u = strtoupper((string) $regNlp['urgency']);
    if ($u === 'EMERGENCY') {
        $level = '1';
        $label = 'Emergency';
        $triageLevel = TriageLevelService::EMERGENCY;
        $assessment['severity']['severity'] = 'emergency';
        $assessment['triage']['triage_classification'] = 'EMERGENCY';
    } elseif ($u === 'URGENT') {
        $level = '2';
        $label = 'Urgent';
        $triageLevel = TriageLevelService::URGENT;
        $assessment['severity']['severity'] = 'urgent';
        $assessment['triage']['triage_classification'] = 'URGENT';
    }
}

$isEmergency = $triageLevel === TriageLevelService::EMERGENCY
    || strtoupper((string) ($assessment['triage']['triage_classification'] ?? '')) === 'EMERGENCY';

$consult_type = $complaint !== ''
    ? $complaint
    : ($symptomList !== [] ? implode(', ', $symptomList) : 'General Consultation');

$nameStmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) FROM users WHERE id = ? LIMIT 1');
$nameStmt->execute([$patient_id]);
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

        BhwPatientWorkflow::onPatientPortalEmergency($pdo, $patient_id, [
            'triage_id'   => $triageId,
            'referral_id' => $referralId,
        ]);

        // highRiskPatient only (aiTriageCompleted would duplicate the emergency alert).
        NotificationEvents::highRiskPatient($pdo, $patient_id, $patientName, $label, $patient_id);
        if ($referralId > 0) {
            NotificationEvents::referralCreated($pdo, $referralId, $patient_id, $providerId, $patient_id);
        }

        $msg = 'Emergency symptoms detected. Teleconsultation is not available — please go to the nearest hospital or emergency department.';
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
            'assessment'   => $assessment,
        ], $msg);
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
        throw new RuntimeException('That appointment slot is no longer available. Please choose another time.');
    }
    if (!appointment_slot_is_today((string) $slot['slot_date'])) {
        throw new RuntimeException('Appointments can only be booked for today.');
    }
    if (!appointment_slot_is_bookable((string) $slot['slot_date'], (string) $slot['start_time'], (string) $slot['end_time'])) {
        throw new RuntimeException('That appointment time has already passed. Please choose a later slot today.');
    }

    $provider_id   = (int) $slot['provider_id'];
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

    $existing_stmt = $pdo->prepare("
        SELECT id, status, consult_date, consult_time
        FROM consultations
        WHERE patient_id = ?
          AND status IN ('pending', 'scheduled', 'in_consultation')
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
    ");
    $existing_stmt->execute([$patient_id]);
    $existing_consult = $existing_stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing_consult && $existing_consult['status'] === 'in_consultation') {
        // Do not leave an orphan pending triage when booking is blocked.
        $pdo->rollBack();
        Api::error('You have a consultation in progress — finish it before booking a new appointment slot.');
    }

    // Protect BHW (or any) future-day appointments from same-day patient rebook overwrite.
    if ($existing_consult && consultation_is_future_day($existing_consult['consult_date'] ?? null)) {
        $futureLabel = date('M j, Y', strtotime((string) $existing_consult['consult_date']));
        if (!empty($existing_consult['consult_time'])) {
            $futureLabel .= ' at ' . date('g:i A', strtotime((string) $existing_consult['consult_time']));
        }
        throw new RuntimeException(
            'You already have an appointment scheduled for ' . $futureLabel
            . '. Cancel or complete that visit before booking a new slot today.'
        );
    }

    $consultCols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN);
    $hasTriageLink = in_array('triage_result_id', $consultCols, true);
    $hasPriorityCol = in_array('consult_priority', $consultCols, true);
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
    }

    $book = $pdo->prepare("
        UPDATE appointment_slots
        SET status = 'booked',
            patient_id = ?,
            consultation_id = ?
        WHERE id = ?
          AND status = 'available'
    ");
    $book->execute([$patient_id, $consultation_id, $slot_id]);

    if ($book->rowCount() === 0) {
        throw new RuntimeException('Could not book the selected slot. It may have just been taken.');
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

    BhwPatientWorkflow::onPatientPortalBooking($pdo, $patient_id, $triageLevel);

    $when = date('M j, Y', strtotime($consult_date)) . ' at ' . date('g:i A', strtotime($consult_time));
    NotificationEvents::appointmentCreated($pdo, $consultation_id, $patient_id, $provider_id, $when, $patient_id);
    NotificationEvents::aiTriageCompleted($pdo, $patient_id, $label, $patient_id);

    Api::success([
        'level'            => $level,
        'label'            => $label,
        'booked'           => true,
        'emergency'        => false,
        'triage_id'        => $triageId,
        'consultation_id'  => $consultation_id,
        'consult_date'     => $consult_date,
        'consult_time'     => $consult_time,
        'provider_name'    => $provider_name,
        'booking_note'     => $booking_note,
        'assessment'       => $assessment,
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

    Api::error('Database error: ' . $e->getMessage(), 500);
}
