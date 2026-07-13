<?php
/**
 * Shared global loader assets for authenticated portals + logout handler.
 */
require_once VIEWS_PATH . '/components/global-loader.php';
mc_global_loader_assets(true);

$logoutHandlerJsVer = (int) @filemtime(ASSETS_PATH . '/js/logout-handler.js');
?>
<script src="<?= ASSET_BASE ?>/assets/js/logout-handler.js?v=<?= $logoutHandlerJsVer ?>"></script>
