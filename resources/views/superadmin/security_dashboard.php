<?php
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/superadmin/security.php';

$page_title = 'Security Dashboard';
$summary = superadmin_get_security_summary($pdo);
$api = ASSET_BASE . '/app/api/superadmin/security.php';

require_once __DIR__ . '/partials/layout_open.php';
?>

<div class="header-row" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
  <div>
    <h2 class="text-h2">Security Center</h2>
    <p class="text-muted">Enterprise security monitoring — failed logins, blocked IPs, sessions, and audit events.</p>
  </div>
</div>

<div class="superadmin-stat-grid">
  <div class="mc-card"><div class="text-h1"><?= $summary['failed24h'] ?></div><div class="text-xs text-muted">Failed Logins (24h)</div></div>
  <div class="mc-card"><div class="text-h1"><?= $summary['blockedIps'] ?></div><div class="text-xs text-muted">Blocked IPs</div></div>
  <div class="mc-card"><div class="text-h1"><?= $summary['activeSessions'] ?></div><div class="text-xs text-muted">Active Sessions</div></div>
  <div class="mc-card"><div class="text-h1"><?= $summary['securityEvents'] ?></div><div class="text-xs text-muted">Security Events (24h)</div></div>
</div>

<div class="mc-card mb-md">
  <h3 class="text-h3 mb-md">Block IP Address</h3>
  <form id="blockIpForm" style="display:flex;gap:8px;flex-wrap:wrap;">
    <input type="text" name="ip" placeholder="IP address" class="mc-btn mc-btn--outline" style="background:#fff;text-align:left;min-width:160px;" required>
    <input type="text" name="reason" placeholder="Reason" class="mc-btn mc-btn--outline" style="background:#fff;text-align:left;flex:1;min-width:200px;">
    <label class="text-sm" style="display:flex;align-items:center;gap:6px;"><input type="checkbox" name="permanent" value="1"> Permanent</label>
    <button type="submit" class="mc-btn mc-btn--primary">Block IP</button>
  </form>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
  <a href="<?= ASSET_BASE ?>/views/superadmin/failed_logins.php" class="mc-card text-sm" style="text-decoration:none;color:inherit;">Failed Logins →</a>
  <a href="<?= ASSET_BASE ?>/views/superadmin/blocked_ips.php" class="mc-card text-sm" style="text-decoration:none;color:inherit;">Blocked IPs →</a>
  <a href="<?= ASSET_BASE ?>/views/superadmin/active_sessions.php" class="mc-card text-sm" style="text-decoration:none;color:inherit;">Active Sessions →</a>
  <a href="<?= ASSET_BASE ?>/views/superadmin/login_attempts.php" class="mc-card text-sm" style="text-decoration:none;color:inherit;">Login Attempts →</a>
  <a href="<?= ASSET_BASE ?>/views/superadmin/security_logs.php" class="mc-card text-sm" style="text-decoration:none;color:inherit;">Security Logs →</a>
  <a href="<?= ASSET_BASE ?>/views/superadmin/audit_trail.php" class="mc-card text-sm" style="text-decoration:none;color:inherit;">Audit Trail →</a>
</div>

<script>
document.getElementById('blockIpForm').onsubmit = function (e) {
  e.preventDefault();
  var fd = new FormData(e.target);
  fd.append('action', 'block_ip');
  fetch(<?= json_encode($api) ?>, { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (j) { alert(j.message); if (j.success) location.reload(); });
};
</script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
