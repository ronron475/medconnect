<?php
/**
 * Live dashboard chart data (Admin + Super Admin).
 * GET /app/api/admin/dashboard_charts.php?days=30
 * GET /app/api/admin/dashboard_charts.php?from=YYYY-MM-DD&to=YYYY-MM-DD
 * Allowed days: 1 (1 Day), 7 (1 Week), 30 (1 Month), 365 (1 Year).
 * Custom range: both from and to required (inclusive). Prefers custom when both are valid.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/api/admin/_auth.php';
require_once BASE_PATH . '/app/includes/admin_dashboard_charts.php';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$days = isset($_GET['days']) ? (int) $_GET['days'] : 30;
$from = isset($_GET['from']) ? (string) $_GET['from'] : '';
$to = isset($_GET['to']) ? (string) $_GET['to'] : '';

echo json_encode([
    'success' => true,
    'data'    => admin_dashboard_chart_payload($pdo, $days, $from, $to),
], JSON_UNESCAPED_UNICODE);
