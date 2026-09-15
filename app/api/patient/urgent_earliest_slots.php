<?php
/**
 * Patient API: earliest bookable slot today per eligible provider (urgent booking helper).
 *
 * Uses the shared appointment_slots pool. Does not lock URGENT patients to one doctor.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/appointment_slots.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/triage_provider_assignment.php';

Api::startJson();
Api::requirePatientReady($pdo);

$patientId = (int) $_SESSION['user_id'];

try {
    $ctx = triage_patient_review_booking_context($pdo, $patientId);
    $urgentChoice = triage_patient_is_urgent_choice_context($ctx);
    $locked = !empty($ctx['locked']) && (int) ($ctx['provider_id'] ?? 0) > 0 && !$urgentChoice;
    $lockedProviderId = $locked ? (int) $ctx['provider_id'] : 0;

    $options = triage_urgent_booking_options($pdo, $lockedProviderId);

    Api::success([
        'today' => appointment_now()->format('Y-m-d'),
        'locked' => $locked,
        'locked_provider_id' => $lockedProviderId,
        'urgent_choice' => $urgentChoice || !$locked,
        'options' => $options,
        'count' => count($options),
        'recommended_provider_id' => (int) ($options[0]['provider_id'] ?? 0),
    ]);
} catch (PDOException $e) {
    Api::error('Could not load earliest slots: ' . $e->getMessage(), 500);
}
