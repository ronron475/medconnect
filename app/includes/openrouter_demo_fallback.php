<?php
/**
 * OpenRouter / Groq / campus BITS Ollama quota fallbacks for Gemini interview.
 *
 * Called only after Gemini returns HTTP 429 / quota exceeded. The Gemini
 * system and user text are forwarded unchanged. The model text is returned
 * for the demo's existing parser. This does not set triage.
 */
declare(strict_types=1);

if (defined('MEDCONNECT_OPENROUTER_DEMO_FALLBACK_LOADED')) {
    return;
}
define('MEDCONNECT_OPENROUTER_DEMO_FALLBACK_LOADED', true);

/** One fixed free OpenRouter model. Not a rotating free router. */
const MEDCONNECT_OPENROUTER_DEMO_MODEL = 'google/gemma-4-31b-it:free';
const MEDCONNECT_OPENROUTER_DEMO_ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

function medconnect_demo_openrouter_local_config_path(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'openrouter_local.php';
}

/**
 * @param mixed $loaded
 */
function medconnect_demo_openrouter_api_key_from_config_value($loaded): string
{
    if (!is_array($loaded)) {
        return '';
    }

    return trim((string) ($loaded['api_key'] ?? ''));
}

function medconnect_demo_openrouter_api_key(): string
{
    $path = medconnect_demo_openrouter_local_config_path();
    if (is_readable($path)) {
        $fromFile = medconnect_demo_openrouter_api_key_from_config_value(include $path);
        if ($fromFile !== '') {
            return $fromFile;
        }
    }

    $val = getenv('OPENROUTER_API_KEY');
    if ($val === false || $val === '') {
        $val = $_ENV['OPENROUTER_API_KEY'] ?? '';
    }

    return trim((string) $val);
}

function medconnect_demo_openrouter_key_is_configured(): bool
{
    return medconnect_demo_openrouter_api_key() !== '';
}

function medconnect_demo_gemini_error_is_quota(string $message): bool
{
    $msg = strtolower($message);
    if (str_contains($msg, '429')) {
        return true;
    }
    if (preg_match('/http (400|500|502|503)\b/', $msg) === 1) {
        return false;
    }

    return str_contains($msg, 'quota') || str_contains($msg, 'resource_exhausted');
}

/**
 * One OpenRouter completion after a Gemini quota error.
 * Returns the model text, or null when this is not a quota error, the key is
 * missing, or OpenRouter does not succeed. Other HTTP statuses do not call out.
 *
 * @param array<string, mixed> $geminiPayload
 * @param (callable(array<string, mixed>): ?string)|null $transport Test seam. Production passes null.
 */
function medconnect_demo_openrouter_quota_text(
    string $geminiError,
    array $geminiPayload,
    ?callable $transport = null
): ?string {
    if (!medconnect_demo_gemini_error_is_quota($geminiError)) {
        return null;
    }
    if ($transport === null && medconnect_demo_openrouter_api_key() === '') {
        return null;
    }

    $body = medconnect_demo_openrouter_request_from_gemini($geminiPayload);
    if ($body === null) {
        return null;
    }

    $text = $transport !== null
        ? $transport($body)
        : medconnect_demo_openrouter_http_complete($body);
    if (!is_string($text)) {
        return null;
    }
    $text = trim($text);

    return $text !== '' ? $text : null;
}

/**
 * @param array<string, mixed> $geminiPayload
 * @return array<string, mixed>|null
 */
