<?php
/**
 * API: Extend active consultation session
 * URL: /app/api/provider/check_extension.php
 */
header('Content-Type: application/json');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';

if (empty($_SESSION['user_id']) || $_SESSION['user_role'] !== 'provider') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
auth_csrf_require();

$provider_id = (int) $_SESSION['user_id'];
$consultation_id = (int) ($_POST['consultation_id'] ?? 0);
$extension_mins = max(5, min(60, (int) ($_POST['extension_mins'] ?? 15)));

if ($consultation_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Consultation ID is required.']);
    exit;
}

try {
    $c_stmt = $pdo->prepare("
        SELECT id, provider_id, consult_date, consult_time, status
        FROM consultations
        WHERE id = ? AND provider_id = ?
        LIMIT 1
    ");
    $c_stmt->execute([$consultation_id, $provider_id]);
    $consultation = $c_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$consultation) {
        echo json_encode(['success' => false, 'message' => 'Consultation not found.']);
        exit;
    }

    if (!in_array($consultation['status'], ['scheduled', 'in_consultation'], true)) {
        echo json_encode(['success' => false, 'message' => 'This consultation is not active.']);
        exit;
    }

    $slot_stmt = $pdo->prepare("
        SELECT id, slot_date, start_time, end_time
        FROM appointment_slots
        WHERE consultation_id = ? AND provider_id = ? AND status = 'booked'
        LIMIT 1
    ");
    $slot_stmt->execute([$consultation_id, $provider_id]);
    $slot = $slot_stmt->fetch(PDO::FETCH_ASSOC);

    if ($slot && trim((string) ($slot['end_time'] ?? '')) !== '') {
        $slot_date = $slot['slot_date'];
        $current_end_time = $slot['end_time'];
    } else {
        echo json_encode([
            'success' => true,
            'continues_past_slot' => true,
            'message' => 'This visit can continue. The booked appointment slot is unchanged.',
            'extension_mins' => 0,
            'seconds_remaining' => 0,
        ]);
        exit;
    }

    $current_end_ts = strtotime($slot_date . ' ' . $current_end_time);
    if ($current_end_ts === false) {
        echo json_encode(['success' => false, 'message' => 'Could not resolve the consultation end time.']);
        exit;
    }
    // The booked slot stays unchanged. Running long does not take the next patient's time.
    $scheduledRemaining = max(0, $current_end_ts - time());
    echo json_encode([
        'success' => true,
        'continues_past_slot' => true,
        'message' => 'This visit can continue past the scheduled slot. The next patient keeps their own time and will be asked to wait.',
        'extension_mins' => 0,
        'new_end_time' => $current_end_time,
        'new_end_label' => date('g:i A', $current_end_ts),
        'seconds_remaining' => $scheduledRemaining,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
