<?php
/**
 * MedConnect application bootstrap.
 * Defines path/URL constants and loads shared dependencies.
 *
 * Goals:
 * - Works on localhost, LAN IPv4, ngrok, and production domains without code changes.
 * - Avoids hardcoded hostnames by deriving the origin from the current request (or env override).
 */

// Composer PSR-4 autoload (optional until vendor/ is installed)
$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_readable($composerAutoload)) {
    require_once $composerAutoload;
}

// Load .env early so session + URL detection can use it.
$envLoader = dirname(__DIR__) . '/config/env_loader.php';
if (is_readable($envLoader)) {
    require_once $envLoader;
}

// XAMPP/local PHP builds may miss mbstring. The medical text pipeline only needs
// basic case/position helpers, so provide ASCII-safe fallbacks instead of failing
// patient booking requests with "Call to undefined function mb_*".
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $string, ?string $encoding = null): string
    {
        return strtolower($string);
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $string, ?string $encoding = null): string
    {
        return strtoupper($string);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        return strlen($string);
    }
}
if (!function_exists('mb_strpos')) {
    function mb_strpos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false
    {
        return strpos($haystack, $needle, $offset);
    }
}
if (!defined('MB_CASE_UPPER')) {
    define('MB_CASE_UPPER', 0);
}
if (!defined('MB_CASE_LOWER')) {
    define('MB_CASE_LOWER', 1);
}
if (!defined('MB_CASE_TITLE')) {
    define('MB_CASE_TITLE', 2);
}
if (!function_exists('mb_convert_case')) {
    function mb_convert_case(string $string, int $mode, ?string $encoding = null): string
    {
        return match ($mode) {
            MB_CASE_UPPER => strtoupper($string),
            MB_CASE_LOWER => strtolower($string),
            default => ucwords(strtolower($string)),
        };
    }
}

if (!function_exists('medconnect_env_bool')) {
    function medconnect_env_bool(string $key, bool $default = false): bool
    {
        $raw = getenv($key);
        if ($raw === false || $raw === '') {
            return $default;
        }
        return !in_array(strtolower(trim((string) $raw)), ['0', 'false', 'no', 'off'], true);
    }
}

if (!function_exists('medconnect_request_is_https')) {
    function medconnect_request_is_https(): bool
    {
        $trustProxy = medconnect_env_bool('MEDCONNECT_TRUST_PROXY', true);
        if ($trustProxy) {
            $xfp = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
            if ($xfp === 'https') return true;
            if ($xfp === 'http') return false;
        }

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443;
    }
}

if (!function_exists('medconnect_is_local_dev_host')) {
    /** True for localhost / private LAN hosts where HTTP dev is expected. */
    function medconnect_is_local_dev_host(?string $host = null): bool
    {
        $host = strtolower(trim((string) ($host ?? ($_SERVER['HTTP_HOST'] ?? ''))));
        if ($host === '') {
            return false;
        }
        // Strip port
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        if (in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            return true;
        }

        return (bool) preg_match(
            '/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/',
            $host
        );
    }
}

if (!function_exists('medconnect_send_security_headers')) {
    /** Security headers required for WebRTC (camera/mic) on deployed HTTPS sites. */
    function medconnect_send_security_headers(): void
    {
        if (headers_sent() || PHP_SAPI === 'cli') {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(self), microphone=(self), display-capture=(self)');
        header('X-Frame-Options: SAMEORIGIN');

        // CSP: allow current inline-script heavy UI while blocking common injection vectors.
        // Tighten further once inline scripts are migrated to external bundles.
        $csp = implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            // Maps embeds.
            "frame-src 'self' https://maps.google.com https://www.google.com",
            // Leaflet tiles (OSM/Esri) + CDN assets used in GIS dashboards.
            "img-src 'self' data: blob: https://*.tile.openstreetmap.org https://server.arcgisonline.com https://unpkg.com",
            "font-src 'self' data: https://fonts.gstatic.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://unpkg.com",
            // Chart.js + Leaflet CDN.
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com",
            "connect-src 'self'",
            "media-src 'self' blob:",
        ]);
        header("Content-Security-Policy: {$csp}");
        header('X-Content-Type-Options: nosniff');

        if (medconnect_request_is_https() && !medconnect_is_local_dev_host()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}

// ── Vercel / serverless: writable session + storage under /tmp ───────────────
$medconnectOnVercel = getenv('VERCEL') !== false
    || getenv('VERCEL_ENV') !== false
    || !empty($_ENV['VERCEL'])
    || !empty($_ENV['VERCEL_ENV']);
if ($medconnectOnVercel) {
    $mcTmp = rtrim(sys_get_temp_dir(), '/\\') . '/medconnect';
    foreach ([$mcTmp, $mcTmp . '/sessions', $mcTmp . '/storage'] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
    }
    if (is_dir($mcTmp . '/sessions')) {
        ini_set('session.save_path', $mcTmp . '/sessions');
    }
}

// ── Secure session defaults (must be set before session_start) ────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    $sameSite = (string) (getenv('MEDCONNECT_SESSION_SAMESITE') ?: 'Lax');
    $sameSite = ucfirst(strtolower(trim($sameSite)));
    if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
        $sameSite = 'Lax';
    }
    ini_set('session.cookie_samesite', $sameSite);

    $isHttps = medconnect_request_is_https();
    if ($isHttps) {
        ini_set('session.cookie_secure', '1');
    }

    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $cookieParams['path'] ?? '/',
        // Host-only cookie so it works for localhost, LAN IP, ngrok, and production.
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => $sameSite,
    ]);

    // read_and_close: used by video_room so Chrome can open provider+patient tabs without session lock deadlock.
    if (defined('MEDCONNECT_SESSION_READ_AND_CLOSE') && MEDCONNECT_SESSION_READ_AND_CLOSE) {
        session_start([
            'read_and_close' => true,
        ]);
    } else {
        session_start();
    }
}

