<?php
Api::startJson();
Api::requirePost();

// CSRF protection (session token is created in bootstrap/app.php)
$csrf = (string) ($_POST['csrf_token'] ?? '');
if (empty($csrf) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
    Api::error('Invalid CSRF token.', 419);
}

$email = strtolower(trim((string) ($_POST['email'] ?? '')));

// Gmail-only validation (shared with OTP send endpoint)
if ($email === '' || preg_match('/\s/', $email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Api::error('Please enter a valid Gmail address.', 422);
}
if (!preg_match('/^[A-Za-z0-9._%+\-]+@gmail\.com$/i', $email)) {
    Api::error('Please enter a valid Gmail address.', 422);
}

// Check if email already exists (global uniqueness starts with users table)
try {
    /** @var PDO $pdo */
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $exists = (bool) $stmt->fetchColumn();
} catch (Throwable $e) {
    error_log('check_email failed: ' . $e->getMessage());
    Api::error('Server error while checking email. Please try again.', 500);
}

if ($exists) {
    Api::error('This Gmail address is already registered. Please try another email address.', 409, [
        'exists' => true,
    ]);
}

Api::success(['available' => true], 'Email is available.');

