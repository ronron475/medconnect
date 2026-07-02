<?php
/**
 * system_settings table helpers.
 */
function system_settings_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `system_settings` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `setting_key` VARCHAR(100) NOT NULL,
            `setting_value` TEXT NOT NULL,
            `updated_by` INT UNSIGNED NULL,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_setting_key` (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function system_settings_get_all(PDO $pdo): array
{
    system_settings_ensure_schema($pdo);
    $rows = $pdo->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        $out[$row['setting_key']] = $row['setting_value'];
    }
    return $out;
}

function system_settings_get(PDO $pdo, string $key, ?string $default = null): ?string
{
    system_settings_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (string) $val : $default;
}

function system_settings_set(PDO $pdo, string $key, string $value, ?int $updatedBy = null): void
{
    system_settings_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, updated_by)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()
    ");
    $stmt->execute([$key, $value, $updatedBy]);
}

function system_settings_set_many(PDO $pdo, array $pairs, ?int $updatedBy = null): void
{
    foreach ($pairs as $key => $value) {
        system_settings_set($pdo, (string) $key, (string) $value, $updatedBy);
    }
}
