<?php
/**
 * Focused CSRF checks for Admin/Superadmin write endpoints.
 * Run: php scripts/dev/test_admin_superadmin_csrf.php
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

require_once $root . '/app/includes/session_cookie.php';
if (session_status() === PHP_SESSION_NONE) {
    medconnect_session_start();
}
require_once $root . '/app/includes/auth_guard.php';
require_once $root . '/app/includes/portal_auth.php';

// ── 1) Token validation ───────────────────────────────────────
$_SESSION['csrf_token'] = 'unit-test-csrf-token-abc';
if (auth_csrf_validate('unit-test-csrf-token-abc')) {
    pass('auth_csrf_validate accepts matching token');
} else {
    fail('auth_csrf_validate accepts matching token');
}
if (!auth_csrf_validate('wrong-token')) {
    pass('auth_csrf_validate rejects wrong token');
} else {
    fail('auth_csrf_validate rejects wrong token');
}
if (!auth_csrf_validate('')) {
    pass('auth_csrf_validate rejects empty token');
} else {
    fail('auth_csrf_validate rejects empty token');
}
if (!auth_csrf_validate(null)) {
    pass('auth_csrf_validate rejects null token');
} else {
    fail('auth_csrf_validate rejects null token');
}

// ── 2) auth_csrf_require rejects missing token (subprocess) ───
$probe = <<<'PHP'
<?php
require $argv[1] . '/app/includes/session_cookie.php';
medconnect_session_start();
$_SESSION['csrf_token'] = 'expected-token';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [];
unset($_SERVER['HTTP_X_CSRF_TOKEN']);
$_SERVER['HTTP_ACCEPT'] = 'application/json';
require $argv[1] . '/app/includes/auth_guard.php';
require $argv[1] . '/app/includes/request_helpers.php';
ob_start();
auth_csrf_require();
echo "REACHED";
PHP;
$tmp = tempnam(sys_get_temp_dir(), 'csrf');
file_put_contents($tmp, $probe);
$out = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($root) . ' 2>&1', $out, $ec);
@unlink($tmp);
$joined = implode("\n", $out);
if (!str_contains($joined, 'REACHED') && str_contains($joined, 'csrf_invalid')) {
    pass('auth_csrf_require rejects missing token with 403');
} else {
    fail('auth_csrf_require rejects missing token with 403', $joined);
}

// Valid header token accepted
$probeOk = <<<'PHP'
<?php
require $argv[1] . '/app/includes/session_cookie.php';
medconnect_session_start();
$_SESSION['csrf_token'] = 'expected-token';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'expected-token';
require $argv[1] . '/app/includes/auth_guard.php';
auth_csrf_require();
echo "OK\n";
PHP;
$tmp = tempnam(sys_get_temp_dir(), 'csrf');
file_put_contents($tmp, $probeOk);
$out = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($root) . ' 2>&1', $out, $ec);
@unlink($tmp);
if (in_array('OK', $out, true)) {
    pass('auth_csrf_require accepts X-CSRF-TOKEN header');
} else {
    fail('auth_csrf_require accepts X-CSRF-TOKEN header', implode('|', $out));
}

// ── 3) portal mutation CSRF only on write methods ─────────────
$portalSrc = file_get_contents($root . '/app/includes/portal_auth.php') ?: '';
if (str_contains($portalSrc, 'function portal_api_require_mutation_csrf')
    && str_contains($portalSrc, 'portal_api_require_mutation_csrf();')
    && substr_count($portalSrc, 'portal_api_require_mutation_csrf();') >= 2
) {
    pass('portal_api_require_admin/superadmin call mutation CSRF');
} else {
    fail('portal_api_require_admin/superadmin call mutation CSRF');
}

$probeGet = <<<'PHP'
<?php
require $argv[1] . '/app/includes/session_cookie.php';
medconnect_session_start();
$_SESSION['csrf_token'] = 't';
$_SERVER['REQUEST_METHOD'] = 'GET';
require $argv[1] . '/app/includes/portal_auth.php';
portal_api_require_mutation_csrf();
echo "GET_OK\n";
PHP;
$tmp = tempnam(sys_get_temp_dir(), 'csrf');
file_put_contents($tmp, $probeGet);
$out = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($root) . ' 2>&1', $out, $ec);
@unlink($tmp);
if (in_array('GET_OK', $out, true)) {
    pass('portal mutation CSRF skips GET');
} else {
    fail('portal mutation CSRF skips GET', implode('|', $out));
}

$probePostMissing = <<<'PHP'
<?php
require $argv[1] . '/app/includes/session_cookie.php';
medconnect_session_start();
$_SESSION['csrf_token'] = 't';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [];
unset($_SERVER['HTTP_X_CSRF_TOKEN']);
$_SERVER['HTTP_ACCEPT'] = 'application/json';
require $argv[1] . '/app/includes/portal_auth.php';
portal_api_require_mutation_csrf();
echo "POST_LEAKED\n";
PHP;
$tmp = tempnam(sys_get_temp_dir(), 'csrf');
file_put_contents($tmp, $probePostMissing);
$out = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($root) . ' 2>&1', $out, $ec);
@unlink($tmp);
$joined = implode("\n", $out);
if ($ec !== 0 || str_contains($joined, 'csrf_invalid') || str_contains($joined, 'Invalid request token')) {
    if (!str_contains($joined, 'POST_LEAKED')) {
        pass('portal mutation CSRF rejects POST without token');
    } else {
        fail('portal mutation CSRF rejects POST without token', $joined);
    }
} else {
    fail('portal mutation CSRF rejects POST without token', $joined);
}

// ── 4) Endpoint coverage: mutating files use portal or own CSRF ─
$dirs = [
    $root . '/app/api/admin',
    $root . '/app/api/superadmin',
];
$missing = [];
foreach ($dirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $name = $file->getFilename();
        if (str_starts_with($name, '_')) {
            continue;
        }
        // Thin aliases that require another portal-gated script.
        if ($name === 'create_doctor.php' || $name === 'account_status.php' && str_contains($file->getPathname(), 'superadmin')) {
            $aliasSrc = file_get_contents($file->getPathname()) ?: '';
            if (preg_match('/require(?:_once)?\s+.+create_staff\.php|require\s+.+admin\/account_status\.php/', $aliasSrc)) {
                continue;
            }
        }
        $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
        $src = file_get_contents($file->getPathname()) ?: '';
        $usesPortal = (bool) preg_match('/portal_api_require_(admin_portal|superadmin)\s*\(|_auth\.php|_bootstrap\.php|create_staff\.php|admin\/account_status\.php/', $src);
        $ownCsrf = (bool) preg_match('/auth_csrf_require\s*\(|auth_csrf_validate\s*\(|admin_settings_verify_csrf\s*\(/', $src);
        $looksMutating = (bool) preg_match('/\$_POST|php:\/\/input|REQUEST_METHOD[^\n]{0,40}POST/', $src);
        if ($looksMutating && !$usesPortal && !$ownCsrf) {
            $missing[] = $rel;
        }
    }
}
if ($missing === []) {
    pass('all mutating admin/superadmin APIs use portal auth or explicit CSRF');
} else {
    fail('mutating APIs missing CSRF gate', implode(', ', $missing));
}

// ── 5) facilities/barangays POST-only ─────────────────────────
foreach (['facilities.php', 'barangays.php'] as $fn) {
    $src = file_get_contents($root . '/app/api/admin/' . $fn) ?: '';
    if (preg_match("/REQUEST_METHOD[^\n]*POST/", $src) && str_contains($src, '405')) {
        pass("admin/{$fn} rejects non-POST");
    } else {
        fail("admin/{$fn} rejects non-POST");
    }
}

// ── 6) fetch shim present in layouts ─────────────────────────
$adminOpen = file_get_contents($root . '/resources/views/admin/partials/layout_open.php') ?: '';
$saOpen = file_get_contents($root . '/resources/views/superadmin/partials/layout_open.php') ?: '';
$js = $root . '/public/assets/js/portal-csrf-fetch.js';
if (is_readable($js) && str_contains($adminOpen, 'portal-csrf-fetch.js') && str_contains($saOpen, 'portal-csrf-fetch.js')) {
    pass('admin/superadmin layouts load portal-csrf-fetch.js');
} else {
    fail('admin/superadmin layouts load portal-csrf-fetch.js');
}

echo "\n";
if ($failures === 0) {
    echo "PASS — all Admin/Superadmin CSRF checks passed\n";
    exit(0);
}
echo "FAIL — {$failures} check(s) failed\n";
exit(1);
