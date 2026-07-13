<?php
/**
 * medConnect — Unified Topbar Header
 */
$page_title = $page_title ?? 'Dashboard';
$user_role = (string) ($_SESSION['user_role'] ?? 'patient');
$is_patient_portal = $user_role === 'patient';

// Role-specific breadcrumb
$breadcrumb = 'Overview';
if ($user_role === 'admin') {
    $breadcrumb = 'Administration';
} elseif ($user_role === 'superadmin') {
    $breadcrumb = 'Super Administration';
} elseif ($user_role === 'provider') {
    $breadcrumb = 'Clinical Portal';
} elseif ($user_role === 'bhw') {
    $breadcrumb = 'Barangay Health Operations';
} else {
    $breadcrumb = 'Patient Care';
}

$first    = htmlspecialchars($_SESSION['first_name'] ?? 'User');
$last     = htmlspecialchars($_SESSION['last_name']  ?? '');
require_once BASE_PATH . '/app/includes/profile_picture.php';

$initials = profile_picture_initials($_SESSION['first_name'] ?? 'U', $_SESSION['last_name'] ?? '');
$header_picture_url = profile_picture_public_url($_SESSION['profile_picture'] ?? null);
  $full_name_header = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
  if ($full_name_header === '') $full_name_header = 'User';
  $portal_label = strtoupper($user_role ?: 'USER');
  $member_since = '';
  $raw_created = $_SESSION['created_at'] ?? $_SESSION['registered_at'] ?? $_SESSION['member_since'] ?? '';
  if (!empty($raw_created)) {
      try {
          $dt = new DateTime((string) $raw_created);
          $member_since = $dt->format('M. Y');
      } catch (Throwable $e) {
          $member_since = '';
      }
  }

// Server-side seed for clock
$today = date('F j, Y');
$now   = date('h:i A');
$header_date_caps = strtoupper(date('l, M j, Y'));
$is_bhw_portal = $user_role === 'bhw';

$profile_menu_href = ASSET_BASE . '/views/' . htmlspecialchars($user_role === 'provider' ? 'provider' : ($user_role === 'admin' ? 'admin' : ($user_role === 'superadmin' ? 'superadmin' : ($user_role === 'bhw' ? 'bhw' : 'patient')))) . '/profile.php';
$settings_menu_href = ASSET_BASE . '/views/' . htmlspecialchars($user_role === 'provider' ? 'provider' : ($user_role === 'admin' ? 'admin' : ($user_role === 'superadmin' ? 'superadmin' : ($user_role === 'bhw' ? 'bhw' : 'patient')))) . '/settings.php';
if ($is_bhw_portal) {
    $profile_menu_href = ASSET_BASE . '/views/bhw/settings/profile.php';
    $settings_menu_href = $profile_menu_href;
}
?>
<header class="topbar<?= $is_patient_portal ? ' topbar--clinical' : '' ?><?= $is_bhw_portal ? ' topbar--bhw-formal' : '' ?>">

  <button type="button" class="mc-nav-toggle" id="mcNavToggle" aria-label="Open navigation menu" aria-expanded="false" aria-controls="app-sidebar">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>

  <!-- ── Left: Date + page title (patient) or breadcrumb + title ── -->
  <div class="topbar-left">
    <?php if (!$is_bhw_portal): ?>
    <a class="topbar-brand" href="<?= ASSET_BASE ?>/views/<?= htmlspecialchars($user_role === 'provider' ? 'provider' : ($user_role === 'admin' ? 'admin' : ($user_role === 'superadmin' ? 'superadmin' : ($user_role === 'bhw' ? 'bhw' : 'patient'))) ) ?>/dashboard.php" aria-label="Home">
      <img src="<?= ASSET_BASE ?>/assets/img/medcon_logo.png" alt="" class="topbar-brand__img"/>
    </a>
    <?php endif; ?>
    <?php if ($is_patient_portal): ?>
    <div class="topbar-title-block">
      <div class="topbar-eyebrow topbar-date-label"><?= htmlspecialchars($header_date_caps) ?></div>
      <h1 class="topbar-title"><?= htmlspecialchars($page_title) ?></h1>
    </div>
    <?php else: ?>
    <div class="topbar-title-block">
      <div class="topbar-eyebrow">
        <?php if ($is_bhw_portal): ?>
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        <?php endif; ?>
        <?= htmlspecialchars($breadcrumb) ?>
      </div>
      <h1 class="topbar-title"><?= htmlspecialchars($page_title) ?></h1>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Right: Utility Group ── -->
  <div class="topbar-right">

    <?php if (!$is_patient_portal): ?>
    <!-- Live Digital Clock -->
    <div class="topbar-datetime" aria-label="Current date and time">
      <span class="topbar-date" id="global-date"><?= $today ?></span>
      <?php if ($is_bhw_portal): ?>
      <span class="topbar-time-sep" aria-hidden="true">|</span>
      <?php endif; ?>
      <span class="topbar-time" id="global-time"><?= $now ?></span>
    </div>

    <!-- Thin vertical separator rule -->
    <div class="topbar-sep<?= $is_bhw_portal ? ' topbar-divider' : '' ?>" aria-hidden="true"></div>
    <?php endif; ?>

    <?php require_once VIEWS_PATH . '/partials/theme_toggle.php'; ?>

    <?php
    $bell_class = 'topbar-icon-btn mc-notif-btn';
    require_once VIEWS_PATH . '/partials/notification_bell.php';
    ?>

    <!-- Circular aqua user avatar badge -->
    <button type="button"
            class="topbar-avatar"
            title="<?= $first . ' ' . $last ?>"
            data-profile-avatar-wrap
            data-profile-menu-trigger="global"
            aria-label="Open profile menu">
      <?= profile_picture_render($initials, $header_picture_url, '', 'sm') ?>
    </button>

    <div class="mc-profmenu" data-profile-menu="global" hidden>
      <div class="mc-profmenu__hero">
        <div class="mc-profmenu__seal">
          <img src="<?= ASSET_BASE ?>/assets/img/medcon_logo.png" alt=""/>
        </div>
        <div class="mc-profmenu__name"><?= htmlspecialchars($full_name_header) ?></div>
        <div class="mc-profmenu__meta">
          <?= htmlspecialchars(ucfirst(strtolower($portal_label))) ?>
          <?php if ($member_since !== ''): ?>
            — Member since <?= htmlspecialchars($member_since) ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="mc-profmenu__actions">
        <a class="mc-profmenu__btn mc-profmenu__btn--primary" href="<?= $profile_menu_href ?>">My Profile</a>
        <a class="mc-profmenu__btn" href="<?= $settings_menu_href ?>">Settings</a>
        <button type="button" class="mc-profmenu__btn mc-profmenu__btn--danger" data-profmenu-logout>Sign out</button>
      </div>
    </div>

    <!-- Minimal logout icon -->
    <button type="button" data-logout-trigger class="topbar-logout" title="Sign out" aria-label="Sign out"
            style="margin-left:0; padding:4px; background:none; border:none; cursor:pointer;">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
    </button>

  </div>
</header>

<script>
(function() {
  const dateEl = document.getElementById('global-date');
  const timeEl = document.getElementById('global-time');
  if (!timeEl) return;
  const M = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  function tick() {
    const d = new Date(), h = d.getHours(), m = d.getMinutes(), ampm = h >= 12 ? 'PM' : 'AM';
    if (dateEl) dateEl.textContent = M[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    timeEl.textContent = (h % 12 || 12) + ':' + (m < 10 ? '0' + m : m) + ' ' + ampm;
  }
  tick(); setInterval(tick, 1000);
})();
</script>
