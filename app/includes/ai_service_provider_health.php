<?php
/**
 * Railway AI provider status for Hostinger health endpoints.
 *
 * Protected /api/gemini_health and /api/groq_health use MEDCONNECT_AI_SERVICE_TOKEN.
 * Public /health already reports Gemini and Groq when that token is missing or rejected.
 * This module never returns the token or provider API keys.
 */
declare(strict_types=1);

require_once __DIR__ . '/ai_service_auth.php';

/**
 * @return array{ok:bool,http_code:int,body:?array,failure:?string,auth:?string}
 */
function ai_service_http_get(string $url, bool $authenticate, int $timeout = 12): array
{
    if ($authenticate && medconnect_ai_service_token() === '') {
        return [
            'ok' => false,
            'http_code' => 0,
            'body' => null,
            'failure' => 'auth_failed',
            'auth' => 'missing_token',
        ];
    }
    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'http_code' => 0,
            'body' => null,
            'failure' => 'unreachable',
            'auth' => null,
        ];
    }

    $curl = curl_init($url);
    if ($curl === false) {
        return [
            'ok' => false,
            'http_code' => 0,
            'body' => null,
            'failure' => 'unreachable',
            'auth' => null,
        ];
    }

    $headers = ['Accept: application/json'];
    if ($authenticate) {
        $headers = array_merge($headers, medconnect_ai_service_auth_headers());
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
    ];
    foreach (ai_service_ssl_curl_options() as $option => $value) {
        $options[$option] = $value;
    }
    curl_setopt_array($curl, $options);
    $raw = curl_exec($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($raw === false || $code === 0) {
        return [
            'ok' => false,
            'http_code' => $code,
            'body' => null,
            'failure' => 'unreachable',
            'auth' => null,
        ];
    }

    $decoded = json_decode((string) $raw, true);
    $body = is_array($decoded) ? $decoded : null;
    if ($code === 401 || $code === 403) {
        return [
            'ok' => false,
            'http_code' => $code,
            'body' => $body,
            'failure' => 'auth_failed',
            'auth' => 'rejected',
        ];
    }
    if ($code < 200 || $code >= 300 || $body === null) {
        return [
            'ok' => false,
            'http_code' => $code,
            'body' => $body,
            'failure' => 'unreachable',
            'auth' => null,
        ];
    }

    return [
        'ok' => true,
        'http_code' => $code,
        'body' => $body,
        'failure' => null,
        'auth' => $authenticate ? 'ok' : null,
    ];
}

/**
 * @return array<int, mixed>
 */
function ai_service_ssl_curl_options(): array
{
    $raw = getenv('AI_SSL_VERIFY');
    if ($raw === false || $raw === '') {
        $raw = $_ENV['AI_SSL_VERIFY'] ?? 'true';
    }
    $verify = !in_array(strtolower(trim((string) $raw)), ['0', 'false', 'no', 'off'], true);
    if (!$verify) {
        return [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ];
    }

    $options = [
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    $ca = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem';
    if (!is_readable($ca)) {
        $ca = (string) (ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: '');
    }
    if ($ca !== '' && is_readable($ca)) {
        $options[CURLOPT_CAINFO] = $ca;
    }

    return $options;
}

function ai_service_url_is_local(string $url): bool
{
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));

    return $host === '' || in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
}

function ai_service_base_is_remote(?string $url = null): bool
{
    $url = $url ?? (defined('AI_SERVICE_BASE_URL') ? (string) AI_SERVICE_BASE_URL : '');

    return $url !== '' && !ai_service_url_is_local($url);
}

function ai_service_safe_error(?string $error): ?string
{
    if ($error === null) {
        return null;
    }
    $error = trim($error);
    if ($error === '') {
        return null;
    }
    if (preg_match('/(AIza[0-9A-Za-z\-_]+|sk-[A-Za-z0-9]|gsk_[A-Za-z0-9]|Bearer\s+\S+|api[_-]?key\s*[=:]\s*\S+)/i', $error) === 1) {
        return 'Upstream AI request failed.';
    }
    if (strlen($error) > 180) {
        $error = substr($error, 0, 180);
    }

    return $error;
}

