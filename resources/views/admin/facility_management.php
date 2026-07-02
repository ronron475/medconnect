<?php
session_start();
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
require_once BASE_PATH . '/app/includes/portal_paths.php';
require_once __DIR__ . '/_portal_access.php';

$tab = $_GET['tab'] ?? 'facilities';
$is_referral = ($tab === 'referral');
$page_title = $is_referral ? 'Referral Center' : 'Facility Management';
$portalBase = portal_views_base();
$refApi = ASSET_BASE . '/app/api/admin/referrals.php';
$facApi = ASSET_BASE . '/app/api/admin/facilities.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS facilities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    facility_name VARCHAR(150) NOT NULL,
    facility_type VARCHAR(50) NOT NULL DEFAULT 'Hospital',
    address VARCHAR(255) NULL,
    contact_number VARCHAR(30) NULL,
    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,
    status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$facilities = $pdo->query("SELECT * FROM facilities WHERE status != 'archived' ORDER BY facility_name")->fetchAll(PDO::FETCH_ASSOC);
$referral_count = 0;
$pending_referrals = 0;
if ($pdo->query("SHOW TABLES LIKE 'digital_referrals'")->rowCount()) {
    $referral_count = (int) $pdo->query('SELECT COUNT(*) FROM digital_referrals')->fetchColumn();
    $pending_referrals = (int) $pdo->query("SELECT COUNT(*) FROM digital_referrals WHERE status = 'pending'")->fetchColumn();
}

require_once __DIR__ . '/partials/layout_open.php';
?>

<div class="header-row" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
  <div>
    <h2 class="text-h2"><?= $is_referral ? 'Referral Center Management' : 'Facility Management' ?></h2>
    <p class="text-muted"><?= $referral_count ?> total referrals · <?= $pending_referrals ?> pending review.</p>
  </div>
  <?php if (!$is_referral): ?>
  <button class="mc-btn mc-btn--primary" id="facAddBtn">+ Add Facility</button>
  <?php endif; ?>
</div>

<nav style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
  <a href="<?= $portalBase ?>/facility_management.php" class="mc-btn <?= !$is_referral ? 'mc-btn--primary' : 'mc-btn--outline' ?>">Facilities</a>
  <a href="<?= $portalBase ?>/facility_management.php?tab=referral" class="mc-btn <?= $is_referral ? 'mc-btn--primary' : 'mc-btn--outline' ?>">
    Referrals <?php if ($pending_referrals > 0): ?><span class="mc-badge" style="margin-left:4px;"><?= $pending_referrals ?></span><?php endif; ?>
  </a>
</nav>

<?php if ($is_referral): ?>

<div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center;">
  <label class="text-sm">Filter
    <select id="refFilter" class="mc-btn mc-btn--outline" style="background:#fff;margin-left:6px;">
      <option value="all">All</option>
      <option value="pending">Pending</option>
      <option value="accepted">Accepted</option>
      <option value="completed">Completed</option>
      <option value="cancelled">Cancelled</option>
    </select>
  </label>
  <span id="refUpdated" class="text-xs text-muted">Loading…</span>
</div>

<div class="mc-card" style="padding:0;overflow:hidden;">
  <table class="mc-table">
    <thead>
      <tr><th>Patient</th><th>Provider</th><th>Type</th><th>Facility</th><th>Reason</th><th>Status</th><th>Date</th><th></th></tr>
    </thead>
    <tbody id="refTableBody">
      <tr><td colspan="8"><div class="mc-table-empty"><p>Loading referrals…</p></div></td></tr>
    </tbody>
  </table>
</div>