function medconnect_demo_openrouter_request_from_gemini(array $geminiPayload): ?array
{
    $messages = [];
    $system = medconnect_demo_openrouter_parts_text($geminiPayload['systemInstruction'] ?? null);
    if ($system !== '') {
        $messages[] = ['role' => 'system', 'content' => $system];
    }

    $contents = $geminiPayload['contents'] ?? null;
    if (is_array($contents)) {
        foreach ($contents as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $text = medconnect_demo_openrouter_parts_text($turn);
            if ($text === '') {
                continue;
            }
            $role = strtolower(trim((string) ($turn['role'] ?? 'user')));
            $messages[] = [
                'role' => ($role === 'model' || $role === 'assistant') ? 'assistant' : 'user',
                'content' => $text,
            ];
        }
    }

    $hasUser = false;
    foreach ($messages as $message) {
        if (($message['role'] ?? '') === 'user') {
            $hasUser = true;
            break;
        }
    }
    if (!$hasUser) {
        return null;
    }

    $gen = is_array($geminiPayload['generationConfig'] ?? null) ? $geminiPayload['generationConfig'] : [];
    $temperature = isset($gen['temperature']) && is_numeric($gen['temperature'])
        ? (float) $gen['temperature']
        : 0.1;
    $maxTokens = isset($gen['maxOutputTokens']) && is_numeric($gen['maxOutputTokens'])
        ? (int) $gen['maxOutputTokens']
        : 1024;

    $body = [
        'model' => MEDCONNECT_OPENROUTER_DEMO_MODEL,
        'temperature' => $temperature,
        'max_tokens' => max(64, min(4096, $maxTokens)),
        'messages' => $messages,
    ];
    if (strtolower(trim((string) ($gen['responseMimeType'] ?? ''))) === 'application/json') {
        $body['response_format'] = ['type' => 'json_object'];
        $body['max_tokens'] = max((int) $body['max_tokens'], 2048);
    }

    return $body;
}

/**
 * @param mixed $node
 */
function medconnect_demo_openrouter_parts_text($node): string
{
    if (!is_array($node)) {
        return '';
    }
    $parts = $node['parts'] ?? null;
    if (!is_array($parts)) {
        return '';
    }
    $chunks = [];
    foreach ($parts as $part) {
        if (is_array($part) && isset($part['text'])) {
            $piece = (string) $part['text'];
            if (trim($piece) !== '') {
                $chunks[] = $piece;
            }
        }
    }

    return implode("\n", $chunks);
}

/**
 * @param array<string, mixed> $body
 */
function medconnect_demo_openrouter_http_complete(array $body): ?string
{
    $apiKey = medconnect_demo_openrouter_api_key();
    if ($apiKey === '' || !function_exists('curl_init')) {
        return null;
    }

    $timeout = 30;
    $envTimeout = getenv('AI_TIMEOUT');
    if ($envTimeout === false || $envTimeout === '') {
        $envTimeout = $_ENV['AI_TIMEOUT'] ?? '';
    }
    $envTimeout = (int) $envTimeout;
    if ($envTimeout > 0) {
        $timeout = max(5, min(60, $envTimeout));
    }

    $verifySsl = true;
    $rawSsl = getenv('AI_SSL_VERIFY');
    if ($rawSsl === false || $rawSsl === '') {
        $rawSsl = $_ENV['AI_SSL_VERIFY'] ?? null;
    }
    if ($rawSsl !== null && $rawSsl !== '') {
        $verifySsl = !in_array(strtolower(trim((string) $rawSsl)), ['0', 'false', 'no', 'off'], true);
    }

    $ch = curl_init(MEDCONNECT_OPENROUTER_DEMO_ENDPOINT);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: https://medconnect.bccbsis.com/public/gemini_clinical_interview_demo.php',
            'X-Title: medConnect clinical interview demo',
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
    ]);
    if ($verifySsl) {
        $ca = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem';
        if (!is_readable($ca)) {
            $ca = (string) (ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: '');
        }
        if ($ca !== '' && is_readable($ca)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
    }
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $errno !== 0 || $code < 200 || $code >= 300) {
        error_log('OpenRouter demo quota fallback unavailable: http ' . $code);

        return null;
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    $text = medconnect_demo_openrouter_choice_text($decoded);

    return $text !== '' ? $text : null;
}

/**
 * @param mixed $content
 */
