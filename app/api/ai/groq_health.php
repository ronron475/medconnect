<?php
/**
 * Groq API health check.
 * Prefers Railway's authenticated /api/groq_health, then Railway's public /health.
 * Does not treat a Hostinger-side missing Groq key as the AI service status.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once BASE_PATH . '/app/includes/ai_service_provider_health.php';
require_once BASE_PATH . '/app/includes/ai_endpoint_security.php';

Api::startJson();
ai_endpoint_rate_limit('ai_groq_health', 30, 60);

if (ai_service_base_is_remote()) {
    $report = ai_service_provider_public_status('groq');
    $payload = [
        'groq' => (bool) $report['ok'],
        'provider' => $report['ok'] ? 'groq' : null,
        'status' => (string) $report['status'],
        'configured' => (bool) $report['configured'],
        'model' => $report['model'],
        'service_auth' => (string) $report['service_auth'],
    ];
    if (ai_endpoint_can_expose_debug()) {
        $payload['error'] = $report['error'];
    }
    Api::success($payload, (string) $report['message']);
    exit;
}

$body = null;
if (defined('AI_SERVICE_BASE_URL') && AI_SERVICE_BASE_URL !== '' && function_exists('curl_init')) {
    $fetched = ai_service_http_get(
        rtrim((string) AI_SERVICE_BASE_URL, '/') . '/api/groq_health',
        medconnect_ai_service_token() !== '',
        12
    );
    if ($fetched['ok'] && is_array($fetched['body'])) {
        $body = $fetched['body'];
    }
}

$aiStatus = MedicalAiInterpreter::providerStatus();
$configured = (bool) ($aiStatus['groq_configured'] ?? false);
$online = is_array($body) && !empty($body['groq']);
$status = $online ? 'online' : (string) ($body['status'] ?? ($configured ? 'offline' : 'not_configured'));
if ($status === 'missing_key') {
    $status = 'not_configured';
}

$payload = [
    'groq' => $online,
    'provider' => $online ? 'groq' : null,
    'status' => $status,
    'configured' => is_array($body) && array_key_exists('configured', $body) ? (bool) $body['configured'] : $configured,
    'model' => is_array($body) ? ($body['model'] ?? null) : null,
    'service_auth' => is_array($body) ? 'ok' : 'not_checked',
];
if (ai_endpoint_can_expose_debug()) {
    $payload['error'] = $online ? null : ai_service_safe_error(isset($body['error']) ? (string) $body['error'] : null);
}

Api::success($payload);
