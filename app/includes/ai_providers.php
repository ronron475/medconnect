<?php
/**
 * Admin AI provider settings — status, test, and non-secret persistence.
 *
 * Maps to existing MedConnect integrations:
 * - medconnect_ai → Python AI microservice (AiServiceClient)
 * - groq          → MedicalAiInterpreter / Groq API
 * - gemini        → FAQ/interview Gemini (AI_API_KEY / GEMINI_API_KEY)
 *
 * Secrets stay in .env. This module never returns full API keys.
 * Clinical triage authority remains ClinicalTriageEngine / TriageLevelService.
 */

declare(strict_types=1);

if (defined('MEDCONNECT_AI_PROVIDERS_LOADED')) {
    return;
}
define('MEDCONNECT_AI_PROVIDERS_LOADED', true);

require_once __DIR__ . '/system_settings.php';

/** @return list<string> */
function ai_providers_runtime_allowlist(): array
{
    return [
        'MEDCONNECT_AI_SERVICE_ENABLED',
        'MEDCONNECT_AI_INTERPRETER',
        'AI_ENABLED',
        'AI_PROVIDER',
        'AI_MODEL',
        'MEDCONNECT_GROQ_MODEL',
        'GROQ_MODEL',
    ];
}

function ai_providers_runtime_path(): string
{
    $base = defined('STORAGE_PATH') ? STORAGE_PATH : (dirname(__DIR__, 2) . '/storage');
    return rtrim(str_replace('\\', '/', $base), '/') . '/ai_provider_runtime.env';
}

/**
 * Force-apply allowlisted runtime overrides (after .env). Safe to call early.
 */
function ai_providers_apply_runtime_file(?string $path = null): void
{
    $path = $path ?? ai_providers_runtime_path();
    if (!is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    $allow = array_fill_keys(ai_providers_runtime_allowlist(), true);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name === '' || !isset($allow[$name])) {
            continue;
        }
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
    }
}

/** @return array<string, array<string, mixed>> */
function ai_providers_catalog(): array
{
    return [
        'medconnect_ai' => [
            'id'          => 'medconnect_ai',
            'name'        => 'MedConnect AI (Real AI)',
            'label'       => 'Real AI',
            'description' => 'Existing Python medical NLP microservice used for analysis and enrichment.',
            'role'        => 'Semantic NLP / enrichment only — does not set final triage level.',
        ],
        'groq' => [
            'id'          => 'groq',
            'name'        => 'Groq',
            'label'       => 'Groq',
            'description' => 'Existing Groq-backed medical language interpreter (Hiligaynon / complaint understanding).',
            'role'        => 'Interpretation and extraction only — does not set final triage level.',
        ],
        'gemini' => [
            'id'          => 'gemini',
            'name'        => 'Gemini',
            'label'       => 'Gemini',
            'description' => 'Existing Google Gemini integration (FAQ fallback, complaint validation, follow-up assists).',
            'role'        => 'Semantic assist / fallback only — does not set final triage level.',
        ],
    ];
}

function ai_providers_gemini_key_configured(): bool
{
    foreach (['AI_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_API_KEY'] as $key) {
        $val = trim((string) (getenv($key) ?: ($_ENV[$key] ?? '')));
        if ($val !== '') {
            return true;
        }
    }
    return false;
}

function ai_providers_gemini_model(): string
{
    $model = trim((string) (getenv('AI_MODEL') ?: ($_ENV['AI_MODEL'] ?? '')));
    if ($model !== '' && str_starts_with(strtolower($model), 'gemini')) {
        return $model;
    }
    return 'gemini-3.5-flash';
}

function ai_providers_groq_model(): string
{
    if (defined('GROQ_MODEL') && GROQ_MODEL !== '') {
        return (string) GROQ_MODEL;
    }
    return (string) (getenv('MEDCONNECT_GROQ_MODEL') ?: getenv('GROQ_MODEL') ?: 'openai/gpt-oss-120b');
}