function medconnect_demo_openrouter_content_text($content): string
{
    if (is_string($content)) {
        return trim($content);
    }
    if (!is_array($content)) {
        return '';
    }
    $chunks = [];
    foreach ($content as $item) {
        if (is_string($item) && trim($item) !== '') {
            $chunks[] = trim($item);
        } elseif (is_array($item)) {
            $piece = trim((string) ($item['text'] ?? ''));
            if ($piece !== '') {
                $chunks[] = $piece;
            }
        }
    }

    return implode("\n", $chunks);
}

function medconnect_demo_openrouter_json_object(string $text): string
{
    $text = trim($text);
    if ($text !== '' && str_starts_with($text, '{') && str_ends_with($text, '}')) {
        return $text;
    }
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        return '';
    }

    return trim(substr($text, $start, $end - $start + 1));
}

/**
 * @param array<string, mixed> $decoded
 */
function medconnect_demo_openrouter_choice_text(array $decoded): string
{
    $message = $decoded['choices'][0]['message'] ?? null;
    if (!is_array($message)) {
        return '';
    }
    $text = medconnect_demo_openrouter_content_text($message['content'] ?? '');
    if ($text !== '') {
        return $text;
    }

    return medconnect_demo_openrouter_json_object(
        medconnect_demo_openrouter_content_text($message['reasoning'] ?? '')
    );
}

function medconnect_demo_groq_api_key(): string
{
    if (defined('GROQ_API_KEY')) {
        $fromConst = trim((string) GROQ_API_KEY);
        if ($fromConst !== '') {
            return $fromConst;
        }
    }
    foreach (['GROQ_API_KEY', 'MEDCONNECT_GROQ_API_KEY'] as $name) {
        $val = getenv($name);
        if ($val === false || $val === '') {
            $val = $_ENV[$name] ?? '';
        }
        $val = trim((string) $val);
        if ($val !== '') {
            return $val;
        }
    }

    return '';
}

function medconnect_demo_groq_model(): string
{
    if (defined('GROQ_MODEL')) {
        $fromConst = trim((string) GROQ_MODEL);
        if ($fromConst !== '') {
            return $fromConst;
        }
    }
    $raw = trim((string) (getenv('MEDCONNECT_GROQ_MODEL') ?: ($_ENV['MEDCONNECT_GROQ_MODEL'] ?? getenv('GROQ_MODEL') ?: ($_ENV['GROQ_MODEL'] ?? ''))));
    if ($raw === 'llama-3.1-8b-instant' || $raw === 'llama-3.3-70b-versatile') {
        return 'openai/gpt-oss-120b';
    }

    return $raw !== '' ? $raw : 'openai/gpt-oss-120b';
}

/**
 * One Groq completion after OpenRouter miss. Same Gemini payload. Does not set triage.
 *
 * @param array<string, mixed> $geminiPayload
 * @param (callable(array<string, mixed>): ?string)|null $transport
 */
function medconnect_demo_groq_quota_text(
    string $geminiError,
    array $geminiPayload,
    ?callable $transport = null
): ?string {
    if (!medconnect_demo_gemini_error_is_quota($geminiError)) {
        return null;
    }
    if ($transport === null && medconnect_demo_groq_api_key() === '') {
        return null;
    }

    $body = medconnect_demo_openrouter_request_from_gemini($geminiPayload);
    if ($body === null) {
        return null;
    }
    $body['model'] = medconnect_demo_groq_model();

    $text = $transport !== null
        ? $transport($body)
        : medconnect_demo_groq_http_complete($body);
    if (!is_string($text)) {
        return null;
    }
    $text = trim($text);

    return $text !== '' ? $text : null;
}

/**
 * @param array<string, mixed> $body
 */
