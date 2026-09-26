<?php
/**
 * Combined health check: PHP launcher diagnostics + Python /health when available.
 * GET /app/api/ai/health.php
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once BASE_PATH . '/app/includes/ai_endpoint_security.php';

Api::startJson();
ai_endpoint_rate_limit('ai_health', 60, 60);

$attemptStart = isset($_GET['start']) && $_GET['start'] === '1';
if ($attemptStart && ai_endpoint_can_start_ai_service() && !AiServiceClient::isHealthy()) {
    AiServiceLauncher::ensureRunning(true);
}

$diag = AiServiceLauncher::diagnostics();
$online = (bool) ($diag['online'] ?? false);

$payload = [
    'status'    => $online ? 'online' : 'offline',
    'service'   => $online ? 'python-medical-profile-nlp' : 'php-validation-workflow',
    'online'    => $online,
    'port_open' => (bool) ($diag['port_open'] ?? false),
    'engine'    => (string) ($diag['engine'] ?? 'php-validation-workflow'),
    'groq'      => $online && ($diag['groq_configured'] ?? false) ? 'connected' : (($diag['groq_configured'] ?? false) ? 'configured' : 'missing'),
];

if (ai_endpoint_can_expose_debug()) {
    $payload['port'] = (int) ($diag['port'] ?? 8765);
    $payload['model'] = (string) ($diag['model'] ?? (defined('GROQ_MODEL') ? GROQ_MODEL : ''));
    $payload['reason'] = (string) ($diag['reason'] ?? '');
    $payload['diagnostics'] = $diag;
}

Api::success($payload, $online ? 'Python AI service online' : 'Python AI service offline');
