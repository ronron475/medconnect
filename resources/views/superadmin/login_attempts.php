<?php
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/login_security.php';
login_security_ensure_schema($pdo);
$page_title = 'Login Attempts';
$page_desc = 'Successful login events — auto-refreshes every 30 seconds.';
$api = ASSET_BASE . '/app/api/superadmin/security.php?action=login_events';
$blockApi = ASSET_BASE . '/app/api/superadmin/security.php';
require_once __DIR__ . '/partials/layout_open.php';
?>
<div class="header-row" style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:8px;margin-bottom:24px;">
  <div>
    <h2 class="text-h2"><?= htmlspecialchars($page_title) ?></h2>
    <?php if (!empty($page_desc)): ?><p class="text-muted"><?= htmlspecialchars($page_desc) ?></p><?php endif; ?>
  </div>
  <span id="secUpdated" class="text-xs text-muted">Loading…</span>
</div>
<div class="mc-card" style="padding:0;overflow:hidden;overflow-x:auto;">
  <table class="mc-table">
    <thead><tr><th>User</th><th>Role</th><th>IP</th><th>Browser</th><th>OS</th><th>Device</th><th>Time</th><th></th></tr></thead>
    <tbody id="secTableBody"><tr><td colspan="8"><div class="mc-table-empty"><p>Loading…</p></div></td></tr></tbody>
  </table>
</div>
<script src="<?= ASSET_BASE ?>/assets/js/superadmin-security-live.js"></script>
<script>
(function () {
  var api = <?= json_encode($api) ?>;
  var blockApi = <?= json_encode($blockApi) ?>;
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function load() {
    fetch(api, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var tb = document.getElementById('secTableBody');
        if (!j.success || !j.rows || !j.rows.length) {
          tb.innerHTML = '<tr><td colspan="8"><div class="mc-table-empty"><p>No login events recorded.</p></div></td></tr>';
          return;
        }
        tb.innerHTML = j.rows.map(function (row) {
          var name = ((row.first_name || '') + ' ' + (row.last_name || '')).trim() || row.email || '—';
          var ip = row.ip_address || '';
          return '<tr><td>' + esc(name) + '</td><td>' + esc(row.role) + '</td><td>' + esc(ip) + '</td><td>' + esc(row.browser) + '</td><td>' + esc(row.os) + '</td><td>' + esc(row.device_type) + '</td><td class="text-xs">' + esc(row.created_at) + '</td><td>' + (ip ? '<button type="button" class="mc-btn mc-btn--outline js-block" data-ip="' + esc(ip) + '" style="padding:2px 8px;font-size:10px;">Block IP</button>' : '') + '</td></tr>';
        }).join('');
        document.querySelectorAll('.js-block').forEach(function (btn) {
          btn.onclick = function () {
            if (!confirm('Block IP ' + btn.dataset.ip + '?')) return;
            var fd = new FormData();
            fd.append('action', 'block_ip');
            fd.append('ip', btn.dataset.ip);
            fd.append('reason', 'Blocked from login attempts');
            fetch(blockApi, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (res) { alert(res.message); });
          };
        });
        document.getElementById('secUpdated').textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
      });
  }
  load();
  setInterval(load, 30000);
})();
</script>
<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
