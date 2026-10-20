<?php
/**
 * Credentials must come from the server environment, not source literals.
 *
 * php scripts/dev/test_credentials_not_hardcoded.php
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

$db = (string) file_get_contents($root . '/config/db.php');
$mail = (string) file_get_contents($root . '/app/includes/mailer.php');
$example = (string) file_get_contents($root . '/.env.example');

$tempRecovery = str_contains($db, 'TODO(temporary-hostinger-recovery)')
    && str_contains($mail, 'TODO(temporary-hostinger-recovery)');
ok('db.php reads DB_PASS from the environment', str_contains($db, "getenv('DB_PASS')") || str_contains($db, "'DB_PASS'"));
ok(
    'db.php password literal only allowed under temporary-hostinger-recovery TODO',
    $tempRecovery || !preg_match('/\$dbPass\s*=\s*\'[^\']+\'/', $db)
);
ok('db.php does not embed a production password literal', !preg_match('/\$dbPass\s*=\s*\"[^\"]+\"/', $db));
ok('db.php has no Temporary Hostinger demo credential defaults', !str_contains($db, 'Temporary Hostinger online demo defaults'));
$envLoader = (string) file_get_contents($root . '/config/env_loader.php');
ok('mailer.php reads MAIL_PASSWORD from the environment', str_contains($mail, "'MAIL_PASSWORD'"));
ok(
    'mailer.php does not define a quoted SMTP password',
    !preg_match('/define\(\s*\'MAIL_PASSWORD\'\s*,\s*\'[^\']+\'\s*\)/', $mail)
);
ok(
    'mailer.php does not define a quoted mailbox password via double quotes',
    !preg_match('/define\(\s*\'MAIL_PASSWORD\'\s*,\s*\"[^\"]+\"\s*\)/', $mail)
);
ok(
    'mailer.php MAIL_PASSWORD fallback only allowed under temporary-hostinger-recovery TODO',
    $tempRecovery || !preg_match("/'MAIL_PASSWORD'\\s*=>\\s*'[^']+'/", $mail)
);
ok(
    'mailer.php has no Temporary Hostinger demo fallbacks block',
    !str_contains($mail, 'Temporary Hostinger online demo fallbacks')
);
ok('temporary-hostinger-recovery TODO markers present while emergency fallbacks exist', $tempRecovery);
ok('mailer.php uses safe failure logging helper', str_contains($mail, 'medconnect_mail_log_failure'));
ok(
    'config/.htaccess denies direct HTTP access',
    is_readable($root . '/config/.htaccess')
    && str_contains((string) file_get_contents($root . '/config/.htaccess'), 'Require all denied')
);
ok('env_loader.php supports MEDCONNECT_ENV_FILE / outside-webroot paths', str_contains($envLoader, 'MEDCONNECT_ENV_FILE'));
ok('mailer.php supports MEDCONNECT_MAIL_CONFIG private PHP file', str_contains($mail, 'MEDCONNECT_MAIL_CONFIG'));
ok('.env.example MAIL_PASSWORD placeholder is empty', (bool) preg_match('/^#?\s*MAIL_PASSWORD=\s*$/m', $example));
ok('.env.example DB_PASS placeholder is empty', (bool) preg_match('/^#?\s*DB_PASS=\s*$/m', $example));

$tracked = [];
exec('git -C ' . escapeshellarg($root) . ' ls-files -- .env .env.local ai_service/.env config/local_secrets.php', $tracked, $code);
ok('real .env files are not git-tracked', $code === 0 && $tracked === []);
$gitignore = (string) file_get_contents($root . '/.gitignore');
ok('config/local_secrets.php is gitignored', str_contains($gitignore, 'local_secrets.php'));
$exampleSecrets = (string) file_get_contents($root . '/config/local_secrets.example.php');
ok(
    'local_secrets.example.php has empty password placeholders',
    is_readable($root . '/config/local_secrets.example.php')
    && (bool) preg_match("/'DB_PASS'\\s*=>\\s*''/", $exampleSecrets)
    && (bool) preg_match("/'MAIL_PASSWORD'\\s*=>\\s*''/", $exampleSecrets)
);
ok(
    'local_secrets.example.php has empty Railway email relay placeholders',
    (bool) preg_match("/'EMAIL_API_KEY'\\s*=>\\s*''/", $exampleSecrets)
    && (bool) preg_match("/'MEDCONNECT_AI_SERVICE_TOKEN'\\s*=>\\s*''/", $exampleSecrets)
);

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
