<?php
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/superadmin/schema.php';
superadmin_ensure_schema($pdo);
$page_title = 'Active Sessions';
$page_desc = 'Users with activity in the last 30 minutes. Terminate suspicious sessions.';
$api = ASSET_BASE . '/app/api/superadmin/security.php';
$listApi = $api . '?action=sessions';
$raw = $pdo->query('
    SELECT s.id, s.session_id, u.email, s.role, s.ip_address, s.browser, s.device, s.last_activity
    FROM active_sessions s
    JOIN users u ON u.id = s.user_id
    WHERE s.last_activity >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    ORDER BY s.last_activity DESC
    LIMIT 200
')->fetchAll(PDO::FETCH_ASSOC);
require_once __DIR__ . '/partials/layout_open.php';
?>
<div class="header-row" style="margin-bottom:24px;display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:8px;">
  <div>
    <h2 class="text-h2"><?= htmlspecialchars($page_title) ?></h2>
    <?php if (!empty($page_desc)): ?><p class="text-muted"><?= htmlspecialchars($page_desc) ?></p><?php endif; ?>
  </div>
  <span id="sessUpdated" class="text-xs text-muted">Loading…</span>
</div>
<div class="mc-card" style="padding:0;overflow:hidden;overflow-x:auto;">
  <table class="mc-table">
    <thead>
      <tr>
        <th>User</th>
        <th>Role</th>
        <th>IP</th>
        <th>Browser</th>
        <th>Device</th>
        <th>Last Activity</th>
        <th></th>
      </tr>
    </thead>
    <tbody id="sessTableBody">
      <?php if (empty($raw)): ?>
      <tr><td colspan="7"><div class="mc-table-empty"><p>No active sessions in the last 30 minutes.</p></div></td></tr>
      <?php else: foreach ($raw as $row): ?>
      <tr>
        <td class="text-sm"><?= htmlspecialchars($row['email'] ?? '—') ?></td>
        <td><?= htmlspecialchars($row['role'] ?? '—') ?></td>
        <td><?= htmlspecialchars($row['ip_address'] ?? '—') ?></td>
        <td><?= htmlspecialchars($row['browser'] ?? '—') ?></td>
        <td><?= htmlspecialchars($row['device'] ?? '—') ?></td>
        <td class="text-xs text-muted"><?= htmlspecialchars($row['last_activity'] ?? '—') ?></td>
        <td>
          <button type="button" class="mc-btn mc-btn--outline js-terminate" data-id="<?= (int) $row['id'] ?>"
                  style="padding:4px 10px;font-size:11px;">Terminate</button>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<script>
(function () {
  var api = <?= json_encode($api) ?>;
  var listApi = <?= json_encode($listApi) ?>;
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function bindTerminate() {
    document.querySelectorAll('.js-terminate').forEach(function (btn) {
      btn.onclick = function () {
        if (!confirm('Terminate this session?')) return;
        var fd = new FormData();
        fd.append('action', 'terminate_session');
        fd.append('session_id', btn.getAttribute('data-id'));
        fetch(api, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (j) { alert(j.message); if (j.success) load(); });
      };
    });
  }
  function load() {
    fetch(listApi, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var tb = document.getElementById('sessTableBody');
        if (!j.success || !j.rows) return;
        var rows = j.rows.filter(function (row) {
          if (!row.last_activity) return false;
          return (Date.now() - new Date(String(row.last_activity).replace(' ', 'T')).getTime()) < 30 * 60 * 1000;
        });
        if (!rows.length) {
          tb.innerHTML = '<tr><td colspan="7"><div class="mc-table-empty"><p>No active sessions in the last 30 minutes.</p></div></td></tr>';
        } else {
          tb.innerHTML = rows.map(function (row) {
            return '<tr><td class="text-sm">' + esc(row.email) + '</td><td>' + esc(row.role) + '</td><td>' + esc(row.ip_address) + '</td><td>' + esc(row.browser) + '</td><td>' + esc(row.device) + '</td><td class="text-xs text-muted">' + esc(row.last_activity) + '</td><td><button type="button" class="mc-btn mc-btn--outline js-terminate" data-id="' + row.id + '" style="padding:4px 10px;font-size:11px;">Terminate</button></td></tr>';
          }).join('');
          bindTerminate();
        }
        document.getElementById('sessUpdated').textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
      });
  }
  bindTerminate();
  setInterval(load, 30000);
})();
</script>
<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
