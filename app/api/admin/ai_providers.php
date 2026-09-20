<?php
/**
 * Admin/SuperAdmin AI provider settings API.
 * GET  → live provider status (no secrets)
 * POST action=save → persist enabled/model settings
 * POST action=test → real connectivity test for one provider
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once BASE_PATH . '/app/includes/portal_auth.php';
require_once BASE_PATH . '/app/includes/ai_providers.php';
require_once BASE_PATH . '/app/includes/audit_log.php';

portal_api_require_admin_portal();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$adminId = (int) ($_SESSION['user_id'] ?? 0);

if ($method === 'GET') {
    $liveTest = isset($_GET['live']) && $_GET['live'] === '1';
    $snapshot = ai_providers_live_snapshot($liveTest);
    echo json_encode(['success' => true, 'data' => $snapshot], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$action = trim((string) ($_POST['action'] ?? ''));
if ($action === '' && isset($_SERVER['CONTENT_TYPE']) && str_contains((string) $_SERVER['CONTENT_TYPE'], 'application/json')) {
    $raw = file_get_contents('php://input');
    $json = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($json)) {
        $action = trim((string) ($json['action'] ?? ''));
        foreach ($json as $k => $v) {
            if (!isset($_POST[$k])) {
                $_POST[$k] = $v;
            }
        }
    }
}

if ($action === 'test') {
    $provider = trim((string) ($_POST['provider'] ?? ''));
    $result = ai_providers_test($provider, true);
    echo json_encode([
        'success' => (bool) ($result['ok'] ?? false),
        'message' => (string) ($result['message'] ?? ''),
        'data'    => $result,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save') {
    $result = ai_providers_save($pdo, [
        'medconnect_ai_enabled' => $_POST['medconnect_ai_enabled'] ?? '0',
        'groq_enabled'          => $_POST['groq_enabled'] ?? '0',
        'gemini_enabled'        => $_POST['gemini_enabled'] ?? '0',
        'groq_model'            => $_POST['groq_model'] ?? '',
        'gemini_model'          => $_POST['gemini_model'] ?? '',
    ], $adminId ?: null);

    if ($result['success'] && function_exists('audit_log')) {
        audit_log($pdo, [
            'patient_id'  => $adminId,
            'action_type' => 'ai_providers_updated',
            'description' => 'Admin updated AI provider settings.',
            'meta'        => ['keys' => array_keys($result['saved'] ?? [])],
        ]);
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action. Use action=save or action=test.']);
