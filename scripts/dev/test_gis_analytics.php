<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/core/BagoBarangayCentroids.php';
require_once dirname(__DIR__, 2) . '/app/core/GisDashboardService.php';

$gis = new GisDashboardService($pdo);
echo json_encode($gis->getAnalytics(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
