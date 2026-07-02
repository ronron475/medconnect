<?php
/**
 * Shared notification assets — include in layout heads and closes.
 */
$assetBase = defined('ASSET_BASE') ? ASSET_BASE : '';
$cssPath = ASSETS_PATH . '/css/notifications.css';
$jsPath  = ASSETS_PATH . '/js/notifications.js';
$cssVer  = file_exists($cssPath) ? (int) filemtime($cssPath) : 1;
$jsVer   = file_exists($jsPath) ? (int) filemtime($jsPath) : 1;

if (empty($notification_scripts_only)): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/assets/css/notifications.css?v=<?= $cssVer ?>"/>
<?php else: ?>
<script src="<?= htmlspecialchars($assetBase) ?>/assets/js/notifications.js?v=<?= $jsVer ?>" defer></script>
<?php endif; ?>
