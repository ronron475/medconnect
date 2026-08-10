<?php
/**
 * Portal nav badge live polling — load at end of body after sidebar markup.
 * Set $portal_nav_badges_skip_js = true when another script polls counts (e.g. provider).
 */
if (!empty($portal_nav_badges_skip_js)) {
    return;
}
$portalNavBadgesJsVer = (int) @filemtime(ASSETS_PATH . '/js/portal-nav-badges.js');
$portalNavBadgesRefreshVer = (int) @filemtime(ASSETS_PATH . '/js/nav-badges-refresh.js');
?>
<script src="<?= ASSET_BASE ?>/assets/js/nav-badges-refresh.js?v=<?= $portalNavBadgesRefreshVer ?>"></script>
<script src="<?= ASSET_BASE ?>/assets/js/portal-nav-badges.js?v=<?= $portalNavBadgesJsVer ?>"></script>
