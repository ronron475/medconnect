<?php
ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/message_deletion.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/rate_limiter.php';

messages_api_require_auth($pdo);

$consultation_id = (int)($_GET['consultation_id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];
session_write_close();

if (!$consultation_id) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Consultation ID required.']);
    exit;
}

try {
    $rl = mc_rate_limiter_allow('messages_list', 90, 30, $user_id);
    if (!$rl['allowed']) {
        ob_end_clean();
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many requests.']);
        exit;
    }

    consultation_messages_ensure_schema($pdo);
    consultation_thread_state_ensure_schema($pdo);

    $pair = message_resolve_pair($pdo, $consultation_id, $user_id);
    if (!$pair['success']) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $pair['message']]);
        exit;
    }

    if (message_pair_is_deleted_for_user($pdo, (int) $pair['patient_id'], (int) $pair['provider_id'], $user_id)) {
        ob_end_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Conversation not found.']);
        exit;
    }

    // IMPORTANT: This endpoint is read-only. Mark-as-read must go through POST mark_read.php with CSRF.
    // Load the full patient–provider history (all consultations in the pair).
    $messages = message_fetch_pair_messages($pdo, $consultation_id, $user_id);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'messages' => $messages,
        'unread_count' => message_unread_count($pdo, $user_id),
    ]);
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load messages.']);
}
