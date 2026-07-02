<?php
require_once dirname(__DIR__, 2) . '/config/db.php';

$sql = file_get_contents(dirname(__DIR__, 2) . '/database/schema_schedule_updates.sql');
$parts = preg_split('/;\s*\R/', $sql);

foreach ($parts as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '' || stripos($stmt, 'USE ') === 0) {
        continue;
    }
    try {
        $pdo->exec($stmt);
        echo "OK: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 80) . "...\n";
    } catch (Throwable $e) {
        echo "ERR: " . $e->getMessage() . "\n";
    }
}
