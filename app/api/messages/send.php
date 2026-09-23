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
$message_kind = strtolower(trim((string) ($_POST['message_kind'] ?? 'chat')));
if ($message_kind !== 'mute_tts') {
    $message_kind = 'chat';
}
$sender_id = (int)$_SESSION['user_id'];

if (!$consultation_id || $message === '') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Message is required.']);
    exit;
}

$maxLen = $message_kind === 'mute_tts' ? 500 : 2000;
if (mb_strlen($message) > $maxLen) {
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

    $pair = message_resolve_pair($pdo, $consultation_id, $sender_id);
    if (!$pair['success']) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Consultation not found or access denied.']);
        exit;
    }

    $patientId = (int) $pair['patient_id'];
    $providerId = (int) $pair['provider_id'];
    $receiver_id = ($patientId === $sender_id) ? $providerId : $patientId;

    if (!$receiver_id) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'No recipient is assigned to this consultation.']);
        exit;
    }

    // Continue the existing patient–provider thread on the canonical consultation when possible.
    $targetConsultationId = message_pair_canonical_consultation_id($pdo, $patientId, $providerId);
    if ($targetConsultationId <= 0) {
        $targetConsultationId = $consultation_id;
    }

    // New activity restores a soft-hidden conversation for both participants (never deletes history).
    message_pair_clear_deleted_for_user($pdo, $patientId, $providerId, $sender_id);
    message_pair_clear_deleted_for_user($pdo, $patientId, $providerId, $receiver_id);

    $stmt = $pdo->prepare("
        INSERT INTO consultation_messages (consultation_id, sender_id, receiver_id, message, message_kind)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$targetConsultationId, $sender_id, $receiver_id, $message, $message_kind]);

    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("
        SELECT cm.id, cm.consultation_id, cm.sender_id, cm.receiver_id, cm.message, cm.message_kind, cm.created_at,
               cm.is_deleted_for_everyone, cm.deleted_at, cm.deleted_for_me_users,
               u.first_name, u.last_name, u.role
        FROM consultation_messages cm
        JOIN users u ON u.id = cm.sender_id
        WHERE cm.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $formatted = message_format_for_viewer($row, $sender_id);

    // Mute-TTS is already delivered live in the video room; skip chat push spam.
    if ($message_kind !== 'mute_tts') {
        require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';
        $senderName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

        if ($_SESSION['user_role'] === 'patient' && $receiver_id) {
            NotificationEvents::patientMessage(
                $pdo,
                $receiver_id,
                $sender_id,
                $senderName ?: 'Patient',
                $sender_id,
                $targetConsultationId
            );
        } elseif ($_SESSION['user_role'] === 'provider' && $receiver_id) {
            NotificationEvents::providerMessage(
                $pdo,
                $receiver_id,
                $sender_id,
                $senderName ?: 'Your healthcare provider',
                $sender_id,
                $targetConsultationId
            );
        }
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
