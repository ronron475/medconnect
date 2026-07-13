<?php
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/message_deletion.php';

messages_api_require_auth($pdo);

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['DELETE', 'POST'], true)) {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrf = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($csrf === '' || !auth_csrf_validate($csrf)) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request token.']);
    exit;
}

$messageId = (int)($_GET['id'] ?? $_POST['message_id'] ?? 0);
$userId = (int)$_SESSION['user_id'];

if (!$messageId) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Message ID is required.']);
    exit;
}

try {
    $result = message_delete_for_me($pdo, $messageId, $userId);
    ob_end_clean();
    if (!$result['success']) {
        http_response_code($result['http_code'] ?? 400);
    }
    echo json_encode($result);
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not delete message.']);
}
