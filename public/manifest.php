<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once BASE_PATH . '/app/includes/mobile_app.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

echo json_encode(
    medconnect_web_manifest(),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
