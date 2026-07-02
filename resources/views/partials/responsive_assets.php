<?php
/**
 * Shared responsive CSS/JS — include in layout <head> and before </body>.
 * Set $responsive_scripts_only = true to load JS only (before </body>).
 */
$assetBase = defined('ASSET_BASE') ? ASSET_BASE : '';
$scriptsOnly = !empty($responsive_scripts_only);

if (!$scriptsOnly): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/css/responsive.css"/>
<?php else: ?>
<script src="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/js/mobile-nav.js" defer></script>
<script src="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/js/header-offset.js" defer></script>
<?php endif; ?>