function ai_providers_bool_env(string $key, bool $default = true): bool
{
    $raw = getenv($key);
    if ($raw === false || $raw === '') {
        $raw = $_ENV[$key] ?? null;
    }
    if ($raw === null || $raw === '') {
        return $default;
    }
    return !in_array(strtolower(trim((string) $raw)), ['0', 'false', 'no', 'off'], true);
}

/**
 * @return array{ok:bool,body:?array,error:?string,http_code:int}
 */
function ai_providers_http_get_json(string $url, int $timeout = 8): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'body' => null, 'error' => 'cURL unavailable', 'http_code' => 0];
    }
    $curl = curl_init($url);
    if ($curl === false) {
        return ['ok' => false, 'body' => null, 'error' => 'cURL init failed', 'http_code' => 0];
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $raw = curl_exec($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $err = curl_error($curl);
    curl_close($curl);
    if ($raw === false) {
        return ['ok' => false, 'body' => null, 'error' => $err !== '' ? $err : 'Request failed', 'http_code' => $code];
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'body' => null, 'error' => 'Invalid JSON response', 'http_code' => $code];
    }
    return ['ok' => $code >= 200 && $code < 300, 'body' => $decoded, 'error' => null, 'http_code' => $code];
}

/**
 * @return array{ok:bool,body:?array,error:?string,http_code:int}
 */
function ai_providers_http_post_json(string $url, array $payload, array $headers, int $timeout = 15): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'body' => null, 'error' => 'cURL unavailable', 'http_code' => 0];
    }
    $curl = curl_init($url);
    if ($curl === false) {
        return ['ok' => false, 'body' => null, 'error' => 'cURL init failed', 'http_code' => 0];
    }
    $hdrs = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $hdrs,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $err = curl_error($curl);
    curl_close($curl);
    if ($raw === false) {
        return ['ok' => false, 'body' => null, 'error' => $err !== '' ? $err : 'Request failed', 'http_code' => $code];
    }
    $decoded = json_decode((string) $raw, true);
    return [
        'ok'        => $code >= 200 && $code < 300,
        'body'      => is_array($decoded) ? $decoded : null,
        'error'     => $code >= 200 && $code < 300 ? null : ('HTTP ' . $code),
        'http_code' => $code,
    ];
}

/**
 * @return array{status:string,label:string,message:string,reachable:bool}
 */
function ai_providers_normalize_state(bool $enabled, bool $configured, bool $reachable, ?string $error = null): array
{
    if (!$enabled) {
        return [
            'status'    => 'disabled',
            'label'     => 'Disabled',
            'message'   => 'Provider is disabled in settings.',
            'reachable' => false,
        ];
    }
    if (!$configured) {
        return [
            'status'    => 'not_configured',
            'label'     => 'Not configured',
            'message'   => 'Required API configuration is missing in server environment.',
            'reachable' => false,
        ];
    }
    if ($reachable) {
        return [
            'status'    => 'connected',
            'label'     => 'Connected',
            'message'   => 'Configured and reachable.',
            'reachable' => true,
        ];
    }
    return [
        'status'    => 'unavailable',
        'label'     => 'Unavailable',
        'message'   => $error !== null && $error !== '' ? $error : 'Configured but not reachable.',
        'reachable' => false,
    ];
}

/**
 * Live provider snapshot for Admin UI (no secrets).
 * When $liveTest is false, uses AI service /health diagnostics (no direct vendor ping).
 * When $liveTest is true, runs real provider tests (Test Connection / Refresh).
 *
 * @return array{providers:list<array<string,mixed>>,generated_at:string}
 */
