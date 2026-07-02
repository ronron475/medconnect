<?php
/**
 * Live dashboard chart data (Admin + Super Admin).
 * GET /app/api/admin/dashboard_charts.php?days=30
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/api/admin/_auth.php';
require_once BASE_PATH . '/app/includes/admin_dashboard_charts.php';

$days = isset($_GET['days']) ? (int) $_GET['days'] : 30;

echo json_encode([
    'success' => true,
    'data'    => admin_dashboard_chart_payload($pdo, $days),
], JSON_UNESCAPED_UNICODE);
