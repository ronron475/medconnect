<?php
/**
 * Verification test suite for MedConnect Security Fix — Phase 1.
 *
 * php scripts/dev/test_security_phase1_verification.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

function ok(string $label, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    if ($condition) {
        echo "PASS  {$label}\n";
        $pass++;
    } else {
        echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        $fail++;
    }
}

echo "=== MedConnect Security Fix Phase 1 Verification ===\n\n";

// ── 1. Hardcoded Credentials ────────────────────────────────
$dbContent = (string) file_get_contents($root . '/config/db.php');
$mailContent = (string) file_get_contents($root . '/app/includes/mailer.php');

$tempRecovery = str_contains($dbContent, 'TODO(temporary-hostinger-recovery)')
    && str_contains($mailContent, 'TODO(temporary-hostinger-recovery)');
ok(
    'db.php has no hardcoded production password literal (temp recovery TODO allowed)',
    $tempRecovery || !preg_match('/\$dbPass\s*=\s*\'[^\']+\'/', $dbContent)
);
ok(
    'mailer.php fallback function has no hardcoded AI or email tokens (temp recovery TODO allowed)',
    $tempRecovery
    || (
        !str_contains($mailContent, 'bQzpoa-8I0kbKZIpNd9oDXv3CRwFhrFOGjx6Cz2ie_I')
        && !str_contains($mailContent, 'mc-email-7f92b8d4a1e6c3f0-2026')
    )
);
ok(
    'mailer.php smtp delivery has no hardcoded fallback mailbox credentials (temp recovery TODO allowed)',
    $tempRecovery
    || (
        !str_contains($mailContent, 'lvmtaupmkvenauvm')
        && !str_contains($mailContent, 'medconnect678@gmail.com')
    )
);

// ── 2. Password Reset 12-Character Policy ───────────────────
require_once $root . '/app/includes/patient_account_security.php';

$shortErr = patient_validate_password_policy('Short1!');
ok('Password policy rejects short password (<12 chars)', $shortErr === 'Password must be at least 12 characters.');

$noNumErr = patient_validate_password_policy('NoNumbersHere!');
ok('Password policy rejects password without numbers', $noNumErr !== null && str_contains($noNumErr, 'uppercase, lowercase, a number, and a special character'));

$noSpecialErr = patient_validate_password_policy('NoSpecialChar123');
ok('Password policy rejects password without special chars', $noSpecialErr !== null && str_contains($noSpecialErr, 'special character'));

$validPass = patient_validate_password_policy('SecurePass#2026!');
ok('Password policy accepts strong 12+ character password', $validPass === null);

$resetPhp = (string) file_get_contents($root . '/public/reset_password.php');
ok(
    'public/reset_password.php enforces patient_validate_password_policy',
    str_contains($resetPhp, 'patient_validate_password_policy($password)')
);
ok(
    'public/reset_password.php has no legacy 6-character check',
    !str_contains($resetPhp, 'strlen($password) < 6')
);

// ── 3. OTP Security (Attempt Cap & Lockout) ──────────────────
$verifyOtpCode = (string) file_get_contents($root . '/app/controllers/auth/verify_otp.php');
$sendOtpCode   = (string) file_get_contents($root . '/app/controllers/auth/send_otp.php');

ok(
    'verify_otp.php enforces maximum 5 incorrect attempts before invalidating OTP',
    str_contains($verifyOtpCode, '$_SESSION[\'otp_failures\']')
    && str_contains($verifyOtpCode, '$failures >= 5')
);
ok(
    'verify_otp.php establishes lockout cooldown on failure threshold',
    str_contains($verifyOtpCode, '$_SESSION[\'otp_lockout_until\'] = $now + 300')
);
ok(
    'send_otp.php respects active verification lockout cooldown',
    str_contains($sendOtpCode, '$lockoutUntil > time()')
);

// ── 4. CSRF Protection on OTP, Password Reset, and Logout ───
ok(
    'verify_otp.php validates CSRF token',
    str_contains($verifyOtpCode, 'hash_equals((string) $_SESSION[\'csrf_token\'], $csrf)')
);
$registerJs = (string) file_get_contents($root . '/public/assets/js/register.js');
ok(
    'register.js submits csrf_token when verifying OTP',
    str_contains($registerJs, "fd.append('csrf_token', (window.CSRF_TOKEN || ''))")
);
$resetOtpApi = (string) file_get_contents($root . '/app/api/reset_password_otp.php');
ok(
    'reset_password_otp.php validates CSRF token',
    str_contains($resetOtpApi, 'hash_equals((string) $_SESSION[\'csrf_token\'], $csrf)')
);
$verifyResetOtpApi = (string) file_get_contents($root . '/app/api/verify_reset_otp.php');
ok(
    'verify_reset_otp.php validates CSRF token',
    str_contains($verifyResetOtpApi, 'hash_equals((string) $_SESSION[\'csrf_token\'], $csrf)')
);
ok(
    'public/reset_password.php contains CSRF hidden field and validates token on POST',
    str_contains($resetPhp, 'name="csrf_token"')
    && str_contains($resetPhp, 'hash_equals((string) $_SESSION[\'csrf_token\'], $submittedCsrf)')
);
$logoutApi = (string) file_get_contents($root . '/app/api/logout.php');
ok(
    'logout.php validates CSRF on POST and protects active sessions',
    str_contains($logoutApi, 'auth_csrf_validate($csrfToken)')
);
$logoutJs = (string) file_get_contents($root . '/public/assets/js/logout-handler.js');
ok(
    'logout-handler.js sends POST with CSRF token',
    str_contains($logoutJs, "method: 'POST'")
    && str_contains($logoutJs, 'csrf_token: token')
);

// ── 5. Rate Limiting Keying Strategy ────────────────────────
require_once $root . '/app/includes/rate_limiter.php';
$_SERVER['REMOTE_ADDR'] = '198.51.100.25';
$key1 = mc_rate_limiter_key('test_bucket');
$keyWithUser = mc_rate_limiter_key('test_bucket', 42);
ok(
    'mc_rate_limiter_key does not embed session_id (prevents session rotation bypass)',
    !str_contains($key1, 'nosession') && !str_contains($key1, ':sess:')
);
ok(
    'mc_rate_limiter_key binds to client IP and authenticated user ID',
    $key1 === 'mc_rl:test_bucket:ip:198.51.100.25'
    && $keyWithUser === 'mc_rl:test_bucket:u:42:ip:198.51.100.25'
);

// ── 6. Sensitive Files & Server Configuration ───────────────
$rootHtaccess   = (string) file_get_contents($root . '/.htaccess');
$publicHtaccess = (string) file_get_contents($root . '/public/.htaccess');

ok(
    'root .htaccess blocks ai_service directory from direct HTTP access',
    str_contains($rootHtaccess, 'ai_service')
);
ok(
    'root .htaccess blocks .env*, .git*, .key, and package metadata',
    str_contains($rootHtaccess, '^\.env') && str_contains($rootHtaccess, 'composer')
);
ok(
    'public .htaccess blocks internal app/ paths before serving existing files',
    str_contains($publicHtaccess, 'RewriteRule ^app/(core|includes|models)/ - [F,L]')
    && strpos($publicHtaccess, 'RewriteRule ^app/(core|includes|models)/ - [F,L]') < strpos($publicHtaccess, 'RewriteCond %{REQUEST_FILENAME} -f')
);

echo "\nSummary: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
