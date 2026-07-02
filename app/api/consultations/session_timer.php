<?php
/**
 * API: Remaining consultation time for active video session
 * URL: /app/api/consultations/session_timer.php?token=...
 */
session_start();
header('Content-Type: application/json');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';

$token = trim((string) ($_GET['token'] ?? ''));

if ($token === '' || empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT vs.consultation_id, c.consult_date, c.consult_time,
               s.slot_date, s.end_time AS slot_end
        FROM video_sessions vs
        JOIN consultations c ON c.id = vs.consultation_id
        LEFT JOIN appointment_slots s ON s.consultation_id = c.id AND s.status = 'booked'
        WHERE vs.room_token = ?
          AND vs.status = 'active'
          AND (vs.provider_id = ? OR vs.patient_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$token, $_SESSION['user_id'], $_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Session not found.']);
        exit;
    }

    $slot_date = $row['slot_date'] ?: $row['consult_date'] ?: date('Y-m-d');
    $end_time = $row['slot_end'];

    if (!$end_time) {
        $base = $row['consult_time'] ?: date('H:i:s');
        $end_ts = strtotime($slot_date . ' ' . $base) + (30 * 60);
    } else {
        $end_ts = strtotime($slot_date . ' ' . $end_time);
    }

    $seconds_remaining = max(0, $end_ts - time());

    echo json_encode([
        'success' => true,
        'seconds_remaining' => $seconds_remaining,
        'end_label' => date('g:i A', $end_ts),
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
