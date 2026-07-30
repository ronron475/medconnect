<?php
/**
 * Load portal dark-mode CSS after page-injected stylesheets (end of body).
 * Set $portal_dark_mode_css in layout_open (e.g. 'admin-dark-mode.css', 'bhw-dark-mode.css').
 */
if (empty($portal_dark_mode_css) || !is_string($portal_dark_mode_css)) {
    return;
}
$portalDarkPath = ASSETS_PATH . '/css/' . basename($portal_dark_mode_css);
if (!file_exists($portalDarkPath)) {
    return;
}
$portalDarkVer = (int) filemtime($portalDarkPath);
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/<?= htmlspecialchars(basename($portal_dark_mode_css), ENT_QUOTES, 'UTF-8') ?>?v=<?= $portalDarkVer ?>"/>
