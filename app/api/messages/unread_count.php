<?php
/**
 * API: Unread consultation message count
 * GET /app/api/messages/unread_count.php
 * Optional ?consultation_id= adds consultation_unread_count for that patient–provider thread.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/message_deletion.php';

Api::startJson();
messages_api_require_auth($pdo);

$userId = (int) $_SESSION['user_id'];
$consultationId = (int) ($_GET['consultation_id'] ?? 0);

try {
    consultation_messages_ensure_schema($pdo);

    $payload = [
        'unread_count'      => message_unread_count($pdo, $userId),
        'latest_unread_at'  => message_latest_unread_at($pdo, $userId),
    ];

    if ($consultationId > 0) {
        $pair = message_resolve_pair($pdo, $consultationId, $userId);
        if (!$pair['success']) {
            Api::error((string) $pair['message'], 403);
        }
        $payload['consultation_unread_count'] = message_pair_is_deleted_for_user($pdo, (int) $pair['patient_id'], (int) $pair['provider_id'], $userId)
            ? 0
            : message_pair_unread_count($pdo, $consultationId, $userId);
    }

    Api::success($payload);
} catch (Exception $e) {
    Api::error('Could not get unread message count.', 500);
}
