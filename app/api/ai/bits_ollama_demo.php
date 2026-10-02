<?php
/**
 * DEMO ONLY — campus BITS Ollama playground API.
 * Does not persist triage_results or touch production patient portal routes.
 *
 * Auth: session CSRF + demo token from bits_ollama_demo.php
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/security_throttle.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth_guard.php';
require_once dirname(dirname(__DIR__)) . '/includes/ai_endpoint_security.php';
require_once dirname(dirname(__DIR__)) . '/includes/openrouter_demo_fallback.php';

Api::startJson();
Api::requirePost();
set_time_limit(120);
ai_endpoint_rate_limit('bits_ollama_demo', 20, 60);

/** @var PDO $pdo */

$csrf = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$sessionToken = (string) ($_SESSION['bits_ollama_demo_token'] ?? '');
$postedToken = (string) ($_POST['demo_token'] ?? $_SERVER['HTTP_X_BITS_OLLAMA_DEMO_TOKEN'] ?? '');
$sessionOk = auth_csrf_validate($csrf)
    && $sessionToken !== ''
    && $postedToken !== ''
    && hash_equals($sessionToken, $postedToken);

if (!$sessionOk) {
    Api::error('Demo session missing or expired. Reload the BITS Ollama Demo page.', 403, [
        'code' => 'demo_session_invalid',
    ]);
}

$ip = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown'));
if (str_contains($ip, ',')) {
    $ip = trim(explode(',', $ip)[0]);
}
$ipKey = security_throttle_key('bits_ollama_demo_ip', $ip ?: 'unknown');
$ipState = security_throttle_check($pdo, $ipKey);
if (!empty($ipState['locked'])) {
    Api::error('Too many demo requests. Please wait and try again.', 429, [
        'code' => 'rate_limited',
        'locked_until' => $ipState['locked_until'] ?? null,
    ]);
}

if (!medconnect_bits_service_enabled()) {
    Api::error('BITS Ollama is disabled.', 503, ['code' => 'bits_disabled']);
}

$prompt = trim((string) ($_POST['prompt'] ?? ''));
if ($prompt === '') {
    Api::error('Please type a prompt.');
}
if (mb_strlen($prompt) > 2000) {
    Api::error('Prompt is too long.');
}

$body = [
    'model' => medconnect_bits_ollama_model(),
    'stream' => false,
    'messages' => [
        [
            'role' => 'system',
            'content' => 'You are a campus demo assistant for medConnect. '
                . 'Help with language understanding only. '
                . 'Do not assign EMERGENCY, URGENT, or NON-URGENT. '
                . 'Do not diagnose or prescribe.',
        ],
        ['role' => 'user', 'content' => $prompt],
    ],
];

$started = microtime(true);
$text = medconnect_demo_bits_http_complete($body);
$elapsedMs = (int) round((microtime(true) - $started) * 1000);

if (!is_string($text) || trim($text) === '') {
    Api::error('BITS did not return a reply. The campus server may be busy. Try again.', 503, [
        'code' => 'bits_unavailable',
        'model' => medconnect_bits_ollama_model(),
        'endpoint' => medconnect_bits_service_url(),
    ]);
}

Api::success([
    'demo' => true,
    'provider' => 'bits_ollama',
    'model' => medconnect_bits_ollama_model(),
    'endpoint' => medconnect_bits_service_url(),
    'prompt' => $prompt,
    'response' => trim($text),
    'elapsed_ms' => $elapsedMs,
    'sets_triage' => false,
    'final_authority' => 'ClinicalTriageEngine',
], 'BITS reply ready');
