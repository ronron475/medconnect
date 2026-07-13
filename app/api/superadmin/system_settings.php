<?php
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/system_settings.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/superadmin/security.php';
require_once BASE_PATH . '/app/includes/audit_log.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $settings = system_settings_get_all($pdo);
    $api = [];
    try {
        $api = $pdo->query('SELECT api_key, api_value FROM api_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {}
    echo json_encode(['success' => true, 'settings' => $settings, 'api' => $api]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$pairs = [];
foreach ($_POST as $key => $value) {
    if ($key === 'action' || $key === 'csrf_token') {
        continue;
    }
    $pairs[(string) $key] = (string) $value;
}

system_settings_set_many($pdo, $pairs, (int) $_SESSION['user_id']);

if (array_key_exists('MAINTENANCE_MODE', $pairs)) {
    system_settings_set(
        $pdo,
        'LANDING_MAINTENANCE_BANNER',
        $pairs['MAINTENANCE_MODE'] === '1' ? '1' : '0',
        (int) $_SESSION['user_id']
    );
}

$apiKeys = [];
try {
    $apiKeys = $pdo->query('SELECT api_key FROM api_settings')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

foreach ($pairs as $key => $value) {
    if (in_array($key, $apiKeys, true) || str_starts_with($key, 'API_')) {
        try {
            $pdo->prepare('
                INSERT INTO api_settings (api_key, api_value, updated_by) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE api_value = VALUES(api_value), updated_by = VALUES(updated_by)
            ')->execute([$key, $value, (int) $_SESSION['user_id']]);
        } catch (Throwable $e) {}
    }
}

superadmin_security_log($pdo, 'settings_updated', 'system', 'success', 'System settings updated');
audit_log($pdo, ['patient_id' => (int) $_SESSION['user_id'], 'action_type' => 'system_settings_updated', 'description' => 'Super Admin updated system settings.']);

echo json_encode(['success' => true, 'message' => 'Settings saved.']);