function medconnect_demo_groq_http_complete(array $body): ?string
{
    $apiKey = medconnect_demo_groq_api_key();
    if ($apiKey === '' || !function_exists('curl_init')) {
        return null;
    }

    $timeout = 30;
    $envTimeout = getenv('AI_TIMEOUT');
    if ($envTimeout === false || $envTimeout === '') {
        $envTimeout = $_ENV['AI_TIMEOUT'] ?? '';
    }
    $envTimeout = (int) $envTimeout;
    if ($envTimeout > 0) {
        $timeout = max(5, min(60, $envTimeout));
    }

    $verifySsl = true;
    $rawSsl = getenv('AI_SSL_VERIFY');
    if ($rawSsl === false || $rawSsl === '') {
        $rawSsl = $_ENV['AI_SSL_VERIFY'] ?? null;
    }
    if ($rawSsl !== null && $rawSsl !== '') {
        $verifySsl = !in_array(strtolower(trim((string) $rawSsl)), ['0', 'false', 'no', 'off'], true);
    }

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
    ]);
    if ($verifySsl) {
        $ca = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem';
        if (!is_readable($ca)) {
            $ca = (string) (ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: '');
        }
        if ($ca !== '' && is_readable($ca)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
    }
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $errno !== 0 || $code < 200 || $code >= 300) {
        error_log('Groq demo quota fallback unavailable: http ' . $code);

        return null;
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    $text = medconnect_demo_openrouter_choice_text($decoded);

    return $text !== '' ? $text : null;
}

function medconnect_bits_service_enabled(): bool
{
    if (defined('BITS_SERVICE_ENABLED')) {
        return (bool) BITS_SERVICE_ENABLED;
    }
    $raw = getenv('MEDCONNECT_BITS_SERVICE');
    if ($raw === false || $raw === '') {
        $raw = $_ENV['MEDCONNECT_BITS_SERVICE'] ?? '1';
    }

    return !in_array(strtolower(trim((string) $raw)), ['0', 'false', 'no', 'off'], true);
}

function medconnect_bits_service_url(): string
{
    if (defined('BITS_SERVICE_URL') && BITS_SERVICE_URL !== '') {
        return rtrim((string) BITS_SERVICE_URL, '/');
    }
    $url = getenv('MEDCONNECT_BITS_SERVICE_URL');
    if ($url === false || $url === '') {
        $url = $_ENV['MEDCONNECT_BITS_SERVICE_URL'] ?? 'https://bits-service.bagocitycollege.com';
    }

    return rtrim((string) $url, '/');
}

function medconnect_bits_ollama_model(): string
{
    if (defined('BITS_OLLAMA_MODEL') && BITS_OLLAMA_MODEL !== '') {
        return (string) BITS_OLLAMA_MODEL;
    }
    $model = getenv('MEDCONNECT_BITS_OLLAMA_MODEL');
    if ($model === false || $model === '') {
        $model = $_ENV['MEDCONNECT_BITS_OLLAMA_MODEL'] ?? 'phi3:mini';
    }

    return (string) $model;
}

/**
 * Run campus BITS/Ollama with the existing Gemini interview payload
 * (systemPrompt + buildUserPrompt). Does not set triage.
 *
 * @param array<string, mixed> $geminiPayload
 * @param (callable(array<string, mixed>): ?string)|null $transport
 */
function medconnect_demo_bits_text_from_gemini(
    array $geminiPayload,
    ?callable $transport = null
): ?string {
    if ($transport === null && !medconnect_bits_service_enabled()) {
        return null;
    }

    $openRouterBody = medconnect_demo_openrouter_request_from_gemini($geminiPayload);
    if ($openRouterBody === null) {
        return null;
    }
    $messages = is_array($openRouterBody['messages'] ?? null) ? $openRouterBody['messages'] : [];
    if ($messages === []) {
        return null;
    }

    $ollamaBody = [
        'model' => medconnect_bits_ollama_model(),
        'stream' => false,
        'messages' => $messages,
    ];

    $text = $transport !== null
        ? $transport($ollamaBody)
        : medconnect_demo_bits_http_complete($ollamaBody);
    if (!is_string($text)) {
        return null;
    }
    $text = trim($text);

    return $text !== '' ? $text : null;
}

