<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/core/BagoBarangayCentroids.php';
require_once dirname(__DIR__, 2) . '/app/core/GisDashboardService.php';

$gis = new GisDashboardService($pdo);
$summary = $gis->getSummary();
$patients = $gis->getPatientRecords();

echo json_encode([
    'summary' => $summary,
    'patient_count' => count($patients),
    'patients_with_coords' => count(array_filter($patients, static fn ($p) => $p['latitude'] && $p['longitude'])),
    'mitche' => array_values(array_filter($patients, static fn ($p) => stripos((string) ($p['patient_name'] ?? ''), 'yuma') !== false)),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
