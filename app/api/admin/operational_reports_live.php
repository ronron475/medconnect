<?php
/**
 * API: Operational Reports live refresh (Admin + Super Admin)
 * GET /app/api/admin/operational_reports_live.php?base=/path/analytics.php&<type>_page=N&users_barangay=ID
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/api/admin/_auth.php';
require_once BASE_PATH . '/app/includes/admin_operational_reports.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Only same-site paths are used for pagination / filter links.
$base = (string) ($_GET['base'] ?? '');
if (!preg_match('#^/[A-Za-z0-9/_.\-]*$#', $base) || strpos($base, '//') !== false) {
    $base = ASSET_BASE . '/views/admin/analytics.php';
}

try {
    echo json_encode([
        'success' => true,
        'sections' => admin_operational_reports_render($pdo, $_GET, $base),
        'updated_at' => date('c'),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('operational_reports_live: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load reports.']);
}
