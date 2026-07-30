<?php
/**
 * API: Verify password-reset OTP
 * URL: /app/api/verify_reset_otp.php
 */
require_once dirname(dirname(__DIR__)) . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$email = strtolower(trim($_POST['email'] ?? ''));
$otp   = trim($_POST['otp'] ?? '');

if ($email === '' || $otp === '') {
    echo json_encode(['success' => false, 'message' => 'Email and OTP are required.']);
    exit;
}

if (empty($_SESSION['reset_email']) || empty($_SESSION['reset_otp']) || empty($_SESSION['reset_expiry'])) {
    echo json_encode(['success' => false, 'message' => 'No OTP found. Please request a new one.']);
    exit;
}

if ($_SESSION['reset_email'] !== $email) {
    echo json_encode(['success' => false, 'message' => 'Email mismatch. Please request a new OTP.']);
    exit;
}

if (time() > (int) $_SESSION['reset_expiry']) {
    unset($_SESSION['reset_otp'], $_SESSION['reset_expiry']);
    echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
    exit;
}

if (!password_verify($otp, (string) $_SESSION['reset_otp'])) {
    echo json_encode(['success' => false, 'message' => 'Incorrect OTP. Please try again.']);
    exit;
}

$_SESSION['reset_verified'] = true;
echo json_encode(['success' => true, 'message' => 'OTP verified. You may now set a new password.']);
