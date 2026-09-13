<?php
/**
 * Shared global loader assets for authenticated portals + logout handler.
 */
require_once VIEWS_PATH . '/components/global-loader.php';
mc_global_loader_assets(true);

$sessionSyncJsVer = (int) @filemtime(ASSETS_PATH . '/js/session-sync.js');
$logoutHandlerJsVer = (int) @filemtime(ASSETS_PATH . '/js/logout-handler.js');
?>
<script src="<?= ASSET_BASE ?>/assets/js/session-sync.js?v=<?= $sessionSyncJsVer ?>"></script>
<script src="<?= ASSET_BASE ?>/assets/js/logout-handler.js?v=<?= $logoutHandlerJsVer ?>"></script>
