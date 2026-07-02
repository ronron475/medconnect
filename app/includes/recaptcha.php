<?php
declare(strict_types=1);

/**
 * Google reCAPTCHA server-side verification (v3 preferred; v2 fallback).
 *
 * Env vars (via .env):
 * - MEDCONNECT_RECAPTCHA_SITE_KEY
 * - MEDCONNECT_RECAPTCHA_SECRET_KEY
 * - MEDCONNECT_RECAPTCHA_VERSION   ("v3" | "v2")
 * - MEDCONNECT_RECAPTCHA_MIN_SCORE (v3 only; default 0.5)
 */

function recaptcha_is_configured(): bool
{
    return defined('RECAPTCHA_SITE_KEY')
        && defined('RECAPTCHA_SECRET_KEY')
        && (string) RECAPTCHA_SITE_KEY !== ''
        && (string) RECAPTCHA_SECRET_KEY !== '';
}

function recaptcha_version(): string
{
    $v = defined('RECAPTCHA_VERSION') ? strtolower((string) RECAPTCHA_VERSION) : 'v3';
    return in_array($v, ['v3', 'v2'], true) ? $v : 'v3';
}

function recaptcha_min_score(): float
{
    $s = defined('RECAPTCHA_MIN_SCORE') ? (float) RECAPTCHA_MIN_SCORE : 0.5;
    if ($s <= 0) return 0.0;
    if ($s >= 1) return 1.0;
    return $s;
}

/**
 * @return array{
 *   ok: bool,
 *   version: string,
 *   score?: float|null,
 *   action?: string|null,
 *   error_codes?: array<int, string>
 * }
 */
function recaptcha_verify_token(string $token, string $expectedAction = '', string $remoteIp = ''): array
{
    $token = trim($token);
    if ($token === '' || !recaptcha_is_configured()) {
        return ['ok' => false, 'version' => recaptcha_version(), 'error_codes' => ['missing-input-response']];
    }

    $post = http_build_query([
        'secret'   => (string) RECAPTCHA_SECRET_KEY,
        'response' => $token,
        'remoteip' => $remoteIp !== '' ? $remoteIp : null,
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n"
                      . "Connection: close\r\n",
            'content' => $post,
            'timeout' => 5,
        ],
    ]);

    $raw = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $ctx);
    if ($raw === false) {
        return ['ok' => false, 'version' => recaptcha_version(), 'error_codes' => ['network-error']];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'version' => recaptcha_version(), 'error_codes' => ['invalid-json']];
    }

    $success = (bool) ($data['success'] ?? false);
    $errors = $data['error-codes'] ?? [];
    $errors = is_array($errors) ? array_values(array_map('strval', $errors)) : [];

    $version = recaptcha_version();
    if (!$success) {
        return ['ok' => false, 'version' => $version, 'error_codes' => $errors];
    }

    // v3 checks (score + action)
    $score = isset($data['score']) ? (float) $data['score'] : null;
    $action = isset($data['action']) ? (string) $data['action'] : null;

    if ($version === 'v3') {
        if ($score === null) {
            return ['ok' => false, 'version' => $version, 'score' => null, 'action' => $action, 'error_codes' => ['missing-score']];
        }
        if ($expectedAction !== '' && $action !== null && $action !== $expectedAction) {
            return ['ok' => false, 'version' => $version, 'score' => $score, 'action' => $action, 'error_codes' => ['bad-action']];
        }
        if ($score < recaptcha_min_score()) {
            return ['ok' => false, 'version' => $version, 'score' => $score, 'action' => $action, 'error_codes' => ['low-score']];
        }
    }

    return ['ok' => true, 'version' => $version, 'score' => $score, 'action' => $action, 'error_codes' => $errors];
}

function recaptcha_client_key(): string
{
    return defined('RECAPTCHA_SITE_KEY') ? (string) RECAPTCHA_SITE_KEY : '';
}

