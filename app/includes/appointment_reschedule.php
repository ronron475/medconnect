<?php
/**
 * Controlled appointment reschedule workflow (provider-initiated, patient confirmation).
 */

declare(strict_types=1);

require_once __DIR__ . '/appointment_slots.php';
require_once __DIR__ . '/appointment_schedule_schema.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/notification_events.php';

/**
 * @return array<int, array<string, mixed>>
 */
function appointment_reschedule_pending_for_patient(PDO $pdo, int $patientId): array
{
    appointment_schedule_ensure_schema($pdo);
    if ($patientId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.consultation_id,
            r.provider_id,
            r.patient_id,
            r.old_slot_id,
            r.new_slot_id,
            r.old_date,
            r.old_time,
            r.new_date,
            r.new_time,
            r.reason,
            r.status,
            r.requested_at,
            CONCAT(u.first_name, ' ', u.last_name) AS provider_name
        FROM appointment_reschedule_log r
        JOIN users u ON u.id = r.provider_id
        WHERE r.patient_id = ?
          AND r.status = 'pending_patient'
        ORDER BY r.requested_at DESC
    ");
    $stmt->execute([$patientId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Provider requests reschedule — reserves new slot as blocked until patient responds.
 *
 * @return array{ok:bool,message:string,reschedule_id?:int}
 */
function appointment_reschedule_provider_request(
    PDO $pdo,
    int $providerId,
    int $consultationId,
    int $newSlotId,
    string $reason,
    int $requestedBy
): array {
    appointment_schedule_ensure_schema($pdo);
    $reason = trim($reason);

    if ($providerId <= 0 || $consultationId <= 0 || $newSlotId <= 0) {
        return ['ok' => false, 'message' => 'Invalid reschedule request.'];
    }
    if ($reason === '') {
        return ['ok' => false, 'message' => 'Please provide a reason for the reschedule.'];
    }

    try {
        $pdo->beginTransaction();

        $consultStmt = $pdo->prepare("
            SELECT id, patient_id, provider_id, consult_date, consult_time, status, reschedule_status
            FROM consultations
            WHERE id = ?
              AND provider_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $consultStmt->execute([$consultationId, $providerId]);
        $consult = $consultStmt->fetch(PDO::FETCH_ASSOC);
        if (!$consult) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Appointment not found.'];
        }

        $consultStatus = (string) ($consult['status'] ?? '');
        if (!in_array($consultStatus, ['pending', 'scheduled'], true)) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Only active scheduled appointments can be rescheduled.'];
        }
        if ((string) ($consult['reschedule_status'] ?? 'none') === 'pending_patient') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'A reschedule request is already pending for this appointment.'];
        }

        $oldSlotStmt = $pdo->prepare("
            SELECT id, provider_id, slot_date, start_time, end_time, status, patient_id, consultation_id
            FROM appointment_slots
            WHERE consultation_id = ?
              AND status = 'booked'
            LIMIT 1
            FOR UPDATE
        ");
        $oldSlotStmt->execute([$consultationId]);
        $oldSlot = $oldSlotStmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldSlot) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Booked time slot not found for this appointment.'];
        }

        $newSlotStmt = $pdo->prepare("
            SELECT id, provider_id, slot_date, start_time, end_time, status
            FROM appointment_slots
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $newSlotStmt->execute([$newSlotId]);
        $newSlot = $newSlotStmt->fetch(PDO::FETCH_ASSOC);
        if (!$newSlot) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Selected new time slot was not found.'];
        }
        if ((int) $newSlot['provider_id'] !== $providerId) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'The new slot must belong to your schedule.'];
        }
        if ((string) $newSlot['status'] !== 'available') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'The selected time slot is no longer available.'];
        }
        if (!appointment_slot_is_bookable((string) $newSlot['slot_date'], (string) $newSlot['start_time'], (string) $newSlot['end_time'])) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'The selected time is not valid for booking.'];
        }

        $patientId = (int) ($consult['patient_id'] ?? 0);
        $oldDate = (string) ($oldSlot['slot_date'] ?? $consult['consult_date']);
        $oldTime = (string) ($oldSlot['start_time'] ?? $consult['consult_time']);
        $newDate = (string) $newSlot['slot_date'];
        $newTime = (string) $newSlot['start_time'];

        $block = $pdo->prepare("
            UPDATE appointment_slots
            SET status = 'blocked',
                consultation_id = ?,
                patient_id = ?
            WHERE id = ?
              AND status = 'available'
        ");
        $block->execute([$consultationId, $patientId, $newSlotId]);
        if ($block->rowCount() < 1) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Could not reserve the new time slot. Please choose another.'];
        }

        $log = $pdo->prepare("
            INSERT INTO appointment_reschedule_log (
                consultation_id, provider_id, patient_id,
                old_slot_id, new_slot_id,
                old_date, old_time, new_date, new_time,
                reason, status, requested_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_patient', ?)
        ");
        $log->execute([
            $consultationId,
            $providerId,
            $patientId,
            (int) $oldSlot['id'],
            $newSlotId,
            $oldDate,
            $oldTime,
            $newDate,
            $newTime,
            $reason,
            $requestedBy,
        ]);
        $rescheduleId = (int) $pdo->lastInsertId();

        $pdo->prepare("
            UPDATE consultations
            SET reschedule_status = 'pending_patient'
            WHERE id = ?
        ")->execute([$consultationId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('appointment_reschedule_provider_request: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not submit reschedule request. Please try again.'];
    }

    $oldWhen = date('M j, Y', strtotime($oldDate)) . ' at ' . date('g:i A', strtotime($oldTime));
    $newWhen = date('M j, Y', strtotime($newDate)) . ' at ' . date('g:i A', strtotime($newTime));

    audit_log($pdo, [
        'patient_id'  => $patientId,
        'action_type' => 'appointment_reschedule_requested',
        'description' => 'Provider requested reschedule for consultation #' . $consultationId
            . ' from ' . $oldWhen . ' to ' . $newWhen . '.',
        'meta'        => [
            'consultation_id' => $consultationId,
            'reschedule_id'   => $rescheduleId,
            'provider_id'     => $providerId,
            'old_date'        => $oldDate,
            'old_time'        => $oldTime,
            'new_date'        => $newDate,
            'new_time'        => $newTime,
            'reason'          => $reason,
        ],
    ]);

    try {
        NotificationEvents::appointmentRescheduleRequested(
            $pdo,
            $consultationId,
            $patientId,
            $providerId,
            $oldWhen,
            $newWhen,
            $reason,
            $providerId
        );
    } catch (Throwable $e) {
        error_log('appointment_reschedule notify: ' . $e->getMessage());
    }

    return [
        'ok' => true,
        'message' => 'Reschedule request sent. The patient must confirm before the appointment time changes.',
        'reschedule_id' => $rescheduleId,
    ];
}

/**
 * Patient accepts or declines a pending reschedule.
 *
 * @return array{ok:bool,message:string}
 */
function appointment_reschedule_patient_respond(
    PDO $pdo,
    int $patientId,
    int $rescheduleId,
    bool $accept,
    string $patientNote = ''
): array {
    appointment_schedule_ensure_schema($pdo);

    if ($patientId <= 0 || $rescheduleId <= 0) {
        return ['ok' => false, 'message' => 'Invalid request.'];
    }

    try {
        $pdo->beginTransaction();

        $reqStmt = $pdo->prepare("
            SELECT *
            FROM appointment_reschedule_log
            WHERE id = ?
              AND patient_id = ?
              AND status = 'pending_patient'
            LIMIT 1
            FOR UPDATE
        ");
        $reqStmt->execute([$rescheduleId, $patientId]);
        $req = $reqStmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Reschedule request not found or already handled.'];
        }

        $consultationId = (int) $req['consultation_id'];
        $providerId = (int) $req['provider_id'];
        $oldSlotId = (int) ($req['old_slot_id'] ?? 0);
        $newSlotId = (int) ($req['new_slot_id'] ?? 0);

        if (!$accept) {
            if ($newSlotId > 0) {
                $pdo->prepare("
                    UPDATE appointment_slots
                    SET status = 'available', patient_id = NULL, consultation_id = NULL
                    WHERE id = ?
                      AND status = 'blocked'
                      AND consultation_id = ?
                ")->execute([$newSlotId, $consultationId]);
            }

            $pdo->prepare("
                UPDATE appointment_reschedule_log
                SET status = 'declined',
                    responded_at = NOW(),
                    responded_by = ?,
                    patient_note = ?
                WHERE id = ?
            ")->execute([$patientId, trim($patientNote) ?: null, $rescheduleId]);

            $pdo->prepare("
                UPDATE consultations
                SET reschedule_status = 'none'
                WHERE id = ?
            ")->execute([$consultationId]);

            $pdo->commit();

            audit_log($pdo, [
                'patient_id'  => $patientId,
                'action_type' => 'appointment_reschedule_declined',
                'description' => 'Patient declined reschedule for consultation #' . $consultationId . '.',
                'meta'        => ['reschedule_id' => $rescheduleId],
            ]);

            try {
                NotificationEvents::appointmentRescheduleDeclined($pdo, $consultationId, $patientId, $providerId, $patientId);
            } catch (Throwable $e) {
                error_log('appointment_reschedule decline notify: ' . $e->getMessage());
            }

            return ['ok' => true, 'message' => 'You declined the reschedule. Your original appointment time stays the same.'];
        }

        $consultStmt = $pdo->prepare("
            SELECT id, consult_date, consult_time, original_consult_date, original_consult_time, status
            FROM consultations
            WHERE id = ?
              AND patient_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $consultStmt->execute([$consultationId, $patientId]);
        $consult = $consultStmt->fetch(PDO::FETCH_ASSOC);
        if (!$consult || !in_array((string) ($consult['status'] ?? ''), ['pending', 'scheduled'], true)) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This appointment is no longer active.'];
        }

        $newSlotStmt = $pdo->prepare("
            SELECT id, slot_date, start_time, end_time, status
            FROM appointment_slots
            WHERE id = ?
              AND consultation_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $newSlotStmt->execute([$newSlotId, $consultationId]);
        $newSlot = $newSlotStmt->fetch(PDO::FETCH_ASSOC);
        if (!$newSlot || (string) $newSlot['status'] !== 'blocked') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'The proposed time slot is no longer reserved. Please contact your doctor.'];
        }

        if ($oldSlotId > 0) {
            $pdo->prepare("
                UPDATE appointment_slots
                SET status = 'cancelled', patient_id = NULL
                WHERE id = ?
                  AND consultation_id = ?
                  AND status = 'booked'
            ")->execute([$oldSlotId, $consultationId]);
        }

        $book = $pdo->prepare("
            UPDATE appointment_slots
            SET status = 'booked',
                patient_id = ?
            WHERE id = ?
              AND status = 'blocked'
              AND consultation_id = ?
        ");
        $book->execute([$patientId, $newSlotId, $consultationId]);
        if ($book->rowCount() < 1) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Could not confirm the new time slot. Please try again.'];
        }

        $origDate = (string) ($consult['original_consult_date'] ?? '');
        $origTime = (string) ($consult['original_consult_time'] ?? '');
        if ($origDate === '' || $origTime === '') {
            $origDate = (string) $consult['consult_date'];
            $origTime = (string) $consult['consult_time'];
        }

        $newDate = (string) $newSlot['slot_date'];
        $newTime = (string) $newSlot['start_time'];

        $pdo->prepare("
            UPDATE consultations
            SET consult_date = ?,
                consult_time = ?,
                original_consult_date = COALESCE(original_consult_date, ?),
                original_consult_time = COALESCE(original_consult_time, ?),
                status = 'scheduled',
                reschedule_status = 'none'
            WHERE id = ?
              AND patient_id = ?
        ")->execute([
            $newDate,
            $newTime,
            $origDate,
            $origTime,
            $consultationId,
            $patientId,
        ]);

        $pdo->prepare("
            UPDATE appointment_reschedule_log
            SET status = 'accepted',
                responded_at = NOW(),
                responded_by = ?,
                patient_note = ?
            WHERE id = ?
        ")->execute([$patientId, trim($patientNote) ?: null, $rescheduleId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('appointment_reschedule_patient_respond: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not process your response. Please try again.'];
    }

    $oldWhen = date('M j, Y', strtotime((string) $req['old_date'])) . ' at ' . date('g:i A', strtotime((string) $req['old_time']));
    $newWhen = date('M j, Y', strtotime((string) $req['new_date'])) . ' at ' . date('g:i A', strtotime((string) $req['new_time']));

    audit_log($pdo, [
        'patient_id'  => $patientId,
        'action_type' => 'appointment_reschedule_accepted',
        'description' => 'Patient accepted reschedule for consultation #' . $consultationId
            . ' from ' . $oldWhen . ' to ' . $newWhen . '.',
        'meta'        => [
            'reschedule_id' => $rescheduleId,
            'consultation_id' => $consultationId,
            'old_date' => $req['old_date'],
            'old_time' => $req['old_time'],
            'new_date' => $req['new_date'],
            'new_time' => $req['new_time'],
        ],
    ]);

    try {
        NotificationEvents::appointmentRescheduled(
            $pdo,
            $consultationId,
            $patientId,
            $providerId,
            $newWhen,
            $patientId,
            $oldWhen
        );
    } catch (Throwable $e) {
        error_log('appointment_reschedule accepted notify: ' . $e->getMessage());
    }

    return [
        'ok' => true,
        'message' => 'Your appointment is now confirmed for ' . $newWhen . '.',
    ];
}
