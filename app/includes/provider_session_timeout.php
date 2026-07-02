<?php
/**
 * Provider session inactivity timeout middleware.
 */

require_once __DIR__ . '/system_preferences.php';

function provider_session_timeout_check(): void
{
    if (($_SESSION['user_role'] ?? '') !== 'provider' || empty($_SESSION['user_id'])) {
        return;
    }

    $timeoutMinutes = (int) ($_SESSION['provider_auto_logout'] ?? 30);
    if ($timeoutMinutes <= 0) {
        $_SESSION['provider_last_activity'] = time();
        return;
    }

    $now = time();
    $last = (int) ($_SESSION['provider_last_activity'] ?? $now);

    if (($now - $last) > ($timeoutMinutes * 60)) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
        header('Location: ' . BASE_URL . '/index.php?session_expired=1');
        exit;
    }

    $_SESSION['provider_last_activity'] = $now;
}
