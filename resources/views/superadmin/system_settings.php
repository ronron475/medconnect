<?php
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/system_settings.php';
$stored = system_settings_get_all($pdo);
$page_title = 'System Settings';
$api = ASSET_BASE . '/app/api/superadmin/system_settings.php';
require_once __DIR__ . '/partials/layout_open.php';
?>
<div class="header-row" style="margin-bottom:24px;"><h2 class="text-h2">Central System Configuration</h2><p class="text-muted">Global platform settings — name, version, timezone, uploads, and policies.</p></div>
<form id="sysForm" class="mc-card" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;">
  <?php
  $fields = [
    'SYSTEM_NAME' => 'System Name',
    'SYSTEM_VERSION' => 'System Version',
    'SYSTEM_TIMEZONE' => 'Timezone',
    'MAX_UPLOAD_MB' => 'Max Upload (MB)',
    'EMAIL_FROM_NAME' => 'Email From Name',
    'EMAIL_FROM_ADDRESS' => 'Email From Address',
  ];
  foreach ($fields as $key => $label):
  ?>
  <label class="text-sm"><?= $label ?>
    <input type="text" name="<?= $key ?>" value="<?= htmlspecialchars($stored[$key] ?? '') ?>" class="mc-btn mc-btn--outline" style="width:100%;background:#fff;text-align:left;margin-top:6px;">
  </label>
  <?php endforeach; ?>
  <div style="grid-column:1/-1;"><button type="submit" class="mc-btn mc-btn--primary">Save All Settings</button></div>
</form>
<script>
document.getElementById('sysForm').onsubmit=function(e){e.preventDefault();fetch(<?= json_encode($api) ?>,{method:'POST',body:new FormData(e.target)}).then(r=>r.json()).then(j=>alert(j.message));};
</script>
<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
