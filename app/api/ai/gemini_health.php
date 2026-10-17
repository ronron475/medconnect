<?php
/**
 * Gemini API health check.
 * Prefers Railway's authenticated /api/gemini_health, then Railway's public /health.
 * Does not treat a Hostinger-side missing Gemini key as the AI service status.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once BASE_PATH . '/app/includes/ai_providers.php';
require_once BASE_PATH . '/app/includes/ai_service_provider_health.php';
require_once BASE_PATH . '/app/includes/ai_endpoint_security.php';

Api::startJson();
ai_endpoint_rate_limit('ai_gemini_health', 30, 60);

if (ai_service_base_is_remote()) {
    $report = ai_service_provider_public_status('gemini');
    $payload = [
        'gemini' => (bool) $report['ok'],
        'provider' => $report['ok'] ? 'gemini' : null,
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

$test = ai_providers_test_gemini(true);
$payload = [
    'gemini' => (bool) ($test['ok'] ?? false),
    'provider' => ($test['ok'] ?? false) ? 'gemini' : null,
    'status' => (string) ($test['status'] ?? 'offline'),
    'configured' => ai_providers_gemini_key_configured(),
    'model' => $test['model'] ?? null,
    'service_auth' => 'not_checked',
];
if (ai_endpoint_can_expose_debug()) {
    $payload['error'] = ($test['ok'] ?? false) ? null : 'Gemini unavailable';
}
Api::success($payload, (string) ($test['message'] ?? 'OK'));
