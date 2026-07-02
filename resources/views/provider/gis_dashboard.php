<?php
$active_page = 'gis_dashboard';
$page_title  = 'GIS Dashboard';
require __DIR__ . '/partials/data.php';

$assetBase = ASSET_BASE;
$apiBase = ASSET_BASE . '/app/api/admin/gis_data.php';
$exportBase = ASSET_BASE . '/app/api/admin/gis_export.php';

require __DIR__ . '/partials/layout_open.php';
require dirname(__DIR__) . '/partials/gis_dashboard_content.php';
require __DIR__ . '/partials/layout_close.php';
