<?php
/**
 * Encrypt existing plaintext patient files in place (AES-256-GCM, app/includes/file_encryption.php).
 *
 *   php scripts/security/encrypt_patient_files.php             dry run (default, changes nothing)
 *   php scripts/security/encrypt_patient_files.php --execute   encrypt
 *   php scripts/security/encrypt_patient_files.php --dir=PATH  limit to one directory (repeatable)
 *
 * Per file: encrypt to a temp file and verify it decrypts to the original SHA-256, move the
 * original aside, move the encrypted file into place, verify again, then delete the original.
 * Any failure restores the original. Filenames (and therefore database references) are unchanged.
 * Already-encrypted files are skipped, so the script is safe to re-run.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
require_once $root . '/config/env_loader.php';
require_once $root . '/app/includes/file_encryption.php';

$execute = in_array('--execute', $argv, true);
$dirs = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--dir=')) {
        $dirs[] = substr($arg, 6);
    }
}
if ($dirs === []) {
    $dirs = [
        $root . '/storage/residency',
        $root . '/storage/uploads/ids',
    ];
}

if (!mc_file_encryption_available()) {
    fwrite(STDERR, "File encryption key is not configured or invalid. Set MEDCONNECT_FILE_ENCRYPTION_KEY_FILE or MEDCONNECT_FILE_ENCRYPTION_KEY." . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, ($execute ? 'EXECUTE' : 'DRY RUN') . PHP_EOL);

$totals = ['plaintext' => 0, 'encrypted' => 0, 'already' => 0, 'failed' => 0];

foreach ($dirs as $dir) {
    $dirReal = realpath($dir);
    if ($dirReal === false || !is_dir($dirReal)) {
        fwrite(STDOUT, "skip missing directory: {$dir}" . PHP_EOL);
        continue;
    }
    fwrite(STDOUT, "directory: {$dirReal}" . PHP_EOL);

    foreach (new DirectoryIterator($dirReal) as $entry) {
        if (!$entry->isFile() || $entry->isLink()) {
            continue;
        }
        $name = $entry->getFilename();
        if (str_starts_with($name, '.') || str_ends_with($name, '.tmp') || str_ends_with($name, '.mcplain-bak')) {
            continue;
        }
        $path = $entry->getPathname();

        if (mc_file_is_encrypted($path)) {
            $totals['already']++;
            continue;
        }
        $totals['plaintext']++;
        if (!$execute) {
            continue;
        }

        $backup = $path . '.mcplain-bak';
        try {
            $original = file_get_contents($path);
            if ($original === false) {
                throw new MedconnectFileEncryptionException('unreadable');
            }
            $originalHash = hash('sha256', $original);
            $mode = fileperms($path) & 0777;

            $tmp = $path . '.mcenc-' . bin2hex(random_bytes(6)) . '.tmp';
            $blob = mc_file_encrypt_bytes($original);
            if (file_put_contents($tmp, $blob, LOCK_EX) !== strlen($blob)) {
                @unlink($tmp);
                throw new MedconnectFileEncryptionException('temp write failed');
            }
            if (!hash_equals($originalHash, hash('sha256', mc_file_read_decrypted($tmp)))) {
                @unlink($tmp);
                throw new MedconnectFileEncryptionException('temp verification failed');
            }

            if (!rename($path, $backup)) {
                @unlink($tmp);
                throw new MedconnectFileEncryptionException('could not move original aside');
            }
            if (!rename($tmp, $path)) {
                @unlink($tmp);
                rename($backup, $path);
                throw new MedconnectFileEncryptionException('could not place encrypted file');
            }
            if (!mc_file_is_encrypted($path) || !hash_equals($originalHash, hash('sha256', mc_file_read_decrypted($path)))) {
                @unlink($path);
                rename($backup, $path);
                throw new MedconnectFileEncryptionException('final verification failed');
            }
            @chmod($path, $mode ?: 0640);
            unlink($backup);
            $totals['encrypted']++;
        } catch (Throwable $e) {
            if (!is_file($path) && is_file($backup)) {
                rename($backup, $path);
            }
            $totals['failed']++;
            fwrite(STDOUT, "  FAILED {$name}: " . $e->getMessage() . " (original kept)" . PHP_EOL);
        }
    }
}

fwrite(STDOUT, sprintf(
    "plaintext found: %d | encrypted now: %d | already encrypted: %d | failed: %d%s",
    $totals['plaintext'],
    $totals['encrypted'],
    $totals['already'],
    $totals['failed'],
    PHP_EOL
));
exit($totals['failed'] > 0 ? 2 : 0);
