<?php
/**
 * Live dashboard chart data (Admin + Super Admin).
 * GET /app/api/admin/dashboard_charts.php?days=180
 * Allowed days: 1, 7, 30, 180 (Last 6 Months), 365 (Year).
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

$days = isset($_GET['days']) ? (int) $_GET['days'] : 180;

echo json_encode([
    'success' => true,
    'data'    => admin_dashboard_chart_payload($pdo, $days),
], JSON_UNESCAPED_UNICODE);
