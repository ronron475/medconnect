<?php
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/superadmin/service.php';
$page_title = 'Database Monitoring';
$api = ASSET_BASE . '/app/api/admin/database_monitoring.php';
require_once __DIR__ . '/partials/layout_open.php';
?>
<div class="header-row" style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:8px;margin-bottom:24px;">
  <div>
    <h2 class="text-h2">Database Monitoring</h2>
    <p class="text-muted">Live database metrics — auto-refreshes every 45 seconds.</p>
  </div>
  <span id="dbMonUpdated" class="text-xs text-muted">Loading…</span>
</div>

<div class="superadmin-stat-grid" style="margin-bottom:24px;" id="dbMonStats">
  <div class="mc-card"><div class="text-h1" id="dbSize">—</div><div class="text-xs text-muted">Total size (MB)</div></div>
  <div class="mc-card"><div class="text-h1" id="dbTables">—</div><div class="text-xs text-muted">Tables tracked</div></div>
  <div class="mc-card"><div class="text-h1" id="dbThreads">—</div><div class="text-xs text-muted">Active connections</div></div>
  <div class="mc-card"><div class="text-h1" id="dbUptime">—</div><div class="text-xs text-muted">Server uptime</div></div>
</div>

<div class="mc-card" style="padding:0;overflow:hidden;">
  <table class="mc-table">
    <thead><tr><th>Table</th><th>Rows (est.)</th><th>Size (MB)</th></tr></thead>
    <tbody id="dbMonBody"><tr><td colspan="3"><div class="mc-table-empty"><p>Loading…</p></div></td></tr></tbody>
  </table>
</div>

<script>
(function () {
  var api = <?= json_encode($api) ?>;
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function fmtUptime(sec) {
    sec = parseInt(sec, 10) || 0;
    var h = Math.floor(sec / 3600);
    var m = Math.floor((sec % 3600) / 60);
    return h + 'h ' + m + 'm';
  }
  function load() {
    fetch(api, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.success) return;
        document.getElementById('dbSize').textContent = j.total_size_mb;
        document.getElementById('dbTables').textContent = (j.tables || []).length;
        document.getElementById('dbThreads').textContent = j.threads;
        document.getElementById('dbUptime').textContent = fmtUptime(j.uptime_seconds);
        var tb = document.getElementById('dbMonBody');
        if (!j.tables || !j.tables.length) {
          tb.innerHTML = '<tr><td colspan="3"><div class="mc-table-empty"><p>No tables found.</p></div></td></tr>';
        } else {
          tb.innerHTML = j.tables.map(function (t) {
            return '<tr><td>' + esc(t.table_name) + '</td><td>' + Number(t.table_rows || 0).toLocaleString() + '</td><td>' + esc(t.size_mb) + '</td></tr>';
          }).join('');
        }
        document.getElementById('dbMonUpdated').textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
      });
  }
  load();
  setInterval(load, 45000);
})();
</script>
<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
