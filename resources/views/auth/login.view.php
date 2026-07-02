<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>MedConnect — Sign In</title>
  <?php
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
    $asset = ASSET_BASE;
  ?>
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/login.css" />
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/responsive.css" />
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/login-loading.css" />
</head>
<body>

<!-- NAV -->
<nav class="nav">
  <div class="nav-logo">
    <div class="logo-crop">
      <img src="../logo.png" alt="MedConnect" class="logo-img" />
    </div>
    <span class="logo-text">
      <span class="logo-med">Med</span><span class="logo-connect">Connect</span>
    </span>
  </div>
  <div class="nav-links">
    <a href="#">How It Works</a>
    <a href="#">Specialties</a>
    <a href="#" class="active">Our Doctors</a>
    <a href="#">Security</a>
  </div>
  <div class="nav-actions">
    <a href="#" class="nav-signin">Sign in</a>
    <a href="#" class="btn-consult">Book Consultation</a>
  </div>
</nav>

<!-- HERO -->
<section class="hero">
  <div class="hero-overlay"></div>
  <div class="hero-content">
    <!-- Floating: Sign-in Card -->
    <div class="signin-card">
      <h2 class="card-title">Start Your Consultation</h2>
      <p class="card-sub">Sign in to connect with a doctor now</p>

      <div class="alert" id="alert" role="alert"></div>

      <form id="login-form" novalidate>
        <div class="form-group">
          <label for="role">Login As</label>
          <div class="input-wrap">
            <span class="input-icon" aria-hidden="true">
              <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </span>
            <select id="role" name="role" required>
              <option value="patient">Patient</option>
              <option value="provider">Healthcare Provider</option>
              <option value="admin">Administrator</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label for="email">Email Address</label>
          <div class="input-wrap">
            <span class="input-icon" aria-hidden="true">
              <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
            </span>
            <input type="email" id="email" name="email" placeholder="you@example.com" autocomplete="email" required />
          </div>
          <span class="field-error" id="email-error"></span>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <div class="input-wrap">
            <span class="input-icon" aria-hidden="true">
              <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </span>
            <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required />
            <button type="button" class="toggle-pwd" id="toggle-pwd" aria-label="Show password">
              <svg id="eye-icon" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
          <span class="field-error" id="password-error"></span>
        </div>

        <div class="forgot-row">
          <a href="#" class="forgot-link">Forgot password?</a>
        </div>

        <div class="forgot-row" style="justify-content: space-between; gap: 12px;">
          <label style="display:flex;align-items:center;gap:8px;font-size:.92rem;cursor:pointer;user-select:none;">
            <input type="checkbox" id="remember-me" name="remember_me" />
            Remember me
          </label>
        </div>

        <div class="form-group" id="mc-recaptcha-v2" hidden>
          <label>Verification</label>
          <div class="input-wrap" style="display:block;padding:10px 0">
            <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars((string) (defined('RECAPTCHA_SITE_KEY') ? RECAPTCHA_SITE_KEY : '')) ?>"></div>
          </div>
          <span class="field-error" id="captcha-error"></span>
        </div>

        <button type="submit" class="btn-signin" id="submit-btn">
          <span id="btn-text">Sign In &amp; Consult Now</span>
          <span id="btn-spinner" class="spinner" hidden></span>
        </button>
      </form>

      <p class="card-register">New patient? <a href="register.controller.php">Create a free account</a></p>

      <div class="card-badges">
        <span class="badge">
          <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          HIPAA Secure
        </span>
        <span class="badge">
          <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          256-bit Encrypted
        </span>
        <span class="badge">
          <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          Verified Doctors
        </span>
      </div>
    </div>

  </div>
</section>

<script>
  window.ASSET_BASE = <?= json_encode(ASSET_BASE) ?>;
  window.APP_BASE = window.ASSET_BASE;
  window.RECAPTCHA_SITE_KEY = <?= json_encode((string) (defined('RECAPTCHA_SITE_KEY') ? RECAPTCHA_SITE_KEY : '')) ?>;
  window.RECAPTCHA_VERSION = <?= json_encode((string) (defined('RECAPTCHA_VERSION') ? RECAPTCHA_VERSION : 'v3')) ?>;
</script>
<?php if (!empty((string) (defined('RECAPTCHA_SITE_KEY') ? RECAPTCHA_SITE_KEY : '')) && !empty((string) (defined('RECAPTCHA_SECRET_KEY') ? RECAPTCHA_SECRET_KEY : ''))): ?>
  <?php if (strtolower((string) (defined('RECAPTCHA_VERSION') ? RECAPTCHA_VERSION : 'v3')) === 'v2'): ?>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <?php else: ?>
    <script src="https://www.google.com/recaptcha/api.js?render=<?= htmlspecialchars((string) (defined('RECAPTCHA_SITE_KEY') ? RECAPTCHA_SITE_KEY : '')) ?>"></script>
  <?php endif; ?>
<?php endif; ?>
<script src="<?= $asset ?>/assets/js/login-loading.js"></script>
<script src="<?= $asset ?>/assets/js/login.js"></script>
</body>
</html>
