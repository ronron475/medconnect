<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');
require_once dirname(__DIR__, 3) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrf = (string) ($_POST['csrf_token'] ?? '');
if (empty($csrf) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token. Please refresh and try again.']);
    exit;
}

$email = strtolower(trim($_POST['email'] ?? ''));

if (empty($email) || preg_match('/\s/', $email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid Gmail address (example@gmail.com). Only Gmail accounts are accepted for registration.']);
    exit;
}

// Gmail-only allowlist (do not rely on client validation)
if (!preg_match('/^[A-Za-z0-9._%+\-]+@gmail\.com$/i', $email)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid Gmail address (example@gmail.com). Only Gmail accounts are accepted for registration.']);
    exit;
}

// Check if email already registered
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'This Gmail address is already registered. Please try another email address.']);
    exit;
}

// Rate limit — max 3 OTP requests per email per 10 minutes
if (isset($_SESSION['otp_email']) && $_SESSION['otp_email'] === $email) {
    $attempts = $_SESSION['otp_attempts'] ?? 0;
    $last_sent = $_SESSION['otp_last_sent'] ?? 0;
    if ($attempts >= 3 && (time() - $last_sent) < 600) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many OTP requests. Please wait 10 minutes before trying again.']);
        exit;
    }
    if ((time() - $last_sent) >= 600) {
        $_SESSION['otp_attempts'] = 0; // reset after cooldown
    }
}

// Generate 6-digit OTP
$otp    = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expiry = time() + 600; // 10 minutes

// Store in session
$_SESSION['otp_email']     = $email;
$_SESSION['otp_code']      = password_hash($otp, PASSWORD_BCRYPT);
$_SESSION['otp_expiry']    = $expiry;
$_SESSION['otp_verified']  = false;
$_SESSION['otp_attempts']  = ($_SESSION['otp_attempts'] ?? 0) + 1;
$_SESSION['otp_last_sent'] = time();

// Send email
$mail = initMailer();
if (!$mail) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Mail service unavailable. Please try again.']);
    exit;
}

try {
    $mail->addAddress($email);
    $mail->Subject = 'Your MedConnect Registration OTP';
    $mail->isHTML(true);
    $mail->Body = "
    <div style='font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:20px'>
      <div style='background:#0d9488;padding:20px;text-align:center;border-radius:8px 8px 0 0'>
        <h2 style='color:#fff;margin:0'>medConnect</h2>
        <p style='color:rgba(255,255,255,0.85);margin:4px 0 0'>City Health Office of Bago City</p>
      </div>
      <div style='background:#f9fafb;padding:30px;border-radius:0 0 8px 8px;border:1px solid #e5e7eb'>
        <h3 style='color:#1f2937;margin-top:0'>Email Verification</h3>
        <p style='color:#4b5563'>Use the OTP below to verify your email and proceed with registration.</p>
        <div style='background:#fff;border:2px dashed #0d9488;border-radius:8px;padding:20px;text-align:center;margin:20px 0'>
          <span style='font-size:36px;font-weight:700;letter-spacing:10px;color:#0d9488'>{$otp}</span>
        </div>
        <p style='color:#6b7280;font-size:13px'>This OTP expires in <strong>10 minutes</strong>. Do not share it with anyone.</p>
        <p style='color:#6b7280;font-size:13px'>If you did not request this, please ignore this email.</p>
      </div>
    </div>";
    $mail->AltBody = "Your MedConnect OTP is: {$otp}. It expires in 10 minutes.";
    $mail->send();
    echo json_encode(['success' => true, 'message' => 'OTP sent to ' . $email . '. Please check your inbox.']);
} catch (Exception $e) {
    error_log('OTP send failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to send OTP. Please try again.']);
}
