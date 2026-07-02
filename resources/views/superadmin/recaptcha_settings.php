<?php
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/system_settings.php';
require_once BASE_PATH . '/app/includes/recaptcha.php';
$stored = system_settings_get_all($pdo);
$page_title = 'reCAPTCHA Settings';
require_once __DIR__ . '/partials/layout_open.php';
?>
<div class="header-row" style="margin-bottom:24px;"><h2 class="text-h2">reCAPTCHA Settings</h2><p class="text-muted">Bot protection on login after repeated failures. Keys are configured in environment.</p></div>
<div class="mc-card" style="max-width:520px;">
  <p class="text-sm mb-md">Status: <strong><?= recaptcha_is_configured() ? 'Configured' : 'Not configured' ?></strong> (<?= htmlspecialchars(recaptcha_version()) ?>)</p>
  <form id="capForm" style="display:flex;flex-direction:column;gap:12px;">
    <label class="text-sm"><input type="checkbox" name="RECAPTCHA_ENABLED" value="1" <?= ($stored['RECAPTCHA_ENABLED'] ?? '1') === '1' ? 'checked' : '' ?>> Enable reCAPTCHA enforcement when configured</label>
    <button type="submit" class="mc-btn mc-btn--primary" style="align-self:flex-start;">Save</button>
  </form>
</div>
<script>
document.getElementById('capForm').onsubmit=function(e){e.preventDefault();var fd=new FormData(e.target);fd.set('RECAPTCHA_ENABLED',e.target.RECAPTCHA_ENABLED.checked?'1':'0');fetch('<?= ASSET_BASE ?>/app/api/superadmin/system_settings.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>alert(j.message));};
</script>
<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
