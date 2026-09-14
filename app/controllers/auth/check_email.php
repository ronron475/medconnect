<?php
Api::startJson();
Api::requirePost();

// CSRF protection (session token is created in bootstrap/app.php)
$csrf = (string) ($_POST['csrf_token'] ?? '');
if (empty($csrf) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
    Api::error('Invalid CSRF token.', 419);
}

$email = strtolower(trim((string) ($_POST['email'] ?? '')));

require_once dirname(__DIR__, 2) . '/includes/contact_validation.php';
if ($err = mc_gmail_validation_error($email, true)) {
    Api::error($err, 422);
}
$email = mc_normalize_email($email);

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

