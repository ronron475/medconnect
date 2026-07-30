<?php
/**
 * Anti-FOUC theme bootstrap — include inside <head> before stylesheets.
 * Reads localStorage first (same priority as medconnect-theme.js) to prevent flash.
 */
$theme_pref = htmlspecialchars($_SESSION['user_theme'] ?? 'system', ENT_QUOTES, 'UTF-8');
$theme_css = ASSETS_PATH . '/css/medconnect-theme.css';
$theme_css_ver = file_exists($theme_css) ? (int) filemtime($theme_css) : time();
?>
<meta name="medconnect-theme" content="<?= $theme_pref ?>">
<script>
(function () {
  var STORAGE_KEY = 'medconnect_theme';
  var serverPref = <?= json_encode($_SESSION['user_theme'] ?? 'system', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

  function resolveTheme(preference) {
    if (preference === 'dark') return 'dark';
    if (preference === 'light') return 'light';
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  var pref = serverPref;
  try {
    var stored = localStorage.getItem(STORAGE_KEY);
    if (stored === 'light' || stored === 'dark' || stored === 'system') {
      pref = stored;
    }
  } catch (e) { /* ignore */ }

  var resolved = resolveTheme(pref);
  var root = document.documentElement;
  root.setAttribute('data-theme-preference', pref);
  root.setAttribute('data-theme-resolved', resolved);

  if (document.body) {
    document.body.setAttribute('data-theme-preference', pref);
    document.body.setAttribute('data-theme-resolved', resolved);
  } else {
    document.addEventListener('DOMContentLoaded', function () {
      if (document.body) {
        document.body.setAttribute('data-theme-preference', pref);
        document.body.setAttribute('data-theme-resolved', resolved);
        if (document.body.classList.contains('landing-page')) {
          document.body.classList.add(resolved === 'dark' ? 'landing-bg--dark' : 'landing-bg--light');
        }
      }
    });
  }
})();
</script>
<style id="mc-theme-anti-fouc">
/* Shared anti-FOUC — all portals: black canvas, flat nav, no teal flash */
html[data-theme-resolved='dark'] body.patient-portal,
html[data-theme-resolved='dark'] body.provider-body,
html[data-theme-resolved='dark'] body.bhw-body,
html[data-theme-resolved='dark'] body.admin-body,
html[data-theme-resolved='dark'] body.superadmin-body {
  background: #0a0a0a !important;
  color: #ffffff !important;
  transition: none !important;
}
html[data-theme-resolved='dark'] body.patient-portal .sidebar,
html[data-theme-resolved='dark'] body.patient-portal .root-wrapper,
html[data-theme-resolved='dark'] body.patient-portal .portal-main,
html[data-theme-resolved='dark'] body.patient-portal .main-content,
html[data-theme-resolved='dark'] body.provider-body .sidebar,
html[data-theme-resolved='dark'] body.provider-body .sb-aqua,
html[data-theme-resolved='dark'] body.provider-body .provider-main,
html[data-theme-resolved='dark'] body.provider-body .provider-page-body,
html[data-theme-resolved='dark'] body.bhw-body .adm-sidebar,
html[data-theme-resolved='dark'] body.bhw-body .portal-main,
html[data-theme-resolved='dark'] body.bhw-body .portal-page-body,
html[data-theme-resolved='dark'] body.admin-body .adm-sidebar,
html[data-theme-resolved='dark'] body.admin-body .portal-main,
html[data-theme-resolved='dark'] body.admin-body .portal-page-body,
html[data-theme-resolved='dark'] body.superadmin-body .adm-sidebar,
html[data-theme-resolved='dark'] body.superadmin-body .portal-main,
html[data-theme-resolved='dark'] body.superadmin-body .portal-page-body {
  background: #0a0a0a !important;
  color: #ffffff !important;
  transition: none !important;
}
html[data-theme-resolved='dark'] body.patient-portal .sidebar,
html[data-theme-resolved='dark'] body.provider-body .sidebar,
html[data-theme-resolved='dark'] body.provider-body .sb-aqua,
html[data-theme-resolved='dark'] body.bhw-body .adm-sidebar,
html[data-theme-resolved='dark'] body.admin-body .adm-sidebar,
html[data-theme-resolved='dark'] body.superadmin-body .adm-sidebar {
  background: #141414 !important;
  background-image: none !important;
}
html[data-theme-resolved='dark'] body.patient-portal .sb-item,
html[data-theme-resolved='dark'] body.patient-portal .sb-item:hover,
html[data-theme-resolved='dark'] body.patient-portal .sb-item:focus,
html[data-theme-resolved='dark'] body.patient-portal .sb-item:active,
html[data-theme-resolved='dark'] body.patient-portal .sb-item.active,
html[data-theme-resolved='dark'] body.provider-body .sba-item,
html[data-theme-resolved='dark'] body.provider-body .sba-item:hover,
html[data-theme-resolved='dark'] body.provider-body .sba-item:focus,
html[data-theme-resolved='dark'] body.provider-body .sba-item:active,
html[data-theme-resolved='dark'] body.provider-body .sba-item.is-active,
html[data-theme-resolved='dark'] body.bhw-body .adm-nav-item,
html[data-theme-resolved='dark'] body.bhw-body .adm-nav-item:hover,
html[data-theme-resolved='dark'] body.bhw-body .adm-nav-item:focus,
html[data-theme-resolved='dark'] body.bhw-body .adm-nav-item:active,
html[data-theme-resolved='dark'] body.bhw-body .adm-nav-item.is-active,
html[data-theme-resolved='dark'] body.admin-body .adm-nav-item,
html[data-theme-resolved='dark'] body.admin-body .adm-nav-item:hover,
html[data-theme-resolved='dark'] body.admin-body .adm-nav-item:focus,
html[data-theme-resolved='dark'] body.admin-body .adm-nav-item:active,
html[data-theme-resolved='dark'] body.admin-body .adm-nav-item.is-active,
html[data-theme-resolved='dark'] body.superadmin-body .adm-nav-item,
html[data-theme-resolved='dark'] body.superadmin-body .adm-nav-item:hover,
html[data-theme-resolved='dark'] body.superadmin-body .adm-nav-item:focus,
html[data-theme-resolved='dark'] body.superadmin-body .adm-nav-item:active,
html[data-theme-resolved='dark'] body.superadmin-body .adm-nav-item.is-active {
  background: transparent !important;
  background-image: none !important;
  box-shadow: none !important;
  outline: none !important;
  border-color: transparent !important;
  color: #ffffff !important;
  transition: none !important;
  -webkit-tap-highlight-color: transparent;
}
</style>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/medconnect-theme.css?v=<?= $theme_css_ver ?>">
<?php
$mc_modal_css = ASSETS_PATH . '/css/mc-modal-system.css';
$mc_modal_css_ver = file_exists($mc_modal_css) ? (int) filemtime($mc_modal_css) : time();
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/mc-modal-system.css?v=<?= $mc_modal_css_ver ?>">
