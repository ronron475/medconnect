<?php
/**
 * Shared portal nav badge CSS/JS.
 * Set $portal_nav_badges_skip_js = true when another script polls counts (e.g. provider).
 */
$portal_nav_badges_skip_js = !empty($portal_nav_badges_skip_js);
$portalNavBadgesCss = ASSETS_PATH . '/css/portal-nav-badges.css';
$portalNavBadgesCssVer = file_exists($portalNavBadgesCss) ? (int) filemtime($portalNavBadgesCss) : time();
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/portal-nav-badges.css?v=<?= $portalNavBadgesCssVer ?>"/>
<?php if (!$portal_nav_badges_skip_js): ?>
<?php $portalNavBadgesJsVer = (int) @filemtime(ASSETS_PATH . '/js/portal-nav-badges.js'); ?>
<script src="<?= ASSET_BASE ?>/assets/js/portal-nav-badges.js?v=<?= $portalNavBadgesJsVer ?>" defer></script>
<?php endif; ?>
