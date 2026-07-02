<?php
/**
 * Apply notification system migration.
 * CLI: php scripts/dev/apply_notification_migration.php
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/core/NotificationManager.php';

NotificationManager::ensureSchema($pdo);
echo "Notification schema ready.\n";
