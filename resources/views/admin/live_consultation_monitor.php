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
require_once __DIR__ . '/_portal_access.php';

$page_title = 'Live Consultation Monitoring';
$api = ASSET_BASE . '/app/api/admin/monitoring.php?type=live';

require_once __DIR__ . '/partials/layout_open.php';
?>

<div class="header-row" style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:8px;margin-bottom:24px;">
  <div>
    <h2 class="text-h2">Live Consultation Monitoring</h2>
    <p class="text-muted">Auto-refreshes every 30 seconds from live consultation data.</p>
  </div>
  <span id="liveConsultUpdated" class="text-xs text-muted">Loading…</span>
</div>

<div class="mc-card" style="padding:0;overflow:hidden;" id="liveConsultPanel" data-api="<?= htmlspecialchars($api) ?>">
  <table class="mc-table">
    <thead><tr><th>Provider</th><th>Patient</th><th>Start Time</th><th>Status</th></tr></thead>
    <tbody id="liveConsultBody">
      <tr><td colspan="4"><div class="mc-table-empty"><p>Loading…</p></div></td></tr>
    </tbody>
  </table>
</div>

<script>
(function () {
  var api = document.getElementById('liveConsultPanel').getAttribute('data-api');
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function load() {
    fetch(api, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var tb = document.getElementById('liveConsultBody');
        if (!j.success || !j.rows || !j.rows.length) {
          tb.innerHTML = '<tr><td colspan="4"><div class="mc-table-empty"><p>No active consultations right now.</p></div></td></tr>';
        } else {
          tb.innerHTML = j.rows.map(function (row) {
            return '<tr><td><strong>' + esc(row.provider_name) + '</strong></td><td>' + esc(row.patient_name) + '</td><td>' + esc(row.started_label) + '</td><td><span class="mc-badge">' + esc(row.status) + '</span></td></tr>';
          }).join('');
        }
        document.getElementById('liveConsultUpdated').textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) + ' · ' + (j.count || 0) + ' active';
      });
  }
  load();
  setInterval(load, 30000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) load(); });
})();
</script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
