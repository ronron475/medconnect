<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/core/includes/mailer.php';

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

// Rate limit — max 3 OTP requests per 10 minutes
if (isset($_SESSION['reset_email']) && $_SESSION['reset_email'] === $email) {
    $attempts  = $_SESSION['reset_attempts'] ?? 0;
    $last_sent = $_SESSION['reset_last_sent'] ?? 0;
    if ($attempts >= 3 && (time() - $last_sent) < 600) {
        echo json_encode(['success' => false, 'message' => 'Too many requests. Please wait 10 minutes.']);
        exit;
    }
    if ((time() - $last_sent) >= 600) $_SESSION['reset_attempts'] = 0;
}

// Always respond success — don't reveal if email exists
$stmt = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE email = ? AND role = 'patient' LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch();

if ($user) {
    // Generate 6-digit OTP
    $otp    = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiry = time() + 600; // 10 minutes

    // Store hashed OTP in session (not DB — no link needed)
    $_SESSION['reset_email']     = $email;
    $_SESSION['reset_otp']       = password_hash($otp, PASSWORD_BCRYPT);
    $_SESSION['reset_expiry']    = $expiry;
    $_SESSION['reset_verified']  = false;
    $_SESSION['reset_attempts']  = ($_SESSION['reset_attempts'] ?? 0) + 1;
    $_SESSION['reset_last_sent'] = time();

    // Send OTP email
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
