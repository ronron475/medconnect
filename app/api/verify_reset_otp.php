<?php
/**
 * API: Verify password-reset OTP
 * URL: /app/api/verify_reset_otp.php
 */
require_once dirname(dirname(__DIR__)) . '/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/app/includes/password_reset_otp.php';

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

$result = password_reset_otp_verify($_SESSION, $email, $otp, session_id(), time());
echo json_encode(['success' => $result['success'], 'message' => $result['message']]);
