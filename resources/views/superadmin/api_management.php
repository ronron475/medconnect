<?php
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/superadmin/schema.php';
superadmin_ensure_schema($pdo);
$page_title = 'API Management';
$apiRows = $pdo->query('SELECT api_key, api_value, updated_at FROM api_settings ORDER BY api_key')->fetchAll(PDO::FETCH_ASSOC);
$api = ASSET_BASE . '/app/api/superadmin/api_settings.php';
require_once __DIR__ . '/partials/layout_open.php';
?>
<div class="header-row" style="margin-bottom:24px;">
  <h2 class="text-h2">API Management</h2>
  <p class="text-muted">Configure AI, video, and email service integration endpoints.</p>
</div>
<form id="apiForm" class="mc-card" style="display:flex;flex-direction:column;gap:12px;max-width:640px;">
  <?php if (empty($apiRows)): ?>
  <p class="text-sm text-muted">No API settings found. Defaults are created on first load.</p>
  <?php else: foreach ($apiRows as $row): ?>
  <label class="text-sm"><?= htmlspecialchars($row['api_key']) ?>
    <input type="text" name="<?= htmlspecialchars($row['api_key']) ?>" value="<?= htmlspecialchars($row['api_value']) ?>"
           class="mc-btn mc-btn--outline" style="width:100%;background:#fff;text-align:left;margin-top:6px;">
    <?php if (!empty($row['updated_at'])): ?>
    <span class="text-xs text-muted">Last updated <?= date('M j, Y g:i A', strtotime($row['updated_at'])) ?></span>
    <?php endif; ?>
  </label>
  <?php endforeach; endif; ?>
  <button type="submit" class="mc-btn mc-btn--primary" style="align-self:flex-start;">Save API Settings</button>
</form>
<script>
document.getElementById('apiForm').onsubmit = function (e) {
  e.preventDefault();
  fetch(<?= json_encode($api) ?>, { method: 'POST', body: new FormData(e.target) })
    .then(function (r) { return r.json(); })
    .then(function (j) { alert(j.message || 'Done'); if (j.success) location.reload(); });
};
</script>
<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
