<?php
/**
 * Anti-FOUC theme bootstrap — include inside <head> before stylesheets.
 */
$theme_pref = htmlspecialchars($_SESSION['user_theme'] ?? 'system', ENT_QUOTES, 'UTF-8');
$theme_css = ASSETS_PATH . '/css/medconnect-theme.css';
$theme_css_ver = file_exists($theme_css) ? (int) filemtime($theme_css) : time();
?>
<meta name="medconnect-theme" content="<?= $theme_pref ?>">
<script>
(function () {
  var pref = <?= json_encode($_SESSION['user_theme'] ?? 'system', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  var resolved = pref === 'dark' ? 'dark' : pref === 'light' ? 'light'
    : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  document.documentElement.setAttribute('data-theme-preference', pref);
  document.documentElement.setAttribute('data-theme-resolved', resolved);
})();
</script>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/medconnect-theme.css?v=<?= $theme_css_ver ?>">
<?php
$mc_modal_css = ASSETS_PATH . '/css/mc-modal-system.css';
$mc_modal_css_ver = file_exists($mc_modal_css) ? (int) filemtime($mc_modal_css) : time();
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/mc-modal-system.css?v=<?= $mc_modal_css_ver ?>">
