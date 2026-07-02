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
    $breadcrumb = 'BHW Operations';
} else {
    $breadcrumb = 'Patient Care';
}

$first    = htmlspecialchars($_SESSION['first_name'] ?? 'User');
$last     = htmlspecialchars($_SESSION['last_name']  ?? '');
require_once BASE_PATH . '/app/includes/profile_picture.php';

$initials = profile_picture_initials($_SESSION['first_name'] ?? 'U', $_SESSION['last_name'] ?? '');
$header_picture_url = profile_picture_public_url($_SESSION['profile_picture'] ?? null);

// Server-side seed for clock
$today = date('F j, Y');
$now   = date('h:i A');
$header_date_caps = strtoupper(date('l, M j, Y'));
?>
<header class="topbar<?= $is_patient_portal ? ' topbar--clinical' : '' ?>">

  <button type="button" class="mc-nav-toggle" id="mcNavToggle" aria-label="Open navigation menu" aria-expanded="false" aria-controls="app-sidebar">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>

  <!-- ── Left: Date + page title (patient) or breadcrumb + title ── -->
  <div class="topbar-left">
    <?php if ($is_patient_portal): ?>
    <div class="topbar-title-block">
      <div class="topbar-eyebrow topbar-date-label"><?= htmlspecialchars($header_date_caps) ?></div>
      <h1 class="topbar-title"><?= htmlspecialchars($page_title) ?></h1>
    </div>
    <?php else: ?>
    <div class="topbar-title-block">
      <div class="topbar-eyebrow"><?= htmlspecialchars($breadcrumb) ?></div>
      <h1 class="topbar-title"><?= htmlspecialchars($page_title) ?></h1>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Right: Utility Group ── -->
  <div class="topbar-right">

    <?php if (!$is_patient_portal): ?>
    <!-- Live Digital Clock -->
    <div class="topbar-datetime">
      <span class="topbar-date" id="global-date"><?= $today ?></span>
      <span class="topbar-time" id="global-time"><?= $now ?></span>
    </div>

    <!-- Thin vertical aqua separator rule -->
    <div class="topbar-sep" aria-hidden="true"></div>
    <?php endif; ?>

    <?php require_once VIEWS_PATH . '/partials/theme_toggle.php'; ?>

    <?php
    $bell_class = 'topbar-icon-btn mc-notif-btn';
    require_once VIEWS_PATH . '/partials/notification_bell.php';
    ?>

    <!-- Circular aqua user avatar badge -->
    <div class="topbar-avatar" title="<?= $first . ' ' . $last ?>" data-profile-avatar-wrap>
      <?= profile_picture_render($initials, $header_picture_url, '', 'sm') ?>
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
