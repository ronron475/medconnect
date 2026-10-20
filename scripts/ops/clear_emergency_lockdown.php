<?php
/**
 * CLI / filesystem break-glass for emergency restore lockdown.
 *
 * Use when MySQL is unavailable and the web Super Admin recovery flow cannot run
 * (login, remember-me, password re-auth, and emergency_clear all require the DB).
 *
 * This script does NOT:
 * - Grant portal access
 * - Bypass authentication for HTTP requests
 * - Auto-clear lockdown without an explicit confirmation phrase
 * - Claim the database is healthy
 *
 * Safe procedure:
 *   1. SSH / Hostinger terminal into the app host.
 *   2. cd to the MedConnect project root.
 *   3. php scripts/ops/clear_emergency_lockdown.php --status
 *   4. Only if status is emergency_lockdown (not an in-progress "restoring"):
 *        php scripts/ops/clear_emergency_lockdown.php --confirm="CLEAR EMERGENCY LOCKDOWN"
 *   5. Repair / restore MySQL separately, then verify the app.
 *
 * Run: php scripts/ops/clear_emergency_lockdown.php --help
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This recovery tool is CLI-only.\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $root);
}

require_once $root . '/app/includes/superadmin/backup.php';

$args = array_slice($argv, 1);
$wantHelp = in_array('--help', $args, true) || in_array('-h', $args, true);
$wantStatus = in_array('--status', $args, true);
$confirm = '';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--confirm=')) {
        $confirm = substr($arg, strlen('--confirm='));
        // Allow surrounding quotes from shells
        $confirm = trim($confirm, " \t\"'");
    }
}

$lockPath = superadmin_restore_lock_path();
$state = superadmin_restore_state($lockPath);

if ($wantHelp || ($args === [])) {
    echo "MedConnect emergency lockdown CLI recovery\n\n";
    echo "Lock file: {$lockPath}\n";
    echo "Current status: " . ($state['status'] ?? 'unknown') . "\n";
    echo "Active: " . (!empty($state['active']) ? 'yes' : 'no') . "\n\n";
    echo "Commands:\n";
    echo "  --status\n";
    echo "  --confirm=\"CLEAR EMERGENCY LOCKDOWN\"\n\n";
    echo "Web recovery (login + Backup & Recovery) requires a healthy MySQL connection.\n";
    echo "Do not delete the lock file casually while status is \"restoring\".\n";
    exit(0);
}

if ($wantStatus) {
    echo json_encode([
        'lock_path' => $lockPath,
        'exists' => is_file($lockPath),
        'state' => [
            'active' => !empty($state['active']),
            'status' => $state['status'] ?? null,
            'reason' => $state['reason'] ?? null,
            'started_at' => $state['started_at'] ?? null,
            'failed_at' => $state['failed_at'] ?? null,
            // diagnostics may contain internal detail — keep short
            'has_diagnostics' => !empty($state['diagnostics']),
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

if ($confirm === '') {
    fwrite(STDERR, "Refusing to modify lockdown without --confirm=\"CLEAR EMERGENCY LOCKDOWN\".\n");
    exit(2);
}

if ($confirm !== 'CLEAR EMERGENCY LOCKDOWN') {
    fwrite(STDERR, "Confirmation phrase mismatch. Lock file unchanged.\n");
    exit(2);
}

if (!is_file($lockPath)) {
    echo "No lockdown lock file present. Nothing to clear.\n";
    exit(0);
}

$status = (string) ($state['status'] ?? '');
if ($status === 'restoring') {
    fwrite(STDERR, "Refusing to clear lock while status is \"restoring\". Wait for the restore process to finish or investigate the running PHP worker first.\n");
    exit(3);
}

if ($status !== 'emergency_lockdown' && !empty($state['active'])) {
    fwrite(STDERR, "Refusing to clear unexpected active status \"{$status}\". Use --status and investigate.\n");
    exit(3);
}

if ($status !== 'emergency_lockdown' && empty($state['active'])) {
    // Malformed/empty fail-closed locks may still need clearing after investigation.
    if (!in_array($status, ['malformed', 'unreadable', ''], true) && $status !== 'idle') {
        fwrite(STDERR, "Lock present with status \"{$status}\". Not clearing automatically.\n");
        exit(3);
    }
}

$backup = $lockPath . '.cleared.' . date('Ymd_His') . '.bak';
@copy($lockPath, $backup);
if (!@unlink($lockPath)) {
    fwrite(STDERR, "Failed to remove lock file. Check permissions on storage/backups.\n");
    exit(4);
}

$logDir = $root . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logLine = sprintf(
    "[%s] emergency_lockdown_cleared_via_cli host=%s user=%s backup=%s prev_status=%s\n",
    date('c'),
    gethostname() ?: 'unknown',
    get_current_user() ?: 'unknown',
    basename($backup),
    $status !== '' ? $status : 'unknown'
);
@file_put_contents($logDir . '/emergency_lockdown_cli.log', $logLine, FILE_APPEND);

echo "Emergency lockdown lock file removed.\n";
echo "Backup of prior lock written to: {$backup}\n";
echo "This does NOT verify MySQL health. Repair the database before returning the site to users.\n";
exit(0);
