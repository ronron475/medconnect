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

$bits = [
    'online' => false,
    'url' => defined('BITS_SERVICE_URL') ? BITS_SERVICE_URL : '',
    'model' => defined('BITS_OLLAMA_MODEL') ? BITS_OLLAMA_MODEL : 'phi3:mini',
    'enabled' => defined('BITS_SERVICE_ENABLED') ? BITS_SERVICE_ENABLED : true,
];
if ($bits['enabled'] && $bits['url'] !== '' && function_exists('curl_init')) {
    $bitsCurl = curl_init(rtrim((string) $bits['url'], '/') . '/api/version');
    if ($bitsCurl !== false) {
        curl_setopt_array($bitsCurl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
        ]);
        $bitsRaw = curl_exec($bitsCurl);
        $bitsCode = (int) curl_getinfo($bitsCurl, CURLINFO_HTTP_CODE);
        curl_close($bitsCurl);
        $bits['online'] = $bitsRaw !== false && $bitsCode >= 200 && $bitsCode < 300;
    }
}

$payload = [
    'status'    => $online ? 'online' : 'offline',
    'service'   => $online ? 'python-medical-profile-nlp' : 'php-validation-workflow',
    'online'    => $online,
    'port_open' => (bool) ($diag['port_open'] ?? false),
    'engine'    => (string) ($diag['engine'] ?? 'php-validation-workflow'),
    'groq'      => $online && ($diag['groq_configured'] ?? false) ? 'connected' : (($diag['groq_configured'] ?? false) ? 'configured' : 'missing'),
    'bits'      => $bits['online'] ? 'online' : ($bits['enabled'] ? 'offline' : 'disabled'),
    'bits_model'=> (string) $bits['model'],
];

if (ai_endpoint_can_expose_debug()) {
    $payload['port'] = (int) ($diag['port'] ?? 8765);
    $payload['model'] = (string) ($diag['model'] ?? (defined('GROQ_MODEL') ? GROQ_MODEL : ''));
    $payload['reason'] = (string) ($diag['reason'] ?? '');
    $payload['diagnostics'] = $diag;
}

Api::success($payload, $online ? 'Python AI service online' : 'Python AI service offline');
