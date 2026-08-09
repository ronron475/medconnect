<?php
/**
 * API: Provider dashboard live refresh
 * GET /app/api/provider/dashboard_live.php
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_dashboard_live.php';

Api::startJson();

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'provider') {
    Api::error('Unauthorized.', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Api::error('Method not allowed.', 405);
}

$providerId = (int) $_SESSION['user_id'];
$period = provider_parse_dashboard_period($_GET['period'] ?? 'week');

try {
    Api::success(provider_dashboard_live_payload($pdo, $providerId, $period));
} catch (Throwable $e) {
    Api::error('Could not load dashboard data.', 500);
}
