<?php
/**
 * API: Admin / Super Admin dashboard live refresh
 * GET /app/api/admin/dashboard_live.php
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/api/admin/_auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/admin_dashboard_live.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$role = (string) ($_SESSION['user_role'] ?? '');
$userId = (int) ($_SESSION['user_id'] ?? 0);

try {
    if ($role === 'superadmin') {
        $payload = superadmin_dashboard_live_payload($pdo);
    } else {
        $payload = admin_dashboard_live_payload($pdo, $userId);
    }
    echo json_encode(array_merge(['success' => true], $payload), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load dashboard data.']);
}
