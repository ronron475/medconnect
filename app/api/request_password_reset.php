<?php
/**
 * API: Request password reset (sends OTP to email)
 * Moved from root/request_password_reset.php
 * URL: /app/api/request_password_reset.php
 */
session_start();
header('Content-Type: application/json');

require_once dirname(dirname(__DIR__)) . '/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once BASE_PATH . '/app/includes/mailer.php';
require_once dirname(dirname(__DIR__)) . '/app/includes/recaptcha.php';
require_once dirname(dirname(__DIR__)) . '/app/includes/login_security.php';
require_once dirname(dirname(__DIR__)) . '/app/includes/security_throttle.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$email = strtolower(trim($_POST['email'] ?? ''));
$recaptchaToken = (string) ($_POST['recaptcha_token'] ?? ($_POST['g-recaptcha-response'] ?? ''));

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

if (recaptcha_is_configured()) {
    $ip = login_security_ip();
    $verify = recaptcha_verify_token($recaptchaToken, 'forgot_password', $ip);
    if (empty($verify['ok'])) {
        echo json_encode(['success' => false, 'message' => 'Please verify that you are not a robot.']);
        exit;
    }
}

// IP throttle (reduce OTP spam)
try {
    $ip = login_security_ip();
    if ($ip) {
        $key = security_throttle_key('pwreset_ip', $ip);
        $state = security_throttle_check($pdo, $key);
        if (!empty($state['locked'])) {
            echo json_encode(['success' => false, 'message' => 'Too many requests. Please wait 10 minutes.']);
            exit;
        }
        // Fail counter is incremented only when an email is submitted (regardless of existence)
        security_throttle_fail($pdo, $key, 'pwreset_ip', 600, 6, 10);
    }
} catch (Throwable $e) { /* non-fatal */ }

// Rate limit
if (isset($_SESSION['reset_email']) && $_SESSION['reset_email'] === $email) {
    $attempts  = $_SESSION['reset_attempts'] ?? 0;
    $last_sent = $_SESSION['reset_last_sent'] ?? 0;
    if ($attempts >= 3 && (time() - $last_sent) < 600) {
        echo json_encode(['success' => false, 'message' => 'Too many requests. Please wait 10 minutes.']);
        exit;
    }
    if ((time() - $last_sent) >= 600) $_SESSION['reset_attempts'] = 0;
}

$stmt = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE email = ? AND role = 'patient' LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch();

if ($user) {
    $otp    = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiry = time() + 600;

    $_SESSION['reset_email']     = $email;
    $_SESSION['reset_otp']       = password_hash($otp, PASSWORD_BCRYPT);
    $_SESSION['reset_expiry']    = $expiry;
    $_SESSION['reset_verified']  = false;
    $_SESSION['reset_attempts']  = ($_SESSION['reset_attempts'] ?? 0) + 1;
    $_SESSION['reset_last_sent'] = time();

    $mail = initMailer();
    if ($mail) {
        try {
            $fullName = $user['first_name'] . ' ' . $user['last_name'];
            $mail->addAddress($email);
            $mail->Subject = 'Your MedConnect Password Reset OTP';
            $mail->isHTML(true);
            $mail->Body = "
            <div style='font-family:Arial,sans-serif;max-width:480px;margin:0 auto'>
              <div style='background:#1a6db5;padding:24px;text-align:center;border-radius:10px 10px 0 0'>
                <h2 style='color:#fff;margin:0'>medConnect</h2>
                <p style='color:rgba(255,255,255,0.8);margin:4px 0 0;font-size:13px'>City Health Office of Bago City</p>
              </div>
              <div style='background:#f8fbff;padding:32px;border-radius:0 0 10px 10px;border:1px solid #d0e4f7'>
                <h3 style='color:#0f172a;margin-top:0'>Password Reset OTP</h3>
                <p style='color:#475569'>Hi {$fullName},</p>
                <p style='color:#475569'>Use this OTP to reset your password. Do not share it with anyone.</p>
                <div style='background:#fff;border:2px dashed #1a6db5;border-radius:10px;padding:20px;text-align:center;margin:20px 0'>
                  <span style='font-size:38px;font-weight:800;letter-spacing:10px;color:#1a6db5'>{$otp}</span>
                </div>
                <p style='color:#94a3b8;font-size:12px'>Expires in <strong>10 minutes</strong>. If you didn't request this, ignore this email.</p>
              </div>
            </div>";
            $mail->AltBody = "Your MedConnect password reset OTP is: {$otp}. Expires in 10 minutes.";
            $mail->send();
        } catch (Exception $e) {
            error_log('Reset OTP email failed: ' . $e->getMessage());
        }
    }
}

echo json_encode(['success' => true, 'message' => 'If that email is registered, an OTP has been sent to your inbox.']);
