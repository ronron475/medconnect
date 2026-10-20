<?php
declare(strict_types=1);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/security.php';

function superadmin_backup_dir(): string
{
    // Controllers that load this file without bootstrap (OCR/OTP) may not define BASE_PATH.
    $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
    $dir = $base . '/storage/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Resolve a backup filesystem path and ensure it is a real file under storage/backups.
 * Rejects path traversal, symlinks that escape the backup dir, and non-files.
 *
 * @return string|null Canonical absolute path, or null if unsafe/missing
 */
function superadmin_backup_resolve_safe_path(string $path): ?string
{
    $path = trim($path);
    if ($path === '') {
        return null;
    }

    // Reject obvious traversal / NUL before filesystem calls.
    if (str_contains($path, "\0") || preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $path)) {
        return null;
    }

    $backupDir = superadmin_backup_dir();
    $backupReal = realpath($backupDir);
    if ($backupReal === false || !is_dir($backupReal)) {
        return null;
    }

    // Resolve relative paths against the backup directory only.
    if (!preg_match('#^(?:[a-zA-Z]:[\\\\/]|/)#', $path)) {
        $path = $backupReal . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    // Reject symlink leaf before realpath follows it (defense in depth).
    if (is_link($path)) {
        return null;
    }

    $resolved = realpath($path);
    if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
        return null;
    }

    if (is_link($resolved)) {
        return null;
    }

    $backupNorm = rtrim(str_replace('\\', '/', $backupReal), '/');
    $resolvedNorm = str_replace('\\', '/', $resolved);
    if ($resolvedNorm !== $backupNorm && !str_starts_with($resolvedNorm, $backupNorm . '/')) {
        return null;
    }

    // Filename must look like a SQL backup (created by this app).
    $base = basename($resolved);
    if ($base === '' || $base === '.' || $base === '..') {
        return null;
    }
    if (!preg_match('/\.sql$/i', $base)) {
        return null;
    }

    return $resolved;
}

const SUPERADMIN_BACKUP_RESTORE_PHRASE = 'RESTORE DATABASE';

/**
/**
 * Re-authenticate the logged-in Admin or Super Admin with their current password.
 * Uses password_verify() against users.password. Never stores the submitted password.
 */
function superadmin_verify_current_password(PDO $pdo, int $userId, string $password): bool
{
    if ($userId <= 0 || $password === '') {
        return false;
    }
    try {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? AND role IN ('admin', 'superadmin') LIMIT 1");
        $stmt->execute([$userId]);
        $hash = (string) ($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return false;
    }
    if ($hash === '') {
        return false;
    }
    return password_verify($password, $hash);
}

/**
 * Determine the user's role for audit logs ('admin', 'superadmin', or 'system').
 */
function superadmin_backup_user_role(PDO $pdo, ?int $userId): string
{
    if (!$userId || $userId <= 0) {
        return 'system';
    }
    if (!empty($_SESSION['user_role']) && (int) ($_SESSION['user_id'] ?? 0) === $userId) {
        return (string) $_SESSION['user_role'];
    }
    try {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $role = (string) ($stmt->fetchColumn() ?: '');
        if ($role !== '') {
            return $role;
        }
    } catch (Throwable $e) {
    }
    return 'admin';
}

function superadmin_backup_confirm_phrase_valid(string $confirmText): bool
{
    return hash_equals(SUPERADMIN_BACKUP_RESTORE_PHRASE, $confirmText);
}

function superadmin_backup_file_checksum(string $path): ?string
{
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return null;
    }
    $hash = @hash_file('sha256', $path);
    if (!is_string($hash) || strlen($hash) !== 64) {
        return null;
    }
    return strtolower($hash);
}

/**
 * @return array{ok:bool,reason:string}
 */
function superadmin_backup_verify_checksum(string $resolvedPath, ?string $storedChecksum): array
{
    $stored = strtolower(trim((string) $storedChecksum));
    if ($stored === '' || !preg_match('/^[a-f0-9]{64}$/', $stored)) {
        return ['ok' => false, 'reason' => 'missing_checksum'];
    }
    $actual = superadmin_backup_file_checksum($resolvedPath);
    if ($actual === null) {
        return ['ok' => false, 'reason' => 'checksum_unavailable'];
    }
    if (!hash_equals($stored, $actual)) {
        return ['ok' => false, 'reason' => 'checksum_mismatch'];
    }
    return ['ok' => true, 'reason' => ''];
}

function superadmin_backup_audit_fail(
    PDO $pdo,
    string $action,
    int $userId,
    int $backupId,
    string $reason
): void {
    $reason = trim($reason);
    if ($reason === '') {
        $reason = 'failed';
    }
    $desc = $backupId > 0 ? "Backup #{$backupId}: {$reason}" : $reason;
    $role = superadmin_backup_user_role($pdo, $userId > 0 ? $userId : null);
    try {
        superadmin_security_log(
            $pdo,
            $action,
            'backup',
            'failure',
            $desc,
            $userId > 0 ? $userId : null,
            $role
        );
    } catch (Throwable $e) {
        // Logging must never block deny-by-default.
    }
}

function superadmin_backup_audit_success(
    PDO $pdo,
    string $action,
    int $userId,
    int $backupId,
    string $description,
    string $status = 'success'
): void {
    $desc = $backupId > 0 ? "Backup #{$backupId}: {$description}" : $description;
    $role = superadmin_backup_user_role($pdo, $userId > 0 ? $userId : null);
    try {
        superadmin_security_log(
            $pdo,
            $action,
            'backup',
            $status,
            $desc,
            $userId > 0 ? $userId : null,
            $role
        );
    } catch (Throwable $e) {
    }
}

/**
 * Unlink a retained dump only when the stored path is inside storage/backups.
 * Unsafe paths are not deleted.
 */
function superadmin_backup_retention_unlink_if_safe(PDO $pdo, int $logId, string $path): bool
{
    if ($path === '') {
        return false;
    }
    $safe = superadmin_backup_resolve_safe_path($path);
    if ($safe === null) {
        superadmin_backup_audit_fail(
            $pdo,
            'database_backup_retention',
            0,
            $logId,
            'unsafe path skipped (not deleted)'
        );
        return false;
    }
    if (is_file($safe)) {
        @unlink($safe);
    }
    return true;
}

/** @return array{enabled:bool,frequency:string,hour:int,weekday:int,retention_count:int,last_auto_at:?string,last_auto_status:?string,last_auto_message:?string} */
function superadmin_backup_settings(PDO $pdo): array
{
    superadmin_ensure_schema($pdo);
    $freq = strtolower((string) system_settings_get($pdo, 'BACKUP_SCHEDULE_FREQUENCY', 'daily'));
    if (!in_array($freq, ['hourly', 'daily', 'weekly'], true)) {
        $freq = 'daily';
    }
    $hour = (int) system_settings_get($pdo, 'BACKUP_SCHEDULE_HOUR', '2');
    if ($hour < 0 || $hour > 23) {
        $hour = 2;
    }
    $weekday = (int) system_settings_get($pdo, 'BACKUP_SCHEDULE_WEEKDAY', '0');
    if ($weekday < 0 || $weekday > 6) {
        $weekday = 0;
    }
    $retention = (int) system_settings_get($pdo, 'BACKUP_RETENTION_COUNT', '14');
    if ($retention < 1) {
        $retention = 1;
    }
    if ($retention > 365) {
        $retention = 365;
    }

    return [
        'enabled' => system_settings_get($pdo, 'BACKUP_AUTO_ENABLED', '0') === '1',
        'frequency' => $freq,
        'hour' => $hour,
        'weekday' => $weekday,
        'retention_count' => $retention,
        'last_auto_at' => system_settings_get($pdo, 'BACKUP_LAST_AUTO_AT'),
        'last_auto_status' => system_settings_get($pdo, 'BACKUP_LAST_AUTO_STATUS'),
        'last_auto_message' => system_settings_get($pdo, 'BACKUP_LAST_AUTO_MESSAGE'),
    ];
}

