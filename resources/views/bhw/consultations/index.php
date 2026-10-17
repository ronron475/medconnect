<?php
/**
 * Retired: the BHW Consultation Center and live video assist are removed.
 * Kept as a redirect so existing links and bookmarks land on the dashboard.
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
header('Location: ' . ASSET_BASE . '/views/bhw/dashboard.php', true, 302);
exit;
