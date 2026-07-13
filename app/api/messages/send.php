<?php
ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/message_deletion.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/rate_limiter.php';

messages_api_require_auth($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$rl = mc_rate_limiter_allow('messages_send', 12, 30, (int) ($_SESSION['user_id'] ?? 0));
if (!$rl['allowed']) {
    ob_end_clean();
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please wait a moment.']);
    exit;
}

$csrf = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!auth_csrf_validate($csrf)) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request token.']);
    exit;
}

$consultation_id = (int)($_POST['consultation_id'] ?? 0);
$message = trim((string)($_POST['message'] ?? ''));
$sender_id = (int)$_SESSION['user_id'];

if (!$consultation_id || $message === '') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Message is required.']);
    exit;
}

if (mb_strlen($message) > 2000) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Message is too long.']);
    exit;
}

if (!mb_check_encoding($message, 'UTF-8')) {
    ob_end_clean();
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid message encoding.']);
    exit;
}

try {
    consultation_messages_ensure_schema($pdo);

    $stmt = $pdo->prepare("
        SELECT id, patient_id, provider_id
        FROM consultations
        WHERE id = ? AND (patient_id = ? OR provider_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$consultation_id, $sender_id, $sender_id]);
    $consultation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$consultation) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Consultation not found or access denied.']);
        exit;
    }

    $receiver_id = ((int)$consultation['patient_id'] === $sender_id)
        ? (int)$consultation['provider_id']
        : (int)$consultation['patient_id'];

    if (!$receiver_id) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'No recipient is assigned to this consultation.']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO consultation_messages (consultation_id, sender_id, receiver_id, message)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$consultation_id, $sender_id, $receiver_id, $message]);

    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("
        SELECT cm.id, cm.consultation_id, cm.sender_id, cm.receiver_id, cm.message, cm.created_at,
               u.first_name, u.last_name, u.role
        FROM consultation_messages cm
        JOIN users u ON u.id = cm.sender_id
        WHERE cm.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $formatted = message_format_for_viewer($row, $sender_id);

    require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';
    $senderName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

    if ($_SESSION['user_role'] === 'patient' && $receiver_id) {
        NotificationEvents::patientMessage(
            $pdo,
            $receiver_id,
            $sender_id,
            $senderName ?: 'Patient',
            $sender_id,
            $consultation_id
        );
    } elseif ($_SESSION['user_role'] === 'provider' && $receiver_id) {
        NotificationEvents::providerMessage(
            $pdo,
            $receiver_id,
            $sender_id,
            $senderName ?: 'Your healthcare provider',
            $sender_id,
            $consultation_id
        );
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Message sent.',
        'data' => $formatted,
    ]);
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not send message.']);
}