/** @param array{enabled?:bool|string,frequency?:string,hour?:int|string,weekday?:int|string,retention_count?:int|string} $input */
function superadmin_backup_settings_save(PDO $pdo, array $input, ?int $updatedBy = null): array
{
    superadmin_ensure_schema($pdo);

    $enabled = !empty($input['enabled']) && (string) $input['enabled'] !== '0';
    $freq = strtolower((string) ($input['frequency'] ?? 'daily'));
    if (!in_array($freq, ['hourly', 'daily', 'weekly'], true)) {
        return ['success' => false, 'message' => 'Invalid schedule frequency.'];
    }
    $hour = (int) ($input['hour'] ?? 2);
    if ($hour < 0 || $hour > 23) {
        return ['success' => false, 'message' => 'Hour must be between 0 and 23.'];
    }
    $weekday = (int) ($input['weekday'] ?? 0);
    if ($weekday < 0 || $weekday > 6) {
        return ['success' => false, 'message' => 'Weekday must be between 0 (Sun) and 6 (Sat).'];
    }
    $retention = (int) ($input['retention_count'] ?? 14);
    if ($retention < 1 || $retention > 365) {
        return ['success' => false, 'message' => 'Retention must be between 1 and 365 backups.'];
    }

    system_settings_set_many($pdo, [
        'BACKUP_AUTO_ENABLED' => $enabled ? '1' : '0',
        'BACKUP_SCHEDULE_FREQUENCY' => $freq,
        'BACKUP_SCHEDULE_HOUR' => (string) $hour,
        'BACKUP_SCHEDULE_WEEKDAY' => (string) $weekday,
        'BACKUP_RETENTION_COUNT' => (string) $retention,
    ], $updatedBy);

    return [
        'success' => true,
        'message' => 'Backup schedule settings saved.',
        'settings' => superadmin_backup_settings($pdo),
    ];
}

function superadmin_backup_mark_auto_result(PDO $pdo, string $status, string $message): void
{
    system_settings_set_many($pdo, [
        'BACKUP_LAST_AUTO_AT' => date('Y-m-d H:i:s'),
        'BACKUP_LAST_AUTO_STATUS' => $status,
        'BACKUP_LAST_AUTO_MESSAGE' => substr($message, 0, 500),
    ], null);
}

function superadmin_backup_is_due(PDO $pdo, ?array $settings = null): bool
{
    $settings ??= superadmin_backup_settings($pdo);
    if (!$settings['enabled']) {
        return false;
    }

    $now = new DateTimeImmutable('now');
    $lastRaw = $settings['last_auto_at'] ?? null;
    $last = $lastRaw ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $lastRaw) : false;
    if ($last === false && $lastRaw) {
        $ts = strtotime($lastRaw);
        $last = $ts ? (new DateTimeImmutable())->setTimestamp($ts) : false;
    }

    $freq = $settings['frequency'];
    $hour = (int) $settings['hour'];

    if ($freq === 'hourly') {
        if (!$last) {
            return true;
        }
        return $last->getTimestamp() <= ($now->getTimestamp() - 55 * 60);
    }

    if ((int) $now->format('G') < $hour) {
        return false;
    }

    if ($freq === 'weekly') {
        if ((int) $now->format('w') !== (int) $settings['weekday']) {
            return false;
        }
    }

    if (!$last) {
        return true;
    }

    // One automatic attempt per calendar day for daily/weekly windows.
    return $last->format('Y-m-d') < $now->format('Y-m-d');
}

/**
 * Create a SQL dump backup.
 *
 * @param int|null $userId Null for scheduled/system runs
 */
function superadmin_create_backup(PDO $pdo, ?int $userId = null, string $type = 'manual'): array
{
    superadmin_ensure_schema($pdo);

    if (!in_array($type, ['manual', 'scheduled', 'pre_restore'], true)) {
        $type = 'manual';
    }

    $timestamp = date('Y-m-d_His');
    $filename = "medconnect_backup_{$timestamp}.sql";
    $dir = superadmin_backup_dir();
    $path = $dir . '/' . $filename;

    $logId = null;
    try {
        $stmt = $pdo->prepare('
            INSERT INTO backup_logs (filename, file_path, backup_type, status, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([$filename, $path, $type, 'in_progress', $userId]);
        $logId = (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        // continue; file may still be written
    }

    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!$dbName) {
        if ($logId) {
            $pdo->prepare("UPDATE backup_logs SET status='failed', notes=? WHERE id=?")
                ->execute(['Could not determine database name.', $logId]);
        }
        return ['success' => false, 'message' => 'Could not determine database name.', 'log_id' => $logId];
    }

    $lines = ["-- medConnect backup\n", '-- Generated: ' . date('c') . "\n", "-- Type: {$type}\n\n"];
    $lines[] = "SET FOREIGN_KEY_CHECKS=0;\n\n";

    try {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            if (!empty($create['Create Table'])) {
                $lines[] = "DROP TABLE IF EXISTS `{$table}`;\n";
                $lines[] = $create['Create Table'] . ";\n\n";
            }
            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) {
                foreach ($rows as $row) {
                    $vals = array_map(static function ($v) use ($pdo) {
                        if ($v === null) {
                            return 'NULL';
                        }
                        return $pdo->quote((string) $v);
                    }, array_values($row));
                    $cols = implode('`, `', array_keys($row));
                    $lines[] = "INSERT INTO `{$table}` (`{$cols}`) VALUES (" . implode(', ', $vals) . ");\n";
                }
                $lines[] = "\n";
            }
        }
    } catch (Throwable $e) {
        if ($logId) {
            $pdo->prepare("UPDATE backup_logs SET status='failed', notes=? WHERE id=?")->execute([$e->getMessage(), $logId]);
        }
        $callerRole = superadmin_backup_user_role($pdo, $userId);
        superadmin_security_log(
            $pdo,
            'database_backup',
            'backup',
            'failure',
            "Backup failed ({$type}): " . $e->getMessage(),
            $userId,
            $callerRole
        );
        return ['success' => false, 'message' => 'Backup failed: ' . $e->getMessage(), 'log_id' => $logId];
    }

    $lines[] = "SET FOREIGN_KEY_CHECKS=1;\n";
    $content = implode('', $lines);
    if (file_put_contents($path, $content) === false) {
        if ($logId) {
            $pdo->prepare("UPDATE backup_logs SET status='failed', notes=? WHERE id=?")
                ->execute(['Could not write backup file.', $logId]);
        }
        return ['success' => false, 'message' => 'Could not write backup file.', 'log_id' => $logId];
    }
    $size = filesize($path) ?: 0;
    $checksum = superadmin_backup_file_checksum($path);
    if ($checksum === null) {
        if ($logId) {
            $pdo->prepare("UPDATE backup_logs SET status='failed', notes=? WHERE id=?")
                ->execute(['Could not compute backup integrity checksum.', $logId]);
        }
        $callerRole = superadmin_backup_user_role($pdo, $userId);
        superadmin_security_log(
            $pdo,
            'database_backup',
            'backup',
            'failure',
            "Backup checksum failed ({$type}): {$filename}",
            $userId,
            $callerRole
        );
        return ['success' => false, 'message' => 'Could not compute backup integrity checksum.', 'log_id' => $logId];
    }

    if ($logId) {
        try {
            $pdo->prepare("UPDATE backup_logs SET status='success', file_size=?, file_checksum=?, notes=? WHERE id=?")
                ->execute([$size, $checksum, 'Backup completed successfully.', $logId]);
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE backup_logs SET status='failed', notes=? WHERE id=?")
                ->execute(['Could not store backup integrity checksum.', $logId]);
            return ['success' => false, 'message' => 'Could not store backup integrity checksum.', 'log_id' => $logId];
        }
    }

    $callerRole = superadmin_backup_user_role($pdo, $userId);
    superadmin_security_log(
        $pdo,
        'database_backup',
        'backup',
        'success',
        "Backup created ({$type}): {$filename}",
        $userId,
        $callerRole
    );

    $purged = superadmin_apply_backup_retention($pdo);

    return [
        'success' => true,
        'message' => 'Backup created successfully.',
        'filename' => $filename,
        'path' => $path,
        'size' => $size,
        'log_id' => $logId,
        'type' => $type,
        'purged' => $purged,
    ];
}

/**
 * Cron/CLI entry: run scheduled backup only when enabled and due.
 * Never restores or overwrites the live database.
 */
