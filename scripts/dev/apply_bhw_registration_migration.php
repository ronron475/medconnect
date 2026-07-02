<?php
/**
 * Apply BHW registration tracking column migration.
 * Usage: php scripts/dev/apply_bhw_registration_migration.php
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/db.php';

$cols = $pdo->query('SHOW COLUMNS FROM patient_registrations')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('registered_by_bhw_id', $cols, true)) {
    $pdo->exec('ALTER TABLE patient_registrations ADD COLUMN registered_by_bhw_id INT UNSIGNED NULL DEFAULT NULL');
    echo "Added registered_by_bhw_id column.\n";
} else {
    echo "registered_by_bhw_id already exists.\n";
}

try {
    $pdo->exec('CREATE INDEX idx_pr_registered_by_bhw ON patient_registrations (registered_by_bhw_id)');
    echo "Index created.\n";
} catch (PDOException $e) {
    echo "Index skipped (may already exist).\n";
}

echo "Done.\n";