function ai_providers_live_snapshot(bool $liveTest = false): array
{
    $catalog = ai_providers_catalog();
    $out = [];

    $svcStatus = null;
    if (class_exists('AiServiceClient')) {
        try {
            $svcStatus = AiServiceClient::connectionStatus();
        } catch (Throwable $e) {
            $svcStatus = ['online' => false, 'reason' => $e->getMessage()];
        }
    }

    // ── MedConnect AI (Python service) ──────────────────────────────────────
    $mcEnabled = defined('AI_SERVICE_ENABLED')
        ? (bool) AI_SERVICE_ENABLED
        : ai_providers_bool_env('MEDCONNECT_AI_SERVICE_ENABLED', true);
    $mcUrl = defined('AI_SERVICE_BASE_URL') ? (string) AI_SERVICE_BASE_URL : 'http://127.0.0.1:8765';
    $mcOnline = $mcEnabled && !empty($svcStatus['online']);
    $mcError = $mcEnabled
        ? ($mcOnline ? null : (string) ($svcStatus['reason'] ?? $svcStatus['message'] ?? 'Cannot reach AI service'))
        : 'MedConnect AI service disabled.';
    $mcState = ai_providers_normalize_state($mcEnabled, true, $mcOnline, $mcError);
    $out[] = array_merge($catalog['medconnect_ai'], [
        'enabled'     => $mcEnabled,
        'configured'  => true,
        'model'       => !empty($svcStatus['model']) ? (string) $svcStatus['model'] : null,
        'endpoint'    => $mcUrl,
        'engine'      => (string) ($svcStatus['engine'] ?? 'python-medical-profile-nlp'),
        'api_key_set' => null,
        'state'       => $mcState,
    ]);

    // ── Groq ────────────────────────────────────────────────────────────────
    $groqConfigured = defined('GROQ_API_KEY') ? GROQ_API_KEY !== '' : (trim((string) (getenv('GROQ_API_KEY') ?: '')) !== '');
    $groqEnabled = defined('AI_INTERPRETER_ENABLED')
        ? (bool) AI_INTERPRETER_ENABLED
        : ai_providers_bool_env('MEDCONNECT_AI_INTERPRETER', true);
    $groqModel = ai_providers_groq_model();
    $groqReachable = false;
    $groqError = null;
    if ($liveTest && $groqEnabled && $groqConfigured) {
        $test = ai_providers_test_groq(true);
        $groqReachable = (bool) ($test['ok'] ?? false);
        $groqError = $groqReachable ? null : (string) ($test['message'] ?? 'Groq unreachable');
        if (!empty($test['model'])) {
            $groqModel = (string) $test['model'];
        }
    } elseif ($groqEnabled && $groqConfigured) {
        // Prefer real signal from AI service health when available.
        if (!empty($svcStatus['groq_connected'])) {
            $groqReachable = true;
        } elseif (($svcStatus['groq'] ?? '') === 'failed') {
            $groqError = (string) ($svcStatus['groq_error'] ?? 'Groq reported failed by AI service');
        } elseif ($groqConfigured) {
            // Configured but not yet live-tested this request.
            $groqError = 'Configured — use Test Connection to verify live reachability.';
        }
        if (!empty($svcStatus['model'])) {
            $groqModel = (string) $svcStatus['model'];
        }
    } elseif (!$groqConfigured) {
        $groqError = 'GROQ_API_KEY not configured';
    }
    $groqState = ai_providers_normalize_state($groqEnabled, $groqConfigured, $groqReachable, $groqError);
    if ($groqEnabled && $groqConfigured && !$groqReachable && !$liveTest && empty($svcStatus['groq_connected']) && ($svcStatus['groq'] ?? '') !== 'failed') {
        $groqState = [
            'status'    => 'configured',
            'label'     => 'Configured',
            'message'   => (string) $groqError,
            'reachable' => false,
        ];
    }
    $out[] = array_merge($catalog['groq'], [
        'enabled'     => $groqEnabled,
        'configured'  => $groqConfigured,
        'model'       => $groqModel,
        'endpoint'    => 'https://api.groq.com/openai/v1',
        'engine'      => 'MedicalAiInterpreter',
        'api_key_set' => $groqConfigured,
        'state'       => $groqState,
    ]);

    // ── Gemini ──────────────────────────────────────────────────────────────
    $gemConfigured = ai_providers_gemini_key_configured();
    $gemEnabled = ai_providers_bool_env('AI_ENABLED', true);
    $gemModel = ai_providers_gemini_model();
    $gemReachable = false;
    $gemError = null;
    if ($liveTest && $gemEnabled && $gemConfigured) {
        $test = ai_providers_test_gemini(true);
        $gemReachable = (bool) ($test['ok'] ?? false);
        $gemError = $gemReachable ? null : (string) ($test['message'] ?? 'Gemini unreachable');
        if (!empty($test['model'])) {
            $gemModel = (string) $test['model'];
        }
    } elseif ($gemEnabled && $gemConfigured) {
        // Peek AI service /health for gemini field when present.
        $healthGemini = null;
        if ($mcOnline && defined('AI_SERVICE_BASE_URL')) {
            $h = ai_providers_http_get_json(AI_SERVICE_BASE_URL . '/health', 4);
            if ($h['ok'] && is_array($h['body'])) {
                $g = $h['body']['gemini'] ?? $h['body']['data']['gemini'] ?? null;
                if ($g === 'connected' || $g === true) {
                    $gemReachable = true;
                } elseif ($g === 'configured' || $g === 'missing') {
                    $healthGemini = (string) $g;
                }
                if (!empty($h['body']['gemini_model'])) {
                    $gemModel = (string) $h['body']['gemini_model'];
                }
            }
        }
        if (!$gemReachable) {
            $gemError = $healthGemini === 'missing'
                ? 'Gemini key missing on AI service'
                : 'Configured — use Test Connection to verify live reachability.';
        }
    } elseif (!$gemConfigured) {
        $gemError = 'AI_API_KEY / GEMINI_API_KEY not configured';
    }
    $gemState = ai_providers_normalize_state($gemEnabled, $gemConfigured, $gemReachable, $gemError);
    if ($gemEnabled && $gemConfigured && !$gemReachable && !$liveTest) {
        $gemState = [
            'status'    => 'configured',
            'label'     => 'Configured',
            'message'   => (string) $gemError,
            'reachable' => false,
        ];
    }
    $out[] = array_merge($catalog['gemini'], [
        'enabled'     => $gemEnabled,
        'configured'  => $gemConfigured,
        'model'       => $gemModel,
        'endpoint'    => 'https://generativelanguage.googleapis.com/v1beta',
        'engine'      => 'FaqChatbotAiFallback / ClinicalInterviewGemini*',
        'api_key_set' => $gemConfigured,
        'state'       => $gemState,
    ]);

    return [
        'providers'    => $out,
        'generated_at' => gmdate('c'),
        'triage_note'  => 'ClinicalTriageEngine / TriageLevelService remain the final triage authority. AI providers do not independently set final triage.',
    ];
}

