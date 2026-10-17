<?php
/**
 * Railway provider-status mapping and live health integration.
 * Does not print service tokens or provider API keys.
 *
 * Run: php scripts/test/ai_service_health_status_tests.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/includes/ai_service_provider_health.php';

$failures = 0;

function pass(string $message): void
{
    echo "PASS  {$message}\n";
}

function fail(string $message, string $detail = ''): void
{
    global $failures;
    $failures++;
    echo "FAIL  {$message}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function expect(bool $ok, string $message, string $detail = ''): void
{
    if ($ok) {
        pass($message);
        return;
    }
    fail($message, $detail);
}

$connected = ai_service_interpret_service_health('gemini', [
    'gemini' => 'connected',
    'gemini_model' => 'gemini-3.5-flash-lite',
    'gemini_error' => null,
]);
expect($connected['ok'] === true, 'public health connected maps to online');
expect($connected['status'] === 'online', 'connected status is online', $connected['status']);
expect($connected['configured'] === true, 'connected Gemini is configured');
expect($connected['model'] === 'gemini-3.5-flash-lite', 'Gemini model is kept', (string) $connected['model']);

$missing = ai_service_interpret_provider_body('gemini', [
    'gemini' => false,
    'status' => 'missing_key',
    'configured' => false,
    'model' => 'gemini-3.5-flash',
    'error' => 'AI_API_KEY / GEMINI_API_KEY not configured',
]);
expect($missing['status'] === 'not_configured', 'missing key stays not_configured', $missing['status']);
expect($missing['configured'] === false, 'missing key is not configured');
expect($missing['ok'] === false, 'missing key is not online');

$quota = ai_service_interpret_provider_body('gemini', [
    'gemini' => false,
    'status' => 'quota_exceeded',
    'configured' => true,
    'model' => 'gemini-3.5-flash-lite',
    'error' => 'HTTP 429',
]);
expect($quota['status'] === 'quota_exceeded', 'quota is not reported as not_configured', $quota['status']);
expect($quota['configured'] === true, 'quota still means the key is configured');

$googleAuth = ai_service_interpret_provider_body('gemini', [
    'gemini' => false,
    'status' => 'auth_failed',
    'configured' => true,
    'model' => 'gemini-3.5-flash-lite',
    'error' => 'Invalid API key',
]);
expect($googleAuth['status'] === 'auth_failed', 'provider auth failure stays auth_failed');
expect($googleAuth['configured'] === true, 'rejected provider key is still configured');

$groq = ai_service_interpret_service_health('groq', [
    'groq' => 'connected',
    'model' => 'openai/gpt-oss-120b',
]);
expect($groq['ok'] === true && $groq['model'] === 'openai/gpt-oss-120b', 'Groq connected status and model are kept');

$redacted = ai_service_safe_error('request failed api_key=AIzaSySECRETVALUE');
expect($redacted === 'Upstream AI request failed.', 'key material is not copied into errors', (string) $redacted);

$serviceAuth = ai_service_provider_failure('gemini', 'auth_failed', 'rejected');
expect($serviceAuth['status'] === 'auth_failed', 'service-token rejection is auth_failed');
expect($serviceAuth['status'] !== 'not_configured', 'service-token rejection is not not_configured');
expect(!str_contains(json_encode($serviceAuth), 'Bearer'), 'failure payload has no bearer token');

$unreachable = ai_service_provider_failure('groq', 'unreachable', 'unreachable');
expect($unreachable['status'] === 'unreachable', 'connection failure is unreachable');

putenv('MEDCONNECT_AI_SERVICE_TOKEN');
unset($_ENV['MEDCONNECT_AI_SERVICE_TOKEN']);

$envFile = $root . '/.env';
if (is_readable($envFile)) {
    $envLines = file($envFile, FILE_IGNORE_NEW_LINES);
    if (is_array($envLines)) {
        foreach ($envLines as $envLine) {
            $envLine = trim($envLine);
            if ($envLine === '' || str_starts_with($envLine, '#') || !str_starts_with($envLine, 'AI_SSL_VERIFY=')) {
                continue;
            }
            $sslFlag = trim(substr($envLine, strlen('AI_SSL_VERIFY=')), " \t\"'");
            putenv('AI_SSL_VERIFY=' . $sslFlag);
            $_ENV['AI_SSL_VERIFY'] = $sslFlag;
        }
    }
}

$railway = 'https://medconnect-production-ab04.up.railway.app';
$liveGemini = ai_service_provider_public_status('gemini', $railway);
$liveGroq = ai_service_provider_public_status('groq', $railway);
$encoded = json_encode([$liveGemini, $liveGroq], JSON_UNESCAPED_UNICODE);
expect(is_string($encoded) && !str_contains($encoded, 'Bearer') && !str_contains($encoded, 'AIza'), 'live payload has no token or API key');
expect(($liveGemini['service_auth'] ?? '') === 'missing_token', 'missing token is reported separately', (string) ($liveGemini['service_auth'] ?? ''));
expect(($liveGemini['ok'] ?? false) === true, 'Railway Gemini is online without a local API key', (string) ($liveGemini['status'] ?? ''));
expect(($liveGemini['status'] ?? '') === 'online', 'Railway Gemini status is online', (string) ($liveGemini['status'] ?? ''));
expect(($liveGemini['configured'] ?? false) === true, 'Railway Gemini is configured');
expect(($liveGemini['model'] ?? '') === 'gemini-3.5-flash-lite', 'Railway Gemini model is gemini-3.5-flash-lite', (string) ($liveGemini['model'] ?? ''));
expect(($liveGroq['ok'] ?? false) === true, 'Railway Groq is online', (string) ($liveGroq['status'] ?? ''));
expect(($liveGroq['model'] ?? '') === 'openai/gpt-oss-120b', 'Railway Groq model is kept', (string) ($liveGroq['model'] ?? ''));

$denied = ai_service_http_get($railway . '/api/gemini_health', false, 12);
expect(($denied['http_code'] ?? 0) === 401, 'protected Gemini health without a token is 401', (string) ($denied['http_code'] ?? 0));
expect(($denied['failure'] ?? '') === 'auth_failed', '401 is auth_failed, not not_configured', (string) ($denied['failure'] ?? ''));

$envLoader = $root . '/config/env_loader.php';
if (is_readable($envLoader)) {
    require_once $envLoader;
}
$tokenSet = medconnect_ai_service_token() !== '';
echo 'INFO  local service token configured: ' . ($tokenSet ? 'yes' : 'no') . ' length=' . strlen(medconnect_ai_service_token()) . "\n";
if ($tokenSet) {
    $authed = ai_service_provider_public_status('gemini', $railway);
    expect(
        ($authed['service_auth'] ?? '') === 'ok',
        'configured local service token is accepted by Railway',
        (string) ($authed['service_auth'] ?? '')
    );
    expect(($authed['status'] ?? '') === 'online', 'authenticated Gemini status is online', (string) ($authed['status'] ?? ''));
    expect(!str_contains((string) json_encode($authed), medconnect_ai_service_token()), 'authenticated payload does not echo the token');
}

echo $failures === 0 ? "OK {$failures} failed\n" : "FAILED {$failures}\n";
exit($failures === 0 ? 0 : 1);
