<?php
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/message_deletion.php';

if (empty($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['provider', 'patient'], true)) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$consultation_id = (int)($_GET['consultation_id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];

if (!$consultation_id) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Consultation ID required.']);
    exit;
}

try {
    $access = message_assert_participant($pdo, $consultation_id, $user_id);
    if (!$access['success']) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $access['message']]);
        exit;
    }

    $messages = message_fetch_consultation_messages($pdo, $consultation_id, $user_id);

    ob_end_clean();
    echo json_encode(['success' => true, 'messages' => $messages]);
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load messages.']);
}
