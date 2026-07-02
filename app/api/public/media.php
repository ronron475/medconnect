<?php
/**
 * Public media file delivery (announcements, media library).
 */
declare(strict_types=1);

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';

$file = (string) ($_GET['f'] ?? '');
$file = str_replace(['..', '\\'], ['', '/'], $file);
$file = ltrim($file, '/');

if ($file === '' || !preg_match('#^uploads/(announcements|media_library)/[a-zA-Z0-9_\-\.]+$#', $file)) {
    http_response_code(404);
    exit('Not found');
}

$path = STORAGE_PATH . '/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
