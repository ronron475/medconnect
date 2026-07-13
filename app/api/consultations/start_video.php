<?php
/**
 * Provider-only: create / resume the live video room for a consultation.
 *
 * Best practice (telemed):
 * - Provider starts the room (creates token, sets in_consultation).
 * - Patient only joins after the room exists (via video_room.php?token=...).
 */
ob_start();
session_start();

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/resources/views/provider/partials/queue_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$role = (string) ($_SESSION['user_role'] ?? '');
$uid  = (int) ($_SESSION['user_id'] ?? 0);

if ($uid <= 0 || $role !== 'provider') {
    ob_end_clean();
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Only the healthcare provider can start the video consultation. Patients should wait for the Join Call button.',
        'code'    => 'provider_start_required',
    ]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
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
               s.slot_date, s.start_time AS slot_start
        FROM consultations c
        LEFT JOIN appointment_slots s ON s.consultation_id = c.id AND s.status = 'booked'
        WHERE c.id = ? AND c.provider_id = ?
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

    $session_access = queue_session_access($consultation);
    if (!$session_access['allowed']) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => $session_access['reason']]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT room_token
        FROM video_sessions
        WHERE consultation_id = ? AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$consultation_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($session) {
        $token = (string) $session['room_token'];
        // Ensure status is live if room already exists
        $pdo->prepare("
            UPDATE consultations
            SET status = 'in_consultation'
            WHERE id = ? AND status IN ('scheduled', 'pending')
        ")->execute([$consultation_id]);
    } else {
        $token = bin2hex(random_bytes(16));
        $ins = $pdo->prepare("
            INSERT INTO video_sessions (consultation_id, room_token, status)
            VALUES (?, ?, 'active')
        ");
        $ins->execute([$consultation_id, $token]);

        $pdo->prepare("
            UPDATE consultations
            SET status = 'in_consultation'
            WHERE id = ? AND status IN ('scheduled', 'pending')
        ")->execute([$consultation_id]);

        require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';
        NotificationEvents::consultationStarting(
            $pdo,
            $consultation_id,
            (int) $consultation['patient_id'],
            $uid
        );
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'token'   => $token,
        'url'     => BASE_URL . '/views/consultation/video_room.php?token=' . $token,
        'message' => 'Video room started. Patient can now join.',
    ]);
} catch (PDOException $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not start video session.']);
}
