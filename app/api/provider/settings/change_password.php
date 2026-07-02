<?php
session_start();
require_once dirname(__DIR__, 4) . '/bootstrap.php';
require_once dirname(__DIR__, 4) . '/config/db.php';
require_once CONTROLLERS_PATH . '/provider/PasswordController.php';

PasswordController::jsonHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$providerId = PasswordController::authorize();
PasswordController::verifyCsrf();

$result = PasswordController::changePassword($pdo, $providerId, $_POST);
if ($result['status'] === 'error' || empty($result['success'])) {
    http_response_code(400);
}
echo json_encode($result);
