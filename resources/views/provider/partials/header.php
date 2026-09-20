<?php /* $page_title must be set before including */ ?>
<header class="pd-header">
  <button class="pd-hamburger" id="pdHamburger" type="button" aria-label="Open navigation menu" aria-expanded="false" aria-controls="app-sidebar">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
  <div class="pd-header-title">
    <div class="pd-header-date"><?= htmlspecialchars(provider_format_date(date('Y-m-d'))) ?></div>
    <div class="pd-header-page"><?= htmlspecialchars($page_title ?? 'Dashboard') ?></div>
  </div>
  <div class="pd-header-right">
    <?php require_once VIEWS_PATH . '/partials/theme_toggle.php'; ?>
    <?php
    $fullscreen_btn_class = 'pd-notif-btn';
    require_once VIEWS_PATH . '/partials/fullscreen_toggle.php';
    ?>
    <div class="pd-header-clock" id="pdClock"></div>
    <?php
    $bell_class = 'pd-notif-btn mc-notif-btn';
    require_once VIEWS_PATH . '/partials/notification_bell.php';
    ?>
    <div class="pd-header-user">
      <div class="pd-header-user-info">
        <div class="pd-header-user-name"><?= htmlspecialchars($provider['display_name'] ?? trim(($provider['first_name'] ?? '') . ' ' . ($provider['last_name'] ?? ''))) ?></div>
        <div class="pd-header-user-role"><?= htmlspecialchars($provider['role'] ?? 'General Medicine') ?></div>
      </div>
      <button type="button" class="pd-avatar" data-profile-avatar-wrap data-profile-menu-trigger="provider" aria-label="Open profile menu">
        <?= profile_picture_render($provider['initials'] ?? 'DR', $provider['picture_url'] ?? null, 'pd-header-avatar', 'sm') ?>
      </button>
    </div>
  </div>
</header>

<?php
$prov_full_name = trim(($provider['display_name'] ?? '') ?: trim(($provider['first_name'] ?? '') . ' ' . ($provider['last_name'] ?? '')));
if ($prov_full_name === '') $prov_full_name = 'Provider';
$prov_role = (string) ($provider['role'] ?? 'Provider');
?>
<div class="mc-profmenu" data-profile-menu="provider" hidden>
  <div class="mc-profmenu__hero">
    <div class="mc-profmenu__seal">
      <img src="<?= ASSET_BASE ?>/assets/img/medcon_logo.png" alt=""/>
    </div>
    <div class="mc-profmenu__name"><?= htmlspecialchars($prov_full_name) ?></div>
    <div class="mc-profmenu__meta"><?= htmlspecialchars($prov_role) ?></div>
  </div>
  <div class="mc-profmenu__actions">
    <a class="mc-profmenu__btn mc-profmenu__btn--primary" href="<?= ASSET_BASE ?>/views/provider/settings.php">My Profile</a>
    <a class="mc-profmenu__btn" href="<?= ASSET_BASE ?>/views/provider/settings.php">Settings</a>
  </div>
</div>
