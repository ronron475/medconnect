<?php
if (session_status() === PHP_SESSION_NONE) {
    require_once dirname(__DIR__, 2) . '/includes/session_cookie.php';
    medconnect_session_start();
}
require_once dirname(__DIR__, 2) . '/includes/password_reset_otp.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$email = strtolower(trim($_POST['email'] ?? ''));
$otp   = trim($_POST['otp'] ?? '');

if (empty($email) || empty($otp)) {
    echo json_encode(['success' => false, 'message' => 'Email and OTP are required.']);
    exit;
}

$result = password_reset_otp_verify($_SESSION, $email, $otp, session_id(), time());
echo json_encode(['success' => $result['success'], 'message' => $result['message']]);
