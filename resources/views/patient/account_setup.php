<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!defined('BASE_PATH')) {
    $d = __DIR__;
    while ($d !== dirname($d)) {
        if (is_file($d . '/mc_load.php')) {
            require_once $d . '/mc_load.php';
            break;
        }
        $d = dirname($d);
    }
}
require_once BASE_PATH . '/app/includes/patient_account_security.php';

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'patient') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
if (!patient_requires_account_setup($pdo, $userId)) {
    header('Location: ' . ASSET_BASE . '/views/patient/dashboard.php');
    exit;
}

$asset = ASSET_BASE;
$csrf = $_SESSION['csrf_token'] ?? '';
$userName = htmlspecialchars(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Account Setup — medConnect</title>
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/style.css"/>
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/register.css"/>
  <style>
    .setup-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 90px 24px 60px; position: relative; z-index: 1; }
    .setup-wrapper { width: 100%; max-width: 560px; }
    .setup-card { background: #fff; border-radius: 20px; padding: 44px 48px; border: 1px solid #e2e8f0; box-shadow: 0 20px 50px rgba(15,23,42,.08); }
    .setup-badge { display: inline-block; background: #ecfdf5; color: #047857; font-size: 12px; font-weight: 700; padding: 6px 12px; border-radius: 999px; margin-bottom: 16px; }
    .setup-title { font-size: 22px; font-weight: 800; color: #0f172a; margin: 0 0 8px; }
    .setup-sub { font-size: 14px; color: #64748b; line-height: 1.6; margin-bottom: 24px; }
    .setup-group { margin-bottom: 18px; }
    .setup-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #334155; }
    .setup-group input[type="password"] { width: 100%; height: 52px; padding: 0 14px; border: 1.5px solid #cbd5e1; border-radius: 12px; font-size: 14px; box-sizing: border-box; }
    .setup-strength-track { height: 8px; border-radius: 999px; background: #e2e8f0; overflow: hidden; margin-top: 10px; }
    .setup-strength-bar { height: 100%; width: 0%; background: #dc2626; transition: width .2s ease, background .2s ease; }
    .setup-strength-text { font-size: 12px; margin-top: 8px; color: #475569; }
    .setup-rules { margin: 10px 0 0; padding: 0 0 0 18px; color: #64748b; font-size: 12px; line-height: 1.55; }
    .setup-rules li.ok { color: #16a34a; }
    .setup-rules li.bad { color: #dc2626; }
    .setup-check { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 12px; font-size: 13px; line-height: 1.5; color: #475569; }
    .setup-check input { margin-top: 3px; flex-shrink: 0; }
    .setup-btn { width: 100%; height: 54px; border: none; border-radius: 12px; background: linear-gradient(135deg, #0d9488, #14b8a6); color: #fff; font-weight: 700; font-size: 15px; cursor: pointer; }
    .setup-btn:disabled { opacity: .55; cursor: not-allowed; }
    .setup-alert { padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 18px; }
    .setup-alert.error { background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5; }
    .setup-alert.success { background: #f0fdf4; color: #16a34a; border: 1px solid #86efac; }
    .setup-note { font-size: 12px; color: #94a3b8; margin-top: 16px; text-align: center; }
  </style>
</head>
<body>
<div class="setup-page">
  <div class="setup-wrapper">
    <div class="setup-card">
      <span class="setup-badge">Required — First Sign-In</span>
      <h1 class="setup-title">Secure Your Account</h1>
      <p class="setup-sub">Hello, <strong><?= $userName ?></strong>. Before accessing your patient dashboard, please set a new password and accept our privacy and terms policies.</p>
      <div id="setup-alert" class="setup-alert" hidden role="alert"></div>
      <form id="accountSetupForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"/>
        <div class="setup-group">
          <label for="password">New Password</label>
          <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password"/>
          <div class="setup-strength-track" aria-hidden="true"><div id="setupStrengthBar" class="setup-strength-bar"></div></div>
          <div id="setupStrengthText" class="setup-strength-text"></div>
          <ul class="setup-rules" id="setupRules">
            <li data-rule="len">At least 12 characters</li>
            <li data-rule="lower">Lowercase letter</li>
            <li data-rule="upper">Uppercase letter</li>
            <li data-rule="digit">Number</li>
            <li data-rule="special">Special character</li>
          </ul>
        </div>
        <div class="setup-group">
          <label for="confirm_password">Confirm Password</label>
          <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password"/>
        </div>
        <label class="setup-check">
          <input type="checkbox" id="accept_privacy" name="accept_privacy" required/>
          <span>I have read and accept the <strong>Privacy Policy</strong> under the Data Privacy Act (RA 10173).</span>
        </label>
        <label class="setup-check">
          <input type="checkbox" id="accept_terms" name="accept_terms" required/>
          <span>I agree to the medConnect <strong>Terms of Service</strong>.</span>
        </label>
        <button type="submit" class="setup-btn" id="setupSubmit">Change Password &amp; Continue</button>
      </form>
      <p class="setup-note">You cannot access appointments, records, or consultations until setup is complete.</p>
    </div>
  </div>
</div>
<script>window.APP_BASE = <?= json_encode($asset) ?>;</script>
<script>
(function () {
  var form = document.getElementById('accountSetupForm');
  var alertEl = document.getElementById('setup-alert');
  var btn = document.getElementById('setupSubmit');
  var pw = document.getElementById('password');
  var bar = document.getElementById('setupStrengthBar');
  var text = document.getElementById('setupStrengthText');
  var rules = document.getElementById('setupRules');

  function showAlert(msg, ok) {
    alertEl.hidden = false;
    alertEl.className = 'setup-alert ' + (ok ? 'success' : 'error');
    alertEl.textContent = msg;
  }

  function setRule(key, ok) {
    if (!rules) return;
    var li = rules.querySelector('li[data-rule="' + key + '"]');
    if (!li) return;
    li.classList.remove('ok', 'bad');
    li.classList.add(ok ? 'ok' : 'bad');
  }

  function updateStrength() {
    if (!pw || !bar || !text) return;
    var v = pw.value || '';
    var okLen = v.length >= 12;
    var okLower = /[a-z]/.test(v);
    var okUpper = /[A-Z]/.test(v);
    var okDigit = /\d/.test(v);
    var okSpecial = /[^A-Za-z0-9]/.test(v);
    setRule('len', okLen);
    setRule('lower', okLower);
    setRule('upper', okUpper);
    setRule('digit', okDigit);
    setRule('special', okSpecial);

    var score = 0;
    if (okLen) score++;
    if (okLower) score++;
    if (okUpper) score++;
    if (okDigit) score++;
    if (okSpecial) score++;

    var pct = Math.round((score / 5) * 100);
    bar.style.width = pct + '%';
    var level = score <= 2 ? 'Weak' : (score <= 4 ? 'Medium' : 'Strong');
    if (score <= 2) bar.style.background = '#dc2626';
    else if (score <= 4) bar.style.background = '#f59e0b';
    else bar.style.background = '#16a34a';
    text.textContent = 'Strength: ' + level;
  }

  if (pw) {
    pw.addEventListener('input', updateStrength);
    updateStrength();
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    alertEl.hidden = true;
    var fd = new FormData(form);
    fd.append('accept_terms', document.getElementById('accept_terms').checked ? '1' : '');
    fd.append('accept_privacy', document.getElementById('accept_privacy').checked ? '1' : '');
    btn.disabled = true;
    btn.textContent = 'Saving…';
    fetch((window.APP_BASE || '') + '/app/api/patient/complete_account_setup.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          showAlert(data.message || 'Account setup complete.', true);
          setTimeout(function () {
            window.location.href = data.redirect || ((window.APP_BASE || '') + '/views/patient/dashboard.php');
          }, 1200);
        } else {
          showAlert(data.message || 'Setup failed.', false);
          btn.disabled = false;
          btn.textContent = 'Change Password & Continue';
        }
      })
      .catch(function () {
        showAlert('Network error. Please try again.', false);
        btn.disabled = false;
        btn.textContent = 'Change Password & Continue';
      });
  });
})();
</script>
</body>
</html>
