<?php
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
if ($pdo->query("SHOW TABLES LIKE 'digital_referrals'")->rowCount()) {
    $referral_count = (int) $pdo->query('SELECT COUNT(*) FROM digital_referrals')->fetchColumn();
}

require_once __DIR__ . '/partials/layout_open.php';
?>

<div class="header-row" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
  <div>
    <h2 class="text-h2"><?= $is_referral ? 'Referral Center' : 'Facility Management' ?></h2>
    <?php if ($is_referral): ?>
    <p class="text-muted"><?= (int) $referral_count ?> doctor-issued referral<?= (int) $referral_count === 1 ? '' : 's' ?> on record.</p>
    <p class="text-xs text-muted" style="margin-top:6px;max-width:52rem;">
      Monitoring only. Doctors create clinical referrals; this center does not track whether a patient followed the referral, and status cannot be changed here.
    </p>
    <?php else: ?>
    <p class="text-muted"><?= (int) $referral_count ?> referral<?= (int) $referral_count === 1 ? '' : 's' ?> on record.</p>
    <?php endif; ?>
  </div>
  <?php if (!$is_referral): ?>
  <button class="mc-btn mc-btn--primary" id="facAddBtn">+ Add Facility</button>
  <?php endif; ?>
</div>

<nav style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
  <a href="<?= $portalBase ?>/facility_management.php" class="mc-btn <?= !$is_referral ? 'mc-btn--primary' : 'mc-btn--outline' ?>">Facilities</a>
  <a href="<?= $portalBase ?>/facility_management.php?tab=referral" class="mc-btn <?= $is_referral ? 'mc-btn--primary' : 'mc-btn--outline' ?>">
    Referrals <span id="refTabUnreadBadge" class="mc-badge" style="margin-left:4px;" hidden aria-hidden="true"></span>
  </a>
</nav>

<?php if ($is_referral): ?>

<div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center;justify-content:flex-end;">
  <span id="refUpdated" class="text-xs text-muted">Loading…</span>
</div>

<div class="mc-card" style="padding:0;overflow:hidden;">
  <table class="mc-table">
    <thead>
      <tr>
        <th>Patient</th>
        <th>Provider</th>
        <th>Type</th>
        <th>Facility</th>
        <th>Reason</th>
        <th>Date</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody id="refTableBody">
      <tr><td colspan="7"><div class="mc-table-empty"><p>Loading referrals…</p></div></td></tr>
    </tbody>
  </table>
</div>

<div id="refDetailModal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="refDetailTitle"
     style="display:none;position:fixed;inset:0;z-index:1100;background:rgba(7,20,40,.55);align-items:center;justify-content:center;padding:20px;">
  <div style="max-width:520px;width:min(520px,92vw);background:var(--mc-surface,#fff);border-radius:12px;padding:20px;box-shadow:0 24px 60px rgba(0,0,0,.2);">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:12px;">
      <div>
        <p class="text-xs text-muted" style="margin:0 0 4px;">Referral details</p>
        <h3 id="refDetailTitle" class="text-h3" style="margin:0;">Referral</h3>
      </div>
      <button type="button" class="mc-btn mc-btn--outline" id="refDetailClose" aria-label="Close">&times;</button>
    </div>
    <dl id="refDetailBody" class="text-sm" style="display:grid;gap:10px;margin:0;"></dl>
    <div style="margin-top:16px;text-align:right;">
      <button type="button" class="mc-btn mc-btn--primary" id="refDetailDone">Close</button>
    </div>
  </div>
</div>