<script>
(function () {
  var api = <?= json_encode($refApi) ?>;
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function load() {
    var st = document.getElementById('refFilter').value;
    fetch(api + '?status=' + encodeURIComponent(st), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var tb = document.getElementById('refTableBody');
        if (!j.success || !j.rows || !j.rows.length) {
          tb.innerHTML = '<tr><td colspan="8"><div class="mc-table-empty"><p>No referrals found.</p></div></td></tr>';
          return;
        }
        tb.innerHTML = j.rows.map(function (row) {
          var dt = row.created_at ? new Date(row.created_at.replace(' ', 'T')).toLocaleString() : '—';
          return '<tr><td><strong>' + esc(row.patient_name) + '</strong></td><td>' + esc(row.provider_name) + '</td><td>' + esc(row.referral_type) + '</td><td>' + esc(row.facility_name || '—') + '</td><td class="text-sm">' + esc(row.reason) + '</td><td><span class="mc-badge">' + esc(row.status) + '</span></td><td class="text-xs text-muted">' + esc(dt) + '</td><td><select class="mc-btn mc-btn--outline ref-status" data-id="' + row.id + '" style="padding:2px 6px;font-size:10px;background:#fff;"><option value="pending"' + (row.status==='pending'?' selected':'') + '>Pending</option><option value="accepted"' + (row.status==='accepted'?' selected':'') + '>Accepted</option><option value="completed"' + (row.status==='completed'?' selected':'') + '>Completed</option><option value="cancelled"' + (row.status==='cancelled'?' selected':'') + '>Cancelled</option></select></td></tr>';
        }).join('');
        document.querySelectorAll('.ref-status').forEach(function (sel) {
          sel.onchange = function () {
            var fd = new FormData();
            fd.append('id', sel.getAttribute('data-id'));
            fd.append('status', sel.value);
            fetch(api, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (res) { if (!res.success) alert(res.message); else load(); });
          };
        });
        document.getElementById('refUpdated').textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
      });
  }
  document.getElementById('refFilter').onchange = load;
  load();
  setInterval(load, 45000);
})();
</script>

<?php else: ?>

<div id="facForm" class="mc-card" style="display:none;margin-bottom:16px;">
  <form id="facilityForm">
    <input type="hidden" name="id" value="">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
      <input name="facility_name" required placeholder="Facility name" class="mc-btn mc-btn--outline" style="background:#fff;">
      <select name="facility_type" class="mc-btn mc-btn--outline" style="background:#fff;">
        <?php foreach (['Hospital','Clinic','Laboratory','Specialist','ABTC','TB-DOTS','Other'] as $t): ?>
        <option value="<?= $t ?>"><?= $t ?></option>
        <?php endforeach; ?>
      </select>
      <input name="address" placeholder="Address" class="mc-btn mc-btn--outline" style="background:#fff;">
      <input name="contact_number" placeholder="Contact" class="mc-btn mc-btn--outline" style="background:#fff;">
      <input name="latitude" placeholder="Latitude" class="mc-btn mc-btn--outline" style="background:#fff;">
      <input name="longitude" placeholder="Longitude" class="mc-btn mc-btn--outline" style="background:#fff;">
      <button type="submit" class="mc-btn mc-btn--primary">Save Facility</button>
    </div>
  </form>
</div>

<div class="mc-card" style="padding:0;overflow:hidden;">
  <table class="mc-table">
    <thead><tr><th>Name</th><th>Type</th><th>Address</th><th>Contact</th><th>Coordinates</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($facilities)): ?>
      <tr><td colspan="7"><div class="mc-table-empty"><p>No facilities registered.</p></div></td></tr>
      <?php else: foreach ($facilities as $f): ?>
      <tr>
        <td><strong><?= htmlspecialchars($f['facility_name']) ?></strong></td>
        <td><?= htmlspecialchars($f['facility_type']) ?></td>
        <td class="text-sm"><?= htmlspecialchars($f['address'] ?? '—') ?></td>
        <td><?= htmlspecialchars($f['contact_number'] ?? '—') ?></td>
        <td class="text-xs"><?= htmlspecialchars(($f['latitude'] ?? '—') . ', ' . ($f['longitude'] ?? '—')) ?></td>
        <td><span class="mc-badge"><?= htmlspecialchars($f['status']) ?></span></td>
        <td><button class="mc-btn mc-btn--outline mc-btn--sm" data-edit='<?= htmlspecialchars(json_encode($f), ENT_QUOTES) ?>'>Edit</button></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
var fapi = <?= json_encode($facApi) ?>;
document.getElementById('facAddBtn').onclick = function () { document.getElementById('facForm').style.display = 'block'; };
document.getElementById('facilityForm').onsubmit = function (e) {
  e.preventDefault();
  fetch(fapi, { method: 'POST', body: new FormData(e.target) }).then(function (r) { return r.json(); }).then(function (j) { alert(j.message); if (j.success) location.reload(); });
};
document.querySelectorAll('[data-edit]').forEach(function (b) {
  b.onclick = function () {
    var d = JSON.parse(b.dataset.edit);
    var f = document.getElementById('facilityForm');
    document.getElementById('facForm').style.display = 'block';
    Object.keys(d).forEach(function (k) { if (f[k]) f[k].value = d[k] || ''; });
  };
});
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
