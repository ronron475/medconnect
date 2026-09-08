<?php
/**
 * Offline probe: Doctor GIS location priority, privacy, and validation.
 * Does not require MySQL (uses in-memory PDO + Reflection).
 * Usage: php scripts/dev/probe_gis_location_precision.php
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/core/BagoBarangayCentroids.php';
require_once dirname(__DIR__, 2) . '/app/core/PatientAddressFormatter.php';
require_once dirname(__DIR__, 2) . '/app/core/PatientLocationResolver.php';
require_once dirname(__DIR__, 2) . '/app/core/GisDashboardService.php';

$stubPdo = new PDO('sqlite::memory:');
$resolver = new PatientLocationResolver($stubPdo);

$gisRef = new ReflectionClass(GisDashboardService::class);
$gis = $gisRef->newInstanceWithoutConstructor();
$pdoProp = $gisRef->getProperty('pdo');
$pdoProp->setAccessible(true);
$pdoProp->setValue($gis, $stubPdo);

$privacy = $gisRef->getMethod('applyViewerPrivacy');
$privacy->setAccessible(true);

$pass = 0;
$fail = 0;

function assertTrue(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        echo "PASS  {$label}\n";
        $pass++;
    } else {
        echo "FAIL  {$label}\n";
        $fail++;
    }
}

$gpsLat = 10.537812345;
$gpsLng = 122.841234567;

$gpsRow = $resolver->resolve([
    'barangay' => 'Poblacion',
    'city_municipality' => 'Bago City',
    'latitude' => $gpsLat,
    'longitude' => $gpsLng,
    'location_source' => 'gps',
    'purok' => 'Sample',
]);
assertTrue(($gpsRow['location_source'] ?? '') === 'gps', 'GPS source preserved');
assertTrue(($gpsRow['location_accuracy'] ?? '') === 'exact', 'GPS accuracy exact');
assertTrue(abs((float) $gpsRow['latitude'] - $gpsLat) < 1e-12, 'GPS lat not replaced/rounded');
assertTrue(abs((float) $gpsRow['longitude'] - $gpsLng) < 1e-12, 'GPS lng not replaced/rounded');

$providerGps = $privacy->invoke($gis, $gpsRow, 'provider');
assertTrue(($providerGps['can_view_exact_location'] ?? false) === true, 'Provider can view exact location');
assertTrue(empty($providerGps['location_privacy_masked']), 'Provider GPS not privacy-masked');
assertTrue(abs((float) $providerGps['latitude'] - $gpsLat) < 1e-12, 'Provider keeps exact GPS lat');
assertTrue(($providerGps['location_source'] ?? '') === 'gps', 'Provider keeps GPS source label');

$adminGps = $privacy->invoke($gis, $gpsRow, 'admin');
assertTrue(($adminGps['location_source'] ?? '') === 'gps', 'Admin keeps GPS source');

$maskedOther = $privacy->invoke($gis, $gpsRow, 'bhw');
assertTrue(!empty($maskedOther['location_privacy_masked']), 'Non-doctor role still privacy-masked');
assertTrue(($maskedOther['location_source'] ?? '') === 'barangay_center', 'Masked role falls back to barangay');
assertTrue(abs((float) $maskedOther['latitude'] - $gpsLat) > 1e-6, 'Masked role does not keep GPS pin');

$geoLat = 10.540111;
$geoLng = 122.845222;
$geoRow = $resolver->resolve([
    'barangay' => 'Poblacion',
    'city_municipality' => 'Bago City',
    'latitude' => $geoLat,
    'longitude' => $geoLng,
    'location_source' => 'address_geocoded',
    'purok' => 'Sample',
    'street' => 'Rizal St',
]);
assertTrue(($geoRow['location_source'] ?? '') === 'address_geocoded', 'Geocoded source preserved');
assertTrue(($geoRow['location_accuracy'] ?? '') === 'geocoded', 'Geocoded accuracy');
$providerGeo = $privacy->invoke($gis, $geoRow, 'provider');
assertTrue(($providerGeo['location_source'] ?? '') === 'address_geocoded', 'Provider keeps geocoded source');
assertTrue(abs((float) $providerGeo['latitude'] - $geoLat) < 1e-12, 'Provider keeps geocoded lat');

$brgyOnly = $resolver->resolve([
    'barangay' => 'Poblacion',
    'city_municipality' => 'Bago City',
    'latitude' => null,
    'longitude' => null,
    'location_source' => '',
]);
assertTrue(($brgyOnly['location_source'] ?? '') === 'barangay_center', 'Barangay-only uses barangay_center');
assertTrue(($brgyOnly['location_accuracy'] ?? '') === 'approximate', 'Barangay-only is approximate');
assertTrue(!empty($brgyOnly['has_map_marker']), 'Barangay-only still has marker');
assertTrue(
    stripos((string) ($brgyOnly['location_note'] ?? ''), 'barangay') !== false,
    'Barangay-only note discloses approximation'
);

$missing = $resolver->resolve([
    'barangay' => '',
    'city_municipality' => 'Bago City',
    'latitude' => null,
    'longitude' => null,
]);
assertTrue(($missing['location_source'] ?? '') === 'unavailable', 'Missing coords → unavailable');
assertTrue(empty($missing['has_map_marker']), 'Missing coords → no marker');

$invalidZero = $resolver->resolve([
    'barangay' => 'Poblacion',
    'city_municipality' => 'Bago City',
    'latitude' => 0,
    'longitude' => 0,
    'location_source' => 'gps',
]);
assertTrue(
    ($invalidZero['location_source'] ?? '') !== 'gps',
    '0,0 GPS not kept as exact'
);
assertTrue(
    ($invalidZero['latitude'] === null && ($invalidZero['location_source'] ?? '') === 'unavailable')
    || ($invalidZero['location_accuracy'] ?? '') === 'approximate',
    '0,0 does not plot as exact GPS'
);

$outOfCity = $resolver->resolve([
    'barangay' => 'Poblacion',
    'city_municipality' => 'Bago City',
    'latitude' => 14.5995,
    'longitude' => 120.9842,
    'location_source' => 'gps',
]);
assertTrue(
    ($outOfCity['location_source'] ?? '') !== 'gps',
    'Out-of-city GPS not kept as exact'
);

$sameA = $resolver->resolve([
    'barangay' => 'Poblacion',
    'city_municipality' => 'Bago City',
]);
$sameB = $resolver->resolve([
    'barangay' => 'Poblacion',
    'city_municipality' => 'Bago City',
]);
assertTrue(
    abs((float) $sameA['latitude'] - (float) $sameB['latitude']) < 1e-12
    && abs((float) $sameA['longitude'] - (float) $sameB['longitude']) < 1e-12,
    'Same barangay patients share identical center (no random jitter)'
);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
