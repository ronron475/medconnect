<?php
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$otp   = trim($_POST['otp'] ?? '');
$email = strtolower(trim($_POST['email'] ?? ''));

if (empty($otp) || empty($email)) {
    echo json_encode(['success' => false, 'message' => 'OTP and email are required.']);
    exit;
}

// Check session
if (empty($_SESSION['otp_email']) || empty($_SESSION['otp_code']) || empty($_SESSION['otp_expiry'])) {
    echo json_encode(['success' => false, 'message' => 'No OTP found. Please request a new one.']);
    exit;
}

if ($_SESSION['otp_email'] !== $email) {
    echo json_encode(['success' => false, 'message' => 'Email mismatch. Please request a new OTP.']);
    exit;
}

if (time() > $_SESSION['otp_expiry']) {
    unset($_SESSION['otp_code'], $_SESSION['otp_expiry']);
    echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
    exit;
}

if (!password_verify($otp, $_SESSION['otp_code'])) {
    echo json_encode(['success' => false, 'message' => 'Incorrect OTP. Please try again.']);
    exit;
}

// OTP correct — mark email as verified for this session
$_SESSION['otp_verified']       = true;
$_SESSION['otp_verified_email'] = $email;

// Clear OTP from session (one-time use)
unset($_SESSION['otp_code'], $_SESSION['otp_expiry']);

echo json_encode(['success' => true, 'message' => 'Email verified successfully.']);
