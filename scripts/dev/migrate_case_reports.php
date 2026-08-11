<?php
/**
 * One-shot migration: case_reports table + video consultation columns.
 * Usage:
 *   php scripts/dev/migrate_case_reports.php
 *   DB_ENV=cloud php scripts/dev/migrate_case_reports.php
 *   DB_HOST=srvXXX.hstgr.io DB_ENV=cloud php scripts/dev/migrate_case_reports.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/config/db.php';
require_once $root . '/app/includes/case_reports_schema.php';

echo "DB: " . DB_HOST . " / " . DB_NAME . "\n";

case_reports_ensure_schema($pdo);

$cols = $pdo->query('SHOW COLUMNS FROM case_reports')->fetchAll(PDO::FETCH_COLUMN);
echo "case_reports columns: " . implode(', ', $cols) . "\n";

$count = (int) $pdo->query('SELECT COUNT(*) FROM case_reports')->fetchColumn();
echo "case_reports rows: {$count}\n";
echo "Migration OK.\n";
