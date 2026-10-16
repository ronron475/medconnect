<?php
/**
 * Provider sends one TextBee follow-up reminder for a follow-up they own.
 * Live SMS stays off unless TEXTBEE_SEND_ENABLED is true.
 */
header('Content-Type: application/json');
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/followup_sms.php';

if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'provider') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

auth_csrf_require();

$followupId = (int) ($_POST['followup_id'] ?? 0);
$providerId = (int) ($_SESSION['user_id'] ?? 0);
if ($followupId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Follow-up ID required.']);
    exit;
}

try {
    followup_sms_ensure_schema($pdo);
    $gate = followup_sms_provider_gate($pdo, $followupId, $providerId);
    if (!$gate['ok']) {
        http_response_code($gate['http']);
        echo json_encode(['success' => false, 'message' => $gate['message']]);
        exit;
    }

    $result = followup_sms_send($pdo, $followupId, $providerId);
    if (!$result['success']) {
        http_response_code($result['status'] === 'duplicate' ? 409 : 400);
    }
    echo json_encode($result);
} catch (Throwable $e) {
    error_log('send_followup_sms: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'SMS reminder could not be sent.']);
}
