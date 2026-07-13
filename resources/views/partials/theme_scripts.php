<?php
$theme_js = ASSETS_PATH . '/js/medconnect-theme.js';
$theme_js_ver = file_exists($theme_js) ? (int) filemtime($theme_js) : time();
$mc_modal_js = ASSETS_PATH . '/js/mc-modal.js';
$mc_modal_js_ver = file_exists($mc_modal_js) ? (int) filemtime($mc_modal_js) : time();
?>
<script src="<?= ASSET_BASE ?>/assets/js/medconnect-theme.js?v=<?= $theme_js_ver ?>"></script>
<script src="<?= ASSET_BASE ?>/assets/js/mc-modal.js?v=<?= $mc_modal_js_ver ?>"></script>
