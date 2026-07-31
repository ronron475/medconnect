<?php
/**
 * Application configuration (non-database).
 */

if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Manila');
}

if (!defined('AI_SERVICE_BASE_URL')) {
    $envUrl = getenv('MEDCONNECT_AI_SERVICE_URL');
    define(
        'AI_SERVICE_BASE_URL',
        $envUrl ? rtrim((string) $envUrl, '/') : 'http://127.0.0.1:8765'
    );
}

/** When false, PHP validation workflow runs without calling the Python service (recommended for shared hosting). */
if (!defined('AI_SERVICE_ENABLED')) {
    define(
        'AI_SERVICE_ENABLED',
        !in_array(strtolower((string) (getenv('MEDCONNECT_AI_SERVICE_ENABLED') ?: 'true')), ['0', 'false', 'no', 'off'], true)
    );
}

/** When false, PHP will not spawn Python from web requests (recommended for production). */
if (!defined('AI_SERVICE_AUTO_START')) {
    define(
        'AI_SERVICE_AUTO_START',
        !in_array(strtolower((string) (getenv('MEDCONNECT_AI_AUTO_START') ?: 'true')), ['0', 'false', 'no', 'off'], true)
    );
}

if (!defined('AI_SERVICE_TIMEOUT_HEALTH')) {
    define('AI_SERVICE_TIMEOUT_HEALTH', max(1, (int) (getenv('MEDCONNECT_AI_HEALTH_TIMEOUT') ?: 3)));
}

if (!defined('AI_SERVICE_TIMEOUT_ANALYZE')) {
    // Groq + lexicon warm-up on first analyze often exceeds 60s
    define('AI_SERVICE_TIMEOUT_ANALYZE', max(30, (int) (getenv('MEDCONNECT_AI_ANALYZE_TIMEOUT') ?: 120)));
}

/** When true with AI_SERVICE_ENABLED, analyze API will not silently fall back to PHP workflow. */
if (!defined('AI_SERVICE_REQUIRE_PYTHON')) {
    define(
        'AI_SERVICE_REQUIRE_PYTHON',
        !in_array(strtolower((string) (getenv('MEDCONNECT_AI_REQUIRE_PYTHON') ?: 'true')), ['0', 'false', 'no', 'off'], true)
    );
}

if (!defined('AI_SERVICE_TIMEOUT_TRANSCRIBE')) {
    define('AI_SERVICE_TIMEOUT_TRANSCRIBE', 180);
}