/**
 * @return array{ok:bool,status:string,message:string,model:?string,provider:string}
 */
function ai_providers_test(string $providerId, bool $force = true): array
{
    $providerId = strtolower(trim($providerId));
    return match ($providerId) {
        'medconnect_ai', 'real_ai', 'medconnect' => ai_providers_test_medconnect_ai(),
        'groq' => ai_providers_test_groq($force),
        'gemini' => ai_providers_test_gemini($force),
        default => [
            'ok'       => false,
            'status'   => 'unknown_provider',
            'message'  => 'Unknown provider.',
            'model'    => null,
            'provider' => $providerId,
        ],
    };
}

/** @return array{ok:bool,status:string,message:string,model:?string,provider:string} */
function ai_providers_test_medconnect_ai(): array
{
    $enabled = defined('AI_SERVICE_ENABLED') ? AI_SERVICE_ENABLED : ai_providers_bool_env('MEDCONNECT_AI_SERVICE_ENABLED', true);
    if (!$enabled) {
        return [
            'ok'       => false,
            'status'   => 'disabled',
            'message'  => 'MedConnect AI service is disabled.',
            'model'    => null,
            'provider' => 'medconnect_ai',
        ];
    }
    if (!class_exists('AiServiceClient')) {
        return [
            'ok'       => false,
            'status'   => 'unavailable',
            'message'  => 'AiServiceClient not loaded.',
            'model'    => null,
            'provider' => 'medconnect_ai',
        ];
    }
    $status = AiServiceClient::connectionStatus();
    $online = (bool) ($status['online'] ?? false);
    return [
        'ok'       => $online,
        'status'   => $online ? 'connected' : 'unavailable',
        'message'  => $online
            ? ('Online at ' . (string) ($status['url'] ?? AI_SERVICE_BASE_URL))
            : (string) ($status['reason'] ?? $status['message'] ?? 'Cannot reach AI service'),
        'model'    => isset($status['model']) ? (string) $status['model'] : null,
        'provider' => 'medconnect_ai',
    ];
}

