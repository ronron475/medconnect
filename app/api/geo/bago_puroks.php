<?php
/**
 * Public reference data: Bago City barangay purok/sitio catalog for client address normalization.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$path = dirname(__DIR__, 3) . '/data/geo/bago_city_puroks.json';
if (!is_readable($path)) {
    http_response_code(404);
    echo json_encode(['error' => 'Catalog unavailable']);
    exit;
}

readfile($path);
