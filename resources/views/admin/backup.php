<?php
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
require_once __DIR__ . '/_portal_access.php';
require_once BASE_PATH . '/app/includes/superadmin/backup.php';

$page_title = 'Database Backup & Restore';
$api = ASSET_BASE . '/app/api/admin/backup.php';
$roleName = 'Admin';

require_once __DIR__ . '/partials/layout_open.php';
require_once VIEWS_PATH . '/partials/backup_management_body.php';
require_once __DIR__ . '/partials/layout_close.php';
