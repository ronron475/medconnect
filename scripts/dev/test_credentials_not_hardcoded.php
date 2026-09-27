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

ok('db.php reads DB_PASS from the environment', str_contains($db, "getenv('DB_PASS')") || str_contains($db, "'DB_PASS'"));
ok('db.php has no quoted database password', !preg_match('/\$dbPass\s*=\s*\'[^\']+\'/', $db));
ok('db.php does not embed a production password literal', !preg_match('/\$dbPass\s*=\s*\"[^\"]+\"/', $db));
ok('mailer.php reads MAIL_PASSWORD from the environment', str_contains($mail, "'MAIL_PASSWORD'"));
ok(
    'mailer.php does not define a quoted SMTP password',
    !preg_match('/define\(\s*\'MAIL_PASSWORD\'\s*,\s*\'[^\']+\'\s*\)/', $mail)
);
ok(
    'mailer.php does not define a quoted mailbox password via double quotes',
    !preg_match('/define\(\s*\'MAIL_PASSWORD\'\s*,\s*\"[^\"]+\"\s*\)/', $mail)
);
ok('.env.example MAIL_PASSWORD placeholder is empty', (bool) preg_match('/^#?\s*MAIL_PASSWORD=\s*$/m', $example));
ok('.env.example DB_PASS placeholder is empty', (bool) preg_match('/^#?\s*DB_PASS=\s*$/m', $example));

$tracked = [];
exec('git -C ' . escapeshellarg($root) . ' ls-files -- .env .env.local ai_service/.env', $tracked, $code);
ok('real .env files are not git-tracked', $code === 0 && $tracked === []);

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
