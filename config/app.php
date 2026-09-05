<?php
/**
 * Application configuration (non-database).
 */

if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Manila');
}

/**
 * Railway FastAPI (Python AI) for production when Hostinger .env cannot be edited.
 * Override anytime with MEDCONNECT_AI_SERVICE_URL in .env.
 */
if (!defined('MEDCONNECT_PRODUCTION_AI_SERVICE_URL')) {
    define(
        'MEDCONNECT_PRODUCTION_AI_SERVICE_URL',
        'https://medconnect-production-f2b7.up.railway.app'
    );
}

if (!function_exists('medconnect_is_production_host')) {
    function medconnect_is_production_host(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host) ?: '';

        return $host === 'medconnect.bccbsis.com'
            || str_ends_with($host, '.bccbsis.com');
    }
}

if (!defined('AI_SERVICE_BASE_URL')) {
    $envUrl = trim((string) (getenv('MEDCONNECT_AI_SERVICE_URL') ?: ''));
    $envHost = strtolower((string) (parse_url($envUrl, PHP_URL_HOST) ?: ''));
    $envIsLocal = $envUrl === ''
        || in_array($envHost, ['127.0.0.1', 'localhost', '::1'], true);

    // On live Hostinger, ignore local/missing AI URLs and use Railway.
    if (medconnect_is_production_host() && $envIsLocal) {
        $envUrl = MEDCONNECT_PRODUCTION_AI_SERVICE_URL;
    }

    define(
        'AI_SERVICE_BASE_URL',
        $envUrl !== '' ? rtrim($envUrl, '/') : 'http://127.0.0.1:8765'
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
    $envAutoStart = getenv('MEDCONNECT_AI_AUTO_START');
    if ($envAutoStart === false || $envAutoStart === '') {
        // Production / remote Railway: never try to spawn local Python on Hostinger.
        $envAutoStart = (medconnect_is_production_host() || !str_contains(AI_SERVICE_BASE_URL, '127.0.0.1'))
            ? 'false'
            : 'true';
    }
    define(
        'AI_SERVICE_AUTO_START',
        !in_array(strtolower((string) $envAutoStart), ['0', 'false', 'no', 'off'], true)
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

/**
 * WebRTC ICE servers for the consultation room.
 *
 * STUN alone cannot traverse the symmetric NAT used by most mobile carriers, so a
 * TURN relay is required for phone-to-laptop calls to connect reliably. Configure
 * in .env; without TURN credentials the room still works on networks where STUN
 * is enough (same LAN, most home broadband).
 *
 *   MEDCONNECT_TURN_URLS=turn:turn.example.com:3478,turns:turn.example.com:5349
 *   MEDCONNECT_TURN_USERNAME=...
 *   MEDCONNECT_TURN_CREDENTIAL=...
 */
if (!function_exists('medconnect_ice_servers')) {
    /**
     * @return list<array{urls: string|list<string>, username?: string, credential?: string}>
     */
    function medconnect_ice_servers(): array
    {
        $stunEnv = trim((string) (getenv('MEDCONNECT_STUN_URLS') ?: ''));
        $stunUrls = $stunEnv !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $stunEnv))))
            : ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302', 'stun:stun.cloudflare.com:3478'];

        $servers = [];
        foreach ($stunUrls as $url) {
            $servers[] = ['urls' => $url];
        }

        $turnUrls = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) (getenv('MEDCONNECT_TURN_URLS') ?: ''))
        )));
        $turnUser = trim((string) (getenv('MEDCONNECT_TURN_USERNAME') ?: ''));
        $turnPass = trim((string) (getenv('MEDCONNECT_TURN_CREDENTIAL') ?: ''));

        if ($turnUrls !== [] && $turnUser !== '' && $turnPass !== '') {
            $servers[] = [
                'urls'       => $turnUrls,
                'username'   => $turnUser,
                'credential' => $turnPass,
            ];
        }

        return $servers;
    }
}

/** True once a TURN relay is configured; used to warn during development only. */
if (!function_exists('medconnect_has_turn_server')) {
    function medconnect_has_turn_server(): bool
    {
        foreach (medconnect_ice_servers() as $server) {
            foreach ((array) $server['urls'] as $url) {
                if (stripos((string) $url, 'turn:') === 0 || stripos((string) $url, 'turns:') === 0) {
                    return true;
                }
            }
        }

        return false;
    }
}

