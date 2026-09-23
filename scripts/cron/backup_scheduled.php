<?php
/**
 * Scheduled database backup runner for hosting/server cron.
 *
 * Example crontab (run hourly; PHP decides if a backup is due):
 *   5 * * * * /usr/bin/php /path/to/medconnect/scripts/cron/backup_scheduled.php >> /path/to/medconnect/storage/logs/backup_cron.log 2>&1
 *
 * Flags:
 *   --force   Run even if not due (still requires automatic backups enabled)
 *
 * Never restores or overwrites the live database.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line (cron).\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require_once $root . '/bootstrap.php';
require_once $root . '/config/db.php';
require_once $root . '/app/includes/superadmin/backup.php';

$force = in_array('--force', array_slice($argv, 1), true);
$result = superadmin_run_scheduled_backup($pdo, $force);

$ts = date('c');
$msg = $result['message'] ?? 'done';
$skipped = !empty($result['skipped']) ? ' SKIPPED' : '';
$status = !empty($result['success']) ? 'OK' : 'FAIL';
echo "[{$ts}] {$status}{$skipped} {$msg}\n";

exit(!empty($result['success']) ? 0 : 1);
