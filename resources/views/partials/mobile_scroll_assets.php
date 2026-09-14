<?php
/**
 * Mobile scroll policy CSS — load LAST after portal/provider shells.
 */
$assetBase = defined('ASSET_BASE') ? ASSET_BASE : '';
$mobileScrollCss = defined('ASSETS_PATH') ? ASSETS_PATH . '/css/mobile-scroll.css' : '';
$mobileScrollVer = ($mobileScrollCss && is_file($mobileScrollCss))
    ? (int) filemtime($mobileScrollCss)
    : time();
?>
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/css/mobile-scroll.css?v=<?= $mobileScrollVer ?>"/>
