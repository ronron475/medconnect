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
            // Maps + Google reCAPTCHA challenge iframes.
            "frame-src 'self' https://maps.google.com https://www.google.com https://recaptcha.google.com",
            // Leaflet tiles (OSM/Esri) + CDN assets used in GIS dashboards.
            "img-src 'self' data: blob: https://*.tile.openstreetmap.org https://server.arcgisonline.com https://unpkg.com https://www.gstatic.com",
            "font-src 'self' data: https://fonts.gstatic.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://unpkg.com https://www.gstatic.com",
            // Chart.js + Leaflet CDN + Google reCAPTCHA (forgot-password / login).
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com https://www.google.com https://www.gstatic.com",
            "connect-src 'self' https://www.google.com https://www.gstatic.com",
            "media-src 'self' blob:",
        ]);
        header("Content-Security-Policy: {$csp}");
        header('X-Content-Type-Options: nosniff');

        if (medconnect_request_is_https() && !medconnect_is_local_dev_host()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
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
    define('STORAGE_PATH', BASE_PATH . '/storage');
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

    $protocol = medconnect_request_is_https() ? 'https' : 'http';
    $trustProxy = medconnect_env_bool('MEDCONNECT_TRUST_PROXY', true);
    $host = ($trustProxy && !empty($_SERVER['HTTP_X_FORWARDED_HOST']))
        ? trim((string) $_SERVER['HTTP_X_FORWARDED_HOST'])
        : ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/\s+/', '', (string) $host);
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

// Application config
require_once CONFIG_PATH . '/app.php';
require_once CONFIG_PATH . '/ai_interpreter.php';
date_default_timezone_set(APP_TIMEZONE);

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
