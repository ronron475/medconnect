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

/**
 * Same env resolution style as config/db.php ($envString).
 * Order: getenv → $_ENV → $_SERVER (never logs values).
 */
if (!function_exists('medconnect_mail_env_string')) {
    function medconnect_mail_env_string(string $key): ?string
    {
        $candidates = [
            getenv($key),
            $_ENV[$key] ?? null,
            $_SERVER[$key] ?? null,
            $_SERVER['REDIRECT_' . $key] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if ($candidate !== false && $candidate !== null && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return null;
    }
}

// Resolve MAIL_* exactly like config/db.php: environment first, then temporary
// Hostinger hardcoded fallbacks so production works without a server .env.
// TODO(temporary-hostinger-recovery): Remove hardcoded mail fallbacks after
// Hostinger administrator configures MAIL_* / EMAIL_API_KEY / MEDCONNECT_AI_SERVICE_TOKEN
// in server .env. Env always wins.
$mailHost = medconnect_mail_env_string('MAIL_HOST') ?: 'smtp.gmail.com';
$mailPort = medconnect_mail_env_string('MAIL_PORT') ?: '587';
$mailUser = medconnect_mail_env_string('MAIL_USERNAME') ?: 'medconnect678@gmail.com';
$mailPass = (string) (medconnect_mail_env_string('MAIL_PASSWORD') ?? '');
if ($mailPass === '') {
    // TODO(temporary-hostinger-recovery): remove after Hostinger .env has MAIL_PASSWORD.
    $mailPass = 'kdivsmhihjifejzz';
}
$mailFromEmail = medconnect_mail_env_string('MAIL_FROM_EMAIL') ?: 'medconnect678@gmail.com';
if ($mailFromEmail === '' || !filter_var($mailFromEmail, FILTER_VALIDATE_EMAIL)) {
    $mailFromEmail = $mailUser !== '' ? $mailUser : 'medconnect678@gmail.com';
}
$mailFromName = medconnect_mail_env_string('MAIL_FROM_NAME') ?: 'MedConnect Bago City';
$mailEmailApiKey = medconnect_mail_env_string('EMAIL_API_KEY') ?: 'mc-email-7f92b8d4a1e6c3f0-2026';
$mailAiToken = medconnect_mail_env_string('MEDCONNECT_AI_SERVICE_TOKEN')
    ?: 'bQzpoa-8I0kbKZIpNd9oDXv3CRwFhrFOGjx6Cz2ie_I';

if (!defined('MAIL_HOST')) {
    define('MAIL_HOST', $mailHost);
}
if (!defined('MAIL_PORT')) {
    define('MAIL_PORT', $mailPort);
}
if (!defined('MAIL_USERNAME')) {
    define('MAIL_USERNAME', $mailUser);
}
if (!defined('MAIL_PASSWORD')) {
    define('MAIL_PASSWORD', $mailPass);
}
if (!defined('MAIL_FROM_EMAIL')) {
    define('MAIL_FROM_EMAIL', $mailFromEmail);
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', $mailFromName);
}
if (!defined('EMAIL_API_KEY')) {
    define('EMAIL_API_KEY', $mailEmailApiKey);
}
if (!defined('MEDCONNECT_AI_SERVICE_TOKEN')) {
    define('MEDCONNECT_AI_SERVICE_TOKEN', $mailAiToken);
}

if (!function_exists('medconnect_railway_email_url')) {
    function medconnect_railway_email_url(): string
    {
        $custom = getenv('MEDCONNECT_EMAIL_RELAY_URL') ?: (getenv('MEDCONNECT_EMAIL_URL') ?: '');
        if ($custom !== false && trim((string) $custom) !== '') {
            return trim((string) $custom);
        }
        $base = '';
        if (defined('AI_SERVICE_BASE_URL') && AI_SERVICE_BASE_URL !== '') {
            $base = rtrim((string) AI_SERVICE_BASE_URL, '/');
        } else {
            $aiUrl = getenv('MEDCONNECT_AI_SERVICE_URL') ?: '';
            if ($aiUrl !== false && trim((string) $aiUrl) !== '') {
                $base = rtrim(trim((string) $aiUrl), '/');
            }
        }
        // Retired Railway hostname — use current production email relay.
        if ($base !== '' && str_contains($base, 'medconnect-production-f2b7.up.railway.app')) {
            $base = 'https://medconnect-production-ab04.up.railway.app';
        }
        if ($base !== '') {
            return $base . '/email/send';
        }
        return 'https://medconnect-production-ab04.up.railway.app/email/send';
    }
}

if (!defined('MEDCONNECT_RAILWAY_EMAIL_URL')) {
    define('MEDCONNECT_RAILWAY_EMAIL_URL', medconnect_railway_email_url());
}

function medconnect_mail_env(string $key): string
{
    // 1) Live environment (same as db.php $envString) — allows tests / runtime overrides.
    $live = medconnect_mail_env_string($key);
    if ($live !== null) {
        return $live;
    }

    // 2) Constants resolved at include time (db.php-style Hostinger fallbacks).
    if (defined($key)) {
        $constantValue = constant($key);
        if (is_string($constantValue) || is_numeric($constantValue)) {
            $trimmed = trim((string) $constantValue);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }
    }

    // 3) Optional private PHP/env file overrides.
    $fromFile = medconnect_mail_env_from_file($key);
    if ($fromFile !== '') {
        return $fromFile;
    }

    return medconnect_mail_fallback($key);
}

/**
 * Resolve a mail-related key from private env files (never from PHP source).
 * Also supports MEDCONNECT_MAIL_CONFIG → absolute path to a PHP file outside
 * public_html that returns ['MAIL_USERNAME' => ..., 'MAIL_PASSWORD' => ..., ...].
 */
function medconnect_mail_env_from_file(string $key): string
{
    static $mailConfig = null;
    if ($mailConfig === null) {
        $mailConfig = [];
        $cfgPath = getenv('MEDCONNECT_MAIL_CONFIG');
        if ($cfgPath === false || trim((string) $cfgPath) === '') {
            $cfgPath = $_ENV['MEDCONNECT_MAIL_CONFIG'] ?? ($_SERVER['MEDCONNECT_MAIL_CONFIG'] ?? '');
        }
        $cfgPath = trim((string) $cfgPath);
        if ($cfgPath !== '' && is_readable($cfgPath)) {
            $loaded = include $cfgPath;
            if (is_array($loaded)) {
                foreach ($loaded as $name => $value) {
                    if (!is_string($name) || (!is_string($value) && !is_numeric($value))) {
                        continue;
                    }
                    $mailConfig[$name] = trim((string) $value);
                }
            }
        }
    }
    if (isset($mailConfig[$key]) && $mailConfig[$key] !== '') {
        return $mailConfig[$key];
    }

    $paths = function_exists('medconnect_env_candidate_paths')
        ? medconnect_env_candidate_paths()
        : [dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env'];

    foreach ($paths as $path) {
        if (!is_readable($path)) {
            continue;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
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
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            return $value;
        }
    }

    return '';
}

function medconnect_mail_fallback(string $key): string
{
    // Credentials are resolved like config/db.php into MAIL_* / EMAIL_* constants above.
    // Keep this helper for callers; values come from those constants when env is empty.
    if (defined($key)) {
        $constantValue = constant($key);
        if (is_string($constantValue) || is_numeric($constantValue)) {
            return trim((string) $constantValue);
        }
    }

    return '';
}

/** Safe log line for mail failures (never includes passwords, tokens, or SMTP auth text). */
function medconnect_mail_log_failure(string $context): void
{
    $context = preg_replace('/\s+/', ' ', trim($context)) ?: 'mail';
    error_log('MedConnect Mailer: ' . $context . ' failed.');
}

function medconnect_direct_smtp_available(): bool
{
    $user = medconnect_mail_env('MAIL_USERNAME');
    $pass = medconnect_mail_env('MAIL_PASSWORD');
    return $user !== '' && $pass !== '';
}

/**
 * Hostinger-friendly fallback using the server MTA (no outbound SMTP sockets).
 * Prefer a From address on the site domain so the local MTA accepts the message.
 */
function medconnect_php_mail_deliver(string $to, string $subject, string $html, string $text): void
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Email could not be sent.');
    }

    $from = medconnect_mail_env('MAIL_FROM_EMAIL');
    if ($from === '') {
        $from = medconnect_mail_env('MAIL_USERNAME');
    }
    $fromName = medconnect_mail_env('MAIL_FROM_NAME');
    if ($fromName === '') {
        $fromName = 'MedConnect Bago City';
    }

    $siteHost = '';
    if (defined('BASE_URL') && is_string(BASE_URL) && BASE_URL !== '') {
        $siteHost = (string) parse_url(BASE_URL, PHP_URL_HOST);
    }
    if ($siteHost === '' && !empty($_SERVER['HTTP_HOST'])) {
        $siteHost = preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']) ?: '';
    }
    $fromDomain = strtolower((string) substr(strrchr($from, '@') ?: '', 1));
    if (
        $siteHost !== ''
        && ($from === '' || in_array($fromDomain, ['gmail.com', 'googlemail.com'], true))
    ) {
        $from = 'noreply@' . $siteHost;
    }
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Email could not be sent.');
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $encodedFromName . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'X-Mailer: MedConnect',
    ];
    $body = $html !== '' ? $html : nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));

    $ok = @mail($to, $encodedSubject, $body, implode("\r\n", $headers), '-f' . $from);
    if (!$ok) {
        medconnect_mail_log_failure('PHP mail()');
        throw new Exception('Email could not be sent.');
    }
}

