<?php
/**
 * API: Reset password after OTP verification
 * URL: /app/api/reset_password_otp.php
 */
require_once dirname(dirname(__DIR__)) . '/bootstrap.php';
require_once BASE_PATH . '/app/includes/login_security.php';
require_once BASE_PATH . '/app/includes/patient_account_security.php';
require_once BASE_PATH . '/app/includes/remember_me.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm_password'] ?? '');

    if (empty($_SESSION['reset_verified']) || empty($_SESSION['reset_email'])) {
        echo json_encode(['success' => false, 'message' => 'OTP verification required. Please start over.']);
        exit;
    }

    if ($_SESSION['reset_email'] !== $email) {
        echo json_encode(['success' => false, 'message' => 'Session mismatch. Please start over.']);
        exit;
    }

    $policyError = patient_validate_password_policy($password);
    if ($policyError !== null) {
        echo json_encode(['success' => false, 'message' => $policyError]);
        exit;
    }

    if ($password !== $confirm) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
        exit;
    }

    $userStmt = $pdo->prepare("SELECT id, password FROM users WHERE email = ? AND role = 'patient' LIMIT 1");
    $userStmt->execute([$email]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'No patient account found for this email.']);
        exit;
    }

    $userId = (int) $user['id'];
    $hash   = patient_hash_password($password);

    $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ? AND role = ?');
    $stmt->execute([$hash, $userId, 'patient']);

    $verifyStmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $verifyStmt->execute([$userId]);
    $storedHash = (string) ($verifyStmt->fetchColumn() ?: '');
    if ($storedHash === '' || !password_verify($password, $storedHash)) {
        echo json_encode(['success' => false, 'message' => 'Could not save your new password. Please try again.']);
        exit;
    }

    // Optional legacy email-verification columns (ignore if absent).
    try {
        $pdo->prepare('UPDATE users SET email_verification_code = NULL, email_verification_expiry = NULL WHERE id = ?')
            ->execute([$userId]);
    } catch (Throwable $e) { /* non-fatal */ }

    remember_me_revoke_for_user($pdo, $userId);
    remember_me_clear_cookie();

    unset(
        $_SESSION['reset_email'],
        $_SESSION['reset_otp'],
        $_SESSION['reset_expiry'],
        $_SESSION['reset_verified'],
        $_SESSION['reset_attempts'],
        $_SESSION['reset_last_sent']
    );

    echo json_encode(['success' => true, 'message' => 'Password reset successfully. You can now sign in.']);
} catch (Throwable $e) {
    error_log('reset_password_otp: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not reset password. Please try again.']);
}
