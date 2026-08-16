<?php
/**
 * API: Lightweight live-sync fingerprints for the current user.
 * GET /app/api/live/sync.php
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/live_sync.php';

Api::startJson();
Api::requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Api::error('Method not allowed.', 405);
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['user_role'] ?? '');
Api::releaseSession();

if ($userId <= 0 || $role === '') {
    Api::error('Unauthorized.', 401);
}

try {
    Api::success(live_sync_payload($pdo, $userId, $role));
} catch (Throwable $e) {
    Api::error('Could not load live state.', 500);
}
