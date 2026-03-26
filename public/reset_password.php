<?php
session_start();
require_once __DIR__ . '/../config/db.php';

$token   = trim($_GET['token'] ?? '');
$error   = '';
$success = '';
$valid   = false;
$user    = null;

if (empty($token)) {
    $error = 'Invalid reset link.';
} else {
    $stmt = $pdo->prepare("SELECT id, first_name FROM users WHERE email_verification_code = ? AND email_verification_expiry > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    $valid = (bool)$user;
    if (!$valid) $error = 'This reset link is invalid or has expired. Please request a new one.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    if (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("UPDATE users SET password = ?, email_verification_code = NULL, email_verification_expiry = NULL WHERE id = ?")
            ->execute([$hash, $user['id']]);
        $success = 'Password reset successfully! You can now log in.';
        $valid = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Reset Password — medConnect</title>
  <link rel="stylesheet" href="../assets/css/style.css"/>
  <link rel="stylesheet" href="../assets/css/register.css"/>
  <style>
    /* Page uses register.css animated background */
    .reset-page {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 90px 24px 60px;
      position: relative;
      z-index: 1;
    }
    .reset-wrapper { width: 100%; max-width: 560px; }

    /* Card matches reg-card exactly */
    .reset-card {
      background: var(--white);
      border-radius: 20px;
      padding: 44px 52px 48px;
      border: 1px solid rgba(209,228,248,0.8);
      box-shadow: var(--shadow-float);
      animation: fade-up 0.46s ease both;
      min-height: 400px;
    }

    /* Header matches card-header */
    .reset-header {
      display: flex;
      align-items: center;
      gap: 16px;
      margin-bottom: 32px;
      padding-bottom: 22px;
      border-bottom: 1.5px solid #e8f1fb;
    }
    .reset-icon {
      width: 48px; height: 48px;
      border-radius: 13px;
      background: linear-gradient(135deg, #1a6db5 0%, #3b82f6 100%);
      display: flex; align-items: center; justify-content: center;
      color: #fff; flex-shrink: 0;
      box-shadow: 0 4px 18px rgba(26,109,181,0.30);
    }
    .reset-title { font-size: 18px; font-weight: 800; color: var(--text-dark); margin-bottom: 4px; line-height: 1.2; }
    .reset-sub   { font-size: 13px; color: var(--text-mid); line-height: 1.55; }

    /* Form fields match register inputs */
    .reset-form-group { margin-bottom: 20px; }
    .reset-form-group label {
      display: block;
      font-size: 12.5px;
      font-weight: 600;
      color: var(--text-dark);
      margin-bottom: 7px;
    }
    .reset-input-wrap { position: relative; }
    .reset-input-wrap input {
      width: 100%;
      height: 58px;
      padding: 0 16px 0 46px;
      border: 1.5px solid #d0e4f7;
      border-radius: 12px;
      font-size: 14.5px;
      font-family: inherit;
      color: var(--text-dark);
      background: #fff;
      outline: none;
      transition: border-color .18s, box-shadow .18s;
      box-sizing: border-box;
    }
    .reset-input-wrap input:focus {
      border-color: #3b82f6;
      box-shadow: 0 0 0 3.5px rgba(59,130,246,.12);
      background: #fafcff;
    }
    .reset-input-icon {
      position: absolute; left: 14px; top: 50%;
      transform: translateY(-50%);
      color: #93c5fd; display: flex; pointer-events: none;
    }

    /* Button matches btn-submit */
    .reset-btn {
      width: 100%; height: 58px; border: none;
      border-radius: 12px; cursor: pointer;
      background: linear-gradient(135deg, #1a6db5 0%, #3b82f6 100%);
      color: #fff; font-size: 15.5px; font-weight: 700;
      font-family: inherit;
      box-shadow: 0 4px 18px rgba(26,109,181,.28);
      transition: opacity .2s, transform .15s;
      margin-top: 8px;
      display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .reset-btn:hover { opacity: .91; transform: translateY(-1px); }
    .reset-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }

    /* Alert matches register alerts */
    .reset-alert {
      padding: 12px 16px;
      border-radius: 10px;
      font-size: 13.5px;
      margin-bottom: 22px;
      line-height: 1.5;
    }
    .reset-alert.error   { background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5; }
    .reset-alert.success { background: #f0fdf4; color: #16a34a; border: 1px solid #86efac; }

    .reset-back {
      display: block; text-align: center;
      margin-top: 20px; font-size: 13px; color: var(--text-mid);
    }
    .reset-back a { color: #1a6db5; font-weight: 600; text-decoration: none; }
    .reset-back a:hover { text-decoration: underline; }

    /* Success state */
    .reset-success-icon {
      width: 64px; height: 64px; border-radius: 50%;
      background: linear-gradient(135deg, #16a34a, #22c55e);
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 20px;
      box-shadow: 0 4px 20px rgba(22,163,74,.30);
    }
  </style>
</head>
<body>

<!-- Animated canvas background (same as register page) -->
<div class="bg-canvas" aria-hidden="true">
  <canvas id="bubble-canvas"></canvas>
</div>

<!-- Navbar — matches register page -->
<nav class="navbar" id="navbar">
  <div class="nav-container">
    <a href="index.php" class="nav-logo">
      <span class="logo-icon" aria-hidden="true">
        <svg width="30" height="30" viewBox="0 0 30 30" fill="none">
          <rect width="30" height="30" rx="9" fill="url(#lg2)"/>
          <path d="M15 8v14M8 15h14" stroke="#fff" stroke-width="2.8" stroke-linecap="round"/>
          <defs><linearGradient id="lg2" x1="0" y1="0" x2="30" y2="30" gradientUnits="userSpaceOnUse"><stop stop-color="#1a6db5"/><stop offset="1" stop-color="#3b82f6"/></linearGradient></defs>
        </svg>
      </span>
      <span class="logo-text">med<span class="logo-accent">Connect</span></span>
    </a>
    <a href="index.php" class="btn-nav-back">&#8592; Back to Sign In</a>
  </div>
</nav>

<div class="reset-page">
  <div class="reset-wrapper">
    <div class="reset-card">

      <div class="reset-header">
        <div class="reset-icon">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
          </svg>
        </div>
        <div>
          <div class="reset-title">Reset Password</div>
          <div class="reset-sub">
            <?php if ($success): ?>
              Your password has been updated successfully.
            <?php elseif ($valid): ?>
              Hi <?= htmlspecialchars($user['first_name']) ?>, set your new password below.
            <?php else: ?>
              Password reset — medConnect
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if ($success): ?>
        <div style="text-align:center;padding:20px 0">
          <div class="reset-success-icon">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          </div>
          <div class="reset-alert success" style="text-align:left"><?= htmlspecialchars($success) ?></div>
          <a href="index.php" style="display:inline-flex;align-items:center;justify-content:center;width:100%;height:58px;border-radius:12px;background:linear-gradient(135deg,#1a6db5,#3b82f6);color:#fff;font-size:15px;font-weight:700;text-decoration:none;box-shadow:0 4px 18px rgba(26,109,181,.28)">Sign In Now</a>
        </div>

      <?php elseif ($valid): ?>
        <?php if ($error): ?>
          <div class="reset-alert error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"/>
          <div class="reset-form-group">
            <label>New Password <span style="color:#dc2626">*</span></label>
            <div class="reset-input-wrap">
              <span class="reset-input-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              </span>
              <input type="password" name="password" placeholder="At least 6 characters" required/>
            </div>
          </div>
          <div class="reset-form-group">
            <label>Confirm Password <span style="color:#dc2626">*</span></label>
            <div class="reset-input-wrap">
              <span class="reset-input-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              </span>
              <input type="password" name="confirm_password" placeholder="Repeat your password" required/>
            </div>
          </div>
          <button type="submit" class="reset-btn">Reset Password</button>
        </form>

      <?php else: ?>
        <div class="reset-alert error"><?= htmlspecialchars($error) ?></div>
        <a href="index.php" style="display:inline-flex;align-items:center;justify-content:center;width:100%;height:58px;border-radius:12px;background:linear-gradient(135deg,#1a6db5,#3b82f6);color:#fff;font-size:15px;font-weight:700;text-decoration:none;box-shadow:0 4px 18px rgba(26,109,181,.28)">Back to Sign In</a>
      <?php endif; ?>

    </div>
  </div>
</div>

<script src="../assets/js/register.js"></script>
</body>
</html>
