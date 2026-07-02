<?php
/**
 * Super Admin view bootstrap — session, auth, session tracking.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!defined('BASE_PATH')) {
    $d = __DIR__;
    while ($d !== dirname($d)) {
        if (is_file($d . '/mc_load.php')) {
            require_once $d . '/mc_load.php';
            break;
        }
        $d = dirname($d);
    }
}
require_once BASE_PATH . '/app/includes/auth_guard.php';
auth_require_role('superadmin');

require_once BASE_PATH . '/app/includes/superadmin/security.php';
if (isset($pdo) && $pdo instanceof PDO && !empty($_SESSION['user_id'])) {
    superadmin_touch_session($pdo, (int) $_SESSION['user_id']);
}

define('MC_SUPERADMIN_PORTAL', true);
