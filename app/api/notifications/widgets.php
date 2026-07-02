<?php
/**
 * API: Dashboard notification widgets
 * GET /app/api/notifications/widgets.php
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/NotificationManager.php';

Api::startJson();
Api::requireAuth();

$userId = (int) $_SESSION['user_id'];
$role   = (string) ($_SESSION['user_role'] ?? 'patient');

try {
    Api::success([
        'widgets' => NotificationManager::getDashboardWidgets($pdo, $userId, $role),
    ]);
} catch (Exception $e) {
    Api::error('Could not load widgets.', 500);
}
