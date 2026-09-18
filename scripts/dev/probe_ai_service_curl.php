<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/config/env_loader.php';
require_once BASE_PATH . '/config/app.php';

$url = AI_SERVICE_BASE_URL . '/health';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
]);
$body = curl_exec($ch);
$err = curl_error($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "url=$url\ncode=$code\nerr=$err\nbody_prefix=" . substr((string) $body, 0, 120) . "\n";

$ch2 = curl_init($url);
curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
]);
$body2 = curl_exec($ch2);
$err2 = curl_error($ch2);
$code2 = (int) curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);
echo "ssl_off code=$code2 err=$err2 ok=" . (str_contains((string) $body2, '"success"') ? 'yes' : 'no') . "\n";
