<?php
require_once __DIR__ . '/../bootstrap.php';
$asset = ASSET_BASE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
  <link rel="icon" type="image/png" href="<?= $asset ?>/assets/img/medcon_logo.png"/>
  <link rel="shortcut icon" href="<?= $asset ?>/assets/img/medcon_logo.png"/>
  <link rel="apple-touch-icon" href="<?= $asset ?>/assets/img/medcon_logo.png"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verified - MedConnect</title>
    <link rel="stylesheet" href="<?= $asset ?>/assets/css/style.css">
    <style>
        .success-container {
            max-width: 500px;
            margin: 100px auto;
            padding: 40px;
            text-align: center;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .success-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: #10b981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .success-icon svg {
            width: 40px;
            height: 40px;
            stroke: white;
            stroke-width: 3;
        }
        .success-title {
            font-size: 28px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 16px;
        }
        .success-message {
            color: #6b7280;
            margin-bottom: 32px;
            line-height: 1.6;
        }
        .btn-login {
            background: #0d9488;
            color: white;
            padding: 12px 32px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            transition: background 0.2s;
        }
        .btn-login:hover {
            background: #0f766e;
        }
    </style>
</head>
<body>
    <div class="bg-canvas" aria-hidden="true">
        <canvas id="bubble-canvas"></canvas>
    </div>

    <div class="success-container">
        <div class="success-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
        </div>
        
        <h1 class="success-title">Email Verified Successfully!</h1>
        
        <p class="success-message">
            Your email has been verified and your account is now fully active.
            You can log in right away.
        </p>
        
        <a href="/index.php" class="btn-login">Login to Your Account</a>
    </div>

    <script src="<?= $asset ?>/assets/js/register.js"></script>
</body>
</html>

