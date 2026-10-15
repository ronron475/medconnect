<?php
if (session_status() === PHP_SESSION_NONE) {
    require_once dirname(__DIR__, 2) . '/includes/session_cookie.php';
    medconnect_session_start();
}
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrf = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($csrf) || empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token. Please refresh and try again.']);
    exit;
}

$otp   = trim($_POST['otp'] ?? '');
$email = strtolower(trim($_POST['email'] ?? ''));

if (empty($otp) || empty($email) || !preg_match('/^\d{6}$/', $otp)) {
    echo json_encode(['success' => false, 'message' => 'A valid 6-digit OTP and email are required.']);
    exit;
}

$now = time();

// Temporary lockout / cooldown check
$lockoutUntil = (int) ($_SESSION['otp_lockout_until'] ?? 0);
if ($lockoutUntil > $now) {
    $remaining = $lockoutUntil - $now;
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => "Too many incorrect attempts. Please wait {$remaining} seconds before trying again."]);
    exit;
}

// Window rate limiting: max 5 verification attempts per 60 seconds
$windowStart = (int) ($_SESSION['otp_verify_window_start'] ?? 0);
$hits = (int) ($_SESSION['otp_verify_hits'] ?? 0);
if ($windowStart === 0 || ($now - $windowStart) >= 60) {
    $windowStart = $now;
    $hits = 0;
}
$hits++;
$_SESSION['otp_verify_window_start'] = $windowStart;
$_SESSION['otp_verify_hits'] = $hits;
if ($hits > 5) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many verification attempts. Please wait a minute before trying again.']);
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

if ($now > (int) $_SESSION['otp_expiry']) {
    unset($_SESSION['otp_code'], $_SESSION['otp_expiry']);
    echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
    exit;
}

if (!password_verify($otp, $_SESSION['otp_code'])) {
    $failures = (int) ($_SESSION['otp_failures'] ?? 0) + 1;
    $_SESSION['otp_failures'] = $failures;
    if ($failures >= 5) {
        unset($_SESSION['otp_code'], $_SESSION['otp_expiry'], $_SESSION['otp_failures']);
        $_SESSION['otp_lockout_until'] = $now + 300; // 5-minute lockout
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many incorrect attempts. Your OTP has been invalidated. Please wait 5 minutes before requesting a new OTP.']);
        exit;
    }
    $attemptsLeft = 5 - $failures;
    echo json_encode(['success' => false, 'message' => "Incorrect OTP. Please try again. ({$attemptsLeft} attempt(s) remaining)"]);
    exit;
}

// OTP correct — mark email as verified for this session
$_SESSION['otp_verified']       = true;
$_SESSION['otp_verified_email'] = $email;

// Clear OTP from session (one-time use) and reset failure counters
unset(
    $_SESSION['otp_code'],
    $_SESSION['otp_expiry'],
    $_SESSION['otp_failures'],
    $_SESSION['otp_verify_hits'],
    $_SESSION['otp_verify_window_start'],
    $_SESSION['otp_lockout_until']
);

echo json_encode(['success' => true, 'message' => 'Email verified successfully.']);

