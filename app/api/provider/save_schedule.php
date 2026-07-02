<?php
/**
 * API: Save Provider Schedule and Generate Appointment Slots
 * URL: /app/api/provider/save_schedule.php
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/appointment_slots.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'provider') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$provider_id = (int) $_SESSION['user_id'];
$day         = trim((string) ($_POST['day'] ?? ''));
$start_time  = trim((string) ($_POST['start_time'] ?? ''));
$end_time    = trim((string) ($_POST['end_time'] ?? ''));
$duration    = (int) ($_POST['duration'] ?? 30);
$is_active   = isset($_POST['is_active']) ? 1 : 0;

$valid_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
if (!in_array($day, $valid_days, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid day of week.']);
    exit;
}

$today_name = date('l');
if ($day !== $today_name) {
    echo json_encode(['success' => false, 'message' => 'You can only update today\'s schedule (' . $today_name . ').']);
    exit;
}

if ($start_time === '' || $end_time === '') {
    echo json_encode(['success' => false, 'message' => 'Day and working hours are required.']);
    exit;
}

if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start_time) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end_time)) {
    echo json_encode(['success' => false, 'message' => 'Invalid time format.']);
    exit;
}

if (strlen($start_time) === 5) {
    $start_time .= ':00';
}
if (strlen($end_time) === 5) {
    $end_time .= ':00';
}

if (strtotime($end_time) <= strtotime($start_time)) {
    echo json_encode(['success' => false, 'message' => 'End time must be later than start time.']);
    exit;
}

if (!in_array($duration, [15, 30, 45, 60], true)) {
    $duration = 30;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO provider_schedules (provider_id, day_of_week, start_time, end_time, slot_duration, is_active)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            start_time = VALUES(start_time),
            end_time = VALUES(end_time),
            slot_duration = VALUES(slot_duration),
            is_active = VALUES(is_active)
    ");
    $stmt->execute([$provider_id, $day, $start_time, $end_time, $duration, $is_active]);

    appointment_slots_clear_day($pdo, $provider_id, $day);

    $slots_created = 0;
    if ($is_active) {
        $slots_created = appointment_slots_sync_today($pdo, $provider_id);
    }

    $pdo->commit();

    echo json_encode([
        'success'       => true,
        'message'       => 'Schedule updated and today\'s slots are now available for patients.',
        'slots_created' => $slots_created,
        'today'         => date('Y-m-d'),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
