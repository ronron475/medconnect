<?php
/**
 * Quick offline checks for smart GIS location helpers.
 * Usage: php scripts/dev/test_smart_gis_location.php
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/core/PatientAddressFormatter.php';
require_once dirname(__DIR__, 2) . '/app/core/BagoBarangayCentroids.php';

$addressRow = [
    'purok' => 'Balatong',
    'barangay' => 'Balingasag',
    'city_municipality' => 'Bago City',
    'province' => 'Negros Occidental',
];

$formatted = PatientAddressFormatter::build($addressRow);
$confidence = PatientAddressFormatter::confidence($addressRow);

echo "Formatted address: {$formatted}\n";
echo "Confidence: {$confidence}\n";

$canonical = BagoBarangayCentroids::canonicalName('Brgy. Balingasag');
echo "Canonical barangay: {$canonical}\n";

$invalid = BagoBarangayCentroids::canonicalName('Population');
echo "Invalid barangay canonical: " . var_export($invalid, true) . "\n";

echo "Done.\n";