function ai_service_status_message(string $provider, string $status, ?string $model): string
{
    $name = $provider === 'groq' ? 'Groq' : 'Gemini';
    $modelNote = ($model !== null && $model !== '') ? ' (' . $model . ')' : '';

    return match ($status) {
        'online' => $name . ' is connected on the AI service' . $modelNote . '.',
        'configured' => $name . ' is configured on the AI service' . $modelNote . '.',
        'not_configured' => $name . ' is not configured on the AI service.',
        'auth_failed' => $name . ' rejected the API credentials on the AI service.',
        'quota_exceeded' => $name . ' quota is exceeded on the AI service.',
        'unreachable' => 'The AI service could not be reached.',
        default => $name . ' is unavailable on the AI service.',
    };
}

/**
 * @param array<string, mixed> $body
 * @return array{ok:bool,status:string,configured:bool,model:?string,message:string,error:?string,provider:string}
 */
function ai_service_interpret_provider_body(string $provider, array $body): array
{
    $provider = $provider === 'groq' ? 'groq' : 'gemini';
    $raw = strtolower(trim((string) ($body['status'] ?? '')));
    $online = !empty($body[$provider]) || $raw === 'online';
    if ($online) {
        $status = 'online';
    } elseif (in_array($raw, ['missing_key', 'missing', 'not_configured'], true)) {
        $status = 'not_configured';
    } elseif (in_array($raw, ['auth_failed', 'quota_exceeded', 'configured'], true)) {
        $status = $raw;
    } elseif ($raw === '' || $raw === 'offline') {
        $status = 'unavailable';
    } else {
        $status = $raw;
    }

    $configured = array_key_exists('configured', $body)
        ? (bool) $body['configured']
        : !in_array($status, ['not_configured', 'unreachable'], true);
    $model = isset($body['model']) && (string) $body['model'] !== '' ? (string) $body['model'] : null;

    return [
        'ok' => $status === 'online',
        'status' => $status,
        'configured' => $configured,
        'model' => $model,
        'message' => ai_service_status_message($provider, $status, $model),
        'error' => ai_service_safe_error(isset($body['error']) ? (string) $body['error'] : null),
        'provider' => $provider,
    ];
}

/**
 * @param array<string, mixed> $health
 * @return array{ok:bool,status:string,configured:bool,model:?string,message:string,error:?string,provider:string}
 */
function ai_service_interpret_service_health(string $provider, array $health): array
{
    $provider = $provider === 'groq' ? 'groq' : 'gemini';
    $label = strtolower(trim((string) ($health[$provider] ?? 'missing')));
    $model = $provider === 'gemini'
        ? (string) ($health['gemini_model'] ?? '')
        : (string) ($health['model'] ?? '');
    $model = $model !== '' ? $model : null;

    $status = match ($label) {
        'connected', 'online' => 'online',
        'configured' => 'configured',
        'missing', 'missing_key', 'not_configured' => 'not_configured',
        'auth_failed' => 'auth_failed',
        'quota_exceeded' => 'quota_exceeded',
        'unreachable' => 'unreachable',
        default => 'unavailable',
    };
    $configured = !in_array($status, ['not_configured', 'unreachable'], true);

    return [
        'ok' => $status === 'online',
        'status' => $status,
        'configured' => $configured,
        'model' => $model,
        'message' => ai_service_status_message($provider, $status, $model),
        'error' => ai_service_safe_error(isset($health[$provider . '_error']) ? (string) $health[$provider . '_error'] : null),
        'provider' => $provider,
    ];
}

/**
 * @return array{ok:bool,status:string,configured:bool,model:?string,message:string,error:?string,provider:string,remote:bool,service_auth:string}
 */
