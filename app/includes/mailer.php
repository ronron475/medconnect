<?php
/**
 * MedConnect Mailer
 */

$envLoader = dirname(__DIR__, 2) . '/config/env_loader.php';
if (is_readable($envLoader)) {
    require_once $envLoader;
}

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
    $mailEnv = static function (string $key, string $default = ''): string {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $default;
        }

        return trim((string) $value);
    };
    $mailUser = $mailEnv('MAIL_USERNAME');
    define('MAIL_HOST', $mailEnv('MAIL_HOST', 'smtp.gmail.com'));
    define('MAIL_PORT', (int) ($mailEnv('MAIL_PORT', '587') ?: '587'));
    define('MAIL_SMTP_SECURE', $mailEnv('MAIL_SMTP_SECURE', 'tls'));
    define('MAIL_SMTP_AUTH', true);
    define('MAIL_USERNAME', $mailUser);
    define('MAIL_PASSWORD', $mailEnv('MAIL_PASSWORD'));
    define('MAIL_FROM_EMAIL', $mailEnv('MAIL_FROM_EMAIL', $mailUser));
    define('MAIL_FROM_NAME', $mailEnv('MAIL_FROM_NAME', 'MedConnect Bago City'));
    define('MAIL_DEBUG_MODE', false);
    define('MAIL_CHARSET', 'UTF-8');
}

function initMailer() {
    if (!class_exists(PHPMailer::class)) {
        error_log('Mailer init failed: PHPMailer is not installed (vendor/autoload.php).');
        return null;
    }

    $missing = [];
    foreach (['MAIL_HOST', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_EMAIL'] as $key) {
        if (!defined($key) || trim((string) constant($key)) === '') {
            $missing[] = $key;
        }
    }
    if ($missing !== []) {
        error_log('Mailer init failed: missing environment variables: ' . implode(', ', $missing));
        return null;
    }

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
        // Fail faster on stuck SMTP instead of hanging the OTP request.
        $mail->Timeout = 12;
        $mail->SMTPKeepAlive = false;

        $verifySsl = true;
        $sslFlag = getenv('AI_SSL_VERIFY');
        if ($sslFlag === false || $sslFlag === '') {
            $sslFlag = $_ENV['AI_SSL_VERIFY'] ?? 'true';
        }
        if (in_array(strtolower(trim((string) $sslFlag)), ['0', 'false', 'no', 'off'], true)) {
            $verifySsl = false;
        }
        $caFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem';
        if (!is_readable($caFile)) {
            $caFile = (string) (ini_get('openssl.cafile') ?: ini_get('curl.cainfo') ?: '');
        }
        $ssl = [
            'verify_peer' => $verifySsl,
            'verify_peer_name' => $verifySsl,
            'allow_self_signed' => !$verifySsl,
        ];
        if ($caFile !== '' && is_readable($caFile)) {
            $ssl['cafile'] = $caFile;
        }
        $mail->SMTPOptions = ['ssl' => $ssl];

        return $mail;
    } catch (Throwable $e) {
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
        $mail->Subject = 'Verify your Gmail and create your MedConnect password';
        $mail->Body = "
            <div style=\"font-family:Arial,sans-serif;max-width:560px;margin:0 auto;color:#1e293b;\">
                <h2 style=\"color:#0d9488;\">Verify your Gmail</h2>
                <p>Dear {$safeName},</p>
                <p>Your Barangay Health Worker started a MedConnect registration for you. Open the link below on your own phone or device to verify this Gmail, then create your own password.</p>
                <table style=\"margin:20px 0;border-collapse:collapse;width:100%;\">
                    <tr><td style=\"padding:8px 0;color:#64748b;\">Patient ID</td><td style=\"padding:8px 0;font-weight:600;\">{$safeCode}</td></tr>
                    <tr><td style=\"padding:8px 0;color:#64748b;\">Email</td><td style=\"padding:8px 0;\">{$safeEmail}</td></tr>
                </table>
                <p>This link expires in 72 hours. Your password is never sent by email, and the health worker cannot see or set it. You cannot sign in until you create it yourself.</p>
                <p style=\"margin:28px 0;\">
                    <a href=\"{$setupUrl}\" style=\"background:#0d9488;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;\">Verify Gmail and create password</a>
                </p>
                <p style=\"font-size:13px;color:#64748b;\">If the button does not work, copy and paste this URL into your browser:<br>{$setupUrl}</p>
                <p style=\"font-size:13px;color:#64748b;margin-top:24px;\">After you create your password, sign in at <a href=\"{$loginUrl}\">{$loginUrl}</a>.</p>
                <hr style=\"border:none;border-top:1px solid #e2e8f0;margin:24px 0;\">
                <p style=\"font-size:12px;color:#94a3b8;\">This message was sent because a healthcare worker registered your account. If you did not expect this email, contact your barangay health center.</p>
            </div>
        ";
        $mail->AltBody = "Verify your Gmail\n\nPatient ID: {$patientCode}\nEmail: {$to}\n\nOpen this link on your own device to verify your Gmail and create your password. Your password is not included in this email.\n{$setupUrl}\n\nAfter that, sign in: {$loginUrl}";
        $mail->send();
        return ['success' => true, 'message' => 'Welcome email sent.'];
    } catch (Exception $e) {
        error_log('Patient welcome email failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to send welcome email.'];
    }
}

