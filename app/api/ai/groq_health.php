<?php
/**
 * Groq API health check (proxies Python /api/groq_health when available).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once BASE_PATH . '/app/includes/ai_endpoint_security.php';

Api::startJson();
ai_endpoint_rate_limit('ai_groq_health', 30, 60);

$body = null;
if (function_exists('curl_init')) {
    $curl = curl_init(AI_SERVICE_BASE_URL . '/api/groq_health');
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

$aiStatus = MedicalAiInterpreter::providerStatus();
$configured = (bool) ($aiStatus['groq_configured'] ?? false);
$online = is_array($body) && !empty($body['groq']);

$payload = [
    'groq'       => $online,
    'provider'   => $online ? 'groq' : null,
    'status'     => $online ? 'online' : (string) ($body['status'] ?? ($configured ? 'offline' : 'missing_key')),
    'configured' => $configured,
];
if (ai_endpoint_can_expose_debug()) {
    $payload['model'] = (string) ($body['model'] ?? (defined('GROQ_MODEL') ? GROQ_MODEL : ''));
    $payload['error'] = $configured ? ($body['error'] ?? null) : 'GROQ_API_KEY not configured';
}

Api::success($payload);