/** @return array{ok:bool,status:string,message:string,model:?string,provider:string} */
function ai_providers_test_groq(bool $force = true): array
{
    $configured = defined('GROQ_API_KEY') ? GROQ_API_KEY !== '' : (trim((string) (getenv('GROQ_API_KEY') ?: '')) !== '');
    $enabled = defined('AI_INTERPRETER_ENABLED')
        ? (bool) AI_INTERPRETER_ENABLED
        : ai_providers_bool_env('MEDCONNECT_AI_INTERPRETER', true);
    $model = ai_providers_groq_model();

    if (!$enabled) {
        return [
            'ok' => false, 'status' => 'disabled', 'message' => 'Groq interpreter is disabled.',
            'model' => $model, 'provider' => 'groq',
        ];
    }
    if (!$configured) {
        return [
            'ok' => false, 'status' => 'not_configured', 'message' => 'GROQ_API_KEY not configured.',
            'model' => $model, 'provider' => 'groq',
        ];
    }

    // Prefer existing Python health endpoint when the AI service is up.
    if (defined('AI_SERVICE_BASE_URL') && AI_SERVICE_BASE_URL !== '') {
        $proxied = ai_providers_http_get_json(AI_SERVICE_BASE_URL . '/api/groq_health', 12);
        if ($proxied['ok'] && is_array($proxied['body'])) {
            $body = $proxied['body'];
            $ok = !empty($body['groq']) || (($body['status'] ?? '') === 'online');
            return [
                'ok'       => $ok,
                'status'   => $ok ? 'connected' : (string) ($body['status'] ?? 'unavailable'),
                'message'  => $ok
                    ? ('Groq reachable via AI service' . (!empty($body['model']) ? (' · ' . $body['model']) : ''))
                    : (string) ($body['error'] ?? 'Groq health check failed'),
                'model'    => isset($body['model']) ? (string) $body['model'] : $model,
                'provider' => 'groq',
            ];
        }
    }

    // Direct Groq chat ping (same endpoint MedicalAiInterpreter uses).
    $key = defined('GROQ_API_KEY') ? GROQ_API_KEY : (string) getenv('GROQ_API_KEY');
    $res = ai_providers_http_post_json(
        'https://api.groq.com/openai/v1/chat/completions',
        [
            'model'       => $model,
            'temperature' => 0,
            'max_tokens'  => 8,
            'messages'    => [
                ['role' => 'user', 'content' => 'Reply with OK only.'],
            ],
        ],
        ['Authorization: Bearer ' . $key],
        15
    );
    if ($res['ok'] && is_array($res['body']) && !empty($res['body']['choices'][0]['message']['content'])) {
        return [
            'ok'       => true,
            'status'   => 'connected',
            'message'  => 'Groq API responded successfully.',
            'model'    => $model,
            'provider' => 'groq',
        ];
    }
    $msg = (string) ($res['error'] ?? 'Groq API request failed');
    if (($res['http_code'] ?? 0) === 401 || ($res['http_code'] ?? 0) === 403) {
        $msg = 'Invalid Groq API key (HTTP ' . $res['http_code'] . ')';
    }
    return [
        'ok'       => false,
        'status'   => 'unavailable',
        'message'  => $msg,
        'model'    => $model,
        'provider' => 'groq',
    ];
}

