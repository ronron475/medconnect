<?php
/**
 * Patient API: live booking / waitlist state for the dashboard (no page reload).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_slot_waitlist.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_symptoms_review_submit.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_booking_status.php';

Api::startJson();
Api::requirePatientReady($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Api::error('Method not allowed.', 405);
}

$patientId = (int) ($_SESSION['user_id'] ?? 0);
Api::releaseSession();

try {
    patient_slot_waitlist_process_throttled($pdo);
    $wait = patient_slot_waitlist_dashboard_state($pdo, $patientId);
    $pending = patient_symptoms_review_pending_state($pdo, $patientId);
    $ready = patient_care_tips_ready_to_schedule_state($pdo, $patientId);
    $hasOpen = patient_portal_has_open_consultation($pdo, $patientId);

    Api::success([
        'waitlist' => $wait,
        'symptoms_review_pending' => $pending,
        'care_tips_ready' => $ready,
        'has_open_consultation' => $hasOpen,
    ]);
} catch (Throwable $e) {
    error_log('booking_state: ' . $e->getMessage());
    Api::error('Could not load booking state.', 500);
}
