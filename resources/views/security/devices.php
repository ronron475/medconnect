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
require_once BASE_PATH . '/app/includes/portal_layout.php';
require_once CONFIG_PATH . '/db.php';

auth_require_login();

login_security_ensure_schema($pdo);

$page_title = 'Security — Devices';
$role = portal_layout_role();
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

portal_layout_open($role);
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/portal-settings-shell.css?v=<?= (int) @filemtime(ASSETS_PATH . '/css/portal-settings-shell.css') ?>">

<div class="mc-security-devices">
<div class="mc-card mc-security-devices__intro">
  <h2 class="text-h2">Security</h2>
  <p class="text-muted text-sm">Review devices and recent logins for your account.</p>
</div>

<div class="mc-notif-widgets mc-security-devices__stats">
  <div class="mc-notif-widget">
    <div class="mc-notif-widget-value"><?= number_format(count($devices)) ?></div>
    <div class="mc-notif-widget-label">Known devices</div>
  </div>
  <div class="mc-notif-widget mc-notif-widget--alert">
    <div class="mc-notif-widget-value"><?= number_format(count($events)) ?></div>
    <div class="mc-notif-widget-label">Recent logins (shown)</div>
  </div>
</div>

<div class="mc-card mc-security-devices__card">
  <div class="mc-security-devices__card-head">
    <h3 class="text-h3">Devices</h3>
    <p class="text-muted text-sm">Devices are grouped by a privacy-conscious fingerprint (not a hardware ID).</p>
  </div>
  <div class="mc-security-devices__card-body">
    <?php if (empty($devices)): ?>
      <div class="mc-notif-empty">No device records yet.</div>
    <?php else: ?>
      <div class="mc-notif-list mc-notif-list--feed mc-security-devices__list">
        <?php foreach ($devices as $d): ?>
          <div class="mc-notif-item">
            <div class="mc-notif-icon mc-notif-icon--critical">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
              </svg>
            </div>
            <div class="mc-notif-body">
              <p class="mc-notif-title">Device fingerprint</p>
              <p class="mc-notif-message mc-security-devices__message">
                <?= htmlspecialchars(substr((string) ($d['device_fingerprint'] ?? ''), 0, 16) . '…') ?>
                <br/>
                Last seen: <?= htmlspecialchars(date('M j, Y', strtotime((string) ($d['last_seen_at'] ?? 'now'))) . ' • ' . date('g:i A', strtotime((string) ($d['last_seen_at'] ?? 'now')))) ?>
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

<div class="mc-card mc-security-devices__card">
  <div class="mc-security-devices__card-head">
    <h3 class="text-h3">Recent logins</h3>
    <p class="text-muted text-sm">If you don’t recognize a login, change your password immediately.</p>
  </div>
  <div class="mc-security-devices__card-body">
    <?php if (empty($events)): ?>
      <div class="mc-notif-empty">No login events found.</div>
    <?php else: ?>
      <div class="mc-notif-list mc-notif-list--feed mc-security-devices__list">
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
                <span><?= htmlspecialchars(date('M j, Y', strtotime((string) ($e['created_at'] ?? 'now'))) . ' • ' . date('g:i A', strtotime((string) ($e['created_at'] ?? 'now')))) ?></span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
</div>

<?php portal_layout_close($role); ?>