<script>
(function () {
  var api = <?= json_encode($refApi) ?>;
  var rowsById = {};
  var marking = {};
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function stampUpdated() {
    document.getElementById('refUpdated').textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }
  function formatTabBadge(n) {
    if (n <= 0) return '';
    return n > 9 ? '9+' : String(n);
  }
  function refreshTabBadge(unread) {
    var n = Math.max(0, parseInt(unread, 10) || 0);
    var badge = document.getElementById('refTabUnreadBadge');
    if (!badge) return;
    var text = formatTabBadge(n);
    badge.textContent = text;
    badge.hidden = n <= 0;
    badge.setAttribute('aria-hidden', n <= 0 ? 'true' : 'false');
  }
  function refreshNavBadge(unread) {
    var n = Math.max(0, parseInt(unread, 10) || 0);
    refreshTabBadge(n);
    document.querySelectorAll('[data-nav-badge="pending_referrals"]').forEach(function (badge) {
      badge.textContent = formatTabBadge(n);
      badge.hidden = n <= 0;
      badge.setAttribute('aria-hidden', n <= 0 ? 'true' : 'false');
    });
    // Keep live sidebar poller in sync so a stale in-flight poll cannot restore the old count.
    try {
      window.dispatchEvent(new CustomEvent('medconnect:referrals-unread', {
        detail: { unread_count: n }
      }));
    } catch (e) { /* ignore */ }
  }
  function markRead(referralId) {
    var id = parseInt(referralId, 10) || 0;
    if (id <= 0) return Promise.resolve(null);
    if (marking[id]) return marking[id];

    var fd = new FormData();
    fd.append('action', 'mark_read');
    fd.append('referral_id', String(id));
    fd.append('csrf_token', document.body.dataset.csrf || '');
    marking[id] = fetch(api, { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.success) {
          if (rowsById[id]) rowsById[id].is_unread = false;
          if (typeof j.unread_count === 'number') refreshNavBadge(j.unread_count);
          return j;
        }
        return null;
      })
      .catch(function () { return null; })
      .finally(function () { delete marking[id]; });
    return marking[id];
  }
  function openDetail(row) {
    var body = document.getElementById('refDetailBody');
    var title = document.getElementById('refDetailTitle');
    var modal = document.getElementById('refDetailModal');
    title.textContent = (row.referral_type || 'Referral');
    var dt = row.created_at ? new Date(String(row.created_at).replace(' ', 'T')).toLocaleString() : '—';
    body.innerHTML =
      '<div><dt class="text-xs text-muted">Patient</dt><dd style="margin:2px 0 0;"><strong>' + esc(row.patient_name || 'Unknown patient') + '</strong></dd></div>' +
      '<div><dt class="text-xs text-muted">Provider</dt><dd style="margin:2px 0 0;">' + esc(row.provider_name || '—') + '</dd></div>' +
      '<div><dt class="text-xs text-muted">Facility / service</dt><dd style="margin:2px 0 0;">' + esc(row.facility_name || '—') + '</dd></div>' +
      '<div><dt class="text-xs text-muted">Reason for referral</dt><dd style="margin:2px 0 0;">' + esc(row.reason || '—') + '</dd></div>' +
      '<div><dt class="text-xs text-muted">Date created</dt><dd style="margin:2px 0 0;">' + esc(dt) + '</dd></div>';
    modal.setAttribute('aria-hidden', 'false');
    modal.style.display = 'flex';

    // Viewing details is the read action (Admin + SuperAdmin). Idempotent for already-read rows.
    var wasUnread = !!row.is_unread;
    markRead(row.id).then(function (j) {
      if (!j || !j.success) return;
      if (wasUnread) {
        load(false);
      } else if (typeof j.unread_count === 'number') {
        refreshNavBadge(j.unread_count);
      }
    });
  }
  function closeDetail() {
    var modal = document.getElementById('refDetailModal');
    modal.setAttribute('aria-hidden', 'true');
    modal.style.display = 'none';
  }
  function load(updateStamp) {
    if (updateStamp !== false) { /* keep */ }
    fetch(api + '?status=all', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var tb = document.getElementById('refTableBody');
        if (!j.success) {
          tb.innerHTML = '<tr><td colspan="7"><div class="mc-table-empty"><p>' + esc(j.message || 'Could not load referrals.') + '</p></div></td></tr>';
          stampUpdated();
          return;
        }
        if (typeof j.unread_count === 'number') refreshNavBadge(j.unread_count);
        rowsById = {};
        if (!j.rows || !j.rows.length) {
          tb.innerHTML = '<tr><td colspan="7"><div class="mc-table-empty"><p>No referrals found.</p></div></td></tr>';
          stampUpdated();
          return;
        }
        tb.innerHTML = j.rows.map(function (row) {
          rowsById[row.id] = row;
          var dt = row.created_at ? new Date(row.created_at.replace(' ', 'T')).toLocaleString() : '—';
          var patient = (row.patient_name || '').trim() || 'Unknown patient';
          var provider = (row.provider_name || '').trim() || '—';
          var unreadDot = row.is_unread
            ? ' <span class="mc-badge" style="margin-left:6px;font-size:10px;">Unread</span>'
            : '';
          return '<tr data-ref-id="' + esc(row.id) + '"' + (row.is_unread ? ' style="font-weight:600;"' : '') + '>' +
            '<td><strong>' + esc(patient) + '</strong>' + unreadDot + '</td>' +
            '<td>' + esc(provider) + '</td>' +
            '<td>' + esc(row.referral_type) + '</td>' +
            '<td>' + esc(row.facility_name || '—') + '</td>' +
            '<td class="text-sm">' + esc(row.reason) + '</td>' +
            '<td class="text-xs text-muted">' + esc(dt) + '</td>' +
            '<td><button type="button" class="mc-btn mc-btn--outline mc-btn--sm js-ref-view" data-ref-id="' + esc(row.id) + '">View Details</button></td>' +
          '</tr>';
        }).join('');
        stampUpdated();
      })
      .catch(function () {
        document.getElementById('refTableBody').innerHTML = '<tr><td colspan="7"><div class="mc-table-empty"><p>Could not load referrals.</p></div></td></tr>';
        stampUpdated();
      });
  }
  document.getElementById('refTableBody').addEventListener('click', function (e) {
    var btn = e.target.closest('.js-ref-view');
    if (!btn) return;
    var id = parseInt(btn.getAttribute('data-ref-id'), 10) || 0;
    if (id && rowsById[id]) openDetail(rowsById[id]);
  });
  document.getElementById('refDetailClose').addEventListener('click', closeDetail);
  document.getElementById('refDetailDone').addEventListener('click', closeDetail);
  document.getElementById('refDetailModal').addEventListener('click', function (e) {
    if (e.target === e.currentTarget) closeDetail();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeDetail();
  });
  load();
  setInterval(function () { load(); }, 45000);
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
        <td><button class="mc-btn mc-btn--outline mc-btn--info mc-btn--sm" data-edit='<?= htmlspecialchars(json_encode($f), ENT_QUOTES) ?>'>Edit</button></td>
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
