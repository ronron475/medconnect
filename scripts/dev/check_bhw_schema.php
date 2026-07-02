<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
foreach (['users', 'patient_registrations', 'triage_results', 'barangays'] as $t) {
    try {
        $c = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN);
        echo "$t: " . implode(', ', $c) . PHP_EOL;
    } catch (Exception $e) {
        echo "$t: missing" . PHP_EOL;
    }
}
