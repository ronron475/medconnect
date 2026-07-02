<?php
session_start();
require_once dirname(__DIR__, 4) . '/bootstrap.php';
require_once dirname(__DIR__, 4) . '/config/db.php';
require_once CONTROLLERS_PATH . '/provider/SettingsController.php';

ProviderSettingsController::jsonHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$userId = ProviderSettingsController::authorize();
ProviderSettingsController::verifyCsrf();

$result = ProviderSettingsController::saveProfile($pdo, $userId, $_POST);
if (!$result['success']) {
    http_response_code(400);
}
echo json_encode($result);
