<?php
/**
 * Security → Devices & logins
 */
declare(strict_types=1);

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
require_once BASE_PATH . '/app/includes/login_security.php';
require_once CONFIG_PATH . '/db.php';

auth_require_login();

login_security_ensure_schema($pdo);

$page_title = 'Security — Devices';
$role = $_SESSION['user_role'] ?? 'patient';
$userId = (int) ($_SESSION['user_id'] ?? 0);

try {
    $devicesStmt = $pdo->prepare("
        SELECT device_fingerprint, first_seen_at, last_seen_at, last_ip, last_user_agent
        FROM user_devices
        WHERE user_id = ?
        ORDER BY last_seen_at DESC
        LIMIT 50
    ");
    $devicesStmt->execute([$userId]);
    $devices = $devicesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $devices = [];
}

try {
    $eventsStmt = $pdo->prepare("
        SELECT ip_address, browser, os, device_type, created_at
        FROM user_login_events
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $eventsStmt->execute([$userId]);
    $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $events = [];
}

switch ($role) {
    case 'admin':
        require_once VIEWS_PATH . '/admin/partials/layout_open.php';
        break;
    case 'superadmin':
        require_once VIEWS_PATH . '/superadmin/partials/layout_open.php';
        break;
    case 'provider':
        require_once VIEWS_PATH . '/provider/partials/icons.php';
        require_once VIEWS_PATH . '/provider/partials/data.php';
        require_once VIEWS_PATH . '/provider/partials/layout_open.php';
        break;
    case 'bhw':
        require_once VIEWS_PATH . '/bhw/partials/layout_open.php';
        break;
    default:
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once VIEWS_PATH . '/patient/partials/layout_head.php'; ?>
<?php require_once VIEWS_PATH . '/partials/notification_assets.php'; ?>
</head>
<body class="patient-portal">
<?php require_once VIEWS_PATH . '/patient/partials/layout_shell_open.php'; ?>
        <?php
        break;
}
?>

<div class="mc-card" style="margin-bottom:16px;">
  <h2 class="text-h2" style="margin:0 0 4px;">Security</h2>
  <p class="text-muted text-sm" style="margin:0;">Review devices and recent logins for your account.</p>
</div>

<div class="mc-notif-widgets" style="margin-bottom:16px;">
  <div class="mc-notif-widget">
    <div class="mc-notif-widget-value"><?= number_format(count($devices)) ?></div>
    <div class="mc-notif-widget-label">Known devices</div>
  </div>
  <div class="mc-notif-widget mc-notif-widget--alert">
    <div class="mc-notif-widget-value"><?= number_format(count($events)) ?></div>
    <div class="mc-notif-widget-label">Recent logins (shown)</div>
  </div>
</div>

<div class="mc-card" style="padding:0;overflow:hidden;margin-bottom:16px;">
  <div style="padding:16px 18px;border-bottom:1px solid #f1f5f9;background:#fafbfc;">
    <h3 class="text-h3" style="margin:0;">Devices</h3>
    <p class="text-muted text-sm" style="margin:4px 0 0;">Devices are grouped by a privacy-conscious fingerprint (not a hardware ID).</p>
  </div>
  <div style="padding:0;">
    <?php if (empty($devices)): ?>
      <div class="mc-notif-empty">No device records yet.</div>
    <?php else: ?>
      <div class="mc-notif-list mc-notif-list--feed" style="max-height:none;">
        <?php foreach ($devices as $d): ?>
          <div class="mc-notif-item">
            <div class="mc-notif-icon mc-notif-icon--critical">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
              </svg>
            </div>
            <div class="mc-notif-body">
              <p class="mc-notif-title">Device fingerprint</p>
              <p class="mc-notif-message" style="-webkit-line-clamp:3;">
                <?= htmlspecialchars(substr((string) ($d['device_fingerprint'] ?? ''), 0, 16) . '…') ?>
                <br/>
                Last seen: <?= htmlspecialchars(date('M j, Y g:i A', strtotime((string) ($d['last_seen_at'] ?? 'now')))) ?>
              </p>
              <div class="mc-notif-meta">
                <span><?= htmlspecialchars((string) ($d['last_ip'] ?? '')) ?></span>
                <span><?= htmlspecialchars((string) ($d['last_user_agent'] ?? '')) ?></span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="mc-card" style="padding:0;overflow:hidden;">
  <div style="padding:16px 18px;border-bottom:1px solid #f1f5f9;background:#fafbfc;">
    <h3 class="text-h3" style="margin:0;">Recent logins</h3>
    <p class="text-muted text-sm" style="margin:4px 0 0;">If you don’t recognize a login, change your password immediately.</p>
  </div>
  <div style="padding:0;">
    <?php if (empty($events)): ?>
      <div class="mc-notif-empty">No login events found.</div>
    <?php else: ?>
      <div class="mc-notif-list mc-notif-list--feed" style="max-height:none;">
        <?php foreach ($events as $e): ?>
          <div class="mc-notif-item">
            <div class="mc-notif-icon mc-notif-icon--warning">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
              </svg>
            </div>
            <div class="mc-notif-body">
              <p class="mc-notif-title">
                <?= htmlspecialchars((string) ($e['browser'] ?? 'Unknown')) ?> on <?= htmlspecialchars((string) ($e['os'] ?? 'Unknown')) ?>
                (<?= htmlspecialchars((string) ($e['device_type'] ?? 'unknown')) ?>)
              </p>
              <p class="mc-notif-message">
                IP: <?= htmlspecialchars((string) ($e['ip_address'] ?? '')) ?>
              </p>
              <div class="mc-notif-meta">
                <span><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string) ($e['created_at'] ?? 'now')))) ?></span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
switch ($role) {
    case 'admin':
        require_once VIEWS_PATH . '/admin/partials/layout_close.php';
        break;
    case 'superadmin':
        require_once VIEWS_PATH . '/superadmin/partials/layout_close.php';
        break;
    case 'provider':
        require_once VIEWS_PATH . '/provider/partials/layout_close.php';
        break;
    case 'bhw':
        require_once VIEWS_PATH . '/bhw/partials/layout_close.php';
        break;
    default:
        require_once VIEWS_PATH . '/patient/partials/layout_shell_close.php';
        break;
}

