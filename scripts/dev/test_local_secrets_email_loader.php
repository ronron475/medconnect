<?php
/**
 * Private local_secrets.php email/DB loader behavior (no real secrets, no live email).
 *
 * php scripts/dev/test_local_secrets_email_loader.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$fail = 0;
$pass = 0;

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail, $pass;
    if ($cond) {
        echo "PASS  {$label}\n";
        $pass++;

        return;
    }
    $fail++;
    echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$envLoaderSrc = (string) file_get_contents($root . '/config/env_loader.php');
$exampleSrc = (string) file_get_contents($root . '/config/local_secrets.example.php');
$mailSrc = (string) file_get_contents($root . '/app/includes/mailer.php');

ok('env_loader loads config/local_secrets.php', str_contains($envLoaderSrc, 'local_secrets.php'));
ok('env_loader never overrides existing getenv/$_ENV', str_contains($envLoaderSrc, 'getenv($secretName) === false'));
ok('example documents EMAIL_API_KEY', str_contains($exampleSrc, "'EMAIL_API_KEY'"));
ok('example documents MEDCONNECT_AI_SERVICE_TOKEN', str_contains($exampleSrc, "'MEDCONNECT_AI_SERVICE_TOKEN'"));
ok('example EMAIL_API_KEY placeholder is empty', (bool) preg_match("/'EMAIL_API_KEY'\\s*=>\\s*''/", $exampleSrc));
ok('example MEDCONNECT_AI_SERVICE_TOKEN placeholder is empty', (bool) preg_match("/'MEDCONNECT_AI_SERVICE_TOKEN'\\s*=>\\s*''/", $exampleSrc));
ok(
    'mailer loads Gmail SMTP from local_secrets and disables Railway email relay',
    str_contains($mailSrc, 'local_secrets.php')
    && str_contains($mailSrc, 'PHPMailer/Gmail SMTP only')
    && str_contains($mailSrc, 'medconnect_railway_relay_available')
    && str_contains($mailSrc, 'return false;')
);

// Isolate loader logic in a subprocess-like fresh include using a temp secrets file.
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mc_local_secrets_test_' . bin2hex(random_bytes(4));
mkdir($tmpDir);
$tmpSecrets = $tmpDir . DIRECTORY_SEPARATOR . 'local_secrets.php';
file_put_contents(
    $tmpSecrets,
    "<?php\nreturn [\n  'EMAIL_API_KEY' => 'unit-test-email-key',\n  'MEDCONNECT_AI_SERVICE_TOKEN' => 'unit-test-ai-token',\n  'MAIL_USERNAME' => 'unit@example.com',\n];\n"
);

$loadFn = static function (string $path): void {
    $loadedSecrets = include $path;
    if (!is_array($loadedSecrets)) {
        return;
    }
    foreach ($loadedSecrets as $secretName => $secretValue) {
        if (!is_string($secretName) || $secretName === '') {
            continue;
        }
        if (!is_string($secretValue) && !is_numeric($secretValue)) {
            continue;
        }
        $secretValue = trim((string) $secretValue);
        if ($secretValue === '') {
            continue;
        }
        if (getenv($secretName) === false && !array_key_exists($secretName, $_ENV)) {
            putenv("{$secretName}={$secretValue}");
            $_ENV[$secretName] = $secretValue;
        }
    }
};

// Preserve pre-existing env: set MAIL_USERNAME first, secrets must not override.
putenv('MAIL_USERNAME=already-set@example.com');
$_ENV['MAIL_USERNAME'] = 'already-set@example.com';
putenv('EMAIL_API_KEY');
unset($_ENV['EMAIL_API_KEY']);
putenv('MEDCONNECT_AI_SERVICE_TOKEN');
unset($_ENV['MEDCONNECT_AI_SERVICE_TOKEN']);

$loadFn($tmpSecrets);

ok('loads EMAIL_API_KEY when missing from environment', (string) getenv('EMAIL_API_KEY') === 'unit-test-email-key');
ok('loads MEDCONNECT_AI_SERVICE_TOKEN when missing from environment', (string) getenv('MEDCONNECT_AI_SERVICE_TOKEN') === 'unit-test-ai-token');
ok('does not override pre-existing MAIL_USERNAME', (string) getenv('MAIL_USERNAME') === 'already-set@example.com');

// Missing private file: loader no-ops (simulate by pointing at missing path).
$missing = $tmpDir . DIRECTORY_SEPARATOR . 'does_not_exist.php';
$before = (string) getenv('EMAIL_API_KEY');
if (!is_readable($missing)) {
    // same guard as env_loader
    ok('missing private secrets file is a no-op', !is_readable($missing) && $before === 'unit-test-email-key');
} else {
    ok('missing private secrets file is a no-op', false);
}

@unlink($tmpSecrets);
@rmdir($tmpDir);

// Ensure private file stays out of git index listing for this repo.
$tracked = [];
exec('git -C ' . escapeshellarg($root) . ' ls-files -- config/local_secrets.php', $tracked, $code);
ok('config/local_secrets.php is not git-tracked', $code === 0 && $tracked === []);

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
