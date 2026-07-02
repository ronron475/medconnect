<?php
/**
 * API: Reset password after OTP verification
 * Moved from root/reset_password_otp.php
 * URL: /app/api/reset_password_otp.php
 */
session_start();
header('Content-Type: application/json');

require_once dirname(dirname(__DIR__)) . '/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/app/includes/recaptcha.php';
require_once dirname(dirname(__DIR__)) . '/app/includes/login_security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$email    = strtolower(trim($_POST['email']            ?? ''));
$password = trim($_POST['password']                    ?? '');
$confirm  = trim($_POST['confirm_password']            ?? '');
$recaptchaToken = (string) ($_POST['recaptcha_token'] ?? ($_POST['g-recaptcha-response'] ?? ''));

if (empty($_SESSION['reset_verified']) || empty($_SESSION['reset_email'])) {
    echo json_encode(['success' => false, 'message' => 'OTP verification required. Please start over.']);
    exit;
}

if ($_SESSION['reset_email'] !== $email) {
    echo json_encode(['success' => false, 'message' => 'Session mismatch. Please start over.']);
    exit;
}

if (recaptcha_is_configured()) {
    $ip = login_security_ip();
    $verify = recaptcha_verify_token($recaptchaToken, 'reset_password', $ip);
    if (empty($verify['ok'])) {
        echo json_encode(['success' => false, 'message' => 'Please verify that you are not a robot.']);
        exit;
    }
}

if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
    exit;
}

if ($password !== $confirm) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$stmt = $pdo->prepare("UPDATE users SET password = ?, email_verification_code = NULL, email_verification_expiry = NULL WHERE email = ? AND role = 'patient'");
$stmt->execute([$hash, $email]);

if ($stmt->rowCount() === 0) {
    echo json_encode(['success' => false, 'message' => 'Account not found.']);
    exit;
}

unset(
    $_SESSION['reset_email'],
    $_SESSION['reset_otp'],
    $_SESSION['reset_expiry'],
    $_SESSION['reset_verified'],
    $_SESSION['reset_attempts'],
    $_SESSION['reset_last_sent']
);

echo json_encode(['success' => true, 'message' => 'Password reset successfully. You can now sign in.']);
