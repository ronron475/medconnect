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
        ['health_summary.php','Health Summary', '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>'],
        ['consultations.php','My Sessions',     '<path d="M15 10l4.553-2.276A1 1 0 0 1 21 8.618v6.764a1 1 0 0 1-1.447.894L15 14M5 18h8a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2z"/>'],
        ['triage.php',       'Book Consultation',  '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>'],
        ['my_health.php',    'My Health',       '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>'],
        ['messages.php',     'Messages',        '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>'],
        ['settings.php',     'Settings',        '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>'],
    ];
}

// BHW uses dedicated grouped sidebar
if (($_SESSION['user_role'] ?? '') === 'bhw') {
    require_once VIEWS_PATH . '/bhw/partials/sidebar.php';
    return;
}

$sidebar_messages_unread = 0;
if (in_array($_SESSION['user_role'] ?? '', ['patient', 'provider'], true) && !empty($_SESSION['user_id']) && isset($pdo) && $pdo instanceof PDO) {
    require_once BASE_PATH . '/app/includes/message_deletion.php';
    consultation_messages_ensure_schema($pdo);
    $sidebar_messages_unread = message_unread_count($pdo, (int) $_SESSION['user_id']);
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
        $patient_direct_pages = ['dashboard.php', 'messages.php', 'my_health.php', 'profile.php', 'consultations.php', 'triage.php', 'health_summary.php', 'settings.php'];
        $href = ASSET_BASE . "/views/" . $role_path . "/" . ($is_patient && !in_array($file, $patient_direct_pages, true) ? "dashboard.php#view-" . $view_id : $file);
        
        // Active logic: current routed page, or dashboard hash on legacy combined view
        $is_active = ($current_page === $file);
        if ($is_patient && $current_page === 'dashboard.php' && empty($_GET['path'])) {
            $is_active = ($file === 'dashboard.php');
        }
    ?>
    <a href="<?= $href ?>"
       class="sb-item <?= $is_active ? 'active' : '' ?>"
       data-view="<?= $view_id ?>"<?= $file === 'messages.php' ? ' data-nav-messages' : '' ?>>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <?= $icon ?>
      </svg>
      <span class="sb-label"><?= $label ?></span>
      <?php if ($file === 'messages.php'): ?>
      <span class="mc-nav-messages-badge" data-nav-messages-badge<?= $sidebar_messages_unread <= 0 ? ' hidden' : '' ?>><?= $sidebar_messages_unread > 99 ? '99+' : (int) $sidebar_messages_unread ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <!-- User Profile -->
  <?php if (!empty($_SESSION['user_id'])): ?>
  <?php
  $is_patient_sidebar = (($_SESSION['user_role'] ?? '') === 'patient');
  $sidebar_patient_id = 'MC-' . str_pad((string) ($_SESSION['user_id'] ?? 0), 6, '0', STR_PAD_LEFT);
  $sidebar_patient_verified = true;
  if ($is_patient_sidebar && !empty($_SESSION['user_id']) && isset($pdo) && $pdo instanceof PDO) {
      try {
          $email = (string) ($_SESSION['email'] ?? '');
          if ($email !== '') {
              $vStmt = $pdo->prepare("SELECT COALESCE(status, 'pending') AS reg_status FROM patient_registrations WHERE email = ? LIMIT 1");
              $vStmt->execute([$email]);
              $vRow = $vStmt->fetch(PDO::FETCH_ASSOC);
              $regStatus = strtolower(trim((string) ($vRow['reg_status'] ?? 'pending')));
              $sidebar_patient_verified = in_array($regStatus, ['verified', 'active', 'approved'], true);
          }
      } catch (Throwable $e) {
          $sidebar_patient_verified = true;
      }
  }
  ?>
  <div class="sb-footer" role="group" aria-label="Account actions">
    <div class="sb-profile<?= $is_patient_sidebar ? ' sb-profile--patient' : '' ?>">
      <div class="sb-profile-row">
        <div class="sb-avatar" data-profile-avatar-wrap><?= profile_picture_render($initials, $sidebar_picture_url, '', 'sm') ?></div>
        <div class="sb-profile-info">
          <div class="sb-name"><?= htmlspecialchars($full_name) ?></div>
          <div class="sb-role">
            <?php if (($_SESSION['user_role'] ?? '') === 'bhw'): ?>
              Role: BHW | Sector: Brgy. <?= htmlspecialchars($_SESSION['user_barangay_name'] ?? 'Unassigned') ?>
            <?php else: ?>
              Role: <?= strtoupper($_SESSION['user_role'] ?? 'User') ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php if ($is_patient_sidebar): ?>
      <div class="sb-patient-badges">
        <span class="sb-patient-id"><?= htmlspecialchars($sidebar_patient_id) ?></span>
        <span class="sb-patient-verified<?= $sidebar_patient_verified ? '' : ' sb-patient-verified--pending' ?>"><?= $sidebar_patient_verified ? 'VERIFIED' : 'PENDING' ?></span>
      </div>
      <?php endif; ?>
    </div>
    <!-- Logout -->
    <button id="sb-logout-btn" class="sb-logout" type="button" data-logout-trigger>
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
        <polyline points="16 17 21 12 16 7"/>
        <line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
      <span class="sb-label">Logout</span>
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
