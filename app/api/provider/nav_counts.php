<?php
/**
 * API: Provider sidebar live counts
 * GET /app/api/provider/nav_counts.php
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/portal_nav_badge_counts.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_nav_counts.php';

Api::startJson();

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'provider') {
    Api::error('Unauthorized.', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Api::error('Method not allowed.', 405);
}

$providerId = (int) $_SESSION['user_id'];

try {
    $counts = portal_nav_badge_counts($pdo, 'provider', $providerId);
    $details = provider_nav_counts($pdo, $providerId);
    $messagesUnread = (int) ($counts['messages'] ?? 0);

    Api::success([
        'queue'          => (int) ($counts['queue'] ?? 0),
        'triage'         => (int) ($counts['triage'] ?? 0),
        'triage_urgent'  => (int) ($details['triage_urgent'] ?? 0),
        'referrals'      => (int) ($counts['referrals'] ?? 0),
        'followups'      => (int) ($counts['followups'] ?? 0),
        'messages'       => $messagesUnread,
        'unread_count'   => $messagesUnread,
    ]);
} catch (Throwable $e) {
    Api::error('Could not load nav counts.', 500);
}