function medconnect_railway_relay_available(): bool
{
    $token = medconnect_mail_env('MEDCONNECT_AI_SERVICE_TOKEN');
    $emailKey = medconnect_mail_env('EMAIL_API_KEY');
    return $token !== '' && $emailKey !== '';
}

function medconnect_smtp_read($fp): string
{
    $data = '';
    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) {
            break;
        }
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    return $data;
}

function medconnect_smtp_code(string $response): int
{
    return (int) substr($response, 0, 3);
}

function medconnect_smtp_cmd($fp, string $command, array $ok): void
{
    fwrite($fp, $command . "\r\n");
    $code = medconnect_smtp_code(medconnect_smtp_read($fp));
    if (!in_array($code, $ok, true)) {
        error_log('Direct SMTP failed: code ' . $code);
        throw new Exception('Email could not be sent.');
    }
}

function medconnect_smtp_connect(bool $verify)
{
    $host = medconnect_mail_env('MAIL_HOST');
    if ($host === '') {
        $host = 'smtp.gmail.com';
    }
    $port = (int) medconnect_mail_env('MAIL_PORT');
    if ($port <= 0) {
        $port = 587;
    }
    $ssl = [
        'verify_peer' => $verify,
        'verify_peer_name' => $verify,
        'allow_self_signed' => !$verify,
    ];
    $ca = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem';
    if ($verify && is_readable($ca)) {
        $ssl['cafile'] = $ca;
    }
    $errno = 0;
    $err = '';
    $remote = ($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $err, 15, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $ssl]));
    if (!is_resource($fp)) {
        throw new Exception('Email could not be sent.');
    }
    stream_set_timeout($fp, 15);

    return [$fp, $port];
}