/**
 * Invite email for BHW account activation / onboarding.
 *
 * @return array{success: bool, message: string}
 */
function sendBhwInviteEmail(
    string $to,
    string $fullName,
    string $inviteToken,
    string $barangay = '',
    bool $isReminder = false
): array {
    $mail = initMailer();
    if (!$mail) {
        return ['success' => false, 'message' => 'Failed to initialize mailer.'];
    }

    $activateUrl = BASE_URL . '/public/bhw_activate.php?token=' . urlencode($inviteToken);
    $onboardingUrl = BASE_URL . '/public/bhw_onboarding.php?token=' . urlencode($inviteToken);
    $loginUrl = BASE_URL . '/index.php';
    $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safeBarangay = htmlspecialchars($barangay, ENT_QUOTES, 'UTF-8');
    $subject = $isReminder
        ? 'MedConnect — Continue your BHW account setup'
        : 'MedConnect — Activate your Barangay Health Worker account';
    $intro = $isReminder
        ? 'Here is a new link to continue setting up your Barangay Health Worker account.'
        : 'An administrator has invited you to join <strong>MedConnect Bago City</strong> as a Barangay Health Worker.';

    try {
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = "
            <div style=\"font-family:Arial,sans-serif;max-width:560px;margin:0 auto;color:#1e293b;\">
                <h2 style=\"color:#0d9488;\">MedConnect BHW Invitation</h2>
                <p>Dear {$safeName},</p>
                <p>{$intro}</p>
                " . ($safeBarangay !== '' ? "<p><strong>Assigned barangay:</strong> {$safeBarangay}</p>" : '') . "
                <p>Create your password to activate the invite, then complete your personal information and upload your Government-issued ID.</p>
                <p style=\"margin:28px 0;\">
                    <a href=\"{$activateUrl}\" style=\"background:#0d9488;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;\">Activate My Account</a>
                </p>
                <p style=\"font-size:13px;color:#64748b;\">If you already set a password, continue onboarding:<br><a href=\"{$onboardingUrl}\">{$onboardingUrl}</a></p>
                <p style=\"font-size:13px;color:#64748b;\">Activation link: {$activateUrl}</p>
                <p style=\"font-size:13px;color:#64748b;margin-top:24px;\">After approval you can sign in at <a href=\"{$loginUrl}\">{$loginUrl}</a>.</p>
                <hr style=\"border:none;border-top:1px solid #e2e8f0;margin:24px 0;\">
                <p style=\"font-size:12px;color:#94a3b8;\">If you did not expect this invitation, contact your City Health Office.</p>
            </div>
        ";
        $mail->AltBody = "MedConnect BHW Invitation\n\nDear {$fullName},\n\nActivate: {$activateUrl}\nOnboarding: {$onboardingUrl}\n";
        $mail->send();
        return ['success' => true, 'message' => 'Invite email sent.'];
    } catch (Exception $e) {
        error_log('BHW invite email failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to send invite email.'];
    }
}

/**
 * Correction / additional documents email for BHW applicants.
 *
 * @return array{success: bool, message: string}
 */
function sendBhwCorrectionEmail(string $to, string $fullName, string $token, string $note): array
{
    $mail = initMailer();
    if (!$mail) {
        return ['success' => false, 'message' => 'Failed to initialize mailer.'];
    }

    $url = BASE_URL . '/public/bhw_onboarding.php?token=' . urlencode($token);
    $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safeNote = htmlspecialchars($note, ENT_QUOTES, 'UTF-8');

    try {
        $mail->addAddress($to);
        $mail->Subject = 'MedConnect — Additional documents required for your BHW application';
        $mail->Body = "
            <div style=\"font-family:Arial,sans-serif;max-width:560px;margin:0 auto;color:#1e293b;\">
                <h2 style=\"color:#0d9488;\">Additional documents needed</h2>
                <p>Dear {$safeName},</p>
                <p>A Super Administrator reviewed your BHW application and needs more information or documents:</p>
                <p style=\"background:#fff7ed;border:1px solid #fed7aa;padding:12px 14px;border-radius:8px;\">{$safeNote}</p>
                <p style=\"margin:28px 0;\">
                    <a href=\"{$url}\" style=\"background:#0d9488;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;\">Update My Application</a>
                </p>
                <p style=\"font-size:13px;color:#64748b;\">Or open: {$url}</p>
            </div>
        ";
        $mail->AltBody = "Additional documents needed\n\n{$note}\n\nUpdate: {$url}";
        $mail->send();
        return ['success' => true, 'message' => 'Correction email sent.'];
    } catch (Exception $e) {
        error_log('BHW correction email failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to send correction email.'];
    }
}

