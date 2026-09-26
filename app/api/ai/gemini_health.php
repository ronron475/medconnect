<?php
/**
 * Gemini API health check (proxies Python /api/gemini_health when available,
 * otherwise uses the shared ai_providers tester — same keys as existing Gemini integration).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once BASE_PATH . '/app/includes/ai_providers.php';
require_once BASE_PATH . '/app/includes/ai_endpoint_security.php';

Api::startJson();
ai_endpoint_rate_limit('ai_gemini_health', 30, 60);

$body = null;
if (defined('AI_SERVICE_BASE_URL') && AI_SERVICE_BASE_URL !== '' && function_exists('curl_init')) {
    $curl = curl_init(AI_SERVICE_BASE_URL . '/api/gemini_health');
    if ($curl !== false) {
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $raw = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($raw !== false && $code >= 200 && $code < 300) {
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }
    }
}

if (is_array($body)) {
    $online = !empty($body['gemini']) || (($body['status'] ?? '') === 'online');
    $payload = [
        'gemini'     => $online,
        'provider'   => $online ? 'gemini' : null,
        'status'     => $online ? 'online' : (string) ($body['status'] ?? 'offline'),
        'configured' => ai_providers_gemini_key_configured(),
    ];
    if (ai_endpoint_can_expose_debug()) {
        $payload['model'] = (string) ($body['model'] ?? ai_providers_gemini_model());
        $payload['error'] = $body['error'] ?? null;
    }
    Api::success($payload);
    exit;
}

$test = ai_providers_test_gemini(true);
$payload = [
    'gemini'     => (bool) ($test['ok'] ?? false),
    'provider'   => ($test['ok'] ?? false) ? 'gemini' : null,
    'status'     => (string) ($test['status'] ?? 'offline'),
    'configured' => ai_providers_gemini_key_configured(),
];
if (ai_endpoint_can_expose_debug()) {
    $payload['model'] = (string) ($test['model'] ?? ai_providers_gemini_model());
    $payload['error'] = ($test['ok'] ?? false) ? null : 'Gemini unavailable';
}
Api::success($payload);
