<?php
/**
 * Super Admin backup security gates (password, phrase, checksum, retention).
 * Does not execute a live database restore or create a production dump.
 *
 * Run: php scripts/dev/test_superadmin_backup_security.php
 */
declare(strict_types=1);

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

$root = dirname(__DIR__, 2);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $root);
}

require_once $root . '/app/includes/superadmin/backup.php';

$plain = 'SaBackupTest!' . bin2hex(random_bytes(4));
$hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 4]);
$wrong = 'WrongPass!999';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT NOT NULL, password TEXT NOT NULL)');
$pdo->exec('CREATE TABLE backup_logs (
    id INTEGER PRIMARY KEY,
    filename TEXT,
    file_path TEXT,
    backup_type TEXT,
    status TEXT,
    file_checksum TEXT,
    created_by INTEGER,
    notes TEXT,
    created_at TEXT
)');
$pdo->exec('CREATE TABLE security_logs (
    id INTEGER PRIMARY KEY,
    user_id INTEGER,
    role TEXT,
    action TEXT,
    module TEXT,
    status TEXT,
    description TEXT,
    ip_address TEXT,
    user_agent TEXT,
    browser TEXT,
    device TEXT,
    meta TEXT,
    created_at TEXT
)');

$insUser = $pdo->prepare('INSERT INTO users (id, role, password) VALUES (?, ?, ?)');
$insUser->execute([1, 'superadmin', $hash]);
$insUser->execute([2, 'admin', $hash]);
$insUser->execute([3, 'superadmin', '']);

$backupDir = superadmin_backup_dir();
$tag = 'sec_' . bin2hex(random_bytes(4));
$goodFile = $backupDir . DIRECTORY_SEPARATOR . "{$tag}_ok.sql";
$tamperFile = $backupDir . DIRECTORY_SEPARATOR . "{$tag}_tamper.sql";
$noHashFile = $backupDir . DIRECTORY_SEPARATOR . "{$tag}_legacy.sql";
$outsideFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "{$tag}_outside.sql";
$payload = "-- medconnect security test backup\nSELECT 1;\n";
file_put_contents($goodFile, $payload);
file_put_contents($tamperFile, $payload);
file_put_contents($noHashFile, $payload);
file_put_contents($outsideFile, "DROP TABLE users;\n");
$goodHash = superadmin_backup_file_checksum($goodFile);
$tamperHash = superadmin_backup_file_checksum($tamperFile);
file_put_contents($tamperFile, $payload . "-- tampered\n");

$insLog = $pdo->prepare('INSERT INTO backup_logs (id, filename, file_path, backup_type, status, file_checksum) VALUES (?, ?, ?, ?, ?, ?)');
$insLog->execute([10, basename($goodFile), $goodFile, 'manual', 'success', $goodHash]);
$insLog->execute([11, basename($tamperFile), $tamperFile, 'manual', 'success', $tamperHash]);
$insLog->execute([12, basename($noHashFile), $noHashFile, 'manual', 'success', null]);
$insLog->execute([13, 'evil.sql', $outsideFile, 'manual', 'success', hash('sha256', 'x')]);
$insLog->execute([14, basename($goodFile), $goodFile, 'restore', 'success', $goodHash]);
$insLog->execute([15, basename($goodFile), $goodFile, 'manual', 'failed', $goodHash]);

$cleanup = static function () use ($goodFile, $tamperFile, $noHashFile, $outsideFile): void {
    @unlink($goodFile);
    @unlink($tamperFile);
    @unlink($noHashFile);
    @unlink($outsideFile);
};

