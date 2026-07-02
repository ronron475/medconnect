<?php
/**
 * Apply patient account security migration (users + patient_registrations columns).
 * Usage: php scripts/dev/apply_patient_account_security_migration.php
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/includes/patient_account_security.php';

patient_security_ensure_schema($pdo);
echo "Patient account security schema is up to date.\n";
