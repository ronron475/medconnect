<?php
/**
 * API: Mark a consultation thread as read for current user.
 * POST /app/api/messages/mark_read.php
 *
 * Body:
 * - consultation_id
 * - csrf_token
 */
ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/message_deletion.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/rate_limiter.php';

messages_api_require_auth($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$rl = mc_rate_limiter_allow('messages_mark_read', 30, 30, (int) $_SESSION['user_id']);
if (!$rl['allowed']) {
    ob_end_clean();
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests.']);
    exit;
}

$csrf = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($csrf === '' || !auth_csrf_validate($csrf)) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request token.']);
    exit;
}

$consultationId = (int) ($_POST['consultation_id'] ?? 0);
$userId = (int) $_SESSION['user_id'];
if ($consultationId <= 0) {
    ob_end_clean();
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Consultation ID required.']);
    exit;
}

try {
    consultation_messages_ensure_schema($pdo);
    consultation_thread_state_ensure_schema($pdo);

    $access = message_assert_participant($pdo, $consultationId, $userId);
    if (!$access['success']) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $access['message']]);
        exit;
    }

    $state = consultation_thread_state_get($pdo, $consultationId, $userId);
    if (!empty($state['is_deleted'])) {
        ob_end_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Conversation not found.']);
        exit;
    }

    $changed = message_mark_consultation_read($pdo, $consultationId, $userId);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'marked' => $changed,
        'unread_count' => message_unread_count($pdo, $userId),
    ]);
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not mark messages as read.']);
}

