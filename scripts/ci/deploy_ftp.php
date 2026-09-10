<?php
/**
 * FTP/FTPS deploy fallback for shell runners without lftp.
 * Uses the same DEPLOY_FTP_* env vars as deploy_ftp.sh.
 *
 * Does NOT delete remote-only files (.env, uploads, logs stay on the server).
 */
declare(strict_types=1);

$host = getenv('DEPLOY_FTP_HOST') ?: '';
$user = getenv('DEPLOY_FTP_USER') ?: '';
$pass = getenv('DEPLOY_FTP_PASS') ?: '';
$port = (int) (getenv('DEPLOY_FTP_PORT') ?: 21);
$remoteDir = getenv('DEPLOY_FTP_REMOTE_DIR') ?: '.';
$useSsl = filter_var(getenv('DEPLOY_FTP_SSL') ?: 'true', FILTER_VALIDATE_BOOLEAN);

if ($host === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "DEPLOY_FTP_HOST, DEPLOY_FTP_USER, and DEPLOY_FTP_PASS are required.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__, 2));
if ($root === false) {
    fwrite(STDERR, "Could not resolve repo root.\n");
    exit(1);
}

$excludeExact = [
    '.git',
    '.env',
    '.cursor',
    '.vercel',
    'node_modules',
    'vendor',
    '.venv',
];
$excludePrefixes = [
    'storage/uploads/',
    'storage/logs/',
    'storage/cache/',
    'storage/temp/',
    'storage/recordings/',
    'ai_service/.venv/',
];
$excludeFiles = [
    'public/genhash.php',
    'public/setup_admin.php',
    'public/seed_provider.php',
    'public/test_db_connection.php',
    'public/debug_profile.php',
    'public/delete_user.php',
];

function deploy_rel(string $root, string $path): string
{
    $root = str_replace('\\', '/', $root);
    $path = str_replace('\\', '/', $path);
    $rel = ltrim(substr($path, strlen($root)), '/');
    return $rel;
}

function deploy_excluded(string $rel, array $exact, array $prefixes, array $files): bool
{
    $rel = str_replace('\\', '/', $rel);
    if ($rel === '' || str_starts_with($rel, '.git/') || $rel === '.git') {
        return true;
    }
    if (str_starts_with($rel, '.env')) {
        return true;
    }
    foreach ($exact as $name) {
        if ($rel === $name || str_starts_with($rel, $name . '/')) {
            return true;
        }
    }
    foreach ($prefixes as $prefix) {
        if (str_starts_with($rel, $prefix)) {
            return true;
        }
    }
    if (in_array($rel, $files, true)) {
        return true;
    }
    if (str_ends_with($rel, '.bak') || str_ends_with($rel, '.prev')) {
        return true;
    }
    if (str_contains($rel, '/__pycache__/') || str_ends_with($rel, '/__pycache__')) {
        return true;
    }
    return false;
}

echo "Deploying to {$host}:{$port} → {$remoteDir} (PHP FTP fallback)\n";

$conn = $useSsl ? @ftp_ssl_connect($host, $port, 30) : @ftp_connect($host, $port, 30);
if (!$conn) {
    // Fall back to plain FTP if FTPS handshake fails.
    if ($useSsl) {
        echo "FTPS connect failed; retrying plain FTP…\n";
        $conn = @ftp_connect($host, $port, 30);
    }
}
if (!$conn) {
    fwrite(STDERR, "Could not connect to FTP host {$host}:{$port}\n");
    exit(1);
}

if (!@ftp_login($conn, $user, $pass)) {
    fwrite(STDERR, "FTP login failed.\n");
    ftp_close($conn);
    exit(1);
}

ftp_pasv($conn, true);

if ($remoteDir !== '.' && $remoteDir !== '') {
    if (!@ftp_chdir($conn, $remoteDir)) {
        fwrite(STDERR, "Could not chdir to remote dir: {$remoteDir}\n");
        ftp_close($conn);
        exit(1);
    }
}

$uploaded = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    /** @var SplFileInfo $fileInfo */
    if (!$fileInfo->isFile()) {
        continue;
    }
    $local = $fileInfo->getPathname();
    $rel = deploy_rel($root, $local);
    if (deploy_excluded($rel, $excludeExact, $excludePrefixes, $excludeFiles)) {
        continue;
    }

    $parts = explode('/', $rel);
    array_pop($parts);
    $dir = '';
    foreach ($parts as $part) {
        $dir = $dir === '' ? $part : ($dir . '/' . $part);
        @ftp_mkdir($conn, $dir);
    }

    if (!@ftp_put($conn, $rel, $local, FTP_BINARY)) {
        fwrite(STDERR, "Failed to upload: {$rel}\n");
        ftp_close($conn);
        exit(1);
    }
    $uploaded++;
    if ($uploaded % 50 === 0) {
        echo "Uploaded {$uploaded} files…\n";
    }
}

ftp_close($conn);
echo "Deploy finished successfully ({$uploaded} files).\n";
