<?php /* $page_title must be set before including */ ?>
<header class="pd-header">
  <button class="pd-hamburger" id="pdHamburger" type="button" aria-label="Open navigation menu" aria-expanded="false" aria-controls="app-sidebar">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
  <a class="pd-brand" href="<?= ASSET_BASE ?>/views/provider/dashboard.php" aria-label="Home">
    <img src="<?= ASSET_BASE ?>/assets/img/medcon_logo.png" alt="" class="pd-brand__img"/>
  </a>
  <div class="pd-header-title">
    <div class="pd-header-date"><?= htmlspecialchars(provider_format_date(date('Y-m-d'))) ?></div>
    <div class="pd-header-page"><?= htmlspecialchars($page_title ?? 'Provider Dashboard') ?></div>
  </div>
  <div class="pd-header-right">
    <?php require_once VIEWS_PATH . '/partials/theme_toggle.php'; ?>
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
    <button type="button" data-logout-trigger class="topbar-logout pd-topbar-logout" title="Sign out" aria-label="Sign out"
            style="margin-left:4px; padding:6px; background:none; border:none; cursor:pointer; color:inherit;">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
    </button>
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
    <a class="mc-profmenu__btn mc-profmenu__btn--primary" href="<?= ASSET_BASE ?>/views/provider/profile.php">My Profile</a>
    <a class="mc-profmenu__btn" href="<?= ASSET_BASE ?>/views/provider/settings.php">Settings</a>
    <button type="button" class="mc-profmenu__btn mc-profmenu__btn--danger" data-profmenu-logout>Sign out</button>
  </div>
</div>