function medconnect_smtp_deliver(string $to, string $subject, string $html, string $text): void
{
    $user = medconnect_mail_env('MAIL_USERNAME');
    $pass = str_replace(' ', '', medconnect_mail_env('MAIL_PASSWORD'));
    $from = medconnect_mail_env('MAIL_FROM_EMAIL');
    if ($from === '') {
        $from = $user;
    }
    $fromName = medconnect_mail_env('MAIL_FROM_NAME');
    if ($fromName === '') {
        $fromName = 'MedConnect Bago City';
    }
    if ($user === '' || $pass === '') {
        medconnect_mail_log_failure('SMTP credentials missing');
        throw new Exception('Email service is not configured.');
    }
    try {
        medconnect_smtp_deliver_with($to, $subject, $html, $text, $user, $pass, $from, $fromName);
    } catch (Exception $e) {
        medconnect_mail_log_failure('SMTP delivery');
        throw new Exception('Email could not be sent.');
    }
}

function medconnect_phpmailer_autoload(): bool
{
    if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class, false)) {
        return true;
    }
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (is_readable($autoload)) {
        require_once $autoload;
    }
    return class_exists(\PHPMailer\PHPMailer\PHPMailer::class);
}

/**
 * Human-readable mail transport status (no secrets).
 *
 * @return array{smtp:bool,relay:bool,message:string}
 */
