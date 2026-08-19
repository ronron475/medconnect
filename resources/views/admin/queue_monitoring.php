<?php
/**
 * Legacy Admin Queue Monitoring URL.
 * Queue monitoring is now a tab on Consultation Monitoring.
 */
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
require_once BASE_PATH . '/app/includes/portal_paths.php';
require_once __DIR__ . '/_portal_access.php';

header('Location: ' . portal_view_url('live_consultation_monitor.php', 'tab=queue'));
exit;
