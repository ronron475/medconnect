<?php
/**
 * API: Extend active consultation session
 * URL: /app/api/provider/check_extension.php
 */
session_start();
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

    if ($slot) {
        $slot_date = $slot['slot_date'];
        $current_end_time = $slot['end_time'];
        $slot_id = (int) $slot['id'];
    } else {
        $slot_date = $consultation['consult_date'] ?: date('Y-m-d');
        $base = $consultation['consult_time'] ?: date('H:i:s');
        $current_end_time = date('H:i:s', strtotime($slot_date . ' ' . $base) + (30 * 60));
        $slot_id = 0;
    }

    $current_end_ts = strtotime($slot_date . ' ' . $current_end_time);
    $new_end_ts = $current_end_ts + ($extension_mins * 60);
    $new_end_time = date('H:i:s', $new_end_ts);

    $conflict_stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM appointment_slots
        WHERE provider_id = ?
          AND slot_date = ?
          AND status = 'booked'
          AND (consultation_id IS NULL OR consultation_id != ?)
          AND start_time < ?
          AND end_time > ?
    ");
    $conflict_stmt->execute([
        $provider_id,
        $slot_date,
        $consultation_id,
        $new_end_time,
        $current_end_time,
    ]);

    if ((int) $conflict_stmt->fetchColumn() > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Extension blocked: another patient is booked in the next slot.',
        ]);
        exit;
    }

    if ($slot_id > 0) {
        $update = $pdo->prepare("UPDATE appointment_slots SET end_time = ? WHERE id = ?");
        $update->execute([$new_end_time, $slot_id]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Session extended by ' . $extension_mins . ' minutes.',
        'extension_mins' => $extension_mins,
        'new_end_time' => $new_end_time,
        'new_end_label' => date('g:i A', $new_end_ts),
        'seconds_remaining' => max(0, $new_end_ts - time()),
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
