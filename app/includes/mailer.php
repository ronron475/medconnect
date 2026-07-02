<?php
/**
 * MedConnect Mailer
 */

if (!defined('BASE_PATH')) {
    require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
}

$composerAutoload = BASE_PATH . '/vendor/autoload.php';
if (is_readable($composerAutoload)) {
    require_once $composerAutoload;
}

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

if (!defined('MAIL_HOST')) {
    define('MAIL_HOST',       'smtp.gmail.com');
    define('MAIL_PORT',       587);
    define('MAIL_SMTP_SECURE','tls');
    define('MAIL_SMTP_AUTH',  true);
    define('MAIL_USERNAME',   'sumagaysayjanica@gmail.com');
    define('MAIL_PASSWORD',   'tepmayjrpuyaaatn');
    define('MAIL_FROM_EMAIL', 'sumagaysayjanica@gmail.com');
    define('MAIL_FROM_NAME',  'MedConnect Bago City');
    define('MAIL_DEBUG_MODE', false);
    define('MAIL_CHARSET',    'UTF-8');
}

function initMailer() {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = MAIL_SMTP_AUTH;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_SMTP_SECURE;
        $mail->Port       = MAIL_PORT;
        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->CharSet    = MAIL_CHARSET;
        $mail->isHTML(true);

        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        return $mail;
    } catch (Exception $e) {
        error_log('Mailer init failed: ' . $e->getMessage());
        return null;
    }
}

function sendVerificationEmail($to, $verificationToken, $fullName) {
    $mail = initMailer();
    if (!$mail) return ['success' => false, 'message' => 'Failed to initialize mailer.'];
    try {
        $url = BASE_URL . '/public/verify.php?token=' . urlencode($verificationToken);
        $mail->addAddress($to);
        $mail->Subject = 'Verify Your MedConnect Account';
        $mail->Body    = "<p>Dear {$fullName},</p><p><a href='{$url}'>Click here</a> to verify. Expires in 24 hours.</p>";
        $mail->AltBody = "Verify: {$url}";
        $mail->send();
        return ['success' => true, 'message' => 'Email sent.'];
    } catch (Exception $e) {
        error_log('Email failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to send email.'];
    }
}

/**
 * Welcome email for BHW-assisted patient registration with secure password setup link.
 *
 * @return array{success: bool, message: string}
 */
function sendPatientWelcomeEmail(string $to, string $fullName, string $patientCode, string $setupToken): array
{
    $mail = initMailer();
    if (!$mail) {
        return ['success' => false, 'message' => 'Failed to initialize mailer.'];
    }

    $setupUrl = BASE_URL . '/public/setup_password.php?token=' . urlencode($setupToken);
    $loginUrl = BASE_URL . '/index.php';

    $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($patientCode, ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars($to, ENT_QUOTES, 'UTF-8');

    try {
        $mail->addAddress($to);
        $mail->Subject = 'Welcome to MedConnect — Complete Your Account Setup';
        $mail->Body = "
            <div style=\"font-family:Arial,sans-serif;max-width:560px;margin:0 auto;color:#1e293b;\">
                <h2 style=\"color:#0d9488;\">Welcome to MedConnect</h2>
                <p>Dear {$safeName},</p>
                <p>Your Barangay Health Worker has registered you on <strong>MedConnect Bago City</strong>.</p>
                <table style=\"margin:20px 0;border-collapse:collapse;width:100%;\">
                    <tr><td style=\"padding:8px 0;color:#64748b;\">Patient ID</td><td style=\"padding:8px 0;font-weight:600;\">{$safeCode}</td></tr>
                    <tr><td style=\"padding:8px 0;color:#64748b;\">Email</td><td style=\"padding:8px 0;\">{$safeEmail}</td></tr>
                    <tr><td style=\"padding:8px 0;color:#64748b;\">Account Status</td><td style=\"padding:8px 0;color:#059669;font-weight:600;\">Active</td></tr>
                </table>
                <p>To access your patient portal, please set your password using the secure link below. This link expires in 72 hours.</p>
                <p style=\"margin:28px 0;\">
                    <a href=\"{$setupUrl}\" style=\"background:#0d9488;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;\">Set Up My Password</a>
                </p>
                <p style=\"font-size:13px;color:#64748b;\">If the button does not work, copy and paste this URL into your browser:<br>{$setupUrl}</p>
                <p style=\"font-size:13px;color:#64748b;margin-top:24px;\">After setup, sign in at <a href=\"{$loginUrl}\">{$loginUrl}</a>.</p>
                <hr style=\"border:none;border-top:1px solid #e2e8f0;margin:24px 0;\">
                <p style=\"font-size:12px;color:#94a3b8;\">This message was sent because a healthcare worker registered your account. If you did not expect this email, contact your barangay health center.</p>
            </div>
        ";
        $mail->AltBody = "Welcome to MedConnect\n\nPatient ID: {$patientCode}\nEmail: {$to}\n\nSet your password: {$setupUrl}\n\nSign in: {$loginUrl}";
        $mail->send();
        return ['success' => true, 'message' => 'Welcome email sent.'];
    } catch (Exception $e) {
        error_log('Patient welcome email failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to send welcome email.'];
    }
}
