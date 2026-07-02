<?php
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/includes/user_account_status.php';

user_account_status_ensure_schema($pdo);

$tables = $pdo->query("SHOW TABLES LIKE 'user_account_status_logs'")->fetchColumn();
$cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'account_status'")->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'logs_table' => (bool) $tables,
    'account_status_column' => $cols['Type'] ?? null,
], JSON_PRETTY_PRINT) . PHP_EOL;
