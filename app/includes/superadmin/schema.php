<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/system_settings.php';
require_once dirname(__DIR__) . '/login_security.php';

function superadmin_ensure_role_enum(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec("
            ALTER TABLE users
            MODIFY COLUMN role ENUM('patient', 'provider', 'admin', 'bhw', 'superadmin') NOT NULL DEFAULT 'patient'
        ");
    } catch (PDOException $e) {
        // ignore if already applied
    }
    $done = true;
}

function superadmin_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    superadmin_ensure_role_enum($pdo);
    system_settings_ensure_schema($pdo);
    login_security_ensure_schema($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS super_admins (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            permissions JSON NULL,
            notes VARCHAR(255) NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_super_admin_user (user_id),
            KEY idx_super_admin_created (created_at),
            CONSTRAINT fk_super_admins_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_super_admins_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS security_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NULL,
            role VARCHAR(20) NULL,
            action VARCHAR(80) NOT NULL,
            module VARCHAR(60) NULL,
            status ENUM('success','failure','warning','info') NOT NULL DEFAULT 'info',
            description TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            browser VARCHAR(80) NULL,
            device VARCHAR(30) NULL,
            meta JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sec_logs_action (action, created_at),
            KEY idx_sec_logs_user (user_id, created_at),
            KEY idx_sec_logs_ip (ip_address, created_at),
            KEY idx_sec_logs_status (status, created_at),
            CONSTRAINT fk_security_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS failed_logins (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NULL,
            user_id INT UNSIGNED NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            reason VARCHAR(120) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_failed_logins_email (email, created_at),
            KEY idx_failed_logins_ip (ip_address, created_at),
            KEY idx_failed_logins_user (user_id, created_at),
            CONSTRAINT fk_failed_logins_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS blocked_ips (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip_address VARCHAR(45) NOT NULL,
            reason VARCHAR(255) NULL,
            blocked_by INT UNSIGNED NULL,
            blocked_until DATETIME NULL,
            is_permanent TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_blocked_ip (ip_address),
            KEY idx_blocked_until (blocked_until),
            CONSTRAINT fk_blocked_ips_by FOREIGN KEY (blocked_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS active_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL,
            session_id VARCHAR(128) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            browser VARCHAR(80) NULL,
            device VARCHAR(30) NULL,
            last_activity DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_active_sessions_user (user_id, last_activity),
            KEY idx_active_sessions_role (role, last_activity),
            CONSTRAINT fk_active_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS backup_logs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            filename VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NULL,
            file_size BIGINT UNSIGNED NULL DEFAULT 0,
            backup_type ENUM('manual','scheduled','restore') NOT NULL DEFAULT 'manual',
            status ENUM('success','failed','in_progress') NOT NULL DEFAULT 'in_progress',
            created_by INT UNSIGNED NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_backup_status (status, created_at),
            KEY idx_backup_type (backup_type, created_at),
            CONSTRAINT fk_backup_logs_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS api_settings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            api_key VARCHAR(100) NOT NULL,
            api_value TEXT NOT NULL,
            is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
            updated_by INT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_api_key (api_key),
            CONSTRAINT fk_api_settings_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $defaults = [
        'SYSTEM_NAME' => 'medConnect',
        'SYSTEM_VERSION' => '1.0.0',
        'SYSTEM_TIMEZONE' => 'Asia/Manila',
        'MAINTENANCE_MODE' => '0',
        'MAX_UPLOAD_MB' => '10',
        'PASSWORD_MIN_LENGTH' => '8',
        'PASSWORD_REQUIRE_UPPERCASE' => '1',
        'PASSWORD_REQUIRE_NUMBER' => '1',
        'RECAPTCHA_ENABLED' => '1',
        'EMAIL_FROM_NAME' => 'medConnect',
        'EMAIL_FROM_ADDRESS' => 'noreply@medconnect.local',
    ];
    foreach ($defaults as $key => $value) {
        try {
            system_settings_set($pdo, $key, $value, null);
        } catch (Throwable $e) {}
    }

    $apiDefaults = [
        'AI_SERVICE_URL' => 'http://127.0.0.1:5000',
        'AI_SERVICE_ENABLED' => '1',
        'VIDEO_SERVICE_ENABLED' => '1',
        'EMAIL_SERVICE_ENABLED' => '1',
    ];
    foreach ($apiDefaults as $key => $value) {
        try {
            $pdo->prepare('INSERT INTO api_settings (api_key, api_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE api_key = api_key')
                ->execute([$key, $value]);
        } catch (Throwable $e) {}
    }

    $done = true;
}

function superadmin_link_user(PDO $pdo, int $userId, ?int $createdBy = null): void
{
    superadmin_ensure_schema($pdo);
    $stmt = $pdo->prepare('
        INSERT INTO super_admins (user_id, created_by, permissions)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE updated_at = NOW()
    ');
    $stmt->execute([
        $userId,
        $createdBy,
        json_encode(['full_access' => true], JSON_UNESCAPED_UNICODE),
    ]);
}

function superadmin_is_user(PDO $pdo, int $userId): bool
{
    superadmin_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'superadmin' LIMIT 1");
    $stmt->execute([$userId]);
    return (bool) $stmt->fetchColumn();
}
