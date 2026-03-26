<?php
session_start();
require_once __DIR__ . '/../config/db.php';

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    die('Invalid verification link. Please check your email and try again.');
}

try {
    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, email, email_verification_expiry
        FROM users
        WHERE email_verification_code = ? AND is_email_verified = 0
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        die('Invalid or already-used verification link. If you already verified, please log in.');
    }

    if (strtotime($user['email_verification_expiry']) < time()) {
        $pdo->prepare("UPDATE users SET email_verification_code = NULL, email_verification_expiry = NULL WHERE id = ?")
            ->execute([$user['id']]);
        die('Verification link has expired (valid for 24 hours). Please register again.');
    }

    $pdo->beginTransaction();

    // Activate the user account
    $pdo->prepare("
        UPDATE users
        SET is_email_verified = 1, email_verified_at = NOW(),
            email_verification_code = NULL, email_verification_expiry = NULL,
            is_active = 1
        WHERE id = ?
    ")->execute([$user['id']]);

    // Set patient registration to active — no admin approval needed
    $pdo->prepare("
        UPDATE patient_registrations
        SET status = 'active', verified_at = NOW()
        WHERE email = ? AND status = 'ocr_verified'
    ")->execute([$user['email']]);

    $pdo->commit();

    // Log it
    try {
        $reg = $pdo->prepare("SELECT id FROM patient_registrations WHERE email = ? LIMIT 1");
        $reg->execute([$user['email']]);
        $reg_id = ($reg->fetch())['id'] ?? null;

        $pdo->prepare("
            INSERT INTO registration_activity_logs
                (registration_id, action, result, detail, national_id_hash, ip_address, user_agent, created_at)
            VALUES (?, 'email_verified', 'success', 'Email verified — account activated', '', ?, ?, NOW())
        ")->execute([
            $reg_id,
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);
    } catch (Exception $e) { /* non-fatal */ }

    header('Location: verification-success.php');
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Email verification error: ' . $e->getMessage());
    die('An error occurred during verification. Please try again.');
}
