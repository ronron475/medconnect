<?php
session_start();
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/core/BhwApplicationService.php';

$token = trim($_GET['token'] ?? '');
$service = new BhwApplicationService($pdo);
$app = $token !== '' ? $service->findByInviteToken($token) : null;
$needsPassword = $app && (string) ($app['status'] ?? '') === 'invited';
$alreadyOnboarding = $app && in_array((string) ($app['status'] ?? ''), ['onboarding', 'requires_documents'], true);
$valid = (bool) $app;
$asset = ASSET_BASE;
$api = ASSET_BASE . '/app/api/bhw/onboarding.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Activate BHW Account — medConnect</title>
  <link rel="stylesheet" href="<?= htmlspecialchars($asset) ?>/assets/css/style.css"/>
  <link rel="stylesheet" href="<?= htmlspecialchars($asset) ?>/assets/css/register.css"/>
  <style>
    .setup-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 90px 24px 60px; position: relative; z-index: 1; }
    .setup-wrapper { width: 100%; max-width: 560px; }
    .setup-card { background: var(--white); border-radius: 20px; padding: 44px 40px 48px; border: 1px solid rgba(209,228,248,0.8); box-shadow: var(--shadow-float); }
    .setup-title { font-size: 20px; font-weight: 800; color: var(--text-dark); margin-bottom: 8px; }
    .setup-sub { font-size: 14px; color: var(--text-mid); line-height: 1.55; margin-bottom: 24px; }
    .setup-group { margin-bottom: 18px; }
    .setup-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
    .setup-group input { width: 100%; height: 52px; padding: 0 14px; border: 1.5px solid #d0e4f7; border-radius: 12px; font-size: 14px; box-sizing: border-box; }
    .setup-btn { width: 100%; height: 54px; border: none; border-radius: 12px; background: linear-gradient(135deg, #0d9488, #14b8a6); color: #fff; font-weight: 700; font-size: 15px; cursor: pointer; margin-top: 8px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
    .setup-btn:disabled { opacity: .55; cursor: not-allowed; }
    .setup-alert { padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 18px; display: none; }
    .setup-alert.error { display: block; background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5; }
    .setup-alert.success { display: block; background: #f0fdf4; color: #16a34a; border: 1px solid #86efac; }
    .setup-hint { font-size: 12px; color: #64748b; margin-top: 6px; line-height: 1.45; }
  </style>
</head>
<body class="landing-page">
<div class="bg-canvas" aria-hidden="true"><canvas id="bubble-canvas"></canvas></div>
<nav class="navbar" id="navbar">
  <div class="nav-container">
    <a href="<?= htmlspecialchars($asset) ?>/index.php" class="nav-logo">
      <img src="<?= htmlspecialchars($asset) ?>/assets/img/medcon_logo.png" alt="medConnect" class="nav-logo-img"/>
      <span class="logo-text">med<span class="logo-accent">Connect</span></span>
    </a>
    <a href="<?= htmlspecialchars($asset) ?>/index.php" class="btn-nav-back">← Back to Sign In</a>
  </div>
</nav>

<div class="setup-page">
  <div class="setup-wrapper">
    <div class="setup-card">
      <?php if (!$valid): ?>
        <h2 class="setup-title">Link Expired or Invalid</h2>
        <p class="setup-sub">This BHW activation link is no longer valid. Ask your administrator to resend the invitation.</p>
        <a href="<?= htmlspecialchars($asset) ?>/index.php" class="setup-btn">Go to Sign In</a>
      <?php elseif ($alreadyOnboarding): ?>
        <h1 class="setup-title">Continue Onboarding</h1>
        <p class="setup-sub">Welcome, <strong><?= htmlspecialchars($app['display_name'] ?? '') ?></strong>. Your password is already set. Continue completing your profile and documents.</p>
        <a class="setup-btn" href="<?= htmlspecialchars($asset) ?>/public/bhw_onboarding.php?token=<?= urlencode($token) ?>">Continue Setup</a>
      <?php else: ?>
        <h1 class="setup-title">Activate Your BHW Account</h1>
        <p class="setup-sub">Welcome, <strong><?= htmlspecialchars($app['display_name'] ?? '') ?></strong><?= !empty($app['barangay_name']) ? ' · ' . htmlspecialchars((string) $app['barangay_name']) : '' ?>. Create your own password to continue. An administrator will never know this password.</p>
        <div id="setup-alert" class="setup-alert" role="alert"></div>
        <form id="activateForm" novalidate>
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"/>
          <div class="setup-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required minlength="12" autocomplete="new-password" placeholder="At least 12 characters"/>
            <p class="setup-hint">Include uppercase, lowercase, a number, and a special character.</p>
          </div>
          <div class="setup-group">
            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="12" autocomplete="new-password" placeholder="Re-enter password"/>
          </div>
          <button type="submit" class="setup-btn" id="activateSubmit">Set Password &amp; Continue</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>window.APP_BASE = <?= json_encode($asset) ?>;</script>
<script src="<?= htmlspecialchars($asset) ?>/assets/js/register.js"></script>
<?php if ($needsPassword): ?>
<script>
(function () {
  var form = document.getElementById('activateForm');
  var alertEl = document.getElementById('setup-alert');
  var btn = document.getElementById('activateSubmit');
  var api = <?= json_encode($api) ?>;

  function showAlert(msg, ok) {
    alertEl.style.display = 'block';
    alertEl.className = 'setup-alert ' + (ok ? 'success' : 'error');
    alertEl.textContent = msg;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var fd = new FormData(form);
    if (fd.get('password') !== fd.get('confirm_password')) {
      showAlert('Passwords do not match.', false);
      return;
    }
    btn.disabled = true;
    btn.textContent = 'Saving…';
    fetch(api + '?action=activate', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          showAlert(data.message || 'Password saved.', true);
          setTimeout(function () {
            window.location.href = (window.APP_BASE || '') + '/public/bhw_onboarding.php?token=' + encodeURIComponent(fd.get('token'));
          }, 700);
        } else {
          showAlert(data.message || 'Activation failed.', false);
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
