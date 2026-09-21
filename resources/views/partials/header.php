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

// Server-side seed for clock
$today = date('F j, Y');
$now   = date('h:i A');
$is_bhw_portal = $user_role === 'bhw';
$is_admin_portal = ($user_role === 'admin') || ($user_role === 'superadmin');
/* Admin/SuperAdmin/BHW: page title only — no role subtitle above the title. */
$compact_topbar = $is_admin_portal
    || $is_bhw_portal
    || !empty($mc_dashboard_topbar);
?>
<header class="topbar<?= $is_patient_portal ? ' topbar--clinical' : '' ?><?= $is_bhw_portal ? ' topbar--bhw-formal' : '' ?>">

  <button type="button" class="mc-nav-toggle" id="mcNavToggle" aria-label="Open navigation menu" aria-expanded="false" aria-controls="app-sidebar">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>

  <!-- ── Left: page title (patient) or breadcrumb + title ── -->
  <div class="topbar-left">
    <?php if (!$is_patient_portal && !$is_bhw_portal && !$compact_topbar): ?>
    <a class="topbar-brand" href="<?= ASSET_BASE ?>/views/<?= htmlspecialchars($user_role === 'provider' ? 'provider' : ($user_role === 'superadmin' ? 'superadmin' : ($user_role === 'bhw' ? 'bhw' : 'patient'))) ?>/dashboard.php" aria-label="Home">
      <img src="<?= ASSET_BASE ?>/assets/img/medcon_logo.png" alt="" class="topbar-brand__img"/>
    </a>
    <?php endif; ?>
    <?php if ($is_patient_portal): ?>
    <div class="topbar-title-block">
      <h1 class="topbar-title"><?= htmlspecialchars($page_title) ?></h1>
    </div>
    <?php else: ?>
    <div class="topbar-title-block">
      <?php if (!$compact_topbar): ?>
      <div class="topbar-eyebrow">
        <?php if ($is_bhw_portal): ?>
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        <?php endif; ?>
        <?= htmlspecialchars($breadcrumb) ?>
      </div>
      <?php endif; ?>
      <h1 class="topbar-title"><?= htmlspecialchars($page_title) ?></h1>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Right: Utility Group ── -->
  <div class="topbar-right">

    <?php if (!$is_patient_portal): ?>
    <!-- Live date (admin: date only — no stacked time); BHW/provider: date + time -->
    <div class="topbar-datetime<?= $is_admin_portal ? ' topbar-datetime--date-only' : '' ?>" aria-label="<?= $is_admin_portal ? 'Current date' : 'Current date and time' ?>">
      <span class="topbar-date" id="global-date"><?= $today ?></span>
      <?php if (!$is_admin_portal): ?>
      <?php if ($is_bhw_portal): ?>
      <span class="topbar-time-sep" aria-hidden="true">|</span>
      <?php endif; ?>
      <span class="topbar-time" id="global-time"><?= $now ?></span>
      <?php endif; ?>
    </div>

    <!-- Thin vertical separator rule -->
    <div class="topbar-sep<?= $is_bhw_portal ? ' topbar-divider' : '' ?>" aria-hidden="true"></div>
    <?php endif; ?>

    <?php require_once VIEWS_PATH . '/partials/theme_toggle.php'; ?>

    <?php
    $fullscreen_btn_class = 'topbar-icon-btn';
    require_once VIEWS_PATH . '/partials/fullscreen_toggle.php';
    ?>

    <?php
    $bell_class = 'topbar-icon-btn mc-notif-btn';
    require_once VIEWS_PATH . '/partials/notification_bell.php';
    ?>

  </div>
</header>

<script>
(function() {
  const dateEl = document.getElementById('global-date');
  const timeEl = document.getElementById('global-time');
  if (!dateEl && !timeEl) return;
  const M = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  function tick() {
    const d = new Date(), h = d.getHours(), m = d.getMinutes(), ampm = h >= 12 ? 'PM' : 'AM';
    if (dateEl) dateEl.textContent = M[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    if (timeEl) timeEl.textContent = (h % 12 || 12) + ':' + (m < 10 ? '0' + m : m) + ' ' + ampm;
  }
  tick(); setInterval(tick, timeEl ? 1000 : 60000);
})();
</script>
