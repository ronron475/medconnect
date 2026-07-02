<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/core/BagoBarangayCentroids.php';
require_once dirname(__DIR__, 2) . '/app/core/GisDashboardService.php';

$gis = new GisDashboardService($pdo);

echo "=== users (patient) ===\n";
$stmt = $pdo->query("SELECT id, first_name, last_name, email, role, created_at FROM users WHERE role='patient' LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== patient_registrations (Mitche) ===\n";
$stmt = $pdo->prepare("SELECT id, email, first_name, last_name, barangay, city_municipality, province, full_address FROM patient_registrations WHERE first_name LIKE ? OR last_name LIKE ? OR full_name LIKE ? LIMIT 5");
$stmt->execute(['%Mitche%', '%Yuma%', '%Mitche%']);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== patient_locations ===\n";
try {
    $stmt = $pdo->query("SELECT * FROM patient_locations LIMIT 10");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== join test ===\n";
$stmt = $pdo->query("
    SELECT u.id, u.first_name, u.last_name, u.email, pr.barangay, pr.city_municipality, pl.latitude, pl.longitude
    FROM users u
    LEFT JOIN patient_registrations pr ON pr.email = u.email
    LEFT JOIN patient_locations pl ON pl.patient_id = u.id
    WHERE u.role = 'patient'
    LIMIT 10
");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$gis->syncMissingLocations();
echo "\n=== after sync ===\n";
$records = $gis->getPatientRecords();
echo "count=" . count($records) . "\n";
foreach ($records as $r) {
    echo json_encode([
        'id' => $r['patient_id'],
        'name' => $r['patient_name'],
        'barangay' => $r['barangay'],
        'lat' => $r['latitude'],
        'lng' => $r['longitude'],
    ], JSON_UNESCAPED_UNICODE) . "\n";
}