/** @return array{ok:bool,status:string,message:string,model:?string,provider:string} */
function ai_providers_test_gemini(bool $force = true): array
{
    $configured = ai_providers_gemini_key_configured();
    $enabled = ai_providers_bool_env('AI_ENABLED', true);
    $model = ai_providers_gemini_model();

    if (!$enabled) {
        return [
            'ok' => false, 'status' => 'disabled', 'message' => 'Gemini is disabled (AI_ENABLED=false).',
            'model' => $model, 'provider' => 'gemini',
        ];
    }
    if (!$configured) {
        return [
            'ok' => false, 'status' => 'not_configured', 'message' => 'AI_API_KEY / GEMINI_API_KEY not configured.',
            'model' => $model, 'provider' => 'gemini',
        ];
    }

    if (defined('AI_SERVICE_BASE_URL') && AI_SERVICE_BASE_URL !== '') {
        $proxied = ai_providers_http_get_json(AI_SERVICE_BASE_URL . '/api/gemini_health', 12);
        if ($proxied['ok'] && is_array($proxied['body'])) {
            $body = $proxied['body'];
            $ok = !empty($body['gemini']) || (($body['status'] ?? '') === 'online');
            return [
                'ok'       => $ok,
                'status'   => $ok ? 'connected' : (string) ($body['status'] ?? 'unavailable'),
                'message'  => $ok
                    ? ('Gemini reachable via AI service' . (!empty($body['model']) ? (' · ' . $body['model']) : ''))
                    : (string) ($body['error'] ?? 'Gemini health check failed'),
                'model'    => isset($body['model']) ? (string) $body['model'] : $model,
                'provider' => 'gemini',
            ];
        }
    }

    // Direct Gemini generateContent ping (same env keys + endpoint as gemini_client.py).
    $key = '';
    foreach (['AI_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_API_KEY'] as $envKey) {
        $val = trim((string) (getenv($envKey) ?: ($_ENV[$envKey] ?? '')));
        if ($val !== '') {
            $key = $val;
            break;
        }
    }
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model)
        . ':generateContent';
    $res = ai_providers_http_post_json(
        $url,
        [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => 'Reply only with the two letters OK']]],
            ],
            'generationConfig' => [
                'temperature'     => 0,
                'maxOutputTokens' => 64,
            ],
        ],
        ['x-goog-api-key: ' . $key],
        15
    );
    if ($res['ok'] && is_array($res['body']) && !empty($res['body']['candidates'])) {
        return [
            'ok'       => true,
            'status'   => 'connected',
            'message'  => 'Gemini API responded successfully.',
            'model'    => $model,
            'provider' => 'gemini',
        ];
    }
    $msg = (string) ($res['error'] ?? 'Gemini API request failed');
    if (in_array((int) ($res['http_code'] ?? 0), [401, 403], true)) {
        $msg = 'Invalid Gemini API key (HTTP ' . $res['http_code'] . ')';
    }
    return [
        'ok'       => false,
        'status'   => 'unavailable',
        'message'  => $msg,
        'model'    => $model,
        'provider' => 'gemini',
    ];
}

/**
 * Persist non-secret provider settings to system_settings + runtime.env.
 *
 * @param array<string, mixed> $input
 * @return array{success:bool,message:string,saved:array<string,string>}
 */
