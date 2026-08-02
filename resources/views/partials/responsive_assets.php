<?php
/**
 * Shared responsive CSS/JS — include in layout <head> and before </body>.
 * Set $responsive_scripts_only = true to load JS only (before </body>).
 */
$assetBase = defined('ASSET_BASE') ? ASSET_BASE : '';
$scriptsOnly = !empty($responsive_scripts_only);

if (!$scriptsOnly): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/css/responsive.css"/>
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/css/profile-menu.css"/>
<?php
$dashFloatCss = defined('ASSETS_PATH') ? ASSETS_PATH . '/css/dashboard-card-float.css' : '';
$dashFloatVer = ($dashFloatCss && file_exists($dashFloatCss)) ? (int) filemtime($dashFloatCss) : time();
?>
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/css/dashboard-card-float.css?v=<?= $dashFloatVer ?>"/>
<?php else: ?>
<script src="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/js/mobile-nav.js" defer></script>
<script src="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/js/header-offset.js" defer></script>
<script src="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/js/draggable-fab.js" defer></script>
<script src="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/js/profile-menu.js" defer></script>
<?php endif; ?>
