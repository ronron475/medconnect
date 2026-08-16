<?php
/**
 * API: Available appointment slots from provider schedule.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/appointment_slots.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/user_account_status.php';

Api::startJson();
Api::requirePatientReady($pdo);
Api::releaseSession();

$bookingCheck = patient_account_may_submit_consultation($pdo, (int) $_SESSION['user_id']);
if (!$bookingCheck['allowed']) {
    Api::error($bookingCheck['message'], 403, ['code' => $bookingCheck['code'] ?? 'account_blocked']);
}

$provider_id = (int) ($_GET['provider_id'] ?? 0);
$date        = trim((string) ($_GET['date'] ?? ''));

if ($provider_id <= 0) {
    Api::error('Provider ID is required.');
}

$today = appointment_now()->format('Y-m-d');
if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = $today;
}

try {
    $livePoll = isset($_GET['live']) && (string) $_GET['live'] === '1';
    $payload = appointment_slots_patient_today($pdo, $provider_id, !$livePoll);
    // Patients book today only in Asia/Manila. Ignore a mismatched client date
    // so browser timezone cannot empty the grid.
    $payload['requested_date'] = $date;
    $payload['date'] = $payload['today'];

    Api::success($payload);
} catch (PDOException $e) {
    Api::error('Could not load slots: ' . $e->getMessage(), 500);
}
