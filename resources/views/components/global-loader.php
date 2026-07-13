<?php
/**
 * medConnect — Global transparent logo loader component.
 *
 * Usage:
 *   <?php require_once VIEWS_PATH . '/components/global-loader.php'; mc_global_loader_assets(); ?>
 *   <?php mc_render_global_loader_boot(); ?>
 */

declare(strict_types=1);

if (!function_exists('mc_global_loader_asset_ver')) {
    function mc_global_loader_asset_ver(string $file): int
    {
        if (!defined('ASSETS_PATH')) {
            return time();
        }
        $path = ASSETS_PATH . '/' . ltrim($file, '/');
        return is_file($path) ? (int) filemtime($path) : time();
    }
}

if (!function_exists('mc_global_loader_assets')) {
    function mc_global_loader_assets(bool $includeCompatShim = true): void
    {
        $base = defined('ASSET_BASE') ? ASSET_BASE : '';
        $cssVer = mc_global_loader_asset_ver('css/global-loader.css');
        $jsVer  = mc_global_loader_asset_ver('js/global-loader.js');

        echo '<link rel="stylesheet" href="' . htmlspecialchars($base) . '/assets/css/global-loader.css?v=' . $cssVer . '"/>' . "\n";
        echo '<script src="' . htmlspecialchars($base) . '/assets/js/global-loader.js?v=' . $jsVer . '"></script>' . "\n";

        if ($includeCompatShim) {
            $loginJsVer = mc_global_loader_asset_ver('js/login-loading.js');
            echo '<script src="' . htmlspecialchars($base) . '/assets/js/login-loading.js?v=' . $loginJsVer . '"></script>' . "\n";
        }
    }
}

if (!function_exists('mc_render_global_loader_boot')) {
    function mc_render_global_loader_boot(array $opts = []): void
    {
        $base = defined('ASSET_BASE') ? ASSET_BASE : '';
        $logo = $base . '/assets/img/medcon_logo.png';
        $brand = (string) ($opts['brand'] ?? 'medConnect');
        $status = (string) ($opts['status'] ?? 'Loading…');
        $hint = (string) ($opts['hint'] ?? 'Please wait while we prepare the page.');
        ?>
<div id="mc-loader-boot" class="mc-global-loader mc-loader mc-global-loader--boot mc-global-loader--visible" data-mc-loader-boot aria-busy="true" aria-live="polite" aria-hidden="false">
  <div class="mc-loader__panel">
    <div class="mc-global-loader__stage" aria-hidden="true">
      <div class="mc-global-loader__ring"></div>
      <div class="mc-global-loader__glow"></div>
      <div class="mc-global-loader__logo-wrap">
        <img class="mc-global-loader__logo" src="<?= htmlspecialchars($logo) ?>" alt="" width="64" height="64" decoding="async"/>
      </div>
    </div>
    <p class="mc-loader__title"><?= htmlspecialchars($brand) ?></p>
    <p class="mc-loader__status"><?= htmlspecialchars($status) ?></p>
    <p class="mc-loader__hint"><?= htmlspecialchars($hint) ?></p>
    <span class="mc-global-loader__sr-only"><?= htmlspecialchars($status) ?></span>
  </div>
</div>
<script>
  (function () {
    var body = document.body;
    if (!body) return;
    body.classList.add('mc-global-loader-active', 'mc-loader-active', 'mc-global-loader--boot-active');
    // Safety net: never trap users on the boot screen if loader JS fails to load.
    setTimeout(function () {
      var boot = document.getElementById('mc-loader-boot');
      if (!boot || boot.hasAttribute('hidden')) return;
      boot.classList.remove('mc-global-loader--visible', 'mc-loader--visible');
      boot.setAttribute('hidden', '');
      boot.setAttribute('aria-hidden', 'true');
      boot.setAttribute('aria-busy', 'false');
      body.classList.remove('mc-global-loader-active', 'mc-loader-active', 'mc-global-loader--boot-active');
    }, 7000);
  })();
</script>
        <?php
    }
}

if (!function_exists('mc_render_global_loader_anchor')) {
  /**
   * Hidden anchor for legacy persistent overlay IDs (NLP, booking).
   * The global JS loader handles display — no card/modal markup.
   */
    function mc_render_global_loader_anchor(string $id): void
    {
        ?>
<div id="<?= htmlspecialchars($id) ?>" hidden aria-hidden="true" data-mc-global-loader-anchor="1"></div>
        <?php
    }
}

/* Backward-compatible aliases */
if (!function_exists('mc_loader_asset_ver')) {
    function mc_loader_asset_ver(string $file): int
    {
        return mc_global_loader_asset_ver($file);
    }
}

if (!function_exists('mc_loader_assets')) {
    function mc_loader_assets(bool $includeLoginShim = true): void
    {
        mc_global_loader_assets($includeLoginShim);
    }
}

if (!function_exists('mc_render_loader_boot')) {
    function mc_render_loader_boot(array $opts = []): void
    {
        mc_render_global_loader_boot($opts);
    }
}

if (!function_exists('mc_render_loader_panel')) {
    function mc_render_loader_panel(array $opts): void
    {
        $id = $opts['id'] ?? 'mc-loader-panel';
        mc_render_global_loader_anchor($id);
    }
}
