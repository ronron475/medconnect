<?php
/**
 * Apply Super Admin platform migration.
 * Run: php scripts/dev/apply_superadmin_migration.php
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/includes/superadmin/schema.php';

superadmin_ensure_schema($pdo);
echo "OK  Super Admin schema applied.\n";
