<?php
// Prevent any accidental output before JSON
ob_start();
session_start();

if (empty($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['patient', 'provider'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/views/provider/partials/queue_helpers.php';

$consultation_id = (int)($_POST['consultation_id'] ?? 0);

if (!$consultation_id) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Consultation ID required.']);
    exit;
}

try {
    // 1. Check if consultation exists and user is part of it
    $stmt = $pdo->prepare("
        SELECT id, patient_id, provider_id, consult_date, consult_time, status
        FROM consultations
        WHERE id = ? AND (patient_id = ? OR provider_id = ?)
    ");
    $stmt->execute([$consultation_id, $_SESSION['user_id'], $_SESSION['user_id']]);
    $consultation = $stmt->fetch();

    if (!$consultation) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Consultation not found or access denied.']);
        exit;
    }

    if (($_SESSION['user_role'] ?? '') === 'provider') {
        $session_access = queue_session_access($consultation);
        if (!$session_access['allowed']) {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $session_access['reason']]);
            exit;
        }
    }

    // 2. Check if an active video session already exists
    $stmt = $pdo->prepare("SELECT room_token FROM video_sessions WHERE consultation_id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$consultation_id]);
    $session = $stmt->fetch();

    if ($session) {
        $token = $session['room_token'];
    } else {
        // 3. Create new session
        $token = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("INSERT INTO video_sessions (consultation_id, room_token, status) VALUES (?, ?, 'active')");
        $stmt->execute([$consultation_id, $token]);
        
        // Update consultation status if it was scheduled
        $pdo->prepare("UPDATE consultations SET status = 'in_consultation' WHERE id = ? AND status IN ('scheduled', 'pending')")->execute([$consultation_id]);

        require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';
        $role = $_SESSION['user_role'] ?? '';
        if ($role === 'patient') {
            NotificationEvents::patientJoinedWaitingRoom(
                $pdo,
                $consultation_id,
                (int) $consultation['provider_id'],
                trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')),
                (int) $_SESSION['user_id']
            );
            NotificationEvents::consultationStarting($pdo, $consultation_id, (int) $consultation['patient_id'], (int) $_SESSION['user_id']);
        } elseif ($role === 'provider') {
            NotificationEvents::consultationStarting($pdo, $consultation_id, (int) $consultation['patient_id'], (int) $_SESSION['user_id']);
        }
    }

    // Clean any buffered warnings/output before sending JSON
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'token'   => $token,
        'url'     => BASE_URL . '/views/consultation/video_room.php?token=' . $token
    ]);

} catch (PDOException $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
