<?php
/**
 * Patient portal sidebar — single reusable component.
 * BHW uses resources/views/bhw/partials/sidebar.php directly.
 */
declare(strict_types=1);

$user_role = (string) ($_SESSION['user_role'] ?? 'patient');

if ($user_role !== 'patient') {
    return;
}

$current_page = basename($_SERVER['PHP_SELF'] ?? '');

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
$role_path = 'patient';
$nav_items = require BASE_PATH . '/app/includes/nav/patient_nav.php';

$sidebar_messages_unread = 0;
if (!empty($_SESSION['user_id']) && isset($pdo) && $pdo instanceof PDO) {
    require_once BASE_PATH . '/app/includes/message_deletion.php';
    consultation_messages_ensure_schema($pdo);
    $sidebar_messages_unread = message_unread_count($pdo, (int) $_SESSION['user_id']);
}

$patient_direct_pages = ['dashboard.php', 'messages.php', 'my_health.php', 'profile.php', 'consultations.php', 'triage.php', 'health_summary.php', 'settings.php'];
?>
<aside class="sidebar">

  <a href="<?= ASSET_BASE ?>/views/<?= $role_path ?>/dashboard.php" class="sb-logo">
    <img src="<?= ASSET_BASE ?>/assets/img/medcon_logo.png" alt="medConnect" class="sb-logo-img">
  </a>

  <nav class="sb-nav" aria-label="Patient portal navigation">
    <?php foreach ($nav_items as [$file, $label, $icon]):
        $view_id = str_replace('.php', '', $file);
        $href = ASSET_BASE . '/views/' . $role_path . '/' . $file;
        $is_active = ($current_page === $file);
        if ($current_page === 'dashboard.php' && empty($_GET['path'])) {
            $is_active = ($file === 'dashboard.php');
        }
    ?>
    <a href="<?= htmlspecialchars($href) ?>"
       class="sb-item <?= $is_active ? 'active' : '' ?>"
       data-view="<?= htmlspecialchars($view_id) ?>"<?= $file === 'messages.php' ? ' data-nav-messages' : '' ?>
       <?= $is_active ? 'aria-current="page"' : '' ?>>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <?= $icon ?>
      </svg>
      <span class="sb-label"><?= htmlspecialchars($label) ?></span>
      <?php if ($file === 'messages.php'): ?>
      <span class="mc-nav-messages-badge" data-nav-messages-badge<?= $sidebar_messages_unread <= 0 ? ' hidden' : '' ?>><?= $sidebar_messages_unread > 99 ? '99+' : (int) $sidebar_messages_unread ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <?php if (!empty($_SESSION['user_id'])): ?>
  <?php
  $sidebar_patient_id = 'MC-' . str_pad((string) ($_SESSION['user_id'] ?? 0), 6, '0', STR_PAD_LEFT);
  $sidebar_patient_verified = true;
  if (!empty($_SESSION['user_id']) && isset($pdo) && $pdo instanceof PDO) {
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
    <div class="sb-profile sb-profile--patient">
      <div class="sb-profile-row">
        <div class="sb-avatar" data-profile-avatar-wrap><?= profile_picture_render($initials, $sidebar_picture_url, '', 'sm') ?></div>
        <div class="sb-profile-info">
          <div class="sb-name"><?= htmlspecialchars($full_name) ?></div>
          <div class="sb-role">Role: PATIENT</div>
        </div>
      </div>
      <div class="sb-patient-badges">
        <span class="sb-patient-id"><?= htmlspecialchars($sidebar_patient_id) ?></span>
        <span class="sb-patient-verified<?= $sidebar_patient_verified ? '' : ' sb-patient-verified--pending' ?>"><?= $sidebar_patient_verified ? 'VERIFIED' : 'PENDING' ?></span>
      </div>
    </div>
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

</aside>

<?php require_once VIEWS_PATH . '/partials/logout_modal.php'; ?>
