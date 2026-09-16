<?php
/**
 * Patient portal compact top header.
 * Hamburger drawer navigation — no persistent left sidebar rail.
 */
declare(strict_types=1);

$page_title = $page_title ?? 'Patient Dashboard';
$first = htmlspecialchars((string) ($_SESSION['first_name'] ?? 'Patient'));
$last = htmlspecialchars((string) ($_SESSION['last_name'] ?? ''));
require_once BASE_PATH . '/app/includes/profile_picture.php';

$initials = profile_picture_initials($_SESSION['first_name'] ?? 'P', $_SESSION['last_name'] ?? '');
$header_picture_url = profile_picture_public_url($_SESSION['profile_picture'] ?? null);
$full_name_header = trim((string) (($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')));
if ($full_name_header === '') {
    $full_name_header = 'Patient';
}
$header_date_caps = strtoupper(date('l, M j, Y'));
$profile_menu_href = ASSET_BASE . '/views/patient/profile.php';
$settings_menu_href = ASSET_BASE . '/views/patient/settings.php';
?>
<header class="pt-header" id="ptHeader">
  <button
    type="button"
    class="pd-hamburger pt-hamburger"
    id="pdHamburger"
    aria-label="Open navigation menu"
    aria-expanded="false"
    aria-controls="app-sidebar"
  >
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>

  <a class="pt-brand" href="<?= ASSET_BASE ?>/views/patient/dashboard.php" aria-label="medConnect home">
    <img src="<?= ASSET_BASE ?>/assets/img/medcon_logo.png" alt="" class="pt-brand__img" width="34" height="34"/>
  </a>

  <div class="pt-header-title">
    <div class="pt-header-date"><?= htmlspecialchars($header_date_caps) ?></div>
    <div class="pt-header-page"><?= htmlspecialchars($page_title) ?></div>
  </div>

  <div class="pt-header-right">
    <?php require_once VIEWS_PATH . '/partials/theme_toggle.php'; ?>
    <?php
    $fullscreen_btn_class = 'pt-icon-btn';
    require_once VIEWS_PATH . '/partials/fullscreen_toggle.php';
    ?>
    <?php
    $bell_class = 'pt-icon-btn mc-notif-btn';
    require_once VIEWS_PATH . '/partials/notification_bell.php';
    ?>

    <button
      type="button"
      class="pt-avatar"
      title="<?= $first . ($last !== '' ? ' ' . $last : '') ?>"
      data-profile-avatar-wrap
      data-profile-menu-trigger="patient"
      aria-label="Open profile menu"
    >
      <?= profile_picture_render($initials, $header_picture_url, 'pt-header-avatar', 'sm') ?>
    </button>

    <button
      type="button"
      data-logout-trigger
      class="pt-icon-btn pt-logout"
      title="Sign out"
      aria-label="Sign out"
    >
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
        <polyline points="16 17 21 12 16 7"/>
        <line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
    </button>
  </div>
</header>

<div class="mc-profmenu" data-profile-menu="patient" hidden>
  <div class="mc-profmenu__hero">
    <div class="mc-profmenu__seal">
      <img src="<?= ASSET_BASE ?>/assets/img/medcon_logo.png" alt=""/>
    </div>
    <div class="mc-profmenu__name"><?= htmlspecialchars($full_name_header) ?></div>
    <div class="mc-profmenu__meta">Patient</div>
  </div>
  <div class="mc-profmenu__actions">
    <a class="mc-profmenu__btn mc-profmenu__btn--primary" href="<?= htmlspecialchars($profile_menu_href) ?>">My Profile</a>
    <a class="mc-profmenu__btn" href="<?= htmlspecialchars($settings_menu_href) ?>">Settings</a>
  </div>
</div>
