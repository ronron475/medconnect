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

const MEDCONNECT_RAILWAY_EMAIL_URL = 'https://medconnect-production-f2b7.up.railway.app/email/send';

function medconnect_mail_env(string $key): string
{
    $candidates = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
    if ($key === 'MEDCONNECT_AI_SERVICE_TOKEN' && defined('MEDCONNECT_AI_SERVICE_TOKEN')) {
        $candidates[] = MEDCONNECT_AI_SERVICE_TOKEN;
    }
    foreach ($candidates as $candidate) {
        if ($candidate !== false && $candidate !== null && trim((string) $candidate) !== '') {
            return trim((string) $candidate);
        }
    }

    return medconnect_mail_env_from_file($key);
}

function medconnect_mail_env_from_file(string $key): string
{
    $path = dirname(__DIR__, 2) . '/.env';
    if (!is_readable($path)) {
        return '';
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return '';
    }
    foreach ($lines as $line) {
        $line = trim(ltrim($line, "\xEF\xBB\xBF"));
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        if (trim($name) !== $key) {
            continue;
        }
        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        return trim($value);
    }

    return '';
}

class MedConnectRailwayMailer
{
    public string $Subject = '';
    public string $Body = '';
    public string $AltBody = '';
    private string $recipient = '';
    private string $token;
    private string $emailKey;

    public function __construct(string $token, string $emailKey)
    {
        $this->token = $token;
        $this->emailKey = $emailKey;
    }

    public function addAddress(string $address, string $name = ''): void
    {
        $this->recipient = trim($address);
    }

    public function isHTML(bool $isHtml = true): void
    {
    }

    public function send(): bool
    {
        if ($this->recipient === '' || trim($this->Subject) === '' || trim($this->Body) === '') {
            throw new Exception('Email could not be sent.');
        }

        $payload = [
            'recipient' => $this->recipient,
            'subject' => $this->Subject,
            'html' => $this->Body,
        ];
        $text = trim($this->AltBody);
        if ($text !== '') {
            $payload['text'] = $text;
        }

        [$status, $raw] = $this->postJson((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $ok = $status >= 200 && $status < 300 && is_array($decoded) && (($decoded['success'] ?? false) === true);
        if (!$ok) {
            error_log('Railway email API failed: HTTP ' . $status);
            throw new Exception('Email could not be sent.');
        }

        return true;
    }

    /** @return array{0:int,1:string} */
    private function postJson(string $body): array
    {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->token,
            'X-Email-Api-Key: ' . $this->emailKey,
        ];
        if (function_exists('curl_init')) {
            $ch = curl_init(MEDCONNECT_RAILWAY_EMAIL_URL);
            if ($ch === false) {
                return [0, ''];
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return [$status, is_string($raw) ? $raw : ''];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents(MEDCONNECT_RAILWAY_EMAIL_URL, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $headerLine) {
            if (preg_match('#HTTP/\S+\s+(\d+)#', $headerLine, $match) === 1) {
                $status = (int) $match[1];
            }
        }
        return [$status, is_string($raw) ? $raw : ''];
    }
}

function initMailer(): ?MedConnectRailwayMailer
{
    $token = medconnect_mail_env('MEDCONNECT_AI_SERVICE_TOKEN');
    $emailKey = medconnect_mail_env('EMAIL_API_KEY');
    $missing = [];
    if ($token === '') {
        $missing[] = 'MEDCONNECT_AI_SERVICE_TOKEN';
    }
    if ($emailKey === '') {
        $missing[] = 'EMAIL_API_KEY';
    }
    if ($missing !== []) {
        error_log('Mailer init failed: missing environment variables: ' . implode(', ', $missing));
        return null;
    }

    return new MedConnectRailwayMailer($token, $emailKey);
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