function superadmin_run_scheduled_backup(PDO $pdo, bool $force = false): array
{
    superadmin_ensure_schema($pdo);
    $settings = superadmin_backup_settings($pdo);

    if (!$settings['enabled'] && !$force) {
        return ['success' => true, 'skipped' => true, 'message' => 'Automatic backups are disabled.'];
    }

    if (!$force && !superadmin_backup_is_due($pdo, $settings)) {
        return ['success' => true, 'skipped' => true, 'message' => 'Scheduled backup is not due yet.'];
    }

    $result = superadmin_create_backup($pdo, null, 'scheduled');
    if (!empty($result['success'])) {
        $msg = 'Automatic backup succeeded: ' . ($result['filename'] ?? '');
        superadmin_backup_mark_auto_result($pdo, 'success', $msg);
        $result['message'] = $msg;
    } else {
        $msg = (string) ($result['message'] ?? 'Automatic backup failed.');
        superadmin_backup_mark_auto_result($pdo, 'failed', $msg);
        $result['message'] = $msg;
    }

    return $result;
}

/**
 * Keep the newest N successful dump backups; delete older files + log rows.
 * Restore audit rows are never deleted by retention.
 *
 * @return int Number of backups removed
 */
function superadmin_apply_backup_retention(PDO $pdo): int
{
    $settings = superadmin_backup_settings($pdo);
    $keep = (int) $settings['retention_count'];
    if ($keep < 1) {
        return 0;
    }

    try {
        $rows = $pdo->query("
            SELECT id, file_path
            FROM backup_logs
            WHERE status = 'success'
              AND backup_type IN ('manual', 'scheduled')
            ORDER BY created_at DESC, id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return 0;
    }

    if (count($rows) <= $keep) {
        return 0;
    }

    $toDelete = array_slice($rows, $keep);
    $removed = 0;
    $del = $pdo->prepare('DELETE FROM backup_logs WHERE id = ?');
    foreach ($toDelete as $row) {
        $path = (string) ($row['file_path'] ?? '');
        $rowId = (int) ($row['id'] ?? 0);
        superadmin_backup_retention_unlink_if_safe($pdo, $rowId, $path);
        try {
            $del->execute([$rowId]);
            $removed++;
        } catch (Throwable $e) {
            // continue
        }
    }

    if ($removed > 0) {
        superadmin_security_log(
            $pdo,
            'database_backup_retention',
            'backup',
            'info',
            "Retention removed {$removed} old backup(s); keeping newest {$keep}.",
            null,
            'system'
        );
    }

    return $removed;
}

function superadmin_list_backups(PDO $pdo, int $limit = 50): array
{
    superadmin_ensure_schema($pdo);
    try {
        $stmt = $pdo->prepare('
            SELECT b.*, u.first_name, u.last_name
            FROM backup_logs b
            LEFT JOIN users u ON u.id = b.created_by
            ORDER BY b.created_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/** @return array{latest:?array,last_auto:?array,settings:array,is_due:bool} */
function superadmin_backup_status_summary(PDO $pdo): array
{
    try {
        $settings = superadmin_backup_settings($pdo);
    } catch (Throwable $e) {
        $settings = [
            'enabled' => false,
            'frequency' => 'daily',
            'hour' => 2,
            'weekday' => 0,
            'retention_count' => 14,
            'last_auto_at' => null,
            'last_auto_status' => null,
            'last_auto_message' => null,
        ];
    }
    $latest = null;
    try {
        $latest = $pdo->query("
            SELECT id, filename, backup_type, status, file_size, created_at, notes
            FROM backup_logs
            WHERE backup_type IN ('manual', 'scheduled')
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $latest = null;
    }

    $lastAuto = null;
    try {
        $lastAuto = $pdo->query("
            SELECT id, filename, backup_type, status, file_size, created_at, notes
            FROM backup_logs
            WHERE backup_type = 'scheduled'
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $lastAuto = null;
    }

    $isDue = false;
    try {
        $isDue = superadmin_backup_is_due($pdo, $settings);
    } catch (Throwable $e) {
        $isDue = false;
    }

    return [
        'latest' => $latest ?: null,
        'last_auto' => $lastAuto ?: null,
        'settings' => $settings,
        'is_due' => $isDue,
        'restore_state' => superadmin_restore_state(),
    ];
}

const SUPERADMIN_RESTORE_LOCK_FILE = '.restore_state.lock';

function superadmin_restore_lock_path(): string
{
    return superadmin_backup_dir() . '/' . SUPERADMIN_RESTORE_LOCK_FILE;
}

/**
 * Read the current maintenance barrier / restore state fail-closed.
 * An unreadable, empty, or malformed lock file is NEVER interpreted as inactive.
 *
 * @return array{
 *     active: bool,
 *     status: string,
 *     reason: string,
 *     token: ?string,
 *     owner_id: ?int,
 *     owner_role: ?string,
 *     backup_id: ?int,
 *     started_at: ?string,
 *     failed_at: ?string,
 *     diagnostics: ?string
 * }
 */
function superadmin_restore_state(?string $customPath = null): array
{
    $path = $customPath ?? superadmin_restore_lock_path();
    if (!file_exists($path)) {
        return [
            'active' => false,
            'status' => 'idle',
            'reason' => '',
            'token' => null,
            'owner_id' => null,
            'owner_role' => null,
            'backup_id' => null,
            'started_at' => null,
            'failed_at' => null,
            'diagnostics' => null,
        ];
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        // Fail-closed: unreadable lock file MUST be treated as an active maintenance barrier
        return [
            'active' => true,
            'status' => 'unreadable',
            'reason' => 'Maintenance barrier lock file exists but could not be read.',
            'token' => null,
            'owner_id' => null,
            'owner_role' => null,
            'backup_id' => null,
            'started_at' => null,
            'failed_at' => null,
            'diagnostics' => 'Filesystem read error on restore state file.',
        ];
    }

    $rawTrim = trim($raw);
    if ($rawTrim === '') {
        // Fail-closed: empty or interrupted lock file MUST be treated as an active maintenance barrier
        return [
            'active' => true,
            'status' => 'malformed',
            'reason' => 'Maintenance barrier lock file is empty.',
            'token' => null,
            'owner_id' => null,
            'owner_role' => null,
            'backup_id' => null,
            'started_at' => null,
            'failed_at' => null,
            'diagnostics' => 'Empty lock file detected.',
        ];
    }

    $data = json_decode($rawTrim, true);
    if (!is_array($data) || empty($data['status'])) {
        // Fail-closed: corrupted JSON payload
        return [
            'active' => true,
            'status' => 'malformed',
            'reason' => 'Maintenance barrier lock file contains invalid data.',
            'token' => null,
            'owner_id' => null,
            'owner_role' => null,
            'backup_id' => null,
            'started_at' => null,
            'failed_at' => null,
            'diagnostics' => 'Corrupt or non-JSON lock payload.',
        ];
    }

    $status = (string) $data['status'];
    $isActive = in_array($status, ['restoring', 'emergency_lockdown'], true);

    return [
        'active' => $isActive,
        'status' => $status,
        'reason' => (string) ($data['reason'] ?? ($status === 'emergency_lockdown' ? 'Emergency recovery lockdown active' : 'Database restore in progress')),
        'token' => isset($data['token']) ? (string) $data['token'] : null,
        'owner_id' => isset($data['owner_id']) ? (int) $data['owner_id'] : null,
        'owner_role' => isset($data['owner_role']) ? (string) $data['owner_role'] : null,
        'backup_id' => isset($data['backup_id']) ? (int) $data['backup_id'] : null,
        'started_at' => isset($data['started_at']) ? (string) $data['started_at'] : null,
        'failed_at' => isset($data['failed_at']) ? (string) $data['failed_at'] : null,
        'diagnostics' => isset($data['diagnostics']) ? (string) $data['diagnostics'] : null,
    ];
}

/**
 * Acquire exclusive restore locks:
 * 1. MySQL advisory lock on the EXACT PDO connection running the restore.
 * 2. Atomic, file-backed maintenance barrier outside public web root with strict 0600 permissions.
 *
 * @return array{success:bool,token?:string,status_code:int,code:string,message:string}
 */
function superadmin_restore_lock_acquire(
    PDO $pdo,
    int $userId,
    string $userRole,
    int $backupId,
    ?string $customPath = null
): array {
    $path = $customPath ?? superadmin_restore_lock_path();

    // 1. Check existing barrier state
    $existing = superadmin_restore_state($path);
    if ($existing['active']) {
        if ($existing['status'] === 'emergency_lockdown') {
            return [
                'success' => false,
                'status_code' => 503,
                'code' => 'emergency_lockdown',
                'message' => 'System is in emergency recovery mode following a database restore issue. New restore cannot proceed until emergency lockdown is resolved.',
            ];
        }
        return [
            'success' => false,
            'status_code' => 409,
            'code' => 'lock_conflict',
            'message' => 'Another database restore is currently in progress.',
        ];
    }

    // 2. MySQL advisory lock on the exact same PDO connection performing the restore
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $isMysql = str_starts_with($driver, 'mysql');
    if ($isMysql) {
        try {
            $stmt = $pdo->query("SELECT GET_LOCK('medconnect_db_restore_lock', 0)");
            $lockRes = $stmt ? $stmt->fetchColumn() : null;
        } catch (Throwable $e) {
            $lockRes = null;
        }
        // Explicitly handle all GET_LOCK() results: 1 = success; 0 = lock held; NULL = error
        if ($lockRes === null || (int) $lockRes !== 1) {
            return [
                'success' => false,
                'status_code' => 409,
                'code' => 'lock_conflict',
                'message' => 'Another database restore process holds the database lock.',
            ];
        }
    }

    // 3. Re-check file barrier to eliminate any potential acquisition race condition
    $recheck = superadmin_restore_state($path);
    if ($recheck['active']) {
        if ($isMysql) {
            try {
                $pdo->query("SELECT RELEASE_LOCK('medconnect_db_restore_lock')");
            } catch (Throwable $e) {}
        }
        return [
            'success' => false,
            'status_code' => 409,
            'code' => 'lock_conflict',
            'message' => 'Another database restore is currently in progress.',
        ];
    }

    // 4. Atomically create file lock using 'x' mode (exclusive create; fails if file exists)
    $token = bin2hex(random_bytes(32));
    $payload = [
        'status' => 'restoring',
        'token' => $token,
        'owner_id' => $userId,
        'owner_role' => $userRole,
        'backup_id' => $backupId,
        'started_at' => date('c'),
        'pid' => getmypid(),
        'reason' => 'Database restore in progress',
    ];

    $fp = @fopen($path, 'xb');
    if ($fp === false) {
        // Concurrently created by competing process
        if ($isMysql) {
            try {
                $pdo->query("SELECT RELEASE_LOCK('medconnect_db_restore_lock')");
            } catch (Throwable $e) {}
        }
        return [
            'success' => false,
            'status_code' => 409,
            'code' => 'lock_conflict',
            'message' => 'Another database restore is currently in progress.',
        ];
    }

    $written = fwrite($fp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    fclose($fp);
    @chmod($path, 0600);

    if ($written === false || $written <= 0) {
        @unlink($path);
        if ($isMysql) {
            try {
                $pdo->query("SELECT RELEASE_LOCK('medconnect_db_restore_lock')");
            } catch (Throwable $e) {}
        }
        return [
            'success' => false,
            'status_code' => 500,
            'code' => 'lock_write_failure',
            'message' => 'Failed to initialize restore barrier lock file.',
        ];
    }

    if (!defined('MEDCONNECT_RESTORE_RUNNING')) {
        define('MEDCONNECT_RESTORE_RUNNING', true);
    }

    return [
        'success' => true,
        'token' => $token,
        'status_code' => 200,
        'code' => 'ok',
        'message' => 'Restore lock acquired.',
    ];
}

/**
 * Release the ordinary restore lock by its authenticated owner.
 * Strict ownership: wrong/empty token or emergency lockdown NEVER releases file or MySQL advisory lock.
 */
function superadmin_restore_lock_release(PDO $pdo, string $token, ?string $customPath = null): bool
{
    $token = trim($token);
    if ($token === '') {
        return false;
    }

    $path = $customPath ?? superadmin_restore_lock_path();
    $fileReleased = false;

    if (file_exists($path)) {
        $state = superadmin_restore_state($path);
        // CRITICAL: NEVER automatically clear persistent emergency lockdown
        if ($state['status'] === 'emergency_lockdown') {
            return false;
        }
        if (!empty($state['token']) && hash_equals($state['token'], $token)) {
            @unlink($path);
            $fileReleased = !file_exists($path);
        } else {
            // Ownership token mismatch: refuse to clear another owner's lock
            return false;
        }
    } else {
        $fileReleased = true;
    }

    if (!$fileReleased) {
        return false;
    }

    // Release MySQL advisory lock on the connection ONLY if ownership was confirmed and file released
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if (str_starts_with($driver, 'mysql')) {
        try {
            $pdo->query("SELECT RELEASE_LOCK('medconnect_db_restore_lock')");
        } catch (Throwable $e) {}
    }

    return true;
}

/**
 * Transition the maintenance barrier into persistent emergency lockdown.
 * Retains sanitized diagnostics for authorized recovery and releases the MySQL connection lock.
 */
function superadmin_restore_emergency_lockdown(
    PDO $pdo,
    string $token,
    string $sanitizedDiagnostics,
    int $backupId,
    int $userId,
    string $userRole,
    ?string $customPath = null
): void {
    $path = $customPath ?? superadmin_restore_lock_path();
    $payload = [
        'status' => 'emergency_lockdown',
        'token' => $token,
        'owner_id' => $userId,
        'owner_role' => $userRole,
        'backup_id' => $backupId,
        'failed_at' => date('c'),
        'diagnostics' => $sanitizedDiagnostics,
        'requires_superadmin_resolution' => true,
    ];

    $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    @file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($tmp, 0600);
    @rename($tmp, $path);

    // Release MySQL advisory lock to avoid hung sockets
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if (str_starts_with($driver, 'mysql')) {
        try {
            $pdo->query("SELECT RELEASE_LOCK('medconnect_db_restore_lock')");
        } catch (Throwable $e) {}
    }

    try {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'Emergency lockdown engaged: ' . $sanitizedDiagnostics);
    } catch (Throwable $e) {}
}

/**
 * Apply a MedConnect SQL dump statement-by-statement.
 * Avoids "MySQL server has gone away" from sending multi‑MB dumps in one exec().
 *
 * Safe for dumps produced by superadmin_create_backup() (no stored routines / DELIMITER).
 */
function superadmin_backup_exec_sql_dump(PDO $pdo, string $sql): void
{
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    $chunks = preg_split('/;\s*\n/', $sql) ?: [];

    foreach ($chunks as $chunk) {
        $lines = preg_split('/\n/', $chunk) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            $trim = ltrim($line);
            if ($trim === '' || str_starts_with($trim, '--')) {
                continue;
            }
            $kept[] = $line;
        }
        $stmt = trim(implode("\n", $kept));
        if ($stmt === '') {
            continue;
        }
        $stmt = rtrim($stmt, " \t;");
        if ($stmt === '') {
            continue;
        }
        $pdo->exec($stmt);
    }
}

/**
 * Verify database health (connection, required tables, foreign keys, record presence).
 *
 * @return array{ok:bool,reason:string}
 */
function superadmin_verify_database_health(PDO $pdo): array
{
    try {
        $pdo->query('SELECT 1');

        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $requiredTables = ['users', 'backup_logs', 'consultations'];

        if (str_starts_with($driver, 'mysql')) {
            $stmt = $pdo->query('SHOW TABLES');
            $existing = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
            $existingLower = array_map('strtolower', $existing);
            foreach ($requiredTables as $t) {
                if (!in_array(strtolower($t), $existingLower, true)) {
                    return ['ok' => false, 'reason' => "Required table '{$t}' is missing."];
                }
            }

            // Verify foreign key checks are active
            $fkStmt = $pdo->query('SELECT @@FOREIGN_KEY_CHECKS');
            $fkVal = $fkStmt ? (int) $fkStmt->fetchColumn() : 0;
            if ($fkVal !== 1) {
                return ['ok' => false, 'reason' => 'FOREIGN_KEY_CHECKS is not enabled (value: ' . $fkVal . ').'];
            }
        } else {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
            $existing = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
            $existingLower = array_map('strtolower', $existing);
            foreach ($requiredTables as $t) {
                if (!in_array(strtolower($t), $existingLower, true)) {
                    return ['ok' => false, 'reason' => "Required table '{$t}' is missing."];
                }
            }
        }

        $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($userCount <= 0) {
            return ['ok' => false, 'reason' => 'Users table has 0 records.'];
        }

        return ['ok' => true, 'reason' => 'Database healthy.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'reason' => 'Database query check failed: ' . $e->getMessage()];
    }
}

/**
 * Execute recovery replay from a verified pre-restore snapshot.
 * Restores FOREIGN_KEY_CHECKS=1 in finally.
 *
 * @return array{success:bool,reason:string}
 */
function superadmin_restore_execute_recovery(PDO $pdo, string $snapshotPath, int $userId): array
{
    if ($snapshotPath === '' || !is_file($snapshotPath) || !is_readable($snapshotPath)) {
        return ['success' => false, 'reason' => 'Snapshot file is unreadable or missing.'];
    }

    $sql = file_get_contents($snapshotPath);
    if ($sql === false || trim($sql) === '') {
        return ['success' => false, 'reason' => 'Snapshot SQL content is empty.'];
    }

    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $isMysql = str_starts_with($driver, 'mysql');

    try {
        if ($isMysql) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        }
        superadmin_backup_exec_sql_dump($pdo, $sql);
    } catch (Throwable $e) {
        return ['success' => false, 'reason' => 'Recovery SQL execution error.'];
    } finally {
        if ($isMysql) {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable $e) {}
        }
    }

    $health = superadmin_verify_database_health($pdo);
    if (!$health['ok']) {
        return ['success' => false, 'reason' => 'Database failed health check after recovery replay: ' . $health['reason']];
    }

    return ['success' => true, 'reason' => 'Recovery snapshot successfully applied and verified.'];
}

/**
 * Clear persistent emergency lockdown.
 * Explicitly authorized Super Admin only; verifies password and confirms database health.
 */
function superadmin_restore_emergency_clear(
    PDO $pdo,
    int $userId,
    string $currentPassword,
    ?string $customPath = null
): array {
    $path = $customPath ?? superadmin_restore_lock_path();

    if ($userId <= 0) {
        return ['success' => false, 'status_code' => 403, 'message' => 'Super Admin authentication required.'];
    }

    $role = superadmin_backup_user_role($pdo, $userId);
    if ($role !== 'superadmin') {
        return ['success' => false, 'status_code' => 403, 'message' => 'Only Super Admin can clear emergency lockdown.'];
    }

    if ($currentPassword === '' || !superadmin_verify_current_password($pdo, $userId, $currentPassword)) {
        superadmin_backup_audit_fail($pdo, 'emergency_lockdown_clear', $userId, 0, 'Password re-authentication failed');
        return ['success' => false, 'status_code' => 401, 'message' => 'Password is incorrect.'];
    }

    // Health check must succeed before clearing emergency lockdown
    $health = superadmin_verify_database_health($pdo);
    if (!$health['ok']) {
        superadmin_backup_audit_fail($pdo, 'emergency_lockdown_clear', $userId, 0, 'Database health check failed: ' . $health['reason']);
        return [
            'success' => false,
            'status_code' => 400,
            'message' => 'Cannot clear emergency lockdown: database health verification failed (' . $health['reason'] . '). Resolve database issues before clearing.',
        ];
    }

    if (file_exists($path)) {
        @unlink($path);
    }

    superadmin_backup_audit_success($pdo, 'emergency_lockdown_clear', $userId, 0, 'Emergency lockdown cleared by Super Admin after health verification');

    return ['success' => true, 'status_code' => 200, 'message' => 'Emergency lockdown cleared successfully. System returned to normal operation.'];
}

/**
 * Universal maintenance barrier check.
 * Blocks all conflicting requests with HTTP 503 during restore or emergency lockdown.
 * Preserves access strictly for authorized recovery and backup status endpoints.
 */
function superadmin_restore_barrier_enforce(?string $customPath = null): void
{
    if (PHP_SAPI === 'cli' && empty($_SERVER['MEDCONNECT_TEST_ENFORCE_BARRIER'])) {
        return;
    }

    $state = superadmin_restore_state($customPath);
    if (!$state['active']) {
        return;
    }

    // Active restore executing in the same process
    if (defined('MEDCONNECT_RESTORE_RUNNING') && MEDCONNECT_RESTORE_RUNNING) {
        return;
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['REQUEST_URI'] ?? ''));
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $isSuperadminBackupApi = str_contains($script, '/app/api/superadmin/backup.php');
    $isAdminBackupApi = str_contains($script, '/app/api/admin/backup.php');

    // Only allowlisted backup/recovery API requests from authorized users may pass through
    if ($isSuperadminBackupApi || $isAdminBackupApi) {
        if (session_status() === PHP_SESSION_NONE && function_exists('medconnect_session_start')) {
            medconnect_session_start();
        }
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $userRole = (string) ($_SESSION['user_role'] ?? '');
        $action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? ''));

        if ($isSuperadminBackupApi && $userId > 0 && $userRole === 'superadmin') {
            // Super Admin can check status (GET/POST), clear emergency lockdown (POST), recover (POST), download backup (POST), or submit restore (POST)
            if ($action === 'status') {
                return;
            }
            if ($method === 'POST' && in_array($action, ['restore', 'emergency_clear', 'emergency_recover', 'download'], true)) {
                return;
            }
        } elseif ($isAdminBackupApi && $userId > 0 && in_array($userRole, ['admin', 'superadmin'], true)) {
            // Ordinary Admin portal can ONLY check status polling during maintenance
            if ($action === 'status') {
                return;
            }
        }
    }

    // Block ALL other requests (GET views, write APIs, unauthorized callers) with HTTP 503
    $isEmergency = ($state['status'] ?? '') === 'emergency_lockdown';
    $message = $isEmergency
        ? 'The system is in emergency recovery mode following a database restore event. Data modifications and portal views are temporarily unavailable.'
        : 'Scheduled database restoration maintenance is currently in progress. Data modifications and portal views are temporarily unavailable.';

    http_response_code(503);
    header('Retry-After: 60');

    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $isJson = str_contains($uri, '/app/api/') || str_contains($uri, '/api/') || stripos($accept, 'application/json') !== false;

    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'success' => false,
            'message' => $message,
            'code' => $isEmergency ? 'emergency_lockdown' : 'maintenance_mode',
            'retry_after' => 60,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>503 Service Unavailable</title></head><body style="font-family:sans-serif;text-align:center;padding:50px;">'
        . '<h1>503 - Service Unavailable</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    exit;
}

/**
 * Validate a backup dump without restoring or touching live patient data.
 *
 * Checks: log row, path containment, SHA-256 checksum, non-empty file, SQL dump signals.
 *
 * @return array{success:bool,status_code:int,message:string,details?:array}
 */
function superadmin_validate_backup(PDO $pdo, int $backupId, int $userId = 0): array
{
    superadmin_ensure_schema($pdo);

    if ($backupId <= 0) {
        return ['success' => false, 'status_code' => 400, 'message' => 'Backup not found.'];
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM backup_logs WHERE id = ? LIMIT 1');
        $stmt->execute([$backupId]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['success' => false, 'status_code' => 400, 'message' => 'Backup not found.'];
    }

    if (!$backup || empty($backup['file_path'])) {
        return ['success' => false, 'status_code' => 400, 'message' => 'Backup not found.'];
    }
    if (($backup['backup_type'] ?? '') === 'restore') {
        return ['success' => false, 'status_code' => 400, 'message' => 'Cannot validate a restore audit entry as a dump.'];
    }
    if (($backup['status'] ?? '') !== 'success') {
        return ['success' => false, 'status_code' => 400, 'message' => 'Only successful backups can be validated.'];
    }

    $safePath = superadmin_backup_resolve_safe_path((string) $backup['file_path']);
    if ($safePath === null) {
        superadmin_backup_audit_fail($pdo, 'database_backup_validate', $userId, $backupId, 'unsafe path');
        return ['success' => false, 'status_code' => 400, 'message' => 'Backup file not found or not allowed.'];
    }

    $checksumCheck = superadmin_backup_verify_checksum(
        $safePath,
        isset($backup['file_checksum']) ? (string) $backup['file_checksum'] : null
    );
    if (!$checksumCheck['ok']) {
        superadmin_backup_audit_fail($pdo, 'database_backup_validate', $userId, $backupId, (string) ($checksumCheck['reason'] ?? 'checksum'));
        return [
            'success' => false,
            'status_code' => 400,
            'message' => 'Backup integrity check failed. Create a new backup, then validate that file.',
        ];
    }

    $size = filesize($safePath);
    if ($size === false || $size <= 0) {
        return ['success' => false, 'status_code' => 400, 'message' => 'Backup file is empty.'];
    }

    $head = (string) file_get_contents($safePath, false, null, 0, 8192);
    $looksSql = $head !== ''
        && (
            str_contains($head, 'CREATE TABLE')
            || str_contains($head, 'INSERT INTO')
            || str_contains($head, '-- MedConnect')
            || str_contains($head, '-- medconnect')
            || preg_match('/^\s*--/', $head) === 1
        );
    if (!$looksSql) {
        superadmin_backup_audit_fail($pdo, 'database_backup_validate', $userId, $backupId, 'unrecognized dump format');
        return ['success' => false, 'status_code' => 400, 'message' => 'Backup file does not look like a valid SQL dump.'];
    }

    if ($userId > 0) {
        superadmin_backup_audit_success($pdo, 'database_backup_validate', $userId, $backupId, 'validation passed');
    }

    return [
        'success' => true,
        'status_code' => 200,
        'message' => 'Backup validated successfully. Checksum and dump format look good. No live data was modified.',
        'details' => [
            'backup_id' => $backupId,
            'filename' => (string) ($backup['filename'] ?? basename($safePath)),
            'file_size' => (int) $size,
            'checksum_ok' => true,
            'sql_signals_ok' => true,
        ],
    ];
}

/**
 * Test recovery against a disposable MySQL database only.
 * Never restores into the live MedConnect database.
 *
 * On Hostinger single-DB plans, CREATE DATABASE may be denied — returns
 * validate_only mode after integrity checks so demos stay safe.
 *
 * @return array{success:bool,status_code:int,message:string,mode?:string,details?:array}
 */
function superadmin_test_recovery(PDO $pdo, int $backupId, int $userId, string $currentPassword): array
{
    superadmin_ensure_schema($pdo);
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    if ($userId <= 0) {
        return ['success' => false, 'status_code' => 403, 'message' => 'Authorized Admin or Super Admin required.'];
    }
    if ($currentPassword === '') {
        return ['success' => false, 'status_code' => 400, 'message' => 'Password is required to test recovery.'];
    }
    if (!superadmin_verify_current_password($pdo, $userId, $currentPassword)) {
        superadmin_backup_audit_fail($pdo, 'database_backup_test_recovery', $userId, $backupId, 'wrong password');
        return ['success' => false, 'status_code' => 400, 'message' => 'Password is incorrect.'];
    }

    $validated = superadmin_validate_backup($pdo, $backupId, $userId);
    if (empty($validated['success'])) {
        return $validated;
    }

    try {
        $stmt = $pdo->prepare('SELECT file_path, filename FROM backup_logs WHERE id = ? LIMIT 1');
        $stmt->execute([$backupId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['success' => false, 'status_code' => 400, 'message' => 'Backup not found.'];
    }
    $safePath = superadmin_backup_resolve_safe_path((string) ($row['file_path'] ?? ''));
    if ($safePath === null) {
        return ['success' => false, 'status_code' => 400, 'message' => 'Backup file not found or not allowed.'];
    }

    $sql = file_get_contents($safePath);
    if ($sql === false || trim($sql) === '') {
        return ['success' => false, 'status_code' => 400, 'message' => 'Backup file is empty.'];
    }

    $liveDb = '';
    try {
        $liveDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    } catch (Throwable $e) {
        $liveDb = '';
    }

    $testDbName = 'mc_restore_test_' . bin2hex(random_bytes(4));
    $testPdo = null;
    $created = false;

    try {
        $pdo->exec('CREATE DATABASE `' . str_replace('`', '``', $testDbName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created = true;
    } catch (Throwable $e) {
        superadmin_backup_audit_success(
            $pdo,
            'database_backup_test_recovery',
            $userId,
            $backupId,
            'validate_only (CREATE DATABASE denied)'
        );
        return [
            'success' => true,
            'status_code' => 200,
            'mode' => 'validate_only',
            'message' => 'Backup integrity validated. Full disposable-database recovery test is unavailable on this host (CREATE DATABASE denied — typical on Hostinger single-DB plans). Live patient data was not modified.',
            'details' => $validated['details'] ?? [],
        ];
    }

    try {
        $host = defined('DB_HOST') ? (string) DB_HOST : 'localhost';
        $port = defined('DB_PORT') ? (int) DB_PORT : 3306;
        $user = defined('DB_USER') ? (string) DB_USER : '';
        $pass = defined('DB_PASS') ? (string) DB_PASS : '';
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $testDbName . ';charset=utf8mb4';
        $testPdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Safety: refuse if somehow connected to the live database name.
        $connectedDb = (string) $testPdo->query('SELECT DATABASE()')->fetchColumn();
        if ($liveDb !== '' && strcasecmp($connectedDb, $liveDb) === 0) {
            throw new RuntimeException('Refusing test recovery against the live database name.');
        }

        try {
            $testPdo->exec('SET FOREIGN_KEY_CHECKS=0');
            superadmin_backup_exec_sql_dump($testPdo, $sql);
        } finally {
            try {
                $testPdo->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable $eFk) {
            }
        }

        $tableStmt = $testPdo->query('SHOW TABLES');
        $tables = $tableStmt ? $tableStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        if ($tables === []) {
            throw new RuntimeException('Disposable database has no tables after applying dump.');
        }

        $tablesLower = array_map('strtolower', $tables);
        $fullHealthOk = false;
        if (in_array('users', $tablesLower, true)) {
            $health = superadmin_verify_database_health($testPdo);
            if (!$health['ok']) {
                throw new RuntimeException('Disposable recovery health check failed: ' . ($health['reason'] ?? 'unknown'));
            }
            $fullHealthOk = true;
        }

        superadmin_backup_audit_success(
            $pdo,
            'database_backup_test_recovery',
            $userId,
            $backupId,
            'disposable restore ok on ' . $testDbName
        );

        return [
            'success' => true,
            'status_code' => 200,
            'mode' => 'disposable_db',
            'message' => $fullHealthOk
                ? 'Recovery test succeeded on a disposable database. Live patient data was not modified.'
                : 'Recovery SQL applied successfully on a disposable database (core MedConnect tables were not all present in this dump). Live patient data was not modified.',
            'details' => array_merge($validated['details'] ?? [], [
                'test_database' => $testDbName,
                'tables_restored' => count($tables),
                'health_ok' => $fullHealthOk,
            ]),
        ];
    } catch (Throwable $e) {
        $safeReason = preg_replace('/\s+/', ' ', trim($e->getMessage())) ?: 'unknown error';
        $safeReason = substr($safeReason, 0, 180);
        superadmin_backup_audit_fail($pdo, 'database_backup_test_recovery', $userId, $backupId, $safeReason);
        return [
            'success' => false,
            'status_code' => 500,
            'mode' => 'disposable_db',
            'message' => 'Recovery test failed on the disposable database (' . $safeReason . '). Live patient data was not modified.',
        ];
    } finally {
        $testPdo = null;
        if ($created) {
            try {
                $pdo->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $testDbName) . '`');
            } catch (Throwable $eDrop) {
                error_log('MedConnect test recovery cleanup failed for disposable DB (non-fatal).');
            }
        }
    }
}

/**
 * 9-Step Safe Database Restore Execution
 */
function superadmin_restore_backup(
    PDO $pdo,
    int $backupId,
    int $userId,
    string $currentPassword = '',
    string $confirmText = ''
): array {
    superadmin_ensure_schema($pdo);
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
    $userRole = superadmin_backup_user_role($pdo, $userId);

    // STEP 1: Validate authentication, role, CSRF (done at API), password, and confirmation phrase
    $prepared = superadmin_restore_prepare($pdo, $backupId, $userId, $currentPassword, $confirmText);
    if (empty($prepared['success'])) {
        return [
            'success' => false,
            'status_code' => 400,
            'message' => (string) ($prepared['message'] ?? 'Restore is not allowed.'),
        ];
    }

    $safePath = (string) $prepared['path'];
    if (superadmin_backup_resolve_safe_path($safePath) === null) {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'unsafe path');
        return ['success' => false, 'status_code' => 400, 'message' => 'Backup file not found or not allowed.'];
    }

    $sql = file_get_contents($safePath);
    if ($sql === false || trim($sql) === '') {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'empty or invalid backup file');
        return ['success' => false, 'status_code' => 400, 'message' => 'Backup file is empty.'];
    }

    // STEP 2: Acquire exclusive restore lock and activate maintenance barrier
    $lockAcquisition = superadmin_restore_lock_acquire($pdo, $userId, $userRole, $backupId);
    if (!$lockAcquisition['success']) {
        return [
            'success' => false,
            'status_code' => $lockAcquisition['status_code'] ?? 409,
            'code' => $lockAcquisition['code'] ?? 'lock_conflict',
            'message' => $lockAcquisition['message'] ?? 'Unable to acquire restore lock.',
        ];
    }
    $lockToken = (string) $lockAcquisition['token'];

    // Lock is now acquired. Wrap all subsequent steps in try/catch to guarantee safe cleanup or lockdown
    try {
        // STEP 3: Check active consultations and video sessions
        try {
            $activeConsultations = 0;
            $activeVideoSessions = 0;
            $cStmt = $pdo->query("SELECT COUNT(*) FROM consultations WHERE status = 'in_consultation'");
            if ($cStmt) {
                $activeConsultations = (int) $cStmt->fetchColumn();
            }
            $vStmt = $pdo->query("SELECT COUNT(*) FROM video_sessions WHERE status = 'active'");
            if ($vStmt) {
                $activeVideoSessions = (int) $vStmt->fetchColumn();
            }
            if ($activeConsultations > 0 || $activeVideoSessions > 0) {
                superadmin_restore_lock_release($pdo, $lockToken);
                $msg = sprintf(
                    'Cannot restore database while clinical consultations are in progress (%d active consultation(s), %d active video session(s)).',
                    $activeConsultations,
                    $activeVideoSessions
                );
                superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, $msg);
                return ['success' => false, 'status_code' => 409, 'message' => $msg];
            }
        } catch (Throwable $e) {
            // Mock environments without consultations table
        }

        // STEP 4: Validate requested backup path and checksum
        $recheckChecksum = superadmin_backup_verify_checksum($safePath, $prepared['file_checksum'] ?? null);
        if (!$recheckChecksum['ok']) {
            superadmin_restore_lock_release($pdo, $lockToken);
            superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'checksum re-verification failed');
            return ['success' => false, 'status_code' => 400, 'message' => 'Backup integrity check failed.'];
        }

        // STEP 5: Create and verify pre-restore safety snapshot
        $preBackup = superadmin_create_backup($pdo, $userId, 'pre_restore');
        if (empty($preBackup['success']) || empty($preBackup['path'])) {
            superadmin_restore_lock_release($pdo, $lockToken);
            $failMsg = 'Pre-restore safety backup failed: ' . ($preBackup['message'] ?? 'Unable to create pre-restore snapshot');
            superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, $failMsg);
            return ['success' => false, 'status_code' => 500, 'message' => $failMsg];
        }

        $snapshotPath = superadmin_backup_resolve_safe_path((string) $preBackup['path']);
        if ($snapshotPath === null || !is_file($snapshotPath) || filesize($snapshotPath) <= 0) {
            superadmin_restore_lock_release($pdo, $lockToken);
            superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'pre-restore snapshot file invalid');
            return ['success' => false, 'status_code' => 500, 'message' => 'Pre-restore safety snapshot could not be verified. Restore aborted.'];
        }

        $snapshotChecksum = superadmin_backup_file_checksum($snapshotPath);
        if ($snapshotChecksum === null) {
            superadmin_restore_lock_release($pdo, $lockToken);
            superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'pre-restore snapshot checksum failed');
            return ['success' => false, 'status_code' => 500, 'message' => 'Pre-restore safety snapshot checksum verification failed. Restore aborted.'];
        }

        // STEP 6: Recheck relevant activity immediately prior to destructive SQL
        try {
            $cStmt = $pdo->query("SELECT COUNT(*) FROM consultations WHERE status = 'in_consultation'");
            $activeNow = $cStmt ? (int) $cStmt->fetchColumn() : 0;
            if ($activeNow > 0) {
                superadmin_restore_lock_release($pdo, $lockToken);
                $msg = 'A clinical consultation started during preflight checks. Restore aborted.';
                superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, $msg);
                return ['success' => false, 'status_code' => 409, 'message' => $msg];
            }
        } catch (Throwable $e) {}

        // STEP 7: Destructive SQL execution with foreign-key cleanup in finally
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $isMysql = str_starts_with($driver, 'mysql');
        $destructiveFailed = false;
        $destructiveError = '';

        try {
            if ($isMysql) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            }
            superadmin_backup_exec_sql_dump($pdo, $sql);
        } catch (Throwable $e) {
            $destructiveFailed = true;
            $destructiveError = $e->getMessage();
        } finally {
            if ($isMysql) {
                try {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                } catch (Throwable $eFk) {
                    // Foreign key restored without masking primary failure
                }
            }
        }

        // STEP 8: Verify resulting database state
        if (!$destructiveFailed) {
            $health = superadmin_verify_database_health($pdo);
            if (!$health['ok']) {
                $destructiveFailed = true;
                $destructiveError = 'Database health check failed after restore: ' . $health['reason'];
            }
        }

        // Handle recovery if destructive restore failed
        if ($destructiveFailed) {
            // Attempt automated recovery from pre-restore snapshot
            $recovery = superadmin_restore_execute_recovery($pdo, $snapshotPath, $userId);

            if ($recovery['success']) {
                superadmin_restore_lock_release($pdo, $lockToken);
                superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'Restore failed; database was safely reverted to pre-restore snapshot.');
                return [
                    'success' => false,
                    'status_code' => 500,
                    'message' => 'Database restore encountered an error. The database was safely reverted to its pre-restore state. No data was lost.',
                ];
            } else {
                // Both restore and recovery failed: ENGAGE EMERGENCY LOCKDOWN
                superadmin_restore_emergency_lockdown(
                    $pdo,
                    $lockToken,
                    'Restore failure followed by recovery failure: ' . $recovery['reason'],
                    $backupId,
                    $userId,
                    $userRole
                );
                return [
                    'success' => false,
                    'status_code' => 503,
                    'code' => 'emergency_lockdown',
                    'message' => 'Database restore failed and automated recovery could not confirm database health. System has entered emergency lockdown. Please contact system administrator.',
                ];
            }
        }

        // STEP 9: Record outcome, audit log, invalidate sessions, and release lock
        try {
            $pdo->prepare('
                INSERT INTO backup_logs (filename, file_path, backup_type, status, created_by, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ')->execute([
                $prepared['filename'],
                $safePath,
                'restore',
                'success',
                $userId,
                'Restored from backup #' . $backupId,
            ]);
        } catch (Throwable $e) {}

        superadmin_security_log($pdo, 'database_restore', 'backup', 'warning', "Restored backup #{$backupId}", $userId, $userRole);

        // Post-restore session invalidation: bump session_epoch for all other users and purge active_sessions
        try {
            $pdo->prepare('UPDATE users SET session_epoch = session_epoch + 1, updated_at = NOW() WHERE id != ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM active_sessions WHERE user_id != ?')->execute([$userId]);
            $stmt = $pdo->prepare('SELECT session_epoch FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $restoredEpoch = $stmt->fetchColumn();
            if ($restoredEpoch !== false) {
                $_SESSION['session_epoch'] = (int) $restoredEpoch;
            }
        } catch (Throwable $e) {}

        // Release ordinary maintenance lock cleanly
        superadmin_restore_lock_release($pdo, $lockToken);

        return ['success' => true, 'status_code' => 200, 'message' => 'Database restored from backup.'];
    } catch (Throwable $unhandled) {
        $health = superadmin_verify_database_health($pdo);
        if (!$health['ok']) {
            superadmin_restore_emergency_lockdown(
                $pdo,
                $lockToken,
                'Unhandled exception during restore: ' . $unhandled->getMessage(),
                $backupId,
                $userId,
                $userRole
            );
            return [
                'success' => false,
                'status_code' => 503,
                'code' => 'emergency_lockdown',
                'message' => 'Restore failed with an unexpected error and database health could not be verified. Emergency lockdown engaged.',
            ];
        } else {
            superadmin_restore_lock_release($pdo, $lockToken);
            return [
                'success' => false,
                'status_code' => 500,
                'message' => 'Restore aborted due to an internal error.',
            ];
        }
    }
}

