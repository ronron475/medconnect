<?php
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
$role = (string) ($_SESSION['user_role'] ?? '');
if ($role === 'provider') {
    header('Location: ' . BASE_URL . '/views/provider/gis_dashboard.php');
    exit;
}
require_once __DIR__ . '/_portal_access.php';

$page_title = 'GIS Dashboard';
$assetBase = ASSET_BASE;
$apiBase = ASSET_BASE . '/app/api/admin/gis_data.php';
$exportBase = ASSET_BASE . '/app/api/admin/gis_export.php';

require_once __DIR__ . '/partials/layout_open.php';
require_once dirname(__DIR__) . '/partials/gis_dashboard_content.php';
require_once __DIR__ . '/partials/layout_close.php';
