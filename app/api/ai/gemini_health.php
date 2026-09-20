<?php
/**
 * Gemini API health check (proxies Python /api/gemini_health when available,
 * otherwise uses the shared ai_providers tester — same keys as existing Gemini integration).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once BASE_PATH . '/app/includes/ai_providers.php';

Api::startJson();

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
    Api::success([
        'gemini'     => $online,
        'provider'   => $online ? 'gemini' : null,
        'model'      => (string) ($body['model'] ?? ai_providers_gemini_model()),
        'status'     => $online ? 'online' : (string) ($body['status'] ?? 'offline'),
        'configured' => ai_providers_gemini_key_configured(),
        'error'      => $body['error'] ?? null,
        'python'     => $body,
    ]);
    exit;
}

$test = ai_providers_test_gemini(true);
Api::success([
    'gemini'     => (bool) ($test['ok'] ?? false),
    'provider'   => ($test['ok'] ?? false) ? 'gemini' : null,
    'model'      => (string) ($test['model'] ?? ai_providers_gemini_model()),
    'status'     => (string) ($test['status'] ?? 'offline'),
    'configured' => ai_providers_gemini_key_configured(),
    'error'      => ($test['ok'] ?? false) ? null : (string) ($test['message'] ?? 'Gemini unavailable'),
    'python'     => null,
]);
