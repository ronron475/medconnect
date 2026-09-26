<?php
/**
 * Shared server-side upload validation helpers.
 * MIME via finfo, extension allowlists, size limits, safe names, path confinement.
 */
declare(strict_types=1);

/** @return list<string> */
function upload_security_dangerous_extensions(): array
{
    return [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'dll', 'bat', 'cmd',
        'com', 'msi', 'jsp', 'asp', 'aspx', 'htaccess', 'htpasswd', 'ini',
        'svg', // SVG can carry script; block unless a caller explicitly allows image/svg+xml
    ];
}

/**
 * Detect MIME type from file contents (never trust client Content-Type).
 */
function upload_security_detect_mime(string $tmpPath): string
{
    if ($tmpPath === '' || !is_readable($tmpPath)) {
        return '';
    }
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmpPath);
        return $mime !== '' ? strtolower($mime) : '';
    }
    if (function_exists('finfo_open')) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $f ? (string) finfo_file($f, $tmpPath) : '';
        if ($f) {
            finfo_close($f);
        }
        return $mime !== '' ? strtolower($mime) : '';
    }
    return '';
}

function upload_security_original_basename(string $name): string
{
    $base = basename(str_replace(["\0", '\\'], ['', '/'], $name));
    $base = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $base) ?? 'file';
    return $base !== '' ? $base : 'file';
}

/**
 * True when the client filename looks executable / multi-ext scriptish.
 */
function upload_security_filename_is_dangerous(string $name): bool
{
    $base = strtolower(upload_security_original_basename($name));
    if ($base === '' || $base === '.' || $base === '..') {
        return true;
    }
    if (str_contains($base, "\0") || str_contains($base, '..')) {
        return true;
    }
    $parts = explode('.', $base);
    if (count($parts) < 2) {
        return false;
    }
    $dangerous = upload_security_dangerous_extensions();
    // Any segment except the final allowed extension must not be a script type.
    foreach ($parts as $i => $part) {
        if ($part === '') {
            return true;
        }
        if (in_array($part, $dangerous, true)) {
            return true;
        }
    }
    return false;
}

/**
 * Validate an uploaded file against MIME→ext map and max size.
 *
 * @param array<string, mixed> $file $_FILES[...] entry
 * @param array<string, string> $allowedMimes mime => extension (without dot)
 * @return array{
 *   ok:bool,
 *   message:string,
 *   mime:string,
 *   ext:string,
 *   size:int,
 *   tmp:string,
 *   original_basename:string
 * }
 */
function upload_security_validate(array $file, array $allowedMimes, int $maxBytes, bool $requireUploaded = true): array
{
    $fail = static function (string $message): array {
        return [
            'ok' => false,
            'message' => $message,
            'mime' => '',
            'ext' => '',
            'size' => 0,
            'tmp' => '',
            'original_basename' => '',
        ];
    };

    if ($allowedMimes === [] || $maxBytes <= 0) {
        return $fail('Upload configuration error.');
    }

    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return $fail('No file uploaded.');
    }
    if ($err !== UPLOAD_ERR_OK) {
        return $fail('File upload failed. Please try again.');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_readable($tmp)) {
        return $fail('Upload temporary file missing.');
    }
    if ($requireUploaded && !is_uploaded_file($tmp)) {
        return $fail('Invalid upload source.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        return $fail('Uploaded file is empty.');
    }
    if ($size > $maxBytes) {
        $mb = max(1, (int) ceil($maxBytes / (1024 * 1024)));
        return $fail("File exceeds the {$mb} MB limit.");
    }

    $original = upload_security_original_basename((string) ($file['name'] ?? 'file'));
    if (upload_security_filename_is_dangerous($original)) {
        return $fail('File name or type is not allowed.');
    }

    $clientExt = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
    if ($clientExt !== '' && in_array($clientExt, upload_security_dangerous_extensions(), true)) {
        return $fail('Executable or script uploads are not allowed.');
    }

    $mime = upload_security_detect_mime($tmp);
    if ($mime === '' || !isset($allowedMimes[$mime])) {
        return $fail('Invalid file type.');
    }

    $ext = strtolower($allowedMimes[$mime]);
    $allowedExts = array_unique(array_map('strtolower', array_values($allowedMimes)));
    if ($clientExt === 'jpeg') {
        $clientExt = 'jpg';
    }
    if ($clientExt !== '' && $clientExt !== 'blob' && !in_array($clientExt, $allowedExts, true)
        && !($clientExt === 'jpg' && in_array('jpeg', $allowedExts, true))
    ) {
        return $fail('File extension is not allowed.');
    }

    return [
        'ok' => true,
        'message' => '',
        'mime' => $mime,
        'ext' => $ext,
        'size' => $size,
        'tmp' => $tmp,
        'original_basename' => $original,
    ];
}

/**
 * Random opaque storage filename (no user-controlled path segments).
 */
function upload_security_safe_filename(string $prefix, string $ext): string
{
    $prefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $prefix) ?: 'file';
    $ext = strtolower(preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin');
    return $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
}

function upload_security_ensure_dir(string $dir, int $mode = 0750): bool
{
    if (is_dir($dir)) {
        return true;
    }
    return @mkdir($dir, $mode, true) || is_dir($dir);
}

/**
 * Ensure $relativeName resolves to a regular file inside $dir (no traversal).
 *
 * @return string|null Canonical absolute path or null
 */
function upload_security_confine_path(string $dir, string $relativeName): ?string
{
    $relativeName = str_replace("\0", '', $relativeName);
    $relativeName = basename(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relativeName));
    if ($relativeName === '' || $relativeName === '.' || $relativeName === '..') {
        return null;
    }
    if (str_contains($relativeName, '..')) {
        return null;
    }

    $dirReal = realpath($dir);
    if ($dirReal === false || !is_dir($dirReal)) {
        return null;
    }

    $candidate = $dirReal . DIRECTORY_SEPARATOR . $relativeName;
    if (is_link($candidate)) {
        return null;
    }
    $resolved = realpath($candidate);
    if ($resolved === false || !is_file($resolved)) {
        return null;
    }

    $dirNorm = rtrim(str_replace('\\', '/', $dirReal), '/');
    $resNorm = str_replace('\\', '/', $resolved);
    if ($resNorm !== $dirNorm && !str_starts_with($resNorm, $dirNorm . '/')) {
        return null;
    }

    return $resolved;
}

/**
 * Write a Deny-from-all .htaccess when missing (Apache).
 */
function upload_security_write_deny_htaccess(string $dir): void
{
    $ht = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_file($ht)) {
        return;
    }
    @file_put_contents($ht, "Require all denied\nDeny from all\n");
}
