<?php
/**
 * medConnect — Unified Premium Modal System (PHP component)
 *
 * Usage:
 *   <?php require_once VIEWS_PATH . '/components/mc_modal.php'; mc_modal_assets(); ?>
 *   <?php mc_render_logout_modal(); ?>
 */

declare(strict_types=1);

if (!function_exists('mc_modal_asset_ver')) {
    function mc_modal_asset_ver(string $file): int
    {
        if (!defined('ASSETS_PATH')) {
            return time();
        }
        $path = ASSETS_PATH . '/' . ltrim($file, '/');
        return is_file($path) ? (int) filemtime($path) : time();
    }
}

if (!function_exists('mc_modal_assets')) {
    function mc_modal_assets(): void
    {
        $base = defined('ASSET_BASE') ? ASSET_BASE : '';
        $cssVer = mc_modal_asset_ver('css/mc-modal-system.css');
        $jsVer  = mc_modal_asset_ver('js/mc-modal.js');

        echo '<link rel="stylesheet" href="' . htmlspecialchars($base) . '/assets/css/mc-modal-system.css?v=' . $cssVer . '"/>' . "\n";
        echo '<script src="' . htmlspecialchars($base) . '/assets/js/mc-modal.js?v=' . $jsVer . '" defer></script>' . "\n";
    }
}

if (!function_exists('mc_render_modal_shell')) {
    /**
     * @param array{
     *   id?: string,
     *   title?: string,
     *   description?: string,
     *   size?: string,
     *   variant?: string,
     *   body_html?: string,
     *   footer_html?: string,
     *   hidden?: bool,
     *   show_logo?: bool,
     *   show_close?: bool,
     * } $opts
     */
    function mc_render_modal_shell(array $opts = []): void
    {
        $id = $opts['id'] ?? 'mc-modal';
        $title = $opts['title'] ?? '';
        $description = $opts['description'] ?? '';
        $size = $opts['size'] ?? 'md';
        $variant = $opts['variant'] ?? '';
        $bodyHtml = $opts['body_html'] ?? '';
        $footerHtml = $opts['footer_html'] ?? '';
        $hidden = array_key_exists('hidden', $opts) ? (bool) $opts['hidden'] : true;
        $showLogo = $opts['show_logo'] ?? true;
        $showClose = $opts['show_close'] ?? true;
        $base = defined('ASSET_BASE') ? ASSET_BASE : '';
        $logo = $base . '/assets/img/medcon_logo.png';
        $titleId = $id . '-title';
        ?>
<div id="<?= htmlspecialchars($id) ?>-overlay"
     class="mc-modal-overlay"
     role="dialog"
     aria-modal="true"
     aria-labelledby="<?= htmlspecialchars($titleId) ?>"
     aria-hidden="<?= $hidden ? 'true' : 'false' ?>"
     <?= $hidden ? 'hidden' : '' ?>>

  <div class="mc-modal mc-modal--<?= htmlspecialchars($size) ?>">

    <?php if ($showClose): ?>
    <button type="button" class="mc-modal__close" data-mc-modal-close aria-label="Close dialog">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
        <path d="M18 6 6 18"/><path d="m6 6 12 12"/>
      </svg>
    </button>
    <?php endif; ?>

    <div class="mc-modal__header">
      <?php if ($showLogo): ?>
      <img class="mc-modal__logo" src="<?= htmlspecialchars($logo) ?>" alt="medConnect" width="52" height="52" decoding="async"/>
      <?php endif; ?>

      <?php if ($variant !== ''): ?>
      <div class="mc-modal__icon mc-modal__icon--<?= htmlspecialchars($variant) ?>" aria-hidden="true">
        <?php if ($variant === 'logout'): ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($title !== ''): ?>
      <h2 class="mc-modal__title" id="<?= htmlspecialchars($titleId) ?>"><?= htmlspecialchars($title) ?></h2>
      <?php endif; ?>

      <?php if ($description !== ''): ?>
      <p class="mc-modal__desc"><?= htmlspecialchars($description) ?></p>
      <?php endif; ?>
    </div>

    <?php if ($bodyHtml !== ''): ?>
    <div class="mc-modal__body"><?= $bodyHtml ?></div>
    <?php endif; ?>

    <?php if ($footerHtml !== ''): ?>
    <div class="mc-modal__footer mc-modal__footer--split"><?= $footerHtml ?></div>
    <?php endif; ?>

  </div>
</div>
        <?php
    }
}

if (!function_exists('mc_render_logout_modal')) {
    function mc_render_logout_modal(): void
    {
        $footer = '
      <button type="button" id="logout-modal-no" class="mc-modal__btn mc-modal__btn--secondary" data-mc-modal-close data-mc-autofocus="1">Cancel</button>
      <button type="button" id="logout-modal-yes" class="mc-modal__btn mc-modal__btn--primary" data-logout-confirm>Sign Out</button>';

        mc_render_modal_shell([
            'id' => 'logout-modal',
            'title' => 'Sign Out',
            'description' => 'Are you sure you want to sign out? You can sign back in anytime.',
            'size' => 'sm',
            'variant' => 'logout',
            'show_logo' => false,
            'body_html' => '',
            'footer_html' => $footer,
            'hidden' => true,
        ]);
    }
}
