<?php
/**
 * Lightweight AI service status for demo UI (with auto-start attempt).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once BASE_PATH . '/app/includes/ai_endpoint_security.php';

Api::startJson();
ai_endpoint_rate_limit('ai_service_status', 60, 60);

try {
    $autoStart = isset($_GET['start']) && $_GET['start'] === '1';
    if (
        $autoStart
        && ai_endpoint_can_start_ai_service()
        && AI_SERVICE_ENABLED
        && AI_SERVICE_AUTO_START
        && !AiServiceClient::isHealthy(2)
    ) {
        AiServiceLauncher::log('service_status: attempting background auto-start');
        AiServiceLauncher::ensureRunning(false);
    }

    $status = AiServiceClient::connectionStatus();
    $aiStatus = MedicalAiInterpreter::providerStatus();

    $payload = ai_endpoint_public_connection_status([
        'online' => (bool) ($status['online'] ?? false),
        'status' => ($status['online'] ?? false) ? 'online' : 'offline',
        'engine' => $status['engine'] ?? 'php-validation-workflow',
        'service' => $status['service'] ?? '',
        'port_open' => (bool) ($status['port_open'] ?? false),
        'groq_configured' => (bool) ($aiStatus['groq_configured'] ?? false),
        'groq_connected' => (bool) ($status['groq_connected'] ?? false),
        'ai_service_enabled' => AI_SERVICE_ENABLED,
    ]);
    $payload['ai_auto_start'] = AI_SERVICE_AUTO_START;
    $payload['ai_enabled'] = (bool) ($aiStatus['enabled'] ?? true);
    $payload['model'] = (string) ($status['model'] ?? GROQ_MODEL);
    $payload['groq_model'] = GROQ_MODEL;
    $payload['groq'] = ($status['groq_connected'] ?? false)
        ? 'connected'
        : (($status['groq'] ?? '') === 'failed'
            ? 'failed'
            : (($aiStatus['groq_configured'] ?? false) ? 'configured' : 'missing'));

    if (ai_endpoint_can_expose_debug()) {
        $payload['url'] = $status['url'] ?? AI_SERVICE_BASE_URL;
        $payload['message'] = $status['message'] ?? '';
        $payload['reason'] = $status['reason'] ?? '';
        $payload['port'] = (int) ($status['port'] ?? 8765);
        $payload['timeout'] = AI_SERVICE_TIMEOUT_ANALYZE;
        $payload['diagnostics'] = $status['diagnostics'] ?? [];
    }

    Api::success($payload);
} catch (Throwable $e) {
    AiServiceLauncher::log('service_status error: ' . $e->getMessage());
    Api::success([
        'online' => false,
        'status' => 'error',
        'reason' => 'Status check failed.',
        'engine' => 'php-validation-workflow',
        'ai_service_enabled' => defined('AI_SERVICE_ENABLED') ? AI_SERVICE_ENABLED : false,
    ]);
}