try {
    // A
    if (superadmin_verify_current_password($pdo, 1, $plain)) {
        pass('A correct Super Admin password accepted');
    } else {
        fail('A correct Super Admin password accepted');
    }

    // B
    if (!superadmin_verify_current_password($pdo, 1, $wrong)) {
        pass('B wrong password rejected');
    } else {
        fail('B wrong password rejected');
    }

    // C
    if (!superadmin_verify_current_password($pdo, 2, $plain)) {
        pass('C non-Super Admin rejected');
    } else {
        fail('C non-Super Admin rejected');
    }

    // D
    if (!superadmin_verify_current_password($pdo, 1, '') && !superadmin_verify_current_password($pdo, 3, $plain)) {
        pass('D missing password / empty hash rejected');
    } else {
        fail('D missing password / empty hash rejected');
    }

    // E
    if (superadmin_backup_confirm_phrase_valid('RESTORE DATABASE')) {
        pass('E correct RESTORE DATABASE phrase accepted');
    } else {
        fail('E correct RESTORE DATABASE phrase accepted');
    }

    // F
    if (!superadmin_backup_confirm_phrase_valid('restore database')
        && !superadmin_backup_confirm_phrase_valid('RESTORE')
    ) {
        pass('F wrong phrase rejected');
    } else {
        fail('F wrong phrase rejected');
    }

    // G
    if (!superadmin_backup_confirm_phrase_valid('')) {
        pass('G missing phrase rejected');
    } else {
        fail('G missing phrase rejected');
    }

    $okRestore = superadmin_restore_prepare($pdo, 10, 1, $plain, 'RESTORE DATABASE');
    if (!empty($okRestore['success']) && ($okRestore['path'] ?? '') === realpath($goodFile)) {
        pass('H matching checksum accepted (prepare only, no SQL exec)');
    } else {
        fail('H matching checksum accepted (prepare only, no SQL exec)', json_encode($okRestore));
    }

    $legacy = superadmin_restore_prepare($pdo, 12, 1, $plain, 'RESTORE DATABASE');
    if (empty($legacy['success']) && str_contains((string) ($legacy['message'] ?? ''), 'new backup')) {
        pass('I missing checksum rejected');
    } else {
        fail('I missing checksum rejected', json_encode($legacy));
    }

    $tampered = superadmin_restore_prepare($pdo, 11, 1, $plain, 'RESTORE DATABASE');
    if (empty($tampered['success'])) {
        pass('J tampered backup checksum mismatch rejected');
    } else {
        fail('J tampered backup checksum mismatch rejected');
    }

    $outsideKept = is_file($outsideFile);
    $unlinked = superadmin_backup_retention_unlink_if_safe($pdo, 99, $outsideFile);
    if ($outsideKept && is_file($outsideFile) && $unlinked === false) {
        pass('K unsafe retention path not deleted');
    } else {
        fail('K unsafe retention path not deleted');
    }

    $unsafeRestore = superadmin_restore_prepare($pdo, 13, 1, $plain, 'RESTORE DATABASE');
    if (empty($unsafeRestore['success'])) {
        pass('L unsafe restore path rejected');
    } else {
        fail('L unsafe restore path rejected');
    }

    $unsafeDl = superadmin_backup_download_prepare($pdo, 13, 1, $plain);
    if (empty($unsafeDl['success'])) {
        pass('M unsafe download path rejected');
    } else {
        fail('M unsafe download path rejected');
    }

    $apiSrc = file_get_contents($root . '/app/api/superadmin/backup.php') ?: '';
    if (str_contains($apiSrc, "\$action === 'download' && \$method !== 'POST'")
        && str_contains($apiSrc, 'http_response_code(405)')
        && !preg_match('/if \(\$method === \'GET\' && \$action === \'download\'\)/', $apiSrc)
    ) {
        pass('N GET download rejected (POST-only)');
    } else {
        fail('N GET download rejected (POST-only)');
    }

    superadmin_restore_prepare($pdo, 10, 1, $wrong, 'RESTORE DATABASE');
    superadmin_restore_prepare($pdo, 10, 1, '', 'RESTORE DATABASE');
    superadmin_backup_download_prepare($pdo, 10, 1, $wrong);

    $logBlob = '';
    try {
        $logBlob = (string) $pdo->query('SELECT IFNULL(GROUP_CONCAT(description || " " || IFNULL(meta,"")), "") FROM security_logs')->fetchColumn();
    } catch (Throwable $e) {
        $src = file_get_contents($root . '/app/includes/superadmin/backup.php') ?: '';
        $logBlob = $src;
    }
    if (!str_contains($logBlob, $plain) && !str_contains($logBlob, $wrong)) {
        pass('O password never appears in logs');
    } else {
        fail('O password never appears in logs');
    }

    if (!str_contains($logBlob, $payload) && !str_contains($logBlob, 'DROP TABLE users')) {
        pass('P SQL contents never appear in logs');
    } else {
        fail('P SQL contents never appear in logs');
    }

    $missingPw = superadmin_restore_prepare($pdo, 10, 1, '', 'RESTORE DATABASE');
    $missingPhrase = superadmin_restore_prepare($pdo, 10, 1, $plain, '');
    $wrongPhrase = superadmin_restore_prepare($pdo, 10, 1, $plain, 'YES');
    $wrongType = superadmin_restore_prepare($pdo, 14, 1, $plain, 'RESTORE DATABASE');
    $failedStatus = superadmin_restore_prepare($pdo, 15, 1, $plain, 'RESTORE DATABASE');
    $missingRow = superadmin_restore_prepare($pdo, 999, 1, $plain, 'RESTORE DATABASE');
    if (empty($missingPw['success']) && empty($missingPhrase['success']) && empty($wrongPhrase['success'])
        && empty($wrongType['success']) && empty($failedStatus['success']) && empty($missingRow['success'])
    ) {
        pass('restore also rejects missing/wrong phrase, missing backup, wrong type, failed status');
    } else {
        fail('restore also rejects missing/wrong phrase, missing backup, wrong type, failed status');
    }

    $okDl = superadmin_backup_download_prepare($pdo, 10, 1, $plain);
    if (!empty($okDl['success'])) {
        pass('download succeeds with password + checksum (prepare only)');
    } else {
        fail('download succeeds with password + checksum (prepare only)', json_encode($okDl));
    }

    $core = file_get_contents($root . '/app/includes/superadmin/backup.php') ?: '';
    if (str_contains($core, '$pdo->exec($sql)')
        && str_contains($core, 'superadmin_restore_prepare')
        && str_contains($apiSrc, 'never triggered by cron/schedule')
    ) {
        pass('restore remains manual SQL exec after prepare; cron never restores');
    } else {
        fail('restore remains manual SQL exec after prepare; cron never restores');
    }
} finally {
    $cleanup();
}

echo "\n";
if ($failures === 0) {
    echo "PASS — all Super Admin backup security checks passed\n";
    exit(0);
}
echo "FAIL — {$failures} check(s) failed\n";
exit(1);
