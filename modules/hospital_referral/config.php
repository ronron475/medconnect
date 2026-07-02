<?php
/**
 * =============================================================================
 * config.php  —  Environment Configuration
 * =============================================================================
 * Detects localhost vs live server automatically.
 * Include this at the top of any PHP file that needs environment awareness.
 * =============================================================================
 */
declare(strict_types=1);

// ── Environment detection ─────────────────────────────────────────────────────
$host      = $_SERVER['HTTP_HOST']       ?? 'localhost';
$isHttps   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || ($_SERVER['SERVER_PORT'] ?? 80) == 443
          || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'; // works behind load balancers / cPanel

$isLocalhost = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_starts_with($host, '192.168.')
            || str_ends_with($host, '.local');

if (!defined('IS_LOCALHOST')) define('IS_LOCALHOST', $isLocalhost);
if (!defined('IS_HTTPS'))     define('IS_HTTPS',     $isHttps);

// ── Base URL ──────────────────────────────────────────────────────────────────
// Uses __FILE__ (filesystem path) to compute URL relative to doc root,
// so it works regardless of which script called require_once.
$scheme  = $isHttps ? 'https' : 'http';

if (!defined('BASE_URL')) {
    $docRoot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\'));
    $fileDir = str_replace('\\', '/', dirname(dirname(__FILE__))); // hospital-referral folder
    $relPath = '';
    if ($docRoot !== '' && stripos($fileDir, $docRoot) === 0) {
        $relPath = substr($fileDir, strlen($docRoot));
    }
    define('BASE_URL', $scheme . '://' . $host . rtrim($relPath, '/'));
}

if (!defined('CURL_VERIFY_SSL')) {
    define('CURL_VERIFY_SSL', !$isLocalhost);
}

// ── Session security ──────────────────────────────────────────────────────────
// Harden session cookies on live (HTTPS) servers
if ($isHttps && session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_secure',   '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
}

// ── Rate limiting (simple IP-based) ──────────────────────────────────────────
// Prevents the hospital API from being hammered by bots on live servers.
// Allows max 30 requests per IP per minute.
if (!defined('RATE_LIMIT_MAX'))    define('RATE_LIMIT_MAX',    30);
if (!defined('RATE_LIMIT_WINDOW')) define('RATE_LIMIT_WINDOW', 60);
