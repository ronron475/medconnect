<?php
/**
 * Patient consultation cancel + appointment slot release.
 */

declare(strict_types=1);

require_once __DIR__ . '/notification_events.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/triage_assessment_schema.php';

/**
 * Free any booked slot linked to a consultation so another patient can take it.
 * Prefer consultation_id; fall back to patient + date/time when the link is missing.
 */
function consultation_release_booked_slots(
    PDO $pdo,
    int $consultationId,
    ?int $patientId = null,
    ?string $consultDate = null,
    ?string $consultTime = null
): int {
    if ($consultationId <= 0) {
        return 0;
    }

    require_once __DIR__ . '/appointment_slots.php';
    appointment_schedule_ensure_schema($pdo);

    $freed = appointment_slot_set_consultation_status($pdo, $consultationId, 'cancelled');
    if ($freed > 0) {
        return $freed;
    }

    // Legacy / edge cases: booked by patient+time without consultation_id link.
    if ($freed === 0 && $patientId !== null && $patientId > 0 && $consultDate && $consultTime) {
        $date = substr($consultDate, 0, 10);
        $time = substr($consultTime, 0, 8);
        if (strlen($time) === 5) {
            $time .= ':00';
        }
        $fallback = $pdo->prepare("
            UPDATE appointment_slots
            SET status = 'cancelled',
                patient_id = NULL
            WHERE patient_id = ?
              AND slot_date = ?
              AND start_time = ?
              AND status = 'booked'
              AND (consultation_id IS NULL OR consultation_id = 0 OR consultation_id = ?)
        ");
        $fallback->execute([$patientId, $date, $time, $consultationId]);
        $freed = (int) $fallback->rowCount();
    }

    return $freed;
}

/**
 * Patient cancels a pending/scheduled visit and immediately frees the slot
 * so it returns to the provider’s open schedule for other patients.
 *
 * @return array{ok:bool,message:string,slots_freed?:int,consultation_id?:int}
 */
function patient_cancel_consultation(PDO $pdo, int $patientId, int $consultationId, string $reason = ''): array
{
    if ($patientId <= 0 || $consultationId <= 0) {
        return ['ok' => false, 'message' => 'Invalid consultation.'];
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT id, patient_id, provider_id, status, consult_date, consult_time, provider_name
            FROM consultations
            WHERE id = ?
              AND patient_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$consultationId, $patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Appointment not found.'];
        }

        $status = (string) ($row['status'] ?? '');
        if ($status === 'in_consultation') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This visit is already in progress and cannot be cancelled here.'];
        }
        if (!in_array($status, ['pending', 'scheduled'], true)) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This appointment is no longer active.'];
        }

        $upd = $pdo->prepare("
            UPDATE consultations
            SET status = 'cancelled'
            WHERE id = ?
              AND patient_id = ?
              AND status IN ('pending', 'scheduled')
        ");
        $upd->execute([$consultationId, $patientId]);
        if ($upd->rowCount() < 1) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Could not cancel this appointment. Please refresh and try again.'];
        }

        $slotsFreed = consultation_release_booked_slots(
            $pdo,
            $consultationId,
            $patientId,
            (string) ($row['consult_date'] ?? ''),
            (string) ($row['consult_time'] ?? '')
        );

        try {
            $pdo->prepare("
                UPDATE video_sessions
                SET status = 'ended', ended_at = NOW()
                WHERE consultation_id = ?
                  AND status = 'active'
            ")->execute([$consultationId]);
        } catch (PDOException $e) {
            // optional table
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Could not cancel appointment. Please try again.'];
    }

    // Unlock previous care-tips / chief complaint so the patient can start a new case.
    require_once __DIR__ . '/patient_booking_status.php';
    patient_triage_close_cases_for_consultation($pdo, $consultationId);

    $providerId = (int) ($row['provider_id'] ?? 0);
    $reasonNote = trim($reason);
    audit_log($pdo, [
        'patient_id'  => $patientId,
        'action_type' => 'CONSULTATION_CANCELLED_BY_PATIENT',
        'description' => 'Patient cancelled consultation #' . $consultationId
            . ($reasonNote !== '' ? (' — ' . $reasonNote) : '')
            . '; freed ' . $slotsFreed . ' slot(s).',
    ]);

    if ($providerId > 0) {
        NotificationEvents::appointmentCancelled($pdo, $consultationId, $patientId, $providerId, $patientId);
    }

    return [
        'ok' => true,
        'message' => 'Appointment cancelled. The doctor’s time slot is free again for other patients.',
        'slots_freed' => $slotsFreed,
        'consultation_id' => $consultationId,
    ];
}

/**
 * Latest upcoming bookable consultation for a patient (pending/scheduled, today or future).
 *
 * @return array{id:int,provider_name:string,consult_date:string,consult_time:string,label:string}|null
 */
function patient_upcoming_cancellable_consultation(PDO $pdo, int $patientId): ?array
{
    if ($patientId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT id, provider_name, consult_date, consult_time, status
        FROM consultations
        WHERE patient_id = ?
          AND status IN ('pending', 'scheduled')
          AND consult_date >= CURDATE()
        ORDER BY consult_date ASC, consult_time ASC
        LIMIT 1
    ");
    $stmt->execute([$patientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $date = (string) ($row['consult_date'] ?? '');
    $time = (string) ($row['consult_time'] ?? '');
    $when = $date !== '' ? date('M j, Y', strtotime($date)) : 'scheduled time';
    if ($time !== '') {
        $when .= ' at ' . date('g:i A', strtotime($time));
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'provider_name' => trim((string) ($row['provider_name'] ?? 'Your doctor')),
        'consult_date' => $date,
        'consult_time' => $time,
        'label' => $when,
    ];
}

/**
 * When tips were approved and patient still has a booked visit — prompt to cancel.
 *
 * @return array{tip_id:int,chief_complaint:string,upcoming_consultation:array}|null
 */
function patient_tips_ready_cancel_prompt(PDO $pdo, int $patientId): ?array
{
    if ($patientId <= 0) {
        return null;
    }

    $upcoming = patient_upcoming_cancellable_consultation($pdo, $patientId);
    if ($upcoming === null || (int) ($upcoming['id'] ?? 0) <= 0) {
        return null;
    }

    triage_assessment_ensure_schema($pdo);

    $stmt = $pdo->prepare("
        SELECT id, chief_complaint, recommendation_approved_at
        FROM triage_results
        WHERE patient_id = ?
          AND recommendation_status = 'approved'
          AND TRIM(COALESCE(chief_complaint, '')) <> ''
          AND recommendation_approved_at IS NOT NULL
          AND recommendation_approved_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
        ORDER BY recommendation_approved_at DESC
        LIMIT 1
    ");
    $stmt->execute([$patientId]);
    $tip = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tip) {
        return null;
    }

    return [
        'tip_id' => (int) ($tip['id'] ?? 0),
        'chief_complaint' => trim((string) ($tip['chief_complaint'] ?? '')),
        'upcoming_consultation' => $upcoming,
    ];
}
