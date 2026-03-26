<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$email = strtolower(trim($_POST['email'] ?? ''));

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

// Always return success to prevent email enumeration
$stmt = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE email = ? AND role = 'patient' LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch();

if ($user) {
    $token  = bin2hex(random_bytes(30)); // 60-char hex token, fits varchar(128)
    $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $pdo->prepare("UPDATE users SET email_verification_code = ?, email_verification_expiry = ? WHERE id = ?")
        ->execute([$token, $expiry, $user['id']]);

    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // If accessed via localhost, replace with actual server IP so mobile can reach it
    if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
        $server_ip = gethostbyname(gethostname());
        $host = $server_ip . ':' . ($_SERVER['SERVER_PORT'] ?? '80');
        // Remove default port 80
        $host = str_replace(':80', '', $host);
    }
    $resetUrl = $protocol . '://' . $host . '/medconnect/public/reset_password.php?token=' . urlencode($token);
    $fullName = $user['first_name'] . ' ' . $user['last_name'];

    $mail = initMailer();
    if ($mail) {
        try {
            $mail->addAddress($email);
            $mail->Subject = 'Reset Your MedConnect Password';
            $mail->isHTML(true);
            $mail->Body = "
            <div style='font-family:Arial,sans-serif;max-width:520px;margin:0 auto'>
              <div style='background:#1a6db5;padding:24px;text-align:center;border-radius:10px 10px 0 0'>
                <h2 style='color:#fff;margin:0'>medConnect</h2>
                <p style='color:rgba(255,255,255,0.8);margin:4px 0 0;font-size:13px'>City Health Office of Bago City</p>
              </div>
              <div style='background:#f8fbff;padding:32px;border-radius:0 0 10px 10px;border:1px solid #d0e4f7'>
                <h3 style='color:#0f172a;margin-top:0'>Password Reset Request</h3>
                <p style='color:#475569'>Hi {$fullName},</p>
                <p style='color:#475569'>We received a request to reset your password. Click the button below to set a new password.</p>
                <div style='text-align:center;margin:28px 0'>
                  <a href='{$resetUrl}' style='background:linear-gradient(135deg,#1a6db5,#3b82f6);color:#fff;padding:14px 32px;border-radius:10px;text-decoration:none;font-weight:700;font-size:15px'>Reset Password</a>
                </div>
                <p style='color:#94a3b8;font-size:12px'>This link expires in <strong>1 hour</strong>. If you didn't request this, ignore this email.</p>
                <p style='color:#94a3b8;font-size:11px;word-break:break-all'>Or copy this link: {$resetUrl}</p>
              </div>
            </div>";
            $mail->AltBody = "Reset your MedConnect password: {$resetUrl} (expires in 1 hour)";
            $mail->send();
        } catch (Exception $e) {
            error_log('Password reset email failed: ' . $e->getMessage());
        }
    }
}

// Always return success (don't reveal if email exists)
echo json_encode(['success' => true, 'message' => 'If that email is registered, a reset link has been sent. Please check your inbox.']);
