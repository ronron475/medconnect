<?php
/**
 * Password-reset OTP checks.
 * The code stays a 6-digit value with a 10-minute lifetime.
 * Guessing is capped per code, and verification calls are rate-limited.
 * Responses never indicate which digit was correct.
 */
declare(strict_types=1);

const PASSWORD_RESET_OTP_MAX_FAILURES = 5;
const PASSWORD_RESET_OTP_MAX_VERIFIES_PER_WINDOW = 4;
const PASSWORD_RESET_OTP_VERIFY_WINDOW_SECONDS = 60;

function password_reset_otp_hash(string $otp, string $sessionKey): string
{
    return hash_hmac('sha256', $otp, $sessionKey . '|pwreset');
}

function password_reset_otp_matches(string $provided, string $stored, string $sessionKey): bool
{
    if ($provided === '' || $stored === '') {
        return false;
    }
    if (str_starts_with($stored, '$2')) {
        return password_verify($provided, $stored);
    }
    return hash_equals($stored, password_reset_otp_hash($provided, $sessionKey));
}

/**
 * Store a newly issued OTP and clear any previous guess counters.
 *
 * @param array<string, mixed> $session
 */
function password_reset_otp_store(
    array &$session,
    string $email,
    string $role,
    int $userId,
    string $otp,
    int $expiry,
    string $sessionKey,
    int $now
): void {
    $session['reset_email'] = $email;
    $session['reset_role'] = $role;
    $session['reset_user_id'] = $userId;
    $session['reset_otp'] = password_reset_otp_hash($otp, $sessionKey);
    $session['reset_expiry'] = $expiry;
    $session['reset_verified'] = false;
    $session['reset_attempts'] = (int) ($session['reset_attempts'] ?? 0) + 1;
    $session['reset_last_sent'] = $now;
    $session['reset_otp_failures'] = 0;
    $session['reset_verify_hits'] = 0;
    $session['reset_verify_window_start'] = 0;
}

/**
 * @param array<string, mixed> $session
 * @return array{success: bool, message: string}
 */
function password_reset_otp_verify(array &$session, string $email, string $otp, string $sessionKey, int $now): array
{
    if (empty($session['reset_email']) || empty($session['reset_otp']) || empty($session['reset_expiry'])) {
        return password_reset_otp_result(false, 'No OTP found. Please request a new one.');
    }

    if ((string) $session['reset_email'] !== $email) {
        return password_reset_otp_result(false, 'Email mismatch. Please request a new OTP.');
    }

    if ($now > (int) $session['reset_expiry']) {
        unset($session['reset_otp'], $session['reset_expiry'], $session['reset_verified']);
        return password_reset_otp_result(false, 'OTP has expired. Please request a new one.');
    }

    $windowStart = (int) ($session['reset_verify_window_start'] ?? 0);
    $hits = (int) ($session['reset_verify_hits'] ?? 0);
    if ($windowStart === 0 || ($now - $windowStart) >= PASSWORD_RESET_OTP_VERIFY_WINDOW_SECONDS) {
        $windowStart = $now;
        $hits = 0;
    }
    $hits++;
    $session['reset_verify_window_start'] = $windowStart;
    $session['reset_verify_hits'] = $hits;
    if ($hits > PASSWORD_RESET_OTP_MAX_VERIFIES_PER_WINDOW) {
        return password_reset_otp_result(false, 'Too many attempts. Please wait before trying again.');
    }

    $stored = (string) $session['reset_otp'];
    if (!password_reset_otp_matches($otp, $stored, $sessionKey)) {
        $failures = (int) ($session['reset_otp_failures'] ?? 0) + 1;
        $session['reset_otp_failures'] = $failures;
        if ($failures >= PASSWORD_RESET_OTP_MAX_FAILURES) {
            unset($session['reset_otp'], $session['reset_expiry'], $session['reset_verified']);
            return password_reset_otp_result(false, 'Too many incorrect attempts. Please request a new OTP.');
        }
        return password_reset_otp_result(false, 'Incorrect OTP. Please try again.');
    }

    $session['reset_verified'] = true;
    $session['reset_otp_failures'] = 0;
    return password_reset_otp_result(true, 'OTP verified. You may now set a new password.');
}

/**
 * @return array{success: bool, message: string}
 */
function password_reset_otp_result(bool $success, string $message): array
{
    return ['success' => $success, 'message' => $message];
}