/**
 * Validate restore gates without executing SQL (password, phrase, path, checksum, active calls).
 *
 * @return array{success:bool,message?:string,path?:string,filename?:string}
 */
function superadmin_restore_prepare(
    PDO $pdo,
    int $backupId,
    int $userId,
    string $currentPassword,
    string $confirmText
): array {
    if ($userId <= 0) {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'authorized Admin or Super Admin required');
        return ['success' => false, 'message' => 'Authorized Admin or Super Admin required to restore.'];
    }

    if ($currentPassword === '') {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'missing password');
        return ['success' => false, 'message' => 'Password is required to restore.'];
    }
    if (!superadmin_verify_current_password($pdo, $userId, $currentPassword)) {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'wrong password');
        return ['success' => false, 'message' => 'Password is incorrect.'];
    }

    if ($confirmText === '') {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'missing confirmation phrase');
        return ['success' => false, 'message' => 'Type RESTORE DATABASE to confirm.'];
    }
    if (!superadmin_backup_confirm_phrase_valid($confirmText)) {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'incorrect confirmation phrase');
        return ['success' => false, 'message' => 'Confirmation phrase is incorrect. Type RESTORE DATABASE.'];
    }

    // Active-consultation circuit breaker: guard against destructive restore during live visits
    try {
        $activeConsultations = 0;
        $activeVideoSessions = 0;
        $cStmt = $pdo->query("SELECT COUNT(*) FROM consultations WHERE status = 'in_consultation'");
        if ($cStmt) {
            $activeConsultations = (int) $cStmt->fetchColumn();
        }
        $vStmt = $pdo->query("SELECT COUNT(*) FROM video_sessions WHERE status = 'active'");
        if ($vStmt) {
            $activeVideoSessions = (int) $vStmt->fetchColumn();
        }
        if ($activeConsultations > 0 || $activeVideoSessions > 0) {
            $msg = sprintf(
                'Cannot restore database while clinical consultations are in progress (%d active consultation(s), %d active video session(s)).',
                $activeConsultations,
                $activeVideoSessions
            );
            superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, $msg);
            return ['success' => false, 'message' => $msg];
        }
    } catch (Throwable $e) {
        // Mock/SQLite environments without consultations table will safely proceed
    }

    if ($backupId <= 0) {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, 0, 'invalid backup');
        return ['success' => false, 'message' => 'Backup file not found.'];
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM backup_logs WHERE id = ? LIMIT 1');
        $stmt->execute([$backupId]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'invalid backup');
        return ['success' => false, 'message' => 'Backup file not found.'];
    }

    if (!$backup || empty($backup['file_path'])) {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'missing backup');
        return ['success' => false, 'message' => 'Backup file not found.'];
    }
    if (($backup['backup_type'] ?? '') === 'restore') {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'wrong backup type');
        return ['success' => false, 'message' => 'Cannot restore from a restore audit entry.'];
    }
    if (($backup['status'] ?? '') !== 'success') {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'unsuccessful backup status');
        return ['success' => false, 'message' => 'Only successful backups can be restored.'];
    }

    $safePath = superadmin_backup_resolve_safe_path((string) $backup['file_path']);
    if ($safePath === null) {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'unsafe path');
        return ['success' => false, 'message' => 'Backup file not found or not allowed.'];
    }

    $checksumCheck = superadmin_backup_verify_checksum($safePath, isset($backup['file_checksum']) ? (string) $backup['file_checksum'] : null);
    if (!$checksumCheck['ok']) {
        $reason = $checksumCheck['reason'] === 'missing_checksum'
            ? 'missing checksum'
            : 'checksum mismatch';
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, $reason);
        return [
            'success' => false,
            'message' => 'Backup integrity check failed. Create a new backup, then restore from that file.',
        ];
    }

    $size = filesize($safePath);
    if ($size === false || $size <= 0) {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'empty or invalid backup file');
        return ['success' => false, 'message' => 'Backup file is empty.'];
    }

    return [
        'success' => true,
        'path' => $safePath,
        'filename' => (string) ($backup['filename'] ?? basename($safePath)),
        'file_checksum' => (string) ($backup['file_checksum'] ?? ''),
    ];
}

