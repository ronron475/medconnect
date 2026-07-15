<?php
/**
 * Global inactivity timeout applied to ALL authenticated requests.
 *
 * - View pages: redirect to index with `session_expired=1`
 * - JSON/API: returns 401 JSON with `code=session_expired` + `redirect`
 *
 * Provider-specific preference (`provider_auto_logout`) is honored if present.
 */

require_once __DIR__ . '/request_helpers.php';
require_once __DIR__ . '/remember_me.php';

if (!defined('SESSION_TIMEOUT_DEFAULT_MINUTES')) {
    define('SESSION_TIMEOUT_DEFAULT_MINUTES', 30);
}

function session_timeout_minutes_for_current_user(): int
{
    if (!empty($_SESSION['remember_me_extended'])) {
        return REMEMBER_ME_DAYS * 24 * 60;
    }

    $role = (string) ($_SESSION['user_role'] ?? '');

    // Provider portal supports a user-configurable timeout preference.
    if ($role === 'provider' && isset($_SESSION['provider_auto_logout'])) {
        return (int) $_SESSION['provider_auto_logout'];
    }

    return SESSION_TIMEOUT_DEFAULT_MINUTES;
}

function session_timeout_force_logout(): void
{
    global $pdo;

    if (!empty($_SESSION['remember_me_extended'])) {
        if (isset($pdo) && $pdo instanceof PDO && !empty($_SESSION['user_id'])) {
            try {
                remember_me_revoke_for_user($pdo, (int) $_SESSION['user_id']);
            } catch (Throwable $e) { /* non-fatal */ }
        }
        try {
            remember_me_clear_cookie();
        } catch (Throwable $e) { /* non-fatal */ }
    }

    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_unset();
        session_destroy();
    }

    $redirect = BASE_URL . '/index.php?session_expired=1';

    if (request_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Session expired. Please sign in again.',
            'code' => 'session_expired',
            'redirect' => $redirect,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: ' . $redirect);
    exit;
}

function session_timeout_check(): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }

    // Video room / dual-tab demo polls use read-only sessions so they do not
    // persist last_activity. Do not treat those requests as idle expiry, or a
    // live consultation would auto-logout the user mid-call.
    if (defined('MEDCONNECT_SKIP_SESSION_TIMEOUT') && MEDCONNECT_SKIP_SESSION_TIMEOUT) {
        return;
    }
    if (defined('MEDCONNECT_SESSION_READ_AND_CLOSE') && MEDCONNECT_SESSION_READ_AND_CLOSE) {
        return;
    }

    $timeoutMinutes = session_timeout_minutes_for_current_user();
    $now = time();

    // <= 0 means "never auto logout"
    if ($timeoutMinutes <= 0) {
        $_SESSION['last_activity'] = $now;
        if (($_SESSION['user_role'] ?? '') === 'provider') {
            $_SESSION['provider_last_activity'] = $now;
        }
        return;
    }

    $last = (int) ($_SESSION['last_activity'] ?? ($_SESSION['provider_last_activity'] ?? $now));
    if (($now - $last) > ($timeoutMinutes * 60)) {
        session_timeout_force_logout();
    }

    $_SESSION['last_activity'] = $now;
    if (($_SESSION['user_role'] ?? '') === 'provider') {
        $_SESSION['provider_last_activity'] = $now;
    }
}

/**
 * Persist last_activity without holding the session lock longer than necessary.
 * Safe for video-room keep-alive calls from multiple Chrome tabs.
 */
function session_timeout_touch(): void
{
    $openedHere = false;
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
        $openedHere = true;
    }

    if (empty($_SESSION['user_id'])) {
        if ($openedHere && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        return;
    }

    $now = time();
    $_SESSION['last_activity'] = $now;
    if (($_SESSION['user_role'] ?? '') === 'provider') {
        $_SESSION['provider_last_activity'] = $now;
    }

    if ($openedHere && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

