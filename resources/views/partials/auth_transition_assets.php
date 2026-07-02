<?php
/**
 * Shared post-login / post-logout transition overlay assets for authenticated portals.
 */
$authTransitionCssVer = (int) @filemtime(ASSETS_PATH . '/css/login-loading.css');
$authTransitionJsVer  = (int) @filemtime(ASSETS_PATH . '/js/login-loading.js');
$logoutHandlerJsVer   = (int) @filemtime(ASSETS_PATH . '/js/logout-handler.js');
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/login-loading.css?v=<?= $authTransitionCssVer ?>"/>
<script src="<?= ASSET_BASE ?>/assets/js/login-loading.js?v=<?= $authTransitionJsVer ?>"></script>
<script src="<?= ASSET_BASE ?>/assets/js/logout-handler.js?v=<?= $logoutHandlerJsVer ?>"></script>
