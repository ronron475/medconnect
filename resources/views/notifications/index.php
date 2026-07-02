<?php
/**
 * Notification Center — full page for all roles
 */
if (!defined('BASE_PATH')) {
    $d = __DIR__;
    while ($d !== dirname($d)) {
        if (is_file($d . '/mc_load.php')) {
            require_once $d . '/mc_load.php';
            break;
        }
        $d = dirname($d);
    }
}
require_once BASE_PATH . '/app/includes/auth_guard.php';

auth_require_login();

$page_title = 'Notifications';
$role = $_SESSION['user_role'] ?? 'patient';

// Load role-appropriate layout
switch ($role) {
    case 'admin':
        require_once __DIR__ . '/../admin/partials/layout_open.php';
        break;
    case 'provider':
        require_once __DIR__ . '/../provider/partials/icons.php';
        require_once __DIR__ . '/../provider/partials/data.php';
        require_once __DIR__ . '/../provider/partials/layout_open.php';
        break;
    case 'bhw':
        require_once __DIR__ . '/../bhw/partials/layout_open.php';
        break;
    default:
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../patient/partials/layout_head.php'; ?>
<?php require_once VIEWS_PATH . '/partials/notification_assets.php'; ?>
</head>
<body class="patient-portal">
        <?php
        require_once __DIR__ . '/../patient/partials/layout_shell_open.php';
        break;
}
?>

<div class="mc-notif-page" data-notif-page>
  <div class="mc-notif-page-header">
    <div>
      <h2 class="text-h2" style="margin: 0 0 4px;">Notification Center</h2>
      <p class="text-muted text-sm" style="margin: 0;">Your alerts, appointments, referrals, and system messages.</p>
    </div>
    <button type="button" class="mc-btn mc-btn--secondary" onclick="MedConnectNotifications && MedConnectNotifications.markAllRead()">
      Mark all as read
    </button>
  </div>

  <div class="mc-notif-filters" role="search">
    <input type="search" data-notif-search placeholder="Search notifications…" aria-label="Search notifications"/>
    <select data-notif-type aria-label="Filter by type">
      <option value="">All types</option>
      <option value="appointment">Appointment</option>
      <option value="consultation">Consultation</option>
      <option value="referral">Referral</option>
      <option value="medical">Medical</option>
      <option value="security">Security</option>
      <option value="emergency">Emergency</option>
      <option value="reminder">Reminder</option>
      <option value="system">System</option>
      <option value="gis">GIS</option>
      <option value="information">Information</option>
    </select>
    <select data-notif-status aria-label="Filter by status">
      <option value="">All active</option>
      <option value="unread">Unread only</option>
      <option value="archived">Archived</option>
    </select>
  </div>

  <div class="mc-notif-page-list" data-notif-page-list role="feed" aria-label="Notification history">
    <div class="mc-notif-empty">Loading…</div>
  </div>

  <div class="mc-notif-pagination" data-notif-pagination aria-label="Pagination"></div>
</div>

<?php
switch ($role) {
    case 'admin':
        require_once __DIR__ . '/../admin/partials/layout_close.php';
        break;
    case 'provider':
        require_once __DIR__ . '/../provider/partials/layout_close.php';
        break;
    case 'bhw':
        require_once __DIR__ . '/../bhw/partials/layout_close.php';
        break;
    default:
        require_once __DIR__ . '/../patient/partials/layout_shell_close.php';
        break;
}
