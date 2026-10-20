<?php
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/superadmin/backup.php';

$page_title = 'Database Backup & Restore';
$api = ASSET_BASE . '/app/api/superadmin/backup.php';
$roleName = 'Super Admin';

require_once __DIR__ . '/partials/layout_open.php';
require_once VIEWS_PATH . '/partials/backup_management_body.php';
require_once __DIR__ . '/partials/layout_close.php';
