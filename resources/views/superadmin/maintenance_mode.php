<?php
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/system_settings.php';
$stored = system_settings_get_all($pdo);
$on = ($stored['MAINTENANCE_MODE'] ?? '0') === '1'
    || ($stored['LANDING_MAINTENANCE_BANNER'] ?? '0') === '1';
$page_title = 'Maintenance Mode';
require_once __DIR__ . '/partials/layout_open.php';
?>
<div class="header-row" style="margin-bottom:24px;"><h2 class="text-h2">Maintenance Mode</h2><p class="text-muted">When enabled, visitors see a maintenance banner on the public landing page and are notified that some features may be unavailable.</p></div>
<div class="mc-card" style="max-width:480px;">
  <p class="text-sm mb-md">Current status: <strong style="color:<?= $on ? '#b45309' : '#16a34a' ?>;"><?= $on ? 'MAINTENANCE ON' : 'LIVE' ?></strong></p>
  <form id="maintForm">
    <label class="text-sm"><input type="checkbox" name="MAINTENANCE_MODE" value="1" <?= $on ? 'checked' : '' ?>> Enable maintenance mode</label>
    <button type="submit" class="mc-btn mc-btn--primary" style="margin-top:16px;">Update</button>
  </form>
</div>
<script>
document.getElementById('maintForm').onsubmit=function(e){e.preventDefault();var fd=new FormData(e.target);fd.set('MAINTENANCE_MODE',e.target.MAINTENANCE_MODE.checked?'1':'0');fetch('<?= ASSET_BASE ?>/app/api/superadmin/system_settings.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{alert(j.message);location.reload();});};
</script>
<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
