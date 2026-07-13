<?php
/**
 * API: Save Provider Schedule Sessions and Generate Appointment Slots
 * URL: /app/api/provider/save_schedule.php
 *
 * POST:
 *   day         — weekday name (today only)
 *   is_active   — 1|0 day-level booking toggle
 *   sessions    — JSON array [{id?, start_time, end_time, duration}]
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
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_schedule_sessions.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'provider') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
auth_csrf_require();

$provider_id = (int) $_SESSION['user_id'];
$day         = trim((string) ($_POST['day'] ?? ''));
$day_active  = isset($_POST['is_active']) && (string) $_POST['is_active'] !== '0';
$sessionsRaw = $_POST['sessions'] ?? '[]';

if (!in_array($day, provider_schedule_valid_days(), true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid day of week.']);
    exit;
}

$today_name = date('l');
if ($day !== $today_name) {
    echo json_encode(['success' => false, 'message' => 'You can only update today\'s schedule (' . $today_name . ').']);
    exit;
}

$sessionsInput = is_array($sessionsRaw) ? $sessionsRaw : json_decode((string) $sessionsRaw, true);
if (!is_array($sessionsInput)) {
    echo json_encode(['success' => false, 'message' => 'Invalid sessions payload.']);
    exit;
}

// Backward-compatible single-range POST
if ($sessionsInput === [] && !empty($_POST['start_time']) && !empty($_POST['end_time'])) {
    $sessionsInput = [[
        'start_time' => $_POST['start_time'],
        'end_time'   => $_POST['end_time'],
        'duration'   => (int) ($_POST['duration'] ?? 30),
    ]];
}

$validation = provider_schedule_validate_sessions($sessionsInput, $day_active);
if (!$validation['valid']) {
    echo json_encode([
        'success' => false,
        'message' => $validation['errors'][0] ?? 'Invalid schedule sessions.',
        'errors'  => $validation['errors'],
    ]);
    exit;
}

$sessions = $validation['sessions'];

try {
    $pdo->beginTransaction();

    provider_schedule_save_day($pdo, $provider_id, $day, $sessions, $day_active);

    appointment_slots_clear_day($pdo, $provider_id, $day);

    $slots_created = 0;
    if ($day_active && $sessions !== []) {
        $slots_created = appointment_slots_sync_today($pdo, $provider_id);
    }

    $pdo->commit();

    $patients_notified = 0;
    if ($day_active && $slots_created > 0) {
        try {
            require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';
            $nameStmt = $pdo->prepare("
                SELECT TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) AS provider_name
                FROM users WHERE id = ? LIMIT 1
            ");
            $nameStmt->execute([$provider_id]);
            $providerName = trim((string) $nameStmt->fetchColumn());
            $summary = provider_schedule_session_summary($sessions);
            $patients_notified = NotificationEvents::providerScheduleAvailable(
                $pdo,
                $provider_id,
                $providerName,
                $day,
                $summary['start'] ?: '00:00:00',
                $summary['end'] ?: '23:59:59',
                $slots_created
            );
        } catch (Throwable $notifyErr) {
            error_log('save_schedule notification: ' . $notifyErr->getMessage());
        }
    }

    echo json_encode([
        'success'           => true,
        'message'           => $day_active
            ? 'Schedule saved. ' . $slots_created . ' appointment slot(s) are now available for patients.'
            : 'Schedule saved. Today is marked inactive — no new slots were opened.',
        'slots_created'     => $slots_created,
        'sessions_saved'    => count($sessions),
        'patients_notified' => $patients_notified,
        'today'             => date('Y-m-d'),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
