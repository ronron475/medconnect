<?php
/**
 * API: Provider sidebar live counts
 * GET /app/api/provider/nav_counts.php
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_nav_counts.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/message_deletion.php';

Api::startJson();

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'provider') {
    Api::error('Unauthorized.', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Api::error('Method not allowed.', 405);
}

$providerId = (int) $_SESSION['user_id'];

try {
    $counts = provider_nav_counts($pdo, $providerId);

    $messagesUnread = 0;
    try {
        consultation_messages_ensure_schema($pdo);
        $messagesUnread = message_unread_count($pdo, $providerId);
    } catch (Throwable $e) {
        $messagesUnread = 0;
    }

    Api::success([
        'queue'          => $counts['queue'],
        'triage'         => $counts['triage'],
        'triage_urgent'  => $counts['triage_urgent'],
        'referrals'      => $counts['referrals'] ?? 0,
        'followups'      => $counts['followups'] ?? 0,
        'messages'       => $messagesUnread,
        'unread_count'   => $messagesUnread,
    ]);
} catch (Throwable $e) {
    Api::error('Could not load nav counts.', 500);
}