function medconnect_mailer_status(): array
{
    $smtp = medconnect_direct_smtp_available();
    $relay = medconnect_railway_relay_available();
    if ($smtp && $relay) {
        $message = 'Direct SMTP and Railway email relay are configured.';
    } elseif ($smtp) {
        $message = 'Direct SMTP is configured. On Hostinger, outbound ports 587/465 are often blocked — Railway relay is recommended.';
    } elseif ($relay) {
        $message = 'Railway email relay is configured.';
    } else {
        $message = 'Email is not configured. Set MAIL_USERNAME and MAIL_PASSWORD for SMTP, or EMAIL_API_KEY and MEDCONNECT_AI_SERVICE_TOKEN for the Railway relay.';
    }

    return ['smtp' => $smtp, 'relay' => $relay, 'message' => $message];
}

function medconnect_smtp_deliver_with(
    string $to,
    string $subject,
    string $html,
    string $text,
    string $user,
    string $pass,
    string $from,
    string $fromName
): void {
    if (!medconnect_phpmailer_autoload()) {
        error_log('MedConnect Mailer: PHPMailer library is missing (vendor/phpmailer).');
        throw new Exception('Email service is not configured.');
    }

    $host = medconnect_mail_env('MAIL_HOST');
    if ($host === '') {
        $host = 'smtp.gmail.com';
    }
    $port = (int) medconnect_mail_env('MAIL_PORT');
    if ($port <= 0) {
        $port = 587;
    }

    $ca = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem';
    $lastInfo = '';

    // Strict TLS first; on broken local CA bundles (common on XAMPP) retry relaxed
    // like the Railway relay path — auth still required over STARTTLS/SMTPS.
    foreach ([true, false] as $verify) {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->SMTPAuth = true;
            $mail->Username = $user;
            $mail->Password = $pass;
            $mail->Port = $port;
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 15;
            if ($port === 465) {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }

            if ($verify && is_readable($ca)) {
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'allow_self_signed' => false,
                        'cafile' => $ca,
                    ],
                ];
            } elseif (!$verify) {
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ],
                ];
            }

            $mail->setFrom($from, $fromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $text !== '' ? $text : strip_tags($html);
            $mail->send();
            return;
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            $lastInfo = trim((string) $mail->ErrorInfo);
            $certFail = stripos($lastInfo, 'certificate') !== false
                || stripos($lastInfo, 'ssl') !== false
                || stripos($lastInfo, 'enable_crypto') !== false;
            if ($verify && $certFail) {
                error_log('MedConnect Mailer: SMTP TLS verify failed; retrying with relaxed SSL (XAMPP/Hostinger).');
                continue;
            }
            medconnect_mail_log_failure('PHPMailer SMTP');
            if (stripos($lastInfo, 'authenticate') !== false || stripos($lastInfo, '535') !== false) {
                throw new Exception('smtp-auth');
            }
            throw new Exception('Email could not be sent.');
        }
    }

    medconnect_mail_log_failure('PHPMailer SMTP');
    if (stripos($lastInfo, 'authenticate') !== false || stripos($lastInfo, '535') !== false) {
        throw new Exception('smtp-auth');
    }
    throw new Exception('Email could not be sent.');
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

        // Hostinger often blocks outbound SMTP 587/465. Prefer HTTPS Railway relay first.
        if (medconnect_railway_relay_available()) {
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
            if ($ok) {
                return true;
            }
            if ($status === 0) {
                error_log('Railway email API failed: connection/timeout; trying direct SMTP.');
            } else {
                error_log('Railway email API failed: HTTP ' . $status . '; trying direct SMTP.');
            }
        }

        if (medconnect_direct_smtp_available()) {
            try {
                medconnect_smtp_deliver($this->recipient, trim($this->Subject), $this->Body, $this->AltBody);
                return true;
            } catch (Exception $e) {
                error_log('Direct SMTP delivery attempt failed.');
            }
        }

        // Last resort on Hostinger shared hosting: local MTA via PHP mail().
        // Gmail SMTP ports are often blocked from both Hostinger and Railway.
        try {
            medconnect_php_mail_deliver($this->recipient, trim($this->Subject), $this->Body, $this->AltBody);
            return true;
        } catch (Exception $e) {
            error_log('PHP mail() delivery attempt failed.');
        }

        error_log('Mailer send failed: Railway relay, direct SMTP, and PHP mail() all failed.');
        throw new Exception('Email could not be sent.');
    }

    /** @return array{0:int,1:string} */
    private function postJson(string $body): array
    {
        $relayUrl = function_exists('medconnect_railway_email_url')
            ? medconnect_railway_email_url()
            : (defined('MEDCONNECT_RAILWAY_EMAIL_URL') ? (string) MEDCONNECT_RAILWAY_EMAIL_URL : '');
        $relayUrl = trim($relayUrl);
        if ($relayUrl === '' || $this->token === '' || $this->emailKey === '') {
            return [0, ''];
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->token,
            'X-Email-Api-Key: ' . $this->emailKey,
        ];
        if (function_exists('curl_init')) {
            $ca = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem';
            if (!is_readable($ca)) {
                $ca = (string) (ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: '');
            }

            // Try strict TLS first; some Hostinger PHP builds fail CA verify (curl errno 60).
            foreach ([true, false] as $verify) {
                $ch = curl_init($relayUrl);
                if ($ch === false) {
                    return [0, ''];
                }
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 25,
                    CURLOPT_CONNECTTIMEOUT => 8,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_SSL_VERIFYPEER => $verify,
                    CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
                ]);
                if ($verify && $ca !== '' && is_readable($ca)) {
                    curl_setopt($ch, CURLOPT_CAINFO, $ca);
                }
                $raw = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $errno = (int) curl_errno($ch);
                curl_close($ch);
                if ($raw !== false && $errno === 0) {
                    return [$status, is_string($raw) ? $raw : ''];
                }
                if ($verify && $errno === 60) {
                    error_log('Railway email API TLS verify failed; retrying with relaxed SSL for Hostinger.');
                    continue;
                }
                return [0, ''];
            }
            return [0, ''];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 25,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $raw = @file_get_contents($relayUrl, false, $context);
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
    $smtpAvailable = medconnect_direct_smtp_available();
    $relayAvailable = medconnect_railway_relay_available();

    if (!$smtpAvailable && !$relayAvailable) {
        $status = medconnect_mailer_status();
        error_log('Mailer init failed: ' . $status['message']);
        return null;
    }

    $token = medconnect_mail_env('MEDCONNECT_AI_SERVICE_TOKEN');
    $emailKey = medconnect_mail_env('EMAIL_API_KEY');

    return new MedConnectRailwayMailer($token, $emailKey);
}

