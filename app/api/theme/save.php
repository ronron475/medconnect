<?php
session_start();
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/config/db.php';
require_once BASE_PATH . '/app/core/ThemeController.php';

ThemeController::jsonHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$auth = ThemeController::authorize();
ThemeController::verifyCsrf();

$result = ThemeController::saveTheme($pdo, $auth['user_id'], $auth['user_type'], $_POST);
if ($result['status'] === 'error' || empty($result['success'])) {
    http_response_code(400);
}
echo json_encode($result);
