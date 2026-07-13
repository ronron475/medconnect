<?php
/**
 * Patient-only: join an existing live video room.
 *
 * Does NOT create a room. Patient waits until the provider starts it.
 */
ob_start();
session_start();

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/resources/views/provider/partials/queue_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_settings.php';

$uid = patient_settings_require_patient_ready($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

auth_csrf_require();

$consultation_id = (int) ($_POST['consultation_id'] ?? 0);
if ($consultation_id <= 0) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Consultation ID required.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.patient_id, c.provider_id, c.consult_date, c.consult_time, c.status,
               s.slot_date, s.start_time AS slot_start,
               vs.room_token
        FROM consultations c
        LEFT JOIN appointment_slots s ON s.consultation_id = c.id AND s.status = 'booked'
        LEFT JOIN video_sessions vs ON vs.consultation_id = c.id AND vs.status = 'active'
        WHERE c.id = ? AND c.patient_id = ?
        LIMIT 1
    ");
    $stmt->execute([$consultation_id, $uid]);
    $consultation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$consultation) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Consultation not found or access denied.']);
        exit;
    }

    $join = consultation_patient_join_access($consultation);
    if (!$join['allowed']) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'message' => $join['reason'] ?: 'Waiting for your provider to start the video call.',
            'code'    => $join['mode'] ?? 'waiting',
            'mode'    => $join['mode'] ?? 'waiting',
        ]);
        exit;
    }

    $token = trim((string) ($consultation['room_token'] ?? ''));
    if ($token === '') {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Waiting for your provider to start the video call.',
            'code'    => 'waiting',
            'mode'    => 'waiting',
        ]);
        exit;
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'token'   => $token,
        'url'     => BASE_URL . '/views/consultation/video_room.php?token=' . $token,
        'message' => 'Joining live consultation.',
    ]);
} catch (PDOException $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not join video session.']);
}
