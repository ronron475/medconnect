<?php
/**
 * Portal view router — serves resources/views/*.php via /views/* URLs.
 * Prevents direct web access to resources/ while keeping existing bookmarked URLs.
 */
declare(strict_types=1);

$path = (string) ($_GET['path'] ?? '');
$path = str_replace(['\\', "\0"], '/', $path);
$path = preg_replace('#/+#', '/', $path) ?? '';
$path = ltrim($path, '/');

// Chrome dual-tab video demo: never keep a writeable PHP session lock on this page.
// Otherwise the second tab hangs on "Loading…" until the first tab releases the lock.
if (preg_match('#^consultation/video_room\.php$#i', $path)) {
    define('MEDCONNECT_SESSION_READ_AND_CLOSE', true);
}

require_once dirname(__DIR__) . '/bootstrap/app.php';

if ($path === '' || str_contains($path, '..') || !preg_match('/\.php$/i', $path)) {
    http_response_code(404);
    exit('Page not found.');
}

$viewFile = VIEWS_PATH . '/' . $path;
$viewsRoot = realpath(VIEWS_PATH);
$resolved  = realpath($viewFile);

if ($viewsRoot === false || $resolved === false || !str_starts_with($resolved, $viewsRoot)) {
    http_response_code(404);
    exit('Page not found.');
}

if (!defined('MC_VIEW_PATH')) {
    define('MC_VIEW_PATH', $path);
}

require $resolved;
