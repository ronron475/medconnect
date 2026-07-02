<?php
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/db.php';

$sqlFile = dirname(__DIR__, 2) . '/database/migrations/2026_06_24_system_admin_modules.sql';
$sql = file_get_contents($sqlFile);
if ($sql === false) {
    fwrite(STDERR, "Cannot read migration file.\n");
    exit(1);
}

// MySQL 8 may not support ADD COLUMN IF NOT EXISTS — apply statements individually
$statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));

foreach ($statements as $stmt) {
    if ($stmt === '' || str_starts_with($stmt, '--')) {
        continue;
    }
    try {
        $pdo->exec($stmt);
        echo "OK: " . substr(str_replace(["\r", "\n"], ' ', $stmt), 0, 80) . "...\n";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column') || str_contains($e->getMessage(), 'already exists')) {
            echo "SKIP (exists): " . substr($stmt, 0, 60) . "...\n";
            continue;
        }
        fwrite(STDERR, "ERR: {$e->getMessage()}\n");
    }
}

echo "Migration complete.\n";
