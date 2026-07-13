<?php
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/message_deletion.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/rate_limiter.php';

messages_api_require_auth($pdo);

$consultation_id = (int)($_GET['consultation_id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];

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

    $access = message_assert_participant($pdo, $consultation_id, $user_id);
    if (!$access['success']) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $access['message']]);
        exit;
    }

    $state = consultation_thread_state_get($pdo, $consultation_id, $user_id);
    if (!empty($state['is_deleted'])) {
        ob_end_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Conversation not found.']);
        exit;
    }

    // IMPORTANT: This endpoint is read-only. Mark-as-read must go through POST mark_read.php with CSRF.
    $messages = message_fetch_consultation_messages($pdo, $consultation_id, $user_id);

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
