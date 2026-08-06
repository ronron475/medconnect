<?php
/**
 * API: Admin / Super Admin sidebar live badge counts
 * GET /app/api/admin/nav_counts.php
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/portal_nav_badge_counts.php';

Api::startJson();

$role = (string) ($_SESSION['user_role'] ?? '');
if (empty($_SESSION['user_id']) || !in_array($role, ['admin', 'superadmin'], true)) {
    Api::error('Unauthorized.', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Api::error('Method not allowed.', 405);
}

try {
    $counts = portal_nav_badge_counts($pdo, $role, (int) $_SESSION['user_id']);
    Api::success($counts);
} catch (Throwable $e) {
    Api::error('Could not load nav counts.', 500);
}
