<?php
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/superadmin/schema.php';
superadmin_ensure_schema($pdo);
$page_title = 'Security Logs';
$page_desc = 'Enterprise security audit trail — auto-refreshes every 30 seconds.';
$api = ASSET_BASE . '/app/api/superadmin/security.php?action=logs';
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
    <thead><tr><th>User</th><th>Role</th><th>Action</th><th>Module</th><th>Status</th><th>IP</th><th>Time</th></tr></thead>
    <tbody id="secTableBody"><tr><td colspan="7"><div class="mc-table-empty"><p>Loading…</p></div></td></tr></tbody>
  </table>
</div>
<script src="<?= ASSET_BASE ?>/assets/js/superadmin-security-live.js"></script>
<script>
saPollSecurityTable({
  api: <?= json_encode($api) ?>,
  tbodySelector: '#secTableBody',
  colspan: 7,
  updatedId: 'secUpdated',
  columns: [
    { key: function (r) { return ((r.first_name || '') + ' ' + (r.last_name || '')).trim() || 'System'; } },
    { key: 'role' },
    { key: 'action' },
    { key: 'module' },
    { key: 'status' },
    { key: 'ip_address' },
    { key: 'created_at' }
  ]
});
</script>
<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
