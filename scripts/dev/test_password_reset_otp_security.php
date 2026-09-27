<?php
/**
 * Password-reset OTP brute-force limits.
 * Run: php scripts/dev/test_password_reset_otp_security.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = 0;

function pass(string $m): void
{
    echo "PASS  {$m}\n";
}

function fail(string $m, string $d = ''): void
{
    global $failures;
    $failures++;
    echo "FAIL  {$m}" . ($d !== '' ? " — {$d}" : '') . "\n";
}

require_once $root . '/app/includes/password_reset_otp.php';

function issue(array &$session, string $otp, int $now, string $email = 'user@example.com'): void
{
    password_reset_otp_store($session, $email, 'patient', 7, $otp, $now + 600, 'test-session', $now);
}

$session = [];
$otp = '123456';
issue($session, $otp, 1_000);
$near = password_reset_otp_verify($session, 'user@example.com', '123450', 'test-session', 1_000);
$far = password_reset_otp_verify($session, 'user@example.com', '000000', 'test-session', 1_001);
if ($near['message'] === $far['message']
    && $near['message'] === 'Incorrect OTP. Please try again.'
    && !str_contains($near['message'], $otp)
    && !str_contains($far['message'], '12345')
    && !array_key_exists('matched', $near)
    && !array_key_exists('digits', $near)
) {
    pass('wrong codes share one message and do not reveal digits');
} else {
    fail('wrong codes share one message and do not reveal digits', $near['message'] . ' / ' . $far['message']);
}

$session = [];
issue($session, $otp, 2_000);
$ok = password_reset_otp_verify($session, 'user@example.com', $otp, 'test-session', 2_000);
if (!empty($ok['success']) && !empty($session['reset_verified']) && ($session['reset_expiry'] ?? 0) === 2_600) {
    pass('correct OTP still verifies inside 10 minutes');
} else {
    fail('correct OTP still verifies inside 10 minutes');
}

$session = [];
issue($session, $otp, 3_000);
for ($i = 0; $i < PASSWORD_RESET_OTP_MAX_VERIFIES_PER_WINDOW; $i++) {
    password_reset_otp_verify($session, 'user@example.com', '000000', 'test-session', 3_000);
}
$limited = password_reset_otp_verify($session, 'user@example.com', $otp, 'test-session', 3_000);
if (empty($limited['success'])
    && $limited['message'] === 'Too many attempts. Please wait before trying again.'
    && empty($session['reset_verified'])
    && !empty($session['reset_otp'])
    && !str_contains($limited['message'], $otp)
) {
    pass('repeated verification requests are rate-limited');
} else {
    fail('repeated verification requests are rate-limited', (string) ($limited['message'] ?? ''));
}

$session = [];
issue($session, $otp, 4_000);
$locked = null;
for ($i = 0; $i < PASSWORD_RESET_OTP_MAX_FAILURES; $i++) {
    $locked = password_reset_otp_verify(
        $session,
        'user@example.com',
        '000000',
        'test-session',
        4_000 + ($i * PASSWORD_RESET_OTP_VERIFY_WINDOW_SECONDS)
    );
}
$after = password_reset_otp_verify($session, 'user@example.com', $otp, 'test-session', 4_000 + 600);
if (($locked['message'] ?? '') === 'Too many incorrect attempts. Please request a new OTP.'
    && empty($session['reset_otp'])
    && empty($after['success'])
    && empty($session['reset_verified'])
    && !str_contains((string) $after['message'], $otp)
) {
    pass('OTP is invalidated after the failed-attempt limit');
} else {
    fail('OTP is invalidated after the failed-attempt limit', (string) ($locked['message'] ?? '') . ' / ' . (string) ($after['message'] ?? ''));
}

$session = [];
issue($session, $otp, 5_000);
for ($i = 0; $i < PASSWORD_RESET_OTP_MAX_FAILURES; $i++) {
    password_reset_otp_verify(
        $session,
        'user@example.com',
        '111111',
        'test-session',
        5_000 + ($i * PASSWORD_RESET_OTP_VERIFY_WINDOW_SECONDS)
    );
}
issue($session, '654321', 5_400);
$fresh = password_reset_otp_verify($session, 'user@example.com', '654321', 'test-session', 5_400);
if (!empty($fresh['success']) && !empty($session['reset_verified'])) {
    pass('a newly requested OTP can still be verified');
} else {
    fail('a newly requested OTP can still be verified', (string) ($fresh['message'] ?? ''));
}

$legacy = [];
$legacyOtp = '246810';
$legacy['reset_email'] = 'user@example.com';
$legacy['reset_otp'] = password_hash($legacyOtp, PASSWORD_BCRYPT, ['cost' => 4]);
$legacy['reset_expiry'] = 6_600;
$legacy['reset_verified'] = false;
$legacyOk = password_reset_otp_verify($legacy, 'user@example.com', $legacyOtp, 'test-session', 6_000);
if (!empty($legacyOk['success'])) {
    pass('legacy stored OTP still verifies once');
} else {
    fail('legacy stored OTP still verifies once', (string) ($legacyOk['message'] ?? ''));
}

$request = (string) file_get_contents($root . '/app/api/request_password_reset.php');
$verifyApi = (string) file_get_contents($root . '/app/api/verify_reset_otp.php');
$verifyController = (string) file_get_contents($root . '/app/controllers/auth/verify_reset_otp.php');
if (str_contains($request, "str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)")
    && str_contains($request, '$expiry = time() + 600;')
    && str_contains($request, 'password_reset_otp_store(')
    && str_contains($verifyApi, 'password_reset_otp_verify(')
    && str_contains($verifyController, 'password_reset_otp_verify(')
    && !str_contains($verifyApi, 'substr(')
) {
    pass('6-digit OTP, 10-minute expiry, and reset workflow stay in place');
} else {
    fail('6-digit OTP, 10-minute expiry, and reset workflow stay in place');
}

echo "\n";
if ($failures === 0) {
    echo "PASS — password-reset OTP brute-force checks passed\n";
    exit(0);
}
echo "FAIL — {$failures} check(s) failed\n";
exit(1);
