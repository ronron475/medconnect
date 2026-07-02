<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/superadmin/schema.php';
require_once BASE_PATH . '/app/includes/superadmin/security.php';
require_once BASE_PATH . '/app/includes/audit_log.php';

superadmin_ensure_schema($pdo);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $rows = $pdo->query('SELECT api_key, api_value, updated_at FROM api_settings ORDER BY api_key')
        ->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'rows' => $rows]);
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

if ($pairs === []) {
    echo json_encode(['success' => false, 'message' => 'No settings provided.']);
    exit;
}

$uid = (int) ($_SESSION['user_id'] ?? 0);
$stmt = $pdo->prepare('
    INSERT INTO api_settings (api_key, api_value, updated_by)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE api_value = VALUES(api_value), updated_by = VALUES(updated_by)
');

foreach ($pairs as $key => $value) {
    $stmt->execute([$key, $value, $uid ?: null]);
}

superadmin_security_log($pdo, 'api_settings_updated', 'system', 'success', 'API settings updated');
audit_log($pdo, [
    'patient_id' => $uid,
    'action_type' => 'api_settings_updated',
    'description' => 'Super Admin updated API integration settings.',
    'meta' => ['keys' => array_keys($pairs)],
]);

echo json_encode(['success' => true, 'message' => 'API settings saved.']);
