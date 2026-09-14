<?php
/**
 * Global MedConnect multitasking / minimized-task assets (all roles).
 */
if (!defined('ASSET_BASE')) {
    return;
}
$mtCss = defined('ASSETS_PATH') ? ASSETS_PATH . '/css/medconnect-multitask.css' : '';
$mtJs  = defined('ASSETS_PATH') ? ASSETS_PATH . '/js/medconnect-multitask.js' : '';
$mtCssVer = ($mtCss && is_file($mtCss)) ? (int) filemtime($mtCss) : time();
$mtJsVer  = ($mtJs && is_file($mtJs)) ? (int) filemtime($mtJs) : time();
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/medconnect-multitask.css?v=<?= $mtCssVer ?>"/>
<div id="mcMultitaskDock" class="mc-multitask-dock" hidden aria-hidden="true">
  <div class="mc-multitask-dock__list" data-mc-multitask-list></div>
</div>
<script src="<?= ASSET_BASE ?>/assets/js/medconnect-multitask.js?v=<?= $mtJsVer ?>"></script>
