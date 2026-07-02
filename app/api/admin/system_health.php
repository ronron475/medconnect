<?php
/**
 * API: Live system health snapshot (Admin & Super Admin).
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/portal_auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/system_health_monitor.php';

portal_api_require_admin_portal();

try {
    echo json_encode([
        'success' => true,
        'data'    => system_health_snapshot($pdo),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Could not load system health data.',
    ]);
}
