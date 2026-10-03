<?php
/**
 * Generate a patient-file encryption key (32 random bytes, base64).
 *
 *   php scripts/security/generate_file_encryption_key.php
 *       Prints a key for MEDCONNECT_FILE_ENCRYPTION_KEY.
 *   php scripts/security/generate_file_encryption_key.php --out=C:\path\outside\webroot\file_encryption.key
 *       Writes the key file for MEDCONNECT_FILE_ENCRYPTION_KEY_FILE (never overwrites).
 *
 * Losing the key makes every encrypted patient file unrecoverable. Back it up separately
 * from the database and the storage/ directory.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$key = base64_encode(random_bytes(32));

$out = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $out = substr($arg, 6);
    }
}

if ($out === null) {
    fwrite(STDOUT, $key . PHP_EOL);
    exit(0);
}

$outDir = realpath(dirname($out));
if ($outDir === false) {
    fwrite(STDERR, "Directory does not exist: " . dirname($out) . PHP_EOL);
    exit(1);
}
$rootNorm = rtrim(strtolower(str_replace('\\', '/', (string) realpath($root))), '/') . '/';
$outNorm = rtrim(strtolower(str_replace('\\', '/', $outDir)), '/') . '/';
if (str_starts_with($outNorm, $rootNorm)) {
    fwrite(STDERR, "Refusing to write the key inside the project directory. Choose a path outside the web root." . PHP_EOL);
    exit(1);
}
if (file_exists($out)) {
    fwrite(STDERR, "Refusing to overwrite an existing key file." . PHP_EOL);
    exit(1);
}
if (file_put_contents($out, $key . PHP_EOL, LOCK_EX) === false) {
    fwrite(STDERR, "Could not write key file." . PHP_EOL);
    exit(1);
}
@chmod($out, 0400);
fwrite(STDOUT, "Key written. Set MEDCONNECT_FILE_ENCRYPTION_KEY_FILE={$out}" . PHP_EOL);
