<?php
/**
 * Google reCAPTCHA v2 server verification (registration and other public forms).
 * Secret is read from the environment only. Never log the secret or full token.
 */

function medconnect_recaptcha_env(string $name): string
{
    foreach ([getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $candidate) {
        if ($candidate !== false && $candidate !== null && trim((string) $candidate) !== '') {
            return trim((string) $candidate);
        }
    }

    return '';
}

function medconnect_recaptcha_site_key(): string
{
    return medconnect_recaptcha_env('RECAPTCHA_SITE_KEY');
}

/**
 * Verify a v2 checkbox token with Google siteverify. Fail closed.
 *
 * @return array{ok: bool, message: string}
 */
function medconnect_recaptcha_verify(string $token, string $remoteIp = ''): array
{
    $fail = static function (string $message): array {
        return ['ok' => false, 'message' => $message];
    };

    $token = trim($token);
    if ($token === '') {
        return $fail('Please confirm you are not a robot.');
    }

    $secret = medconnect_recaptcha_env('RECAPTCHA_SECRET_KEY');
    if ($secret === '') {
        error_log('reCAPTCHA verify failed: secret not configured');
        return $fail('Unable to verify you are not a robot. Please try again later.');
    }

    $post = [
        'secret'   => $secret,
        'response' => $token,
    ];
    $remoteIp = trim($remoteIp);
    if ($remoteIp !== '') {
        $post['remoteip'] = $remoteIp;
    }

    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    if ($ch === false) {
        error_log('reCAPTCHA verify failed: curl_init');
        return $fail('Unable to verify you are not a robot. Please try again later.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $errno !== 0 || $code < 200 || $code >= 300) {
        error_log('reCAPTCHA verify failed: http=' . $code . ' errno=' . $errno);
        return $fail('Unable to verify you are not a robot. Please try again later.');
    }

    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        error_log('reCAPTCHA verify failed: invalid JSON');
        return $fail('Unable to verify you are not a robot. Please try again later.');
    }

    if (!empty($data['success'])) {
        return ['ok' => true, 'message' => ''];
    }

    error_log('reCAPTCHA verify failed: google success=false');
    return $fail('Please confirm you are not a robot.');
}
