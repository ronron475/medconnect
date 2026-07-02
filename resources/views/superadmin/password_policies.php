<?php
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/system_settings.php';
$stored = system_settings_get_all($pdo);
$page_title = 'Password Policies';
require_once __DIR__ . '/partials/layout_open.php';
?>
<div class="header-row" style="margin-bottom:24px;"><h2 class="text-h2">Password Policies</h2><p class="text-muted">Configure minimum password requirements for all accounts.</p></div>
<form id="pwForm" class="mc-card" style="display:flex;flex-direction:column;gap:16px;max-width:480px;">
  <label class="text-sm">Minimum Length<input type="number" name="PASSWORD_MIN_LENGTH" value="<?= htmlspecialchars($stored['PASSWORD_MIN_LENGTH'] ?? '8') ?>" class="mc-btn mc-btn--outline" style="width:100%;background:#fff;text-align:left;margin-top:6px;"></label>
  <label class="text-sm"><input type="checkbox" name="PASSWORD_REQUIRE_UPPERCASE" value="1" <?= ($stored['PASSWORD_REQUIRE_UPPERCASE'] ?? '1') === '1' ? 'checked' : '' ?>> Require uppercase letter</label>
  <label class="text-sm"><input type="checkbox" name="PASSWORD_REQUIRE_NUMBER" value="1" <?= ($stored['PASSWORD_REQUIRE_NUMBER'] ?? '1') === '1' ? 'checked' : '' ?>> Require number</label>
  <button type="submit" class="mc-btn mc-btn--primary" style="align-self:flex-start;">Save Policies</button>
</form>
<script>
document.getElementById('pwForm').onsubmit=function(e){e.preventDefault();var fd=new FormData(e.target);fd.set('PASSWORD_REQUIRE_UPPERCASE',e.target.PASSWORD_REQUIRE_UPPERCASE.checked?'1':'0');fd.set('PASSWORD_REQUIRE_NUMBER',e.target.PASSWORD_REQUIRE_NUMBER.checked?'1':'0');fetch('<?= ASSET_BASE ?>/app/api/superadmin/system_settings.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>alert(j.message));};
</script>
<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
