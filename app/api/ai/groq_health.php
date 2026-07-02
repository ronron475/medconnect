<?php
/**
 * Groq API health check (proxies Python /api/groq_health when available).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';

Api::startJson();

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

Api::success([
    'groq'       => $online,
    'provider'   => $online ? 'groq' : null,
    'model'      => (string) ($body['model'] ?? GROQ_MODEL),
    'status'     => $online ? 'online' : (string) ($body['status'] ?? ($configured ? 'offline' : 'missing_key')),
    'configured' => $configured,
    'error'      => $body['error'] ?? ($configured ? null : 'GROQ_API_KEY not configured'),
    'python'     => $body,
]);
