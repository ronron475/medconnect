<?php
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/system_settings.php';
$stored = system_settings_get_all($pdo);
$page_title = 'Email Configuration';
require_once __DIR__ . '/partials/layout_open.php';
?>
<div class="header-row" style="margin-bottom:24px;"><h2 class="text-h2">Email Configuration</h2><p class="text-muted">Outbound email identity for system notifications.</p></div>
<form id="emailForm" class="mc-card" style="max-width:480px;display:flex;flex-direction:column;gap:12px;">
  <label class="text-sm">From Name<input name="EMAIL_FROM_NAME" value="<?= htmlspecialchars($stored['EMAIL_FROM_NAME'] ?? '') ?>" class="mc-btn mc-btn--outline" style="width:100%;background:#fff;text-align:left;margin-top:6px;"></label>
  <label class="text-sm">From Address<input name="EMAIL_FROM_ADDRESS" type="email" value="<?= htmlspecialchars($stored['EMAIL_FROM_ADDRESS'] ?? '') ?>" class="mc-btn mc-btn--outline" style="width:100%;background:#fff;text-align:left;margin-top:6px;"></label>
  <button type="submit" class="mc-btn mc-btn--primary" style="align-self:flex-start;">Save</button>
</form>
<script>
document.getElementById('emailForm').onsubmit=function(e){e.preventDefault();fetch('<?= ASSET_BASE ?>/app/api/superadmin/system_settings.php',{method:'POST',body:new FormData(e.target)}).then(r=>r.json()).then(j=>alert(j.message));};
</script>
<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
