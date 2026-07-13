<?php
/**
 * API: Unread consultation message count
 * GET /app/api/messages/unread_count.php
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/message_deletion.php';

Api::startJson();
messages_api_require_auth($pdo);

$userId = (int) $_SESSION['user_id'];

try {
    consultation_messages_ensure_schema($pdo);

    Api::success([
        'unread_count'      => message_unread_count($pdo, $userId),
        'latest_unread_at'  => message_latest_unread_at($pdo, $userId),
    ]);
} catch (Exception $e) {
    Api::error('Could not get unread message count.', 500);
}
