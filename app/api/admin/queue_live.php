<?php
/**
 * API: Admin / Super Admin queue monitoring live refresh
 * GET /app/api/admin/queue_live.php
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/admin_queue_live.php';

Api::startJson();
Api::requireAnyRole(['admin', 'superadmin']);
Api::releaseSession();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Api::error('Method not allowed.', 405);
}

try {
    Api::success(admin_queue_live_payload($pdo));
} catch (Throwable $e) {
    Api::error('Could not load queue.', 500);
}
