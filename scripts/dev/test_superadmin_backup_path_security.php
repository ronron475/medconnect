<?php
/**
 * Focused path-safety tests for Super Admin backup restore.
 * Run: php scripts/dev/test_superadmin_backup_path_security.php
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

$backupDir = superadmin_backup_dir();
$tag = 'pathsec_' . bin2hex(random_bytes(4));
$validSql = $backupDir . DIRECTORY_SEPARATOR . "{$tag}_valid.sql";
$nonSql = $backupDir . DIRECTORY_SEPARATOR . "{$tag}_notes.txt";
$outsideSql = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "{$tag}_evil.sql";

file_put_contents($validSql, "-- medconnect test backup\nSELECT 1;\n");
file_put_contents($nonSql, 'not sql');
file_put_contents($outsideSql, "DROP TABLE users;\n");

$cleanup = static function () use ($validSql, $nonSql, $outsideSql, $backupDir, $tag): void {
    @unlink($validSql);
    @unlink($nonSql);
    @unlink($outsideSql);
    $link = $backupDir . DIRECTORY_SEPARATOR . "{$tag}_link.sql";
    if (is_link($link) || is_file($link)) {
        @unlink($link);
    }
};

try {
    $ok = superadmin_backup_resolve_safe_path($validSql);
    if ($ok !== null && is_file($ok) && realpath($ok) === realpath($validSql)) {
        pass('accepts valid backup under storage/backups');
    } else {
        fail('accepts valid backup under storage/backups', (string) $ok);
    }

    $okRel = superadmin_backup_resolve_safe_path(basename($validSql));
    if ($okRel !== null && realpath($okRel) === realpath($validSql)) {
        pass('accepts basename relative to backup dir');
    } else {
        fail('accepts basename relative to backup dir', (string) $okRel);
    }

    $trav = superadmin_backup_resolve_safe_path(
        $backupDir . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'windows' . DIRECTORY_SEPARATOR . 'system.ini'
    );
    // Also try escaping to temp evil file via .. from backups if layout allows
    $trav2 = superadmin_backup_resolve_safe_path($backupDir . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR . 'nope.sql');
    if ($trav === null && $trav2 === null) {
        pass('rejects path traversal outside backup dir');
    } else {
        fail('rejects path traversal outside backup dir', ($trav ?? '') . '|' . ($trav2 ?? ''));
    }

    $absOut = superadmin_backup_resolve_safe_path($outsideSql);
    if ($absOut === null) {
        pass('rejects absolute path outside backup dir');
    } else {
        fail('rejects absolute path outside backup dir', $absOut);
    }

    if (superadmin_backup_resolve_safe_path('../outside/evil.sql') === null) {
        pass('rejects ../ relative traversal string');
    } else {
        fail('rejects ../ relative traversal string');
    }

    if (superadmin_backup_resolve_safe_path($nonSql) === null) {
        pass('rejects non-.sql file in backup dir');
    } else {
        fail('rejects non-.sql file in backup dir');
    }

    if (superadmin_backup_resolve_safe_path('') === null) {
        pass('rejects empty path');
    } else {
        fail('rejects empty path');
    }

    if (superadmin_backup_resolve_safe_path("foo.sql\0.txt") === null) {
        pass('rejects NUL byte in path');
    } else {
        fail('rejects NUL byte in path');
    }

    $linkPath = $backupDir . DIRECTORY_SEPARATOR . "{$tag}_link.sql";
    $symlinkOk = function_exists('symlink') && @symlink($outsideSql, $linkPath);
    if (!$symlinkOk) {
        pass('symlink escape test skipped (symlink unavailable)');
    } else {
        if (superadmin_backup_resolve_safe_path($linkPath) === null) {
            pass('rejects symlink that targets outside backup dir');
        } else {
            fail('rejects symlink that targets outside backup dir');
        }
    }

    if (superadmin_backup_resolve_safe_path($backupDir . DIRECTORY_SEPARATOR . "{$tag}_missing.sql") === null) {
        pass('rejects missing backup file');
    } else {
        fail('rejects missing backup file');
    }

    $restoreSrc = file_get_contents($root . '/app/includes/superadmin/backup.php') ?: '';
    if (preg_match('/function superadmin_restore_backup[\s\S]*superadmin_backup_resolve_safe_path/', $restoreSrc)) {
        pass('superadmin_restore_backup validates path via resolver');
    } else {
        fail('superadmin_restore_backup validates path via resolver');
    }

    $apiSrc = file_get_contents($root . '/app/api/superadmin/backup.php') ?: '';
    if (str_contains($apiSrc, 'superadmin_backup_resolve_safe_path')) {
        pass('backup download also uses safe path resolver');
    } else {
        fail('backup download also uses safe path resolver');
    }
} finally {
    $cleanup();
}

echo "\n";
if ($failures === 0) {
    echo "PASS — all Super Admin backup path security checks passed\n";
    exit(0);
}
echo "FAIL — {$failures} check(s) failed\n";
exit(1);
