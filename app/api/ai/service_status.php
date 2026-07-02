<?php
/**
 * Lightweight AI service status for demo UI (with auto-start attempt).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';

Api::startJson();

try {
    $autoStart = isset($_GET['start']) && $_GET['start'] === '1';
    if ($autoStart && AI_SERVICE_ENABLED && AI_SERVICE_AUTO_START && !AiServiceClient::isHealthy(2)) {
        AiServiceLauncher::log('service_status: attempting background auto-start');
        AiServiceLauncher::ensureRunning(false);
    }

    $status = AiServiceClient::connectionStatus();
    $aiStatus = MedicalAiInterpreter::providerStatus();

    Api::success([
        'online'            => (bool) ($status['online'] ?? false),
        'status'            => ($status['online'] ?? false) ? 'online' : 'offline',
        'url'               => $status['url'] ?? AI_SERVICE_BASE_URL,
        'message'           => $status['message'] ?? '',
        'reason'            => $status['reason'] ?? '',
        'port'              => (int) ($status['port'] ?? 8765),
        'port_open'         => (bool) ($status['port_open'] ?? false),
        'engine'            => $status['engine'] ?? 'php-validation-workflow',
        'service'           => $status['service'] ?? '',
        'timeout'           => AI_SERVICE_TIMEOUT_ANALYZE,
        'ai_service_enabled' => AI_SERVICE_ENABLED,
        'ai_auto_start'     => AI_SERVICE_AUTO_START,
        'groq_configured'   => (bool) ($aiStatus['groq_configured'] ?? false),
        'groq_connected'    => (bool) ($status['groq_connected'] ?? false),
        'groq_error'        => $status['groq_error'] ?? '',
        'groq'              => ($status['groq_connected'] ?? false)
            ? 'connected'
            : (($status['groq'] ?? '') === 'failed'
                ? 'failed'
                : (($aiStatus['groq_configured'] ?? false) ? 'configured' : 'missing')),
        'ai_enabled'        => (bool) ($aiStatus['enabled'] ?? true),
        'groq_model'        => GROQ_MODEL,
        'model'             => $status['model'] ?? GROQ_MODEL,
        'python_executable' => $status['python_executable'] ?? null,
        'venv_valid'        => (bool) ($status['venv_valid'] ?? false),
        'venv_broken'       => (bool) ($status['venv_broken'] ?? false),
        'dependencies_ok'   => (bool) ($status['dependencies_ok'] ?? false),
        'diagnostics'       => $status['diagnostics'] ?? [],
    ]);
} catch (Throwable $e) {
    AiServiceLauncher::log('service_status error: ' . $e->getMessage());
    Api::success([
        'online'            => false,
        'status'            => 'error',
        'reason'            => 'Status check failed: ' . $e->getMessage(),
        'engine'            => 'php-validation-workflow',
        'ai_service_enabled' => defined('AI_SERVICE_ENABLED') ? AI_SERVICE_ENABLED : false,
    ]);
}
