<?php
$theme_js = ASSETS_PATH . '/js/medconnect-theme.js';
$theme_js_ver = file_exists($theme_js) ? (int) filemtime($theme_js) : time();
?>
<script src="<?= ASSET_BASE ?>/assets/js/medconnect-theme.js?v=<?= $theme_js_ver ?>"></script>
