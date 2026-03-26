<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$email    = strtolower(trim($_POST['email']    ?? ''));
$password = trim($_POST['password']            ?? '');
$confirm  = trim($_POST['confirm_password']    ?? '');

// Gate: OTP must have been verified in this session
if (empty($_SESSION['reset_verified']) || empty($_SESSION['reset_email'])) {
    echo json_encode(['success' => false, 'message' => 'OTP verification required. Please start over.']);
    exit;
}

if ($_SESSION['reset_email'] !== $email) {
    echo json_encode(['success' => false, 'message' => 'Session mismatch. Please start over.']);
    exit;
}

if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
    exit;
}

if ($password !== $confirm) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

// Update password and clear OTP session
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$stmt = $pdo->prepare("UPDATE users SET password = ?, email_verification_code = NULL, email_verification_expiry = NULL WHERE email = ? AND role = 'patient'");
$stmt->execute([$hash, $email]);

if ($stmt->rowCount() === 0) {
    echo json_encode(['success' => false, 'message' => 'Account not found.']);
    exit;
}

// Clear all reset session data
unset(
    $_SESSION['reset_email'],
    $_SESSION['reset_otp'],
    $_SESSION['reset_expiry'],
    $_SESSION['reset_verified'],
    $_SESSION['reset_attempts'],
    $_SESSION['reset_last_sent']
);

echo json_encode(['success' => true, 'message' => 'Password reset successfully. You can now sign in.']);