function ai_providers_save(PDO $pdo, array $input, ?int $userId = null): array
{
    $mcEnabled = !empty($input['medconnect_ai_enabled']) && (string) $input['medconnect_ai_enabled'] !== '0';
    $groqEnabled = !empty($input['groq_enabled']) && (string) $input['groq_enabled'] !== '0';
    $gemEnabled = !empty($input['gemini_enabled']) && (string) $input['gemini_enabled'] !== '0';

    $groqModel = trim((string) ($input['groq_model'] ?? ''));
    if ($groqModel === '') {
        $groqModel = ai_providers_groq_model();
    }
    // Block obvious secret-looking values in model fields.
    if (preg_match('/sk-|AIza|gsk_/i', $groqModel)) {
        return ['success' => false, 'message' => 'Invalid Groq model value.', 'saved' => []];
    }

    $gemModel = trim((string) ($input['gemini_model'] ?? ''));
    if ($gemModel === '') {
        $gemModel = ai_providers_gemini_model();
    }
    if (!str_starts_with(strtolower($gemModel), 'gemini')) {
        return ['success' => false, 'message' => 'Gemini model must start with "gemini".', 'saved' => []];
    }
    if (preg_match('/sk-|AIza|gsk_/i', $gemModel)) {
        return ['success' => false, 'message' => 'Invalid Gemini model value.', 'saved' => []];
    }

    $pairs = [
        'AI_PROVIDER_MEDCONNECT_ENABLED' => $mcEnabled ? '1' : '0',
        'AI_PROVIDER_GROQ_ENABLED'       => $groqEnabled ? '1' : '0',
        'AI_PROVIDER_GEMINI_ENABLED'     => $gemEnabled ? '1' : '0',
        'AI_PROVIDER_GROQ_MODEL'         => $groqModel,
        'AI_PROVIDER_GEMINI_MODEL'       => $gemModel,
    ];
    system_settings_set_many($pdo, $pairs, $userId);

    $runtime = [
        'MEDCONNECT_AI_SERVICE_ENABLED' => $mcEnabled ? 'true' : 'false',
        'MEDCONNECT_AI_INTERPRETER'     => $groqEnabled ? '1' : '0',
        'AI_ENABLED'                    => $gemEnabled ? 'true' : 'false',
        'AI_PROVIDER'                   => 'gemini',
        'AI_MODEL'                      => $gemModel,
        'MEDCONNECT_GROQ_MODEL'         => $groqModel,
        'GROQ_MODEL'                    => $groqModel,
    ];
    $write = ai_providers_write_runtime($runtime);
    if (!$write['success']) {
        return ['success' => false, 'message' => $write['message'], 'saved' => $pairs];
    }

    // Apply for this request (constants already defined stay as-is until next request).
    foreach ($runtime as $k => $v) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
    }

    return [
        'success' => true,
        'message' => 'AI provider settings saved. Enabled flags and models apply on the next request.',
        'saved'   => $pairs,
    ];
}

/**
 * @param array<string, string> $pairs
 * @return array{success:bool,message:string}
 */
function ai_providers_write_runtime(array $pairs): array
{
    $allow = array_fill_keys(ai_providers_runtime_allowlist(), true);
    $lines = [
        '# MedConnect AI provider runtime overrides (non-secret). Generated by Admin settings.',
        '# Do not put API keys here. Keys remain in .env.',
    ];
    foreach ($pairs as $k => $v) {
        if (!isset($allow[$k])) {
            continue;
        }
        $safe = str_replace(["\r", "\n"], '', (string) $v);
        $lines[] = $k . '=' . $safe;
    }
    $path = ai_providers_runtime_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['success' => false, 'message' => 'Cannot create storage directory for AI provider overrides.'];
    }
    $ok = @file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX);
    if ($ok === false) {
        return ['success' => false, 'message' => 'Failed to write AI provider runtime overrides.'];
    }
    return ['success' => true, 'message' => 'Runtime overrides written.'];
}
