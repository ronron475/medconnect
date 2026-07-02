<?php
/**
 * Serve a stored profile picture.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/profile_picture.php';

$filename = basename((string) ($_GET['f'] ?? ''));
if (!preg_match('/^user_\d+_[a-f0-9]{12}\.(jpe?g|png|webp)$/i', $filename)) {
    http_response_code(404);
    exit;
}

$path = profile_picture_storage_dir() . '/' . $filename;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'png'  => 'image/png',
    'webp' => 'image/webp',
    default => 'image/jpeg',
};

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
readfile($path);