function sendVerificationEmail($to, $verificationToken, $fullName) {
    $mail = initMailer();
    if (!$mail) {
        return ['success' => false, 'message' => medconnect_mailer_status()['message']];
    }
    try {
        $url = BASE_URL . '/public/verify.php?token=' . urlencode($verificationToken);
        $mail->addAddress($to);
        $mail->Subject = 'Verify Your MedConnect Account';
        $mail->Body    = "<p>Dear {$fullName},</p><p><a href='{$url}'>Click here</a> to verify. Expires in 24 hours.</p>";
        $mail->AltBody = "Verify: {$url}";
        $mail->send();
        return ['success' => true, 'message' => 'Email sent.'];
    } catch (Exception $e) {
        medconnect_mail_log_failure('verification email');
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
        return ['success' => false, 'message' => medconnect_mailer_status()['message']];
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
        medconnect_mail_log_failure('patient welcome email');
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
        return ['success' => false, 'message' => medconnect_mailer_status()['message']];
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
        medconnect_mail_log_failure('BHW invite email');
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
        return ['success' => false, 'message' => medconnect_mailer_status()['message']];
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
        medconnect_mail_log_failure('BHW correction email');
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
        return ['success' => false, 'message' => medconnect_mailer_status()['message']];
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
        medconnect_mail_log_failure('follow-up email');
        return ['success' => false, 'message' => 'Failed to send follow-up email.'];
    }
}