medconnect_send_security_headers();

// ── Filesystem paths (always project root, never public/) ─────────────────────
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', BASE_PATH . '/public');
}
if (!defined('VIEWS_PATH')) {
    define('VIEWS_PATH', BASE_PATH . '/resources/views');
}
if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PUBLIC_PATH . '/assets');
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', BASE_PATH . '/config');
}
if (!defined('CONTROLLERS_PATH')) {
    define('CONTROLLERS_PATH', BASE_PATH . '/app/controllers');
}
if (!defined('STORAGE_PATH')) {
    if (!empty($medconnectOnVercel)) {
        $mcStorage = rtrim(sys_get_temp_dir(), '/\\') . '/medconnect/storage';
        if (!is_dir($mcStorage)) {
            @mkdir($mcStorage, 0700, true);
        }
        define('STORAGE_PATH', is_dir($mcStorage) ? $mcStorage : (BASE_PATH . '/storage'));
    } else {
        define('STORAGE_PATH', BASE_PATH . '/storage');
    }
}
if (!defined('APP_API_PATH')) {
    define('APP_API_PATH', BASE_PATH . '/app/api');
}
if (!defined('MODULES_PATH')) {
    define('MODULES_PATH', BASE_PATH . '/modules');
}
if (!defined('APP_ROOT')) {
    define('APP_ROOT', BASE_PATH);
}

// ── URL helpers (supports docroot = project root OR public/) ──────────────────
if (!defined('BASE_URL') || !defined('ASSET_BASE')) {
    // Optional override: full app URL, e.g. https://my-ngrok.app/medconnect
    $appUrlOverride = (string) (getenv('MEDCONNECT_APP_URL') ?: '');
    if ($appUrlOverride !== '') {
        $appUrlOverride = rtrim($appUrlOverride, '/');
        $parsed = @parse_url($appUrlOverride);
        if (is_array($parsed) && !empty($parsed['scheme']) && !empty($parsed['host'])) {
            $path = (string) ($parsed['path'] ?? '');
            define('BASE_URL', $appUrlOverride);
            define('ASSET_BASE', rtrim($path, '/'));
        }
    }

    if (!defined('BASE_URL')) {
        $protocol = medconnect_request_is_https() ? 'https' : 'http';
        $trustProxy = medconnect_env_bool('MEDCONNECT_TRUST_PROXY', true);
        $host = ($trustProxy && !empty($_SERVER['HTTP_X_FORWARDED_HOST']))
            ? trim((string) $_SERVER['HTTP_X_FORWARDED_HOST'])
            : ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = preg_replace('/\s+/', '', (string) $host);

        // Vercel serverless: treat site as domain root (assets at /assets/...).
        if (!empty($medconnectOnVercel) || str_contains(strtolower($host), 'vercel.app')) {
            define('BASE_URL', rtrim($protocol . '://' . $host, '/'));
            define('ASSET_BASE', '');
        } else {
            $docRoot  = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
            $publicFs = str_replace('\\', '/', (string) realpath(PUBLIC_PATH) ?: PUBLIC_PATH);
            $baseFs   = str_replace('\\', '/', (string) realpath(BASE_PATH) ?: BASE_PATH);

            $publicIsDocRoot = $docRoot !== '' && strcasecmp(rtrim($docRoot, '/'), rtrim($publicFs, '/')) === 0;

            if ($publicIsDocRoot) {
                define('BASE_URL', rtrim($protocol . '://' . $host, '/'));
                define('ASSET_BASE', '');
            } else {
                $relativeFolder = '';
                if ($docRoot !== '' && stripos($baseFs, $docRoot) === 0) {
                    $relativeFolder = substr($baseFs, strlen($docRoot));
                }
                $relativeFolder = '/' . ltrim(str_replace('\\', '/', $relativeFolder), '/');
                $baseUrl = rtrim($protocol . '://' . $host . $relativeFolder, '/');
                define('BASE_URL', $baseUrl);
                define('ASSET_BASE', rtrim($relativeFolder, '/'));
            }
        }
    }
}

// Application config
require_once CONFIG_PATH . '/app.php';
require_once CONFIG_PATH . '/ai_interpreter.php';
require_once CONFIG_PATH . '/twilio.php';
date_default_timezone_set(APP_TIMEZONE);
ini_set('default_charset', 'UTF-8');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

require_once CONFIG_PATH . '/db.php';

require_once BASE_PATH . '/app/includes/remember_me.php';
remember_me_restore_session($pdo);

require_once BASE_PATH . '/app/includes/session_timeout.php';
session_timeout_check();

if (empty($_SESSION['csrf_token'])) {
    // With read_and_close sessions (video room), this only affects the current request arrays.
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Video room / dual-tab: if a writeable session was opened somehow, release it before serving HTML.
if (defined('MEDCONNECT_SESSION_READ_AND_CLOSE') && MEDCONNECT_SESSION_READ_AND_CLOSE && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Core classes — autoload on first use (avoids loading NLP stack on login/dashboard).
if (!function_exists('medconnect_autoload_core')) {
    function medconnect_autoload_core(string $class): void
    {
        static $coreDir = null;
        if ($coreDir === null) {
            $coreDir = BASE_PATH . '/app/core/';
        }
        $file = $coreDir . $class . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
    spl_autoload_register('medconnect_autoload_core');
}
