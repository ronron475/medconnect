<?php
require_once dirname(__DIR__, 2) . '/config/db.php';

$path = dirname(__DIR__, 2) . '/database/migrations/2026_06_23_gis_patient_locations.sql';
$sql = file_get_contents($path);
if ($sql === false) {
    fwrite(STDERR, "Migration file not found.\n");
    exit(1);
}

foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    if ($statement === '' || str_starts_with($statement, '--')) {
        continue;
    }
    try {
        $pdo->exec($statement);
        echo 'OK: ' . substr(str_replace("\n", ' ', $statement), 0, 60) . PHP_EOL;
    } catch (Throwable $e) {
        echo 'SKIP: ' . $e->getMessage() . PHP_EOL;
    }
}

echo "GIS migration complete.\n";
