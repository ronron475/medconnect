<?php
session_start();
header('Content-Type: application/json');
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/system_settings.php';
require_once BASE_PATH . '/app/includes/audit_log.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/api/admin/_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$settings = [
    'AI_CONFIDENCE_THRESHOLD' => trim($_POST['AI_CONFIDENCE_THRESHOLD'] ?? ''),
    'MAX_APPOINTMENTS_PER_PROVIDER' => trim($_POST['MAX_APPOINTMENTS_PER_PROVIDER'] ?? ''),
    'SESSION_TIMEOUT_MINUTES' => trim($_POST['SESSION_TIMEOUT_MINUTES'] ?? ''),
];

foreach ($settings as $key => $val) {
    if ($val === '') {
        echo json_encode(['success' => false, 'message' => "Missing value for $key"]);
        exit;
    }
    if ($key === 'AI_CONFIDENCE_THRESHOLD' && ((float)$val < 0 || (float)$val > 1)) {
        echo json_encode(['success' => false, 'message' => 'AI confidence must be between 0 and 1.']);
        exit;
    }
    if (in_array($key, ['MAX_APPOINTMENTS_PER_PROVIDER', 'SESSION_TIMEOUT_MINUTES'], true) && (int)$val < 1) {
        echo json_encode(['success' => false, 'message' => "$key must be a positive integer."]);
        exit;
    }
}

$admin_id = (int)$_SESSION['user_id'];
system_settings_set_many($pdo, $settings, $admin_id);

if (function_exists('audit_log')) {
    audit_log($pdo, [
        'patient_id' => $admin_id,
        'action_type' => 'system_settings_updated',
        'description' => 'Admin updated global system settings.',
        'meta' => ['keys' => array_keys($settings)],
    ]);
}

echo json_encode(['success' => true, 'message' => 'System settings saved.']);
