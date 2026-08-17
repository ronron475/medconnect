<?php
/**
 * Serve the official medConnect Android APK only.
 * No query-selected files. No HTML/JSON mixed into the binary.
 */
declare(strict_types=1);

error_reporting(0);
ini_set('display_errors', '0');
ini_set('html_errors', '0');

if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

$apkName = 'medConnect.apk';
$apkPath = __DIR__ . '/downloads/' . $apkName;
$logFile = dirname(__DIR__) . '/storage/logs/apk-download.log';

function medconnect_apk_log(string $message): void
{
    global $logFile;
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        return;
    }
    @file_put_contents(
        $logFile,
        date('c') . ' ' . $message . "\n",
        FILE_APPEND | LOCK_EX
    );
}

function medconnect_apk_unavailable(): void
{
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>medConnect</title>';
    echo '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:Segoe UI,system-ui,sans-serif;background:#012A4A;color:#f0f9fa;padding:24px;text-align:center}p{max-width:28rem;line-height:1.6}a{color:#5eead4}</style></head><body>';
    echo '<p>App download is temporarily unavailable. Please try again later.</p>';
    echo '<p><a href="index.php#download-app">Return to medConnect</a></p>';
    echo '</body></html>';
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

if (!is_file($apkPath) || !is_readable($apkPath)) {
    medconnect_apk_log('APK missing or unreadable');
    medconnect_apk_unavailable();
}

$bytes = (int) filesize($apkPath);
if ($bytes < 10240) {
    medconnect_apk_log('APK too small: ' . $bytes . ' bytes');
    medconnect_apk_unavailable();
}

$handle = fopen($apkPath, 'rb');
if ($handle === false) {
    medconnect_apk_log('APK fopen failed');
    medconnect_apk_unavailable();
}

$magic = fread($handle, 4);
fclose($handle);
if ($magic !== "PK\x03\x04") {
    medconnect_apk_log('APK failed ZIP magic check');
    medconnect_apk_unavailable();
}

if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    if ($zip->open($apkPath) !== true || $zip->locateName('AndroidManifest.xml') === false) {
        if ($zip instanceof ZipArchive) {
            @$zip->close();
        }
        medconnect_apk_log('APK failed AndroidManifest check');
        medconnect_apk_unavailable();
    }
    $zip->close();
}

header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="' . $apkName . '"');
header('Content-Length: ' . $bytes);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Pragma: no-cache');
header_remove('X-Powered-By');

if ($method === 'HEAD') {
    exit;
}

$handle = fopen($apkPath, 'rb');
if ($handle === false) {
    medconnect_apk_log('APK fopen failed for output');
    medconnect_apk_unavailable();
}

fpassthru($handle);
fclose($handle);
exit;