/**
 * Follow-up reminder email after video consultation.
 *
 * @return array{success: bool, message: string}
 */
/**
 * @return array{subject:string,html:string,text:string}
 */
function followUpReminderEmailParts(
    string $fullName,
    string $followupDate,
    string $providerNote = '',
    string $contactNumber = '',
    string $appointmentStart = ''
): array {
    $prettyDate = date('M j, Y', strtotime($followupDate)) ?: $followupDate;
    $appointmentStart = trim($appointmentStart);
    $prettyTime = '';
    if ($appointmentStart !== '') {
        $stamp = strtotime(substr($followupDate, 0, 10) . ' ' . $appointmentStart);
        if ($stamp !== false) {
            $prettyTime = date('g:i A', $stamp);
        }
    }
    $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safeDate = htmlspecialchars($prettyDate, ENT_QUOTES, 'UTF-8');
    $safeTime = htmlspecialchars($prettyTime, ENT_QUOTES, 'UTF-8');
    $safeNote = htmlspecialchars(trim($providerNote), ENT_QUOTES, 'UTF-8');
    $safeContact = htmlspecialchars(trim($contactNumber), ENT_QUOTES, 'UTF-8');
    $portalUrl = rtrim((string) BASE_URL, '/') . '/views/patient/dashboard.php#action-items';
    $whenLine = $prettyTime !== '' ? ($prettyDate . ' • ' . $prettyTime) : $prettyDate;
    $timeRow = $prettyTime !== ''
        ? "<tr><td style=\"padding:12px;color:#64748b;\">Appointment time</td><td style=\"padding:12px;font-weight:700;\">{$safeTime}</td></tr>"
        : '';

    $html = "
            <div style=\"font-family:Arial,sans-serif;max-width:560px;margin:0 auto;color:#1e293b;\">
                <h2 style=\"color:#0d9488;margin-bottom:8px;\">Follow-Up Reminder</h2>
                <p>Dear {$safeName},</p>
                <p>Your healthcare provider has scheduled a follow-up for you.</p>
                <table style=\"margin:20px 0;border-collapse:collapse;width:100%;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;\">
                    <tr><td style=\"padding:12px;color:#64748b;\">Follow-up date</td><td style=\"padding:12px;font-weight:700;\">{$safeDate}</td></tr>
                    {$timeRow}
                    " . ($safeContact !== '' ? "<tr><td style=\"padding:12px;color:#64748b;\">Registered mobile</td><td style=\"padding:12px;\">{$safeContact}</td></tr>" : '') . "
                </table>
                " . ($safeNote !== '' ? "<p><strong>Provider note:</strong><br>{$safeNote}</p>" : '') . "
                <p style=\"margin:28px 0;\">
                    <a href=\"{$portalUrl}\" style=\"background:#0d9488;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;\">Open Patient Portal</a>
                </p>
                <p style=\"font-size:12px;color:#94a3b8;\">This is an automated reminder from MedConnect Bago City.</p>
            </div>
        ";
    $text = "Follow-Up Reminder\n\nDear {$fullName},\nYour follow-up is scheduled for {$whenLine}.\n"
        . ($providerNote !== '' ? "Provider note: {$providerNote}\n" : '')
        . "Portal: {$portalUrl}";

    return [
        'subject' => 'MedConnect Follow-Up Reminder — ' . $prettyDate,
        'html' => $html,
        'text' => $text,
    ];
}

function sendFollowUpReminderEmail(
    string $to,
    string $fullName,
    string $followupDate,
    string $providerNote = '',
    string $contactNumber = '',
    string $appointmentStart = ''
): array {
    $mail = initMailer();
    if (!$mail) {
        return ['success' => false, 'message' => 'Failed to initialize Gmail mailer.'];
    }

    $parts = followUpReminderEmailParts($fullName, $followupDate, $providerNote, $contactNumber, $appointmentStart);

    try {
        $mail->addAddress($to, $fullName);
        $mail->Subject = $parts['subject'];
        $mail->Body = $parts['html'];
        $mail->AltBody = $parts['text'];
        $mail->send();
        return ['success' => true, 'message' => 'Follow-up email sent via Gmail.'];
    } catch (Exception $e) {
        error_log('Follow-up email failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to send follow-up email.'];
    }
}