function ai_service_provider_failure(string $provider, string $failure, string $serviceAuth): array
{
    $provider = $provider === 'groq' ? 'groq' : 'gemini';
    $status = $failure === 'auth_failed' ? 'auth_failed' : 'unreachable';

    return [
        'ok' => false,
        'status' => $status,
        'configured' => false,
        'model' => null,
        'message' => $status === 'auth_failed'
            ? 'The AI service rejected the service token.'
            : 'The AI service could not be reached.',
        'error' => null,
        'provider' => $provider,
        'remote' => true,
        'service_auth' => $serviceAuth,
    ];
}

/**
 * @return array{ok:bool,http_code:int,body:?array,failure:?string,auth:?string}
 */
function ai_service_cached_public_health(string $baseUrl, int $timeout = 12): array
{
    static $cache = [];
    $key = rtrim($baseUrl, '/');
    if (!isset($cache[$key])) {
        $cache[$key] = ai_service_http_get($key . '/health', false, $timeout);
    }

    return $cache[$key];
}

/**
 * @return array{ok:bool,status:string,configured:bool,model:?string,message:string,error:?string,provider:string,remote:bool,service_auth:string}
 */
function ai_service_provider_public_status(string $provider, ?string $baseUrl = null): array
{
    $provider = $provider === 'groq' ? 'groq' : 'gemini';
    $base = $baseUrl ?? (defined('AI_SERVICE_BASE_URL') ? (string) AI_SERVICE_BASE_URL : '');
    $base = rtrim($base, '/');
    if ($base === '' || ai_service_url_is_local($base)) {
        return [
            'ok' => false,
            'status' => 'unreachable',
            'configured' => false,
            'model' => null,
            'message' => 'No remote AI service URL is configured.',
            'error' => null,
            'provider' => $provider,
            'remote' => false,
            'service_auth' => 'not_checked',
        ];
    }

    $probe = null;
    if (medconnect_ai_service_token() !== '') {
        $probe = ai_service_http_get($base . '/api/' . $provider . '_health', true, 12);
        if ($probe['ok'] && is_array($probe['body'])) {
            $mapped = ai_service_interpret_provider_body($provider, $probe['body']);
            $mapped['remote'] = true;
            $mapped['service_auth'] = 'ok';

            return $mapped;
        }
    } else {
        $probe = [
            'ok' => false,
            'http_code' => 0,
            'body' => null,
            'failure' => 'auth_failed',
            'auth' => 'missing_token',
        ];
    }

    $public = ai_service_cached_public_health($base);
    $serviceAuth = ($probe['auth'] ?? '') === 'missing_token' ? 'missing_token' : 'rejected';
    if (($probe['failure'] ?? '') === 'unreachable') {
        $serviceAuth = 'unreachable';
    }
    if ($public['ok'] && is_array($public['body'])) {
        $mapped = ai_service_interpret_service_health($provider, $public['body']);
        $mapped['remote'] = true;
        $mapped['service_auth'] = $serviceAuth === 'unreachable' ? 'ok' : $serviceAuth;

        return $mapped;
    }

    if (($probe['failure'] ?? '') === 'auth_failed' && ($public['failure'] ?? '') !== 'unreachable') {
        return ai_service_provider_failure($provider, 'auth_failed', $serviceAuth);
    }

    return ai_service_provider_failure($provider, 'unreachable', $serviceAuth === 'missing_token' ? 'missing_token' : 'unreachable');
}

/**
 * @param array{ok:bool,status:string,configured:bool,model:?string,message:string} $report
 * @return array{status:string,label:string,message:string,reachable:bool}
 */
function ai_service_admin_state(array $report): array
{
    $status = (string) ($report['status'] ?? 'unavailable');
    if ($status === 'online') {
        $status = 'connected';
    }
    $labels = [
        'connected' => 'Connected',
        'configured' => 'Configured',
        'not_configured' => 'Not configured',
        'auth_failed' => 'Authentication failed',
        'unreachable' => 'Unreachable',
        'quota_exceeded' => 'Quota exceeded',
        'unavailable' => 'Unavailable',
        'disabled' => 'Disabled',
    ];

    return [
        'status' => $status,
        'label' => $labels[$status] ?? 'Unavailable',
        'message' => (string) ($report['message'] ?? ''),
        'reachable' => (bool) ($report['ok'] ?? false),
    ];
}
