<?php
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/superadmin/schema.php';
superadmin_ensure_schema($pdo);
$page_title = 'Blocked IP Addresses';
$page_desc = 'IPs blocked due to abuse — auto-refreshes every 30 seconds.';
$api = ASSET_BASE . '/app/api/superadmin/security.php?action=blocked_ips';
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
    <thead><tr><th>IP</th><th>Reason</th><th>Type</th><th>Until</th><th>Blocked</th><th></th></tr></thead>
    <tbody id="secTableBody"><tr><td colspan="6"><div class="mc-table-empty"><p>Loading…</p></div></td></tr></tbody>
  </table>
</div>
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
          tb.innerHTML = '<tr><td colspan="6"><div class="mc-table-empty"><p>No blocked IPs.</p></div></td></tr>';
          return;
        }
        tb.innerHTML = j.rows.map(function (row) {
          return '<tr><td><strong>' + esc(row.ip_address) + '</strong></td><td>' + esc(row.reason || '—') + '</td><td>' + (row.is_permanent ? 'Permanent' : 'Temporary') + '</td><td>' + esc(row.blocked_until || '—') + '</td><td class="text-xs">' + esc(row.created_at) + '</td><td><button type="button" class="mc-btn mc-btn--outline js-unblock" data-ip="' + esc(row.ip_address) + '" style="padding:2px 8px;font-size:10px;">Unblock</button></td></tr>';
        }).join('');
        document.querySelectorAll('.js-unblock').forEach(function (btn) {
          btn.onclick = function () {
            var fd = new FormData();
            fd.append('action', 'unblock_ip');
            fd.append('ip', btn.dataset.ip);
            fetch(blockApi, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (res) { alert(res.message); if (res.success) load(); });
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
