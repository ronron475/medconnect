<?php
/**
 * No-show handling when a slot ends.
 *
 * A slot end does not complete a visit the patient already joined, and it does
 * not cancel a patient who is waiting because the doctor is still with someone
 * earlier in the queue. Those patients stay scheduled.
 *
 * A patient who never joined, and was not held behind an earlier visit, is
 * cancelled when the slot ends. That cancellation is the existing no-show rule.
 * It does not mark the consultation completed and does not write a SOAP note.
 */
require_once __DIR__ . '/patient_booking_status.php';
require_once __DIR__ . '/consultation_queue_timing.php';

/**
 * @return int Number of consultations updated
 */
function consultations_auto_expire(PDO $pdo, ?int $patient_id = null, ?int $provider_id = null): int
{
    $updated = consultation_timing_close_missed($pdo, $patient_id, $provider_id);

    if ($updated > 0) {
        try {
            require_once __DIR__ . '/patient_slot_waitlist.php';
            patient_slot_waitlist_after_slots_changed($pdo);
        } catch (Throwable $e) {
            error_log('consultations_auto_expire waitlist: ' . $e->getMessage());
        }
    }

    return $updated;
}
