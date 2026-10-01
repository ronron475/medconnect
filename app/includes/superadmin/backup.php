<?php
declare(strict_types=1);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/security.php';

function superadmin_backup_dir(): string
{
    $dir = BASE_PATH . '/storage/backups';
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
 * Re-authenticate the logged-in Super Admin with their current password.
 * Uses password_verify() against users.password. Never stores the submitted password.
 */
function superadmin_verify_current_password(PDO $pdo, int $userId, string $password): bool
{
    if ($userId <= 0 || $password === '') {
        return false;
    }
    try {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? AND role = 'superadmin' LIMIT 1");
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
    try {
        superadmin_security_log(
            $pdo,
            $action,
            'backup',
            'failure',
            $desc,
            $userId > 0 ? $userId : null,
            $userId > 0 ? 'superadmin' : 'system'
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
    try {
        superadmin_security_log(
            $pdo,
            $action,
            'backup',
            $status,
            $desc,
            $userId > 0 ? $userId : null,
            $userId > 0 ? 'superadmin' : 'system'
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

    if (!in_array($type, ['manual', 'scheduled'], true)) {
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
        superadmin_security_log(
            $pdo,
            'database_backup',
            'backup',
            'failure',
            "Backup failed ({$type}): " . $e->getMessage(),
            $userId,
            $userId ? 'superadmin' : 'system'
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
        superadmin_security_log(
            $pdo,
            'database_backup',
            'backup',
            'failure',
            "Backup checksum failed ({$type}): {$filename}",
            $userId,
            $userId ? 'superadmin' : 'system'
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

    superadmin_security_log(
        $pdo,
        'database_backup',
        'backup',
        'success',
        "Backup created ({$type}): {$filename}",
        $userId,
        $userId ? 'superadmin' : 'system'
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
    $settings = superadmin_backup_settings($pdo);
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

    return [
        'latest' => $latest ?: null,
        'last_auto' => $lastAuto ?: null,
        'settings' => $settings,
        'is_due' => superadmin_backup_is_due($pdo, $settings),
    ];
}

function superadmin_restore_backup(
    PDO $pdo,
    int $backupId,
    int $userId,
    string $currentPassword = '',
    string $confirmText = ''
): array {
    superadmin_ensure_schema($pdo);
    $prepared = superadmin_restore_prepare($pdo, $backupId, $userId, $currentPassword, $confirmText);
    if (empty($prepared['success'])) {
        return [
            'success' => false,
            'message' => (string) ($prepared['message'] ?? 'Restore is not allowed.'),
        ];
    }

    $safePath = (string) $prepared['path'];
    $sql = file_get_contents($safePath);
    if ($sql === false || $sql === '') {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'empty or invalid backup file');
        return ['success' => false, 'message' => 'Backup file is empty.'];
    }

    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec($sql);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $e) {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'SQL restore failed');
        return ['success' => false, 'message' => 'Restore failed.'];
    }

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
    } catch (Throwable $e) {
    }

    superadmin_security_log($pdo, 'database_restore', 'backup', 'warning', "Restored backup #{$backupId}", $userId, 'superadmin');

    return ['success' => true, 'message' => 'Database restored from backup.'];
}

/**
 * Validate restore gates without executing SQL (password, phrase, path, checksum).
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
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'authorized Super Admin required');
        return ['success' => false, 'message' => 'Authorized Super Admin required to restore.'];
    }

    if ($currentPassword === '') {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'missing password');
        return ['success' => false, 'message' => 'Super Admin password is required to restore.'];
    }
    if (!superadmin_verify_current_password($pdo, $userId, $currentPassword)) {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'wrong password');
        return ['success' => false, 'message' => 'Super Admin password is incorrect.'];
    }

    if ($confirmText === '') {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'missing confirmation phrase');
        return ['success' => false, 'message' => 'Type RESTORE DATABASE to confirm.'];
    }
    if (!superadmin_backup_confirm_phrase_valid($confirmText)) {
        superadmin_backup_audit_fail($pdo, 'database_restore', $userId, $backupId, 'incorrect confirmation phrase');
        return ['success' => false, 'message' => 'Confirmation phrase is incorrect. Type RESTORE DATABASE.'];
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
        superadmin_backup_audit_fail($pdo, 'database_backup_download', $userId, $backupId, 'authorized Super Admin required');
        return ['success' => false, 'message' => 'Authorized Super Admin required to download.'];
    }

    if ($currentPassword === '') {
        superadmin_backup_audit_fail($pdo, 'database_backup_download', $userId, $backupId, 'missing password');
        return ['success' => false, 'message' => 'Super Admin password is required to download.'];
    }
    if (!superadmin_verify_current_password($pdo, $userId, $currentPassword)) {
        superadmin_backup_audit_fail($pdo, 'database_backup_download', $userId, $backupId, 'wrong password');
        return ['success' => false, 'message' => 'Super Admin password is incorrect.'];
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
