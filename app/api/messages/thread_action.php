<?php
/**
 * API: Archive/restore/soft-delete a consultation conversation for the current user.
 * POST /app/api/messages/thread_action.php
 *
 * Body:
 * - consultation_id (int)
 * - action: archive|restore|delete|undelete
 * - csrf_token
 */
ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/message_deletion.php';

messages_api_require_auth($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrf = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!auth_csrf_validate($csrf)) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request token.']);
    exit;
}

$consultationId = (int) ($_POST['consultation_id'] ?? 0);
$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$userId = (int) $_SESSION['user_id'];

if ($consultationId <= 0 || !in_array($action, ['archive', 'restore', 'delete', 'undelete'], true)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
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

    if ($action === 'archive') {
        consultation_thread_state_upsert($pdo, $consultationId, $userId, ['is_archived' => 1]);
    } elseif ($action === 'restore') {
        consultation_thread_state_upsert($pdo, $consultationId, $userId, ['is_archived' => 0]);
    } elseif ($action === 'delete') {
        consultation_thread_state_upsert($pdo, $consultationId, $userId, ['is_deleted' => 1]);
    } elseif ($action === 'undelete') {
        consultation_thread_state_upsert($pdo, $consultationId, $userId, ['is_deleted' => 0]);
    }

    $state = consultation_thread_state_get($pdo, $consultationId, $userId);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Updated.',
        'state' => [
            'consultation_id' => $consultationId,
            'is_archived' => (int) ($state['is_archived'] ?? 0),
            'is_deleted' => (int) ($state['is_deleted'] ?? 0),
        ],
        'unread_count' => message_unread_count($pdo, $userId),
    ]);
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not update conversation state.']);
}

