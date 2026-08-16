<?php
/**
 * API: Provider schedule live refresh (today's slots).
 * GET /app/api/provider/schedule_live.php
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_schedule_live.php';

Api::startJson();
Api::requireRole('provider');
Api::releaseSession();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Api::error('Method not allowed.', 405);
}

$providerId = (int) $_SESSION['user_id'];

try {
    $payload = provider_schedule_live_payload($pdo, $providerId);
    unset($payload['rows']);
    Api::success($payload);
} catch (Throwable $e) {
    Api::error('Could not load today\'s slots.', 500);
}
