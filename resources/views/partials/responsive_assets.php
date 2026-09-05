<?php
/**
 * Shared responsive CSS/JS — include in layout <head> and before </body>.
 * Set $responsive_scripts_only = true to load JS only (before </body>).
 */
$assetBase = defined('ASSET_BASE') ? ASSET_BASE : '';
$scriptsOnly = !empty($responsive_scripts_only);
$responsiveCssPath = defined('ASSETS_PATH') ? ASSETS_PATH . '/css/responsive.css' : '';
$responsiveCssVer = ($responsiveCssPath && file_exists($responsiveCssPath)) ? (int) filemtime($responsiveCssPath) : time();

if (!$scriptsOnly): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/css/responsive.css?v=<?= $responsiveCssVer ?>"/>
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/css/profile-menu.css"/>
<?php
$dashFloatCss = defined('ASSETS_PATH') ? ASSETS_PATH . '/css/dashboard-card-float.css' : '';
$dashFloatVer = ($dashFloatCss && file_exists($dashFloatCss)) ? (int) filemtime($dashFloatCss) : time();
$dashMobileCss = defined('ASSETS_PATH') ? ASSETS_PATH . '/css/dashboard-mobile.css' : '';
$dashMobileVer = ($dashMobileCss && file_exists($dashMobileCss)) ? (int) filemtime($dashMobileCss) : time();
?>
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/css/dashboard-card-float.css?v=<?= $dashFloatVer ?>"/>
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/css/dashboard-mobile.css?v=<?= $dashMobileVer ?>"/>
<?php else: ?>
<?php $mobileNavJsVer = (int) @filemtime(defined('ASSETS_PATH') ? ASSETS_PATH . '/js/mobile-nav.js' : ''); ?>
<script src="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/js/mobile-nav.js?v=<?= $mobileNavJsVer ?: time() ?>" defer></script>
<script src="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/js/header-offset.js" defer></script>
<script src="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/js/draggable-fab.js" defer></script>
<script src="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/js/profile-menu.js" defer></script>
<?php endif; ?>
