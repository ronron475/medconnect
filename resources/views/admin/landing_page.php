<?php
/**
 * Legacy route — merged into Website Dashboard.
 */
session_start();
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
require_once __DIR__ . '/_portal_access.php';

$target = (defined('MC_PORTAL_SHELL') && MC_PORTAL_SHELL === 'superadmin')
    ? ASSET_BASE . '/views/superadmin/website_dashboard.php'
    : ASSET_BASE . '/views/admin/website_dashboard.php';

header('Location: ' . $target, true, 302);
exit;