/**
 * Campus BITS Ollama after Groq miss. Same Gemini payload. Does not set triage.
 *
 * @param array<string, mixed> $geminiPayload
 * @param (callable(array<string, mixed>): ?string)|null $transport
 */
function medconnect_demo_bits_quota_text(
    string $geminiError,
    array $geminiPayload,
    ?callable $transport = null
): ?string {
    if (!medconnect_demo_gemini_error_is_quota($geminiError)) {
        return null;
    }

    return medconnect_demo_bits_text_from_gemini($geminiPayload, $transport);
}

/**
 * @param array<string, mixed> $body
 */
function medconnect_demo_bits_http_complete(array $body): ?string
{
    $base = medconnect_bits_service_url();
    if ($base === '' || !function_exists('curl_init')) {
        return null;
    }

    $timeout = defined('BITS_SERVICE_TIMEOUT') ? (int) BITS_SERVICE_TIMEOUT : 60;
    $timeout = max(15, min(90, $timeout));
    $verifySsl = true;
    $rawSsl = getenv('AI_SSL_VERIFY');
    if ($rawSsl === false || $rawSsl === '') {
        $rawSsl = $_ENV['AI_SSL_VERIFY'] ?? null;
    }
    if ($rawSsl !== null && $rawSsl !== '') {
        $verifySsl = !in_array(strtolower(trim((string) $rawSsl)), ['0', 'false', 'no', 'off'], true);
    }

    $ca = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem';
    if (!is_readable($ca)) {
        $ca = (string) (ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: '');
    }

    $chatBody = [
        'model' => (string) ($body['model'] ?? medconnect_bits_ollama_model()),
        'stream' => false,
        'messages' => is_array($body['messages'] ?? null) ? $body['messages'] : [],
    ];
    $chatErrno = 0;
    $decoded = medconnect_demo_bits_curl_json($base . '/api/chat', $chatBody, $timeout, $verifySsl, $ca, $chatErrno);
    if (is_array($decoded)) {
        $content = trim((string) ($decoded['message']['content'] ?? ''));
        if ($content !== '') {
            return $content;
        }
    }
    // Chat already used the full timeout (or could not connect). Do not stack another
    // /api/generate wait — the patient UI aborts at 120s.
    if ($decoded === null && $chatErrno !== 0) {
        return null;
    }

    $promptParts = [];
    foreach ($chatBody['messages'] as $message) {
        if (!is_array($message)) {
            continue;
        }
        $role = strtoupper((string) ($message['role'] ?? 'USER'));
        $promptParts[] = $role . ":\n" . trim((string) ($message['content'] ?? ''));
    }
    $genBody = [
        'model' => $chatBody['model'],
        'prompt' => implode("\n\n", $promptParts),
        'stream' => false,
    ];
    $generated = medconnect_demo_bits_curl_json($base . '/api/generate', $genBody, $timeout, $verifySsl, $ca);
    if (!is_array($generated)) {
        return null;
    }
    $content = trim((string) ($generated['response'] ?? ''));

    return $content !== '' ? $content : null;
}

/**
 * @param array<string, mixed> $body
 * @return array<string, mixed>|null
 */
function medconnect_demo_bits_curl_json(
    string $url,
    array $body,
    int $timeout,
    bool $verifySsl,
    string $ca,
    ?int &$curlErrno = null
): ?array {
    $ch = curl_init($url);
    if ($ch === false) {
        $curlErrno = -1;

        return null;
    }
    $opts = [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
    ];
    if ($verifySsl && $ca !== '' && is_readable($ca)) {
        $opts[CURLOPT_CAINFO] = $ca;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    $curlErrno = $errno;
    if ($raw === false || $errno !== 0 || $code < 200 || $code >= 300) {
        error_log('BITS Ollama quota fallback unavailable: http ' . $code . ' errno ' . $errno . ($cerr !== '' ? ' ' . $cerr : ''));

        return null;
    }
    $decoded = json_decode((string) $raw, true);

    return is_array($decoded) ? $decoded : null;
}
