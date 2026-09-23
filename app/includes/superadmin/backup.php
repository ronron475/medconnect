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

    if ($logId) {
        $pdo->prepare("UPDATE backup_logs SET status='success', file_size=?, notes=? WHERE id=?")
            ->execute([$size, 'Backup completed successfully.', $logId]);
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
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
        try {
            $del->execute([(int) $row['id']]);
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

function superadmin_restore_backup(PDO $pdo, int $backupId, int $userId): array
{
    superadmin_ensure_schema($pdo);
    if ($userId <= 0) {
        return ['success' => false, 'message' => 'Authorized Super Admin required to restore.'];
    }

    $stmt = $pdo->prepare('SELECT * FROM backup_logs WHERE id = ? LIMIT 1');
    $stmt->execute([$backupId]);
    $backup = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$backup || empty($backup['file_path']) || !is_readable($backup['file_path'])) {
        return ['success' => false, 'message' => 'Backup file not found.'];
    }
    if (($backup['backup_type'] ?? '') === 'restore') {
        return ['success' => false, 'message' => 'Cannot restore from a restore audit entry.'];
    }
    if (($backup['status'] ?? '') !== 'success') {
        return ['success' => false, 'message' => 'Only successful backups can be restored.'];
    }

    $sql = file_get_contents($backup['file_path']);
    if ($sql === false || $sql === '') {
        return ['success' => false, 'message' => 'Backup file is empty.'];
    }

    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec($sql);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $e) {
        superadmin_security_log($pdo, 'database_restore', 'backup', 'failure', "Restore failed for #{$backupId}: " . $e->getMessage(), $userId, 'superadmin');
        return ['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()];
    }

    try {
        $pdo->prepare('
            INSERT INTO backup_logs (filename, file_path, backup_type, status, created_by, notes, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ')->execute([
            $backup['filename'],
            $backup['file_path'],
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
