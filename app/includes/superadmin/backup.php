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

function superadmin_create_backup(PDO $pdo, int $userId, string $type = 'manual'): array
{
    superadmin_ensure_schema($pdo);

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
    } catch (Throwable $e) {}

    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!$dbName) {
        return ['success' => false, 'message' => 'Could not determine database name.'];
    }

  $lines = ["-- medConnect backup\n", "-- Generated: " . date('c') . "\n\n"];
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
        return ['success' => false, 'message' => 'Backup failed: ' . $e->getMessage()];
    }

    $lines[] = "SET FOREIGN_KEY_CHECKS=1;\n";
    $content = implode('', $lines);
    file_put_contents($path, $content);
    $size = filesize($path) ?: 0;

    if ($logId) {
        $pdo->prepare("UPDATE backup_logs SET status='success', file_size=?, notes=? WHERE id=?")
            ->execute([$size, 'Backup completed successfully.', $logId]);
    }

    superadmin_security_log($pdo, 'database_backup', 'backup', 'success', "Backup created: {$filename}", $userId, 'superadmin');

    return [
        'success' => true,
        'message' => 'Backup created successfully.',
        'filename' => $filename,
        'path' => $path,
        'size' => $size,
        'log_id' => $logId,
    ];
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

function superadmin_restore_backup(PDO $pdo, int $backupId, int $userId): array
{
    superadmin_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM backup_logs WHERE id = ? LIMIT 1');
    $stmt->execute([$backupId]);
    $backup = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$backup || empty($backup['file_path']) || !is_readable($backup['file_path'])) {
        return ['success' => false, 'message' => 'Backup file not found.'];
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
    } catch (Throwable $e) {}

    superadmin_security_log($pdo, 'database_restore', 'backup', 'warning', "Restored backup #{$backupId}", $userId, 'superadmin');

    return ['success' => true, 'message' => 'Database restored from backup.'];
}
