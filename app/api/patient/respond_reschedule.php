<?php
/**
 * API: Patient accepts or declines a pending reschedule request.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/appointment_reschedule.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';

Api::startJson();
Api::requirePatientReady($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Api::error('Method not allowed.', 405);
}

auth_csrf_require();

$patientId = (int) ($_SESSION['user_id'] ?? 0);
$rescheduleId = (int) ($_POST['reschedule_id'] ?? 0);
$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$note = trim((string) ($_POST['note'] ?? ''));

if (!in_array($action, ['accept', 'decline'], true)) {
    Api::error('Invalid action. Use accept or decline.');
}

$result = appointment_reschedule_patient_respond(
    $pdo,
    $patientId,
    $rescheduleId,
    $action === 'accept',
    $note
);

if (!$result['ok']) {
    Api::error($result['message']);
}

Api::success(['reschedule_id' => $rescheduleId], $result['message']);
