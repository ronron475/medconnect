<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/includes/patient_account_security.php';

$token = trim($_GET['token'] ?? '');
$user = $token !== '' ? patient_find_by_setup_token($pdo, $token) : null;
$valid = (bool) $user;
$asset = ASSET_BASE;
$csrf = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Set Up Password — medConnect</title>
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/style.css"/>
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/register.css"/>
  <style>
    .setup-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 90px 24px 60px; position: relative; z-index: 1; }
    .setup-wrapper { width: 100%; max-width: 560px; }
    .setup-card { background: var(--white); border-radius: 20px; padding: 44px 52px 48px; border: 1px solid rgba(209,228,248,0.8); box-shadow: var(--shadow-float); }
    .setup-title { font-size: 20px; font-weight: 800; color: var(--text-dark); margin-bottom: 8px; }
    .setup-sub { font-size: 14px; color: var(--text-mid); line-height: 1.55; margin-bottom: 24px; }
    .setup-group { margin-bottom: 18px; }
    .setup-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
    .setup-group input[type="password"],
    .setup-group input[type="text"].setup-pw-input { width: 100%; height: 52px; padding: 0 44px 0 14px; border: 1.5px solid #d0e4f7; border-radius: 12px; font-size: 14px; box-sizing: border-box; }
    .setup-pw-wrap { position: relative; }
    .setup-pw-wrap .toggle-pwd {
      position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
      width: 36px; height: 36px; min-width: 36px; padding: 0; border: none; background: transparent;
      color: #94a3b8; border-radius: 8px; cursor: pointer;
      display: inline-flex; align-items: center; justify-content: center;
    }
    .setup-pw-wrap .toggle-pwd:hover { color: #0d9488; background: rgba(13,148,136,.08); }
    .setup-check { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 12px; font-size: 13px; line-height: 1.5; }
    .setup-check input { margin-top: 3px; }
    .setup-btn { width: 100%; height: 54px; border: none; border-radius: 12px; background: linear-gradient(135deg, #0d9488, #14b8a6); color: #fff; font-weight: 700; font-size: 15px; cursor: pointer; margin-top: 8px; }
    .setup-btn:disabled { opacity: .55; cursor: not-allowed; }
    .setup-alert { padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 18px; display: none; }
    .setup-alert.error { display: block; background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5; }
    .setup-alert.success { display: block; background: #f0fdf4; color: #16a34a; border: 1px solid #86efac; }
    .setup-invalid { padding: 24px; text-align: center; color: #64748b; }
  </style>
</head>
<body class="landing-page">
<div class="bg-canvas" aria-hidden="true"><canvas id="bubble-canvas"></canvas></div>
<nav class="navbar" id="navbar">
  <div class="nav-container">
    <a href="<?= $asset ?>/index.php" class="nav-logo">
      <img src="<?= $asset ?>/assets/img/medcon_logo.png" alt="medConnect" class="nav-logo-img"/>
      <span class="logo-text">med<span class="logo-accent">Connect</span></span>
    </a>
    <a href="<?= $asset ?>/index.php" class="btn-nav-back">← Back to Sign In</a>
  </div>
</nav>

<div class="setup-page">
  <div class="setup-wrapper">
    <div class="setup-card">
      <?php if (!$valid): ?>
        <div class="setup-invalid">
          <h2 class="setup-title">Link Expired or Invalid</h2>
          <p class="setup-sub">This password setup link is no longer valid. Please contact your Barangay Health Worker or use the temporary password provided at registration to sign in.</p>
          <a href="<?= $asset ?>/index.php" class="setup-btn" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;width:auto;padding:0 24px;">Go to Sign In</a>
        </div>
      <?php else: ?>
        <h1 class="setup-title">Complete Your Account</h1>
        <p class="setup-sub">Welcome, <strong><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></strong>. Create your password and accept our policies to activate your patient portal.</p>
        <div id="setup-alert" class="setup-alert" role="alert" hidden></div>
        <form id="setupForm" novalidate>
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"/>
          <div class="setup-group">
            <label for="password">New Password</label>
            <div class="setup-pw-wrap">
              <input type="password" id="password" name="password" class="setup-pw-input" required minlength="8" autocomplete="new-password" placeholder="Minimum 8 characters"/>
              <button type="button" class="toggle-pwd" data-mc-pw-toggle="password" aria-label="Show password" aria-pressed="false">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-10-7-10-7a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/><line x1="2" y1="2" x2="22" y2="22"/></svg>
              </button>
            </div>
          </div>
          <div class="setup-group">
            <label for="confirm_password">Confirm Password</label>
            <div class="setup-pw-wrap">
              <input type="password" id="confirm_password" name="confirm_password" class="setup-pw-input" required minlength="8" autocomplete="new-password" placeholder="Re-enter password"/>
              <button type="button" class="toggle-pwd" data-mc-pw-toggle="confirm_password" aria-label="Show password" aria-pressed="false">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-10-7-10-7a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/><line x1="2" y1="2" x2="22" y2="22"/></svg>
              </button>
            </div>
          </div>
          <label class="setup-check">
            <input type="checkbox" id="accept_privacy" name="accept_privacy" required/>
            <span>I have read and accept the <strong>Privacy Policy</strong> (RA 10173 Data Privacy Act).</span>
          </label>
          <label class="setup-check">
            <input type="checkbox" id="accept_terms" name="accept_terms" required/>
            <span>I agree to the medConnect <strong>Terms of Service</strong>.</span>
          </label>
          <button type="submit" class="setup-btn" id="setupSubmit">Set Password &amp; Continue</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>window.APP_BASE = <?= json_encode($asset) ?>;</script>
<script src="<?= $asset ?>/assets/js/password-visibility.js?v=1"></script>
<script src="<?= $asset ?>/assets/js/register.js"></script>
<?php if ($valid): ?>
<script>
(function () {
  var form = document.getElementById('setupForm');
  var alertEl = document.getElementById('setup-alert');
  var btn = document.getElementById('setupSubmit');

  function showAlert(msg, ok) {
    alertEl.hidden = false;
    alertEl.className = 'setup-alert ' + (ok ? 'success' : 'error');
    alertEl.textContent = msg;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    alertEl.hidden = true;
    var fd = new FormData(form);
    if (!fd.get('accept_terms') || !fd.get('accept_privacy')) {
      showAlert('Please accept the Privacy Policy and Terms of Service.', false);
      return;
    }
    btn.disabled = true;
    btn.textContent = 'Saving…';
    fetch((window.APP_BASE || '') + '/app/api/patient/setup_password_token.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          showAlert(data.message || 'Password set successfully.', true);
          form.reset();
          setTimeout(function () {
            window.location.href = data.redirect || ((window.APP_BASE || '') + '/index.php?setup_complete=1');
          }, 1500);
        } else {
          showAlert(data.message || 'Could not set password.', false);
          btn.disabled = false;
          btn.textContent = 'Set Password & Continue';
        }
      })
      .catch(function () {
        showAlert('Network error. Please try again.', false);
        btn.disabled = false;
        btn.textContent = 'Set Password & Continue';
      });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
