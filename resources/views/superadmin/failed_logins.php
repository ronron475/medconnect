<?php
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/superadmin/schema.php';
superadmin_ensure_schema($pdo);
$page_title = 'Failed Logins';
$page_desc = 'Recorded failed authentication attempts — auto-refreshes every 30 seconds.';
$api = ASSET_BASE . '/app/api/superadmin/security.php?action=failed_logins';
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
    <thead><tr><th>Email</th><th>IP</th><th>Reason</th><th>Time</th></tr></thead>
    <tbody id="secTableBody"><tr><td colspan="4"><div class="mc-table-empty"><p>Loading…</p></div></td></tr></tbody>
  </table>
</div>
<script src="<?= ASSET_BASE ?>/assets/js/superadmin-security-live.js"></script>
<script>
saPollSecurityTable({
  api: <?= json_encode($api) ?>,
  tbodySelector: '#secTableBody',
  colspan: 4,
  updatedId: 'secUpdated',
  columns: [
    { key: 'email' },
    { key: 'ip_address' },
    { key: 'reason' },
    { key: 'created_at' }
  ]
});
</script>
<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