/**
 * Validate download gates (password, path, checksum) without streaming the file.
 *
 * @return array{success:bool,message?:string,path?:string,filename?:string}
 */
function superadmin_backup_download_prepare(
    PDO $pdo,
    int $backupId,
    int $userId,
    string $currentPassword
): array {
    if ($userId <= 0) {
        superadmin_backup_audit_fail($pdo, 'database_backup_download', $userId, $backupId, 'authorized Admin or Super Admin required');
        return ['success' => false, 'message' => 'Authorized Admin or Super Admin required to download.'];
    }

    if ($currentPassword === '') {
        superadmin_backup_audit_fail($pdo, 'database_backup_download', $userId, $backupId, 'missing password');
        return ['success' => false, 'message' => 'Password is required to download.'];
    }
    if (!superadmin_verify_current_password($pdo, $userId, $currentPassword)) {
        superadmin_backup_audit_fail($pdo, 'database_backup_download', $userId, $backupId, 'wrong password');
        return ['success' => false, 'message' => 'Password is incorrect.'];
    }

    if ($backupId <= 0) {
        superadmin_backup_audit_fail($pdo, 'database_backup_download', $userId, 0, 'invalid backup');
        return ['success' => false, 'message' => 'Backup file not found.'];
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM backup_logs WHERE id = ? AND status = ? LIMIT 1');
        $stmt->execute([$backupId, 'success']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        superadmin_backup_audit_fail($pdo, 'database_backup_download', $userId, $backupId, 'invalid backup');
        return ['success' => false, 'message' => 'Backup file not found.'];
    }

    if (!$row || ($row['backup_type'] ?? '') === 'restore') {
        superadmin_backup_audit_fail($pdo, 'database_backup_download', $userId, $backupId, 'invalid backup');
        return ['success' => false, 'message' => 'Backup file not found.'];
    }

    $safePath = superadmin_backup_resolve_safe_path((string) ($row['file_path'] ?? ''));
    if ($safePath === null) {
        superadmin_backup_audit_fail($pdo, 'database_backup_download', $userId, $backupId, 'unsafe path');
        return ['success' => false, 'message' => 'Backup file not found or not allowed.'];
    }

    $checksumCheck = superadmin_backup_verify_checksum($safePath, isset($row['file_checksum']) ? (string) $row['file_checksum'] : null);
    if (!$checksumCheck['ok']) {
        $reason = $checksumCheck['reason'] === 'missing_checksum'
            ? 'missing checksum'
            : 'checksum mismatch';
        superadmin_backup_audit_fail($pdo, 'database_backup_download', $userId, $backupId, $reason);
        return [
            'success' => false,
            'message' => 'Backup integrity check failed. Create a new backup, then download that file.',
        ];
    }

    superadmin_backup_audit_success($pdo, 'database_backup_download', $userId, $backupId, 'download succeeded');

    return [
        'success' => true,
        'path' => $safePath,
        'filename' => (string) ($row['filename'] ?? basename($safePath)),
    ];
}
