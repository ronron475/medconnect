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
require_once BASE_PATH . '/app/includes/auth_guard.php';
require_once BASE_PATH . '/app/includes/admin_queue_live.php';
require_once __DIR__ . '/_portal_access.php';

$page_title = 'Queue Monitoring';

require_once __DIR__ . '/partials/layout_open.php';

require __DIR__ . '/partials/queue_monitoring_panel.php';
?>
<?php $adminQueueLiveVer = (int) @filemtime(ASSETS_PATH . '/js/admin-queue-live.js'); ?>
<script src="<?= ASSET_BASE ?>/assets/js/admin-queue-live.js?v=<?= $adminQueueLiveVer ?>"></script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
