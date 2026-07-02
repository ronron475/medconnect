<?php
/**
 * medConnect — Unified Anchored Sidebar (Aqua #028090 theme)
 */
$current_page = basename($_SERVER['PHP_SELF'] ?? '');

// Portal views are served via public/view.php?path=patient/foo.php
if ($current_page === 'view.php') {
    $route_path = (string) ($_GET['path'] ?? '');
    if ($route_path !== '') {
        $current_page = basename(str_replace('\\', '/', $route_path));
    } elseif (preg_match('#/views/[^/]+/([^/?]+)#', (string) ($_SERVER['REQUEST_URI'] ?? ''), $route_match)) {
        $current_page = basename($route_match[1]);
    }
}

require_once BASE_PATH . '/app/includes/profile_picture.php';

$initials = profile_picture_initials($_SESSION['first_name'] ?? 'U', $_SESSION['last_name'] ?? '');
$sidebar_picture_url = profile_picture_public_url($_SESSION['profile_picture'] ?? null);
$full_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$role      = ucfirst($_SESSION['user_role'] ?? 'User');

// Determine base path for links based on role
$role_path = ($_SESSION['user_role'] === 'admin') ? 'admin' : (($_SESSION['user_role'] === 'provider') ? 'provider' : (($_SESSION['user_role'] === 'bhw') ? 'bhw' : 'patient'));

$nav_items = [];
if ($_SESSION['user_role'] === 'admin') {
    $admin_nav = require BASE_PATH . '/app/includes/nav/admin_nav.php';
    $nav_items = [];
    foreach ($admin_nav as $section) {
        foreach ($section['items'] as $item) {
            $nav_items[] = [$item[0] . (isset($item[3]) ? '?' . $item[3] : ''), $item[1], $item[2]];
        }
    }
} elseif ($_SESSION['user_role'] === 'provider') {
    $provider_nav = require BASE_PATH . '/app/includes/nav/provider_nav.php';
    $nav_items = [];
    foreach ($provider_nav as $section) {
        foreach ($section['items'] as $item) {
            $nav_items[] = [$item[0], $item[1], $item[2]];
        }
    }
} elseif ($_SESSION['user_role'] !== 'bhw') {
    $nav_items = [
        ['dashboard.php',    ($_SESSION['user_role'] ?? '') === 'patient' ? 'Dashboard' : 'Health Status', '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>'],
        ['profile.php',      'My Identity',     '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>'],
        ['consultations.php','My Sessions',     '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'],
        ['triage.php',       'Triage History',  '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>'],
        ['medical_history.php', 'Medical History', '<path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="10"/>'],
        ['followup.php',     'Follow-Ups',      '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>'],
        ['records.php',      'Health Files',    '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>'],
        ['messages.php',     'Messages',        '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'],
    ];
}

// BHW uses dedicated grouped sidebar
if (($_SESSION['user_role'] ?? '') === 'bhw') {
    require_once VIEWS_PATH . '/bhw/partials/sidebar.php';
    return;
}
?>
<aside class="sidebar">

  <!-- Logo -->
  <a href="<?= ASSET_BASE ?>/views/<?= $role_path ?>/dashboard.php" class="sb-logo">
    <img src="<?= ASSET_BASE ?>/assets/img/medcon_logo.png" alt="medConnect" class="sb-logo-img">
  </a>

  <!-- Navigation -->
  <nav class="sb-nav">
    <?php foreach ($nav_items as [$file, $label, $icon]): 
        $view_id = str_replace('.php', '', $file);
        // Patients use direct page links for all modules except the dashboard home.
        $is_patient = ($_SESSION['user_role'] ?? '') === 'patient';
        
        // Fix: Ensure dashboard.php links always work regardless of current directory
        $patient_direct_pages = ['messages.php', 'records.php', 'followup.php', 'medical_history.php', 'profile.php', 'consultations.php', 'triage.php'];
        $href = ASSET_BASE . "/views/" . $role_path . "/" . ($is_patient && !in_array($file, $patient_direct_pages, true) ? "dashboard.php#view-" . $view_id : $file);
        
        // Active logic: current routed page, or dashboard hash on legacy combined view
        $is_active = ($current_page === $file);
        if ($is_patient && $current_page === 'dashboard.php' && empty($_GET['path'])) {
            $is_active = ($file === 'dashboard.php');
        }
    ?>
    <a href="<?= $href ?>"
       class="sb-item <?= $is_active ? 'active' : '' ?>"
       data-view="<?= $view_id ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <?= $icon ?>
      </svg>
      <?= $label ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <!-- User Profile -->
  <?php if (!empty($_SESSION['user_id'])): ?>
  <?php
  $is_patient_sidebar = (($_SESSION['user_role'] ?? '') === 'patient');
  $sidebar_patient_id = 'MC-' . str_pad((string) ($_SESSION['user_id'] ?? 0), 6, '0', STR_PAD_LEFT);
  ?>
  <div class="sb-footer" role="group" aria-label="Account actions">
    <div class="sb-profile<?= $is_patient_sidebar ? ' sb-profile--patient' : '' ?>">
      <div class="sb-avatar" data-profile-avatar-wrap><?= profile_picture_render($initials, $sidebar_picture_url, '', 'sm') ?></div>
      <div class="sb-profile-info">
        <div class="sb-name"><?= htmlspecialchars($full_name) ?></div>
        <div class="sb-role" style="font-size: 10px; opacity: 0.8; font-weight: 500; letter-spacing: 0.02em;">
          <?php if (($_SESSION['user_role'] ?? '') === 'bhw'): ?>
            Role: BHW | Sector: Brgy. <?= htmlspecialchars($_SESSION['user_barangay_name'] ?? 'Unassigned') ?>
          <?php else: ?>
            Role: <?= strtoupper($_SESSION['user_role'] ?? 'User') ?>
          <?php endif; ?>
        </div>
        <?php if ($is_patient_sidebar): ?>
        <div class="sb-patient-badges">
          <span class="sb-patient-id"><?= htmlspecialchars($sidebar_patient_id) ?></span>
          <span class="sb-patient-verified">VERIFIED</span>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <!-- Logout -->
    <button id="sb-logout-btn" class="sb-logout" type="button" data-logout-trigger>
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
        <polyline points="16 17 21 12 16 7"/>
        <line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
      Logout
    </button>
  </div>
  <?php endif; ?>

  <!-- Institutional Endorsement (hidden on patient portal) -->
  <?php if (($_SESSION['user_role'] ?? '') !== 'patient'): ?>
  <div class="sidebar-watermark" style="margin-top: auto; padding: 20px; text-align: center;">
    <img src="<?= ASSET_BASE ?>/assets/img/bcclogo.png" alt="City Health Office" style="max-width: 80px; height: auto; opacity: 0.5; filter: grayscale(100%) brightness(200%);">
  </div>
  <?php endif; ?>
</aside>

<?php require_once VIEWS_PATH . '/partials/logout_modal.php'; ?>
