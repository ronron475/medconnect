<?php
/**
 * Disposable probe: isolate Phase1 suite-4 hang (superadmin_restore_backup on bad SQL).
 * Does not touch the live medconnect database. CLI only.
 *
 * Run: php scripts/dev/probe_restore_suite4_hang.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $root);
}

require_once $root . '/config/env_loader.php';
require_once $root . '/app/includes/superadmin/backup.php';
require_once $root . '/app/includes/security_throttle.php';

function step(string $msg): void
{
    echo '[' . date('H:i:s') . '] ' . $msg . "\n";
    flush();
}

$testDb = 'medconnect_s4probe_' . bin2hex(random_bytes(4));
step("Creating disposable DB {$testDb}");

$rootPdo = new PDO('mysql:host=localhost;port=3306;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$rootPdo->exec("CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = new PDO("mysql:host=localhost;port=3306;dbname={$testDb};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

register_shutdown_function(static function () use ($rootPdo, $testDb, $root): void {
    try {
        $rootPdo->exec("DROP DATABASE IF EXISTS `{$testDb}`");
        echo "[cleanup] dropped {$testDb}\n";
    } catch (Throwable $e) {
        echo "[cleanup] drop failed: {$e->getMessage()}\n";
    }
    $lock = $root . '/storage/backups/.restore_state.lock';
    if (is_file($lock)) {
        @unlink($lock);
        echo "[cleanup] removed leftover .restore_state.lock\n";
    }
    try {
        $rootPdo->query("SELECT RELEASE_LOCK('medconnect_db_restore_lock')");
    } catch (Throwable $e) {
    }
});

step('Seeding minimal schema');
$pdo->exec("
    CREATE TABLE users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) NOT NULL,
        email VARCHAR(191) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role ENUM('patient','provider','admin','bhw','superadmin') NOT NULL DEFAULT 'patient',
        status VARCHAR(30) NOT NULL DEFAULT 'active',
        session_epoch INT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL
    ) ENGINE=InnoDB;
    CREATE TABLE consultations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        patient_id INT UNSIGNED NOT NULL,
        provider_id INT UNSIGNED NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB;
    CREATE TABLE video_sessions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        consultation_id INT UNSIGNED NOT NULL,
        session_token VARCHAR(255) NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'waiting',
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB;
    CREATE TABLE backup_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        backup_type VARCHAR(50) NOT NULL,
        file_size BIGINT NULL,
        file_checksum VARCHAR(64) NULL,
        status VARCHAR(50) NOT NULL,
        created_by INT NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL
    );
    CREATE TABLE security_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        role VARCHAR(50) NULL,
        action VARCHAR(100) NOT NULL,
        module VARCHAR(50) NOT NULL,
        status VARCHAR(50) NOT NULL,
        description TEXT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        browser VARCHAR(50) NULL,
        device VARCHAR(50) NULL,
        meta JSON NULL,
        created_at DATETIME NOT NULL
    );
    CREATE TABLE active_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        session_id VARCHAR(128) NOT NULL,
        last_activity DATETIME NOT NULL
    );
    CREATE TABLE system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NULL,
        updated_by INT NULL,
        updated_at DATETIME NULL
    );
");
security_throttle_ensure_schema($pdo);

$plain = 'SuperSecret!123';
$hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 4]);
$pdo->prepare("INSERT INTO users (id, first_name, last_name, email, password, role, status, session_epoch, created_at)
    VALUES (1, 'Super', 'Admin', 'superadmin@example.com', ?, 'superadmin', 'active', 1, NOW())")
    ->execute([$hash]);

step('ensure_schema');
superadmin_ensure_schema($pdo);

step('create_backup (standalone preflight)');
$t0 = microtime(true);
$pre = superadmin_create_backup($pdo, 1, 'manual');
step(sprintf('create_backup done in %.2fs success=%s', microtime(true) - $t0, !empty($pre['success']) ? 'yes' : 'no'));

$badSql = "SET FOREIGN_KEY_CHECKS=0;\nCREATE TABLE medconnect_temp_broken (id INT PRIMARY KEY);\nINVALID SQL SYNTAX HERE EXCEPTION;\n";
$badPath = superadmin_backup_dir() . '/medconnect_s4probe_broken_' . bin2hex(random_bytes(3)) . '.sql';
file_put_contents($badPath, $badSql);
$checksum = hash_file('sha256', $badPath);
$pdo->prepare("INSERT INTO backup_logs (filename, file_path, backup_type, file_size, file_checksum, status, created_by, notes, created_at)
    VALUES (?, ?, 'manual', ?, ?, 'success', 1, 'probe bad sql', NOW())")
    ->execute([basename($badPath), $badPath, strlen($badSql), $checksum]);
$badId = (int) $pdo->lastInsertId();
step("bad backup_id={$badId} path={$badPath}");

step('IS_USED_LOCK before restore');
$used = (int) $pdo->query("SELECT IS_USED_LOCK('medconnect_db_restore_lock')")->fetchColumn();
step("advisory_used={$used}");

step('Calling superadmin_restore_backup (suite 4 path)…');
$t1 = microtime(true);
$result = superadmin_restore_backup($pdo, $badId, 1, $plain, 'RESTORE DATABASE');
step(sprintf('restore_backup returned in %.2fs', microtime(true) - $t1));
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

$fk = (int) $pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn();
step("FOREIGN_KEY_CHECKS after={$fk}");

$lockExists = is_file(superadmin_restore_lock_path());
$state = superadmin_restore_state();
step('lock_file_exists=' . ($lockExists ? 'yes' : 'no') . ' status=' . ($state['status'] ?? 'n/a') . ' active=' . (!empty($state['active']) ? 'yes' : 'no'));

@unlink($badPath);
if (!empty($pre['path']) && is_file((string) $pre['path'])) {
    @unlink((string) $pre['path']);
}
// also remove any pre_restore snapshots created during restore
foreach (glob(superadmin_backup_dir() . '/medconnect_backup_*.sql') ?: [] as $f) {
    if (is_file($f) && str_contains((string) file_get_contents($f, false, null, 0, 200), 'Type: pre_restore')) {
        @unlink($f);
    }
}

step('probe complete');
exit(0);
